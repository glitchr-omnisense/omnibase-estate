<?php

namespace Base\Estate\Signature;

use Base\Database\Attribute\Uploader;
use Base\Entity\Signature\Envelope;
use Base\Entity\User;
use Base\Estate\Entity\Document;
use Base\Estate\Entity\Lease;
use Base\Estate\Entity\LeaseParty;
use Base\Estate\Entity\Member;
use Base\Estate\Enum\ActivityKind;
use Base\Estate\Enum\DocumentKind;
use Base\Estate\Enum\PartyRole;
use Base\Estate\Service\ActivityLog;
use Base\Estate\Service\EstateMailer;
use Base\Estate\Service\Receipts;
use Base\Event\SignatureEvent;
use Base\Service\Signatures;
use Doctrine\ORM\EntityManagerInterface;
use Omnisign\Model\Document as SignedDocument;
use Omnisign\Model\Envelope as SignatureRequest;
use Omnisign\Model\Field;
use Omnisign\Model\File;
use Omnisign\Model\Level;
use Omnisign\Model\Signer;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\File\File as UploadFile;
use Twig\Environment;

/**
 * A lease, or its inventory of fixtures, signed through omnisense/omnisign
 * (omnisense/omnibase's Base\Service\Signatures: one envelope about the lease,
 * kept with its provider's reference, its state, the signed document and its
 * evidence in the private storage).
 *
 * What is signed: the lease the team uploaded (a Document « Bail » of the
 * lease), else its particular conditions generated from the record
 * (@Estate/pdf/lease.html.twig); the inventory the team uploaded (a Document
 * « État des lieux »). Each comes with a signature page naming it by its
 * SHA-256, one box per signer. Who signs, in this order: the landlord (the
 * person who sends it, for the company), the tenants, then for the lease its
 * guarantors. The provider e-mails each signer in turn and tells the site
 * (its webhook: POST /signatures/{gateway}/webhook) as they sign, refuse, or
 * let it expire; completed, the signed PDF is also filed as the lease's
 * document, which its tenants see.
 *
 *     estate:
 *         signature:
 *             gateway: docuseal          # a gateway of omnisign.gateways; null: no electronic signature
 *             template: ~                # DocuSeal's open-source edition signs its own templates only: the lease's
 *             inventory_template: ~
 *
 * Not the notary's authentic act: a simple (or advanced) electronic
 * signature of a private deed. The guarantee - the surety's own deed, with
 * its mentions - is not sent yet.
 *
 * This folder is registered only when omnisense/omnisign and the core's
 * Signatures are there; nothing of it implements an omnisign interface.
 */
#[AsEventListener(event: SignatureEvent::COMPLETED, method: 'onCompleted')]
#[AsEventListener(event: SignatureEvent::DECLINED, method: 'onStopped')]
#[AsEventListener(event: SignatureEvent::EXPIRED, method: 'onStopped')]
class LeaseSignatures
{
    public const LEASE = 'lease';
    public const INVENTORY = 'inventory';

    /** The signature page's boxes: two columns from this height, in points (A4: 595 × 842). */
    private const BOX = ['top' => 250, 'left' => 51, 'gap' => 17, 'width' => 238, 'height' => 72, 'row' => 118];

    /** How long the signers have. */
    private const DAYS = 30;

    public function __construct(
        private readonly Signatures $signatures,
        private readonly Receipts $receipts,
        private readonly EntityManagerInterface $entityManager,
        private readonly ActivityLog $activities,
        private readonly EstateMailer $mailer,
        private readonly Environment $twig,
        private readonly ?LoggerInterface $logger = null,
        #[Autowire('%estate.signature.gateway%')] private readonly ?string $gateway = null,
        #[Autowire('%estate.signature.template%')] private readonly ?string $template = null,
        #[Autowire('%estate.signature.inventory_template%')] private readonly ?string $inventoryTemplate = null,
        #[Autowire('%estate.signature.level%')] private readonly string $level = 'simple',
    ) {
    }

    /** Whether leases are signed electronically: a gateway named, and configured. */
    public function isEnabled(): bool
    {
        return null !== $this->gateway && '' !== $this->gateway && $this->signatures->isEnabled($this->gateway);
    }

    public function gateway(): ?string
    {
        return $this->gateway;
    }

    /** The latest envelope of the lease's $kind (lease, inventory), if one was sent. */
    public function latest(Lease $lease, string $kind = self::LEASE): ?Envelope
    {
        if (null === $lease->getId()) {
            return null;
        }
        foreach ($this->signatures->of($lease) as $envelope) {
            if (str_starts_with((string) ($envelope->getEnvelope()['key'] ?? ''), $kind.':')) {
                return $envelope;
            }
        }

        return null;
    }

    /**
     * Where the lease's $kind stands, for its screens: its state, its signers', the counts.
     *
     * @return array{status: string, label: string, signed: int, total: int, signers: list<array<string, mixed>>, sentAt: \DateTimeImmutable, completedAt: ?\DateTimeImmutable, envelope: Envelope}|null
     */
    public function state(Lease $lease, string $kind = self::LEASE): ?array
    {
        $envelope = $this->latest($lease, $kind);
        if (null === $envelope) {
            return null;
        }
        $signers = $envelope->getSigners();
        $signed = \count(array_filter($signers, static fn (array $s) => 'signed' === $s['status']));

        return [
            'status' => $envelope->getStatus(),
            'label' => self::label($envelope->getStatus(), $signed, \count($signers), $envelope->getCompletedAt()),
            'signed' => $signed,
            'total' => \count($signers),
            'signers' => $signers,
            'sentAt' => $envelope->getCreatedAt(),
            'completedAt' => $envelope->getCompletedAt(),
            'envelope' => $envelope,
        ];
    }

    /** The state in words, as the lease's screen shows it. */
    public static function label(string $status, int $signed, int $total, ?\DateTimeImmutable $completedAt = null): string
    {
        return match ($status) {
            Envelope::STATUS_COMPLETED => 'Signé'.($completedAt ? ' le '.$completedAt->format('d/m/Y') : ''),
            Envelope::STATUS_DECLINED => 'Signature refusée',
            Envelope::STATUS_EXPIRED => 'Expiré sans toutes les signatures',
            Envelope::STATUS_CANCELED => 'Envoi annulé',
            Envelope::STATUS_DRAFT => 'Préparé, pas encore envoyé',
            default => sprintf('Envoyé, %d signature(s) sur %d', $signed, $total),
        };
    }

    /** What can be sent: the lease always (generated when none is uploaded), the inventory once uploaded. */
    public function canSend(Lease $lease, string $kind = self::LEASE): bool
    {
        $running = $this->latest($lease, $kind);

        return $this->isEnabled() && (null === $running || $running->isOver()) && (self::LEASE === $kind || null !== $this->uploaded($lease, DocumentKind::INVENTORY) || $this->hasTemplate($kind));
    }

    /**
     * Sent to be signed: the document and its signature page to the provider, the signers
     * e-mailed by it in turn. One envelope at a time: a running one is refused.
     */
    public function send(Lease $lease, User $by, string $kind = self::LEASE): Envelope
    {
        if (!$this->isEnabled()) {
            throw new \LogicException('No electronic signature: estate.signature.gateway names no configured omnisign gateway.');
        }
        $running = $this->latest($lease, $kind);
        if (null !== $running && !$running->isOver()) {
            throw new \DomainException('Une demande de signature est déjà en cours pour ce document.');
        }
        $envelope = $this->signatures->send($lease, $this->request($lease, $by, $kind), $this->gateway);
        $this->activities->log($lease->getOrganisation(), ActivityKind::DOCUMENT, sprintf('%s %s envoyé à la signature (%s)', self::LEASE === $kind ? 'Bail' : 'État des lieux du bail', $lease->getReference(), implode(', ', array_map(static fn (array $s) => $s['name'], $envelope->getSigners()))), $lease->getUnit());
        $this->entityManager->flush();

        return $envelope;
    }

    public function refresh(Lease $lease, string $kind = self::LEASE): ?Envelope
    {
        $envelope = $this->latest($lease, $kind);

        return null === $envelope ? null : $this->signatures->refresh($envelope);
    }

    public function cancel(Lease $lease, string $kind = self::LEASE): ?Envelope
    {
        $envelope = $this->latest($lease, $kind);
        if (null === $envelope || $envelope->isOver()) {
            return $envelope;
        }

        return $this->signatures->cancel($envelope, 'Annulé par le bailleur.');
    }

    public function remind(Lease $lease, string $kind = self::LEASE): void
    {
        $envelope = $this->latest($lease, $kind);
        if (null !== $envelope && !$envelope->isOver()) {
            $this->signatures->remind($envelope);
        }
    }

    /** The signed document, or its evidence ("evidence"), once completed. */
    public function file(Lease $lease, string $kind = self::LEASE, string $file = 'document'): ?string
    {
        $envelope = $this->latest($lease, $kind);
        if (null === $envelope || !$envelope->isCompleted()) {
            return null;
        }

        return 'evidence' === $file ? $this->signatures->evidence($envelope) : $this->signatures->signed($envelope);
    }

    /** Completed (the provider's webhook, or a refresh): told in the feed and to the team, filed as the lease's document. */
    public function onCompleted(SignatureEvent $event): void
    {
        $lease = $event->getSubject();
        $kind = self::kindOf($event->getEnvelope());
        if (!$lease instanceof Lease || null === $kind) {
            return;
        }
        $what = self::LEASE === $kind ? 'Bail' : 'État des lieux du bail';
        $this->activities->log($lease->getOrganisation(), ActivityKind::DOCUMENT, sprintf('%s %s signé par toutes les parties', $what, $lease->getReference()), $lease->getUnit());
        $this->fileSigned($lease, $event->getEnvelope(), $kind);
        $this->entityManager->flush();
        $this->mailer->send((string) $lease->getOrganisation()->getEmail(), sprintf('%s %s signé', $what, $lease->getReference()), 'lease_signature', ['lease' => $lease, 'organisation' => $lease->getOrganisation(), 'kind' => $kind, 'status' => Envelope::STATUS_COMPLETED]);
    }

    /** Refused or expired: the team is told, and may send it again. */
    public function onStopped(SignatureEvent $event): void
    {
        $lease = $event->getSubject();
        $kind = self::kindOf($event->getEnvelope());
        if (!$lease instanceof Lease || null === $kind) {
            return;
        }
        $status = $event->getEnvelope()->getStatus();
        $what = self::LEASE === $kind ? 'Bail' : 'État des lieux du bail';
        $this->activities->log($lease->getOrganisation(), ActivityKind::DOCUMENT, sprintf('%s %s : %s', $what, $lease->getReference(), mb_strtolower(self::label($status, 0, 0))), $lease->getUnit());
        $this->entityManager->flush();
        $this->mailer->send((string) $lease->getOrganisation()->getEmail(), sprintf('%s %s : %s', $what, $lease->getReference(), mb_strtolower(self::label($status, 0, 0))), 'lease_signature', ['lease' => $lease, 'organisation' => $lease->getOrganisation(), 'kind' => $kind, 'status' => $status]);
    }

    /**
     * The signers, in order: the landlord (who sends it, for the company), the occupants, then
     * - for the lease - the guarantors.
     *
     * @return list<Signer>
     */
    public function signers(Lease $lease, User $by, string $kind = self::LEASE): array
    {
        // The sender's name as the company's team knows them (a Member), else their e-mail.
        $member = $this->entityManager->getRepository(Member::class)->findOneBy(['organisation' => $lease->getOrganisation(), 'user' => $by]);
        $landlordName = $member?->getName() ?: (string) $by->getEmail();
        $signers = [new Signer('landlord', sprintf('%s pour %s', $landlordName, $lease->getOrganisation()->getName()), (string) $by->getEmail(), order: 1, role: 'Bailleur')];
        $tenants = 0;
        $guarantors = 0;
        foreach ($lease->getParties() as $party) {
            if (PartyRole::GUARANTOR === $party->getRole()) {
                if (self::LEASE === $kind) {
                    $signers[] = new Signer('guarantor-'.$this->partyKey($party), $party->getName(), $party->getEmail(), $party->getPhone(), order: 3, role: 'Garant '.++$guarantors);
                }
                continue;
            }
            $signers[] = new Signer('tenant-'.$this->partyKey($party), $party->getName(), $party->getEmail(), $party->getPhone(), order: 2, role: 'Locataire '.++$tenants);
        }
        if (0 === $tenants) {
            throw new \DomainException('Le bail n\'a pas de locataire à faire signer.');
        }
        // In signing order, whatever order the parties were entered in (usort keeps the ties' order).
        usort($signers, static fn (Signer $a, Signer $b) => $a->order <=> $b->order);

        return $signers;
    }

    /** The lease's own PDF: the one uploaded, else its particular conditions generated. */
    public function leasePdf(Lease $lease): File
    {
        return $this->uploaded($lease, DocumentKind::LEASE)
            ?? new File($this->receipts->htmlToPdf($this->twig->render('@Estate/pdf/lease.html.twig', ['lease' => $lease, 'organisation' => $lease->getOrganisation()])) ?? throw new \LogicException('dompdf/dompdf is needed to generate the lease.'), sprintf('bail-%s.pdf', self::slug($lease->getReference())));
    }

    private function request(Lease $lease, User $by, string $kind): SignatureRequest
    {
        $what = self::LEASE === $kind ? 'Bail' : 'État des lieux';
        $title = sprintf('%s %s — %s', $what, $lease->getReference(), $lease->getUnit()->getName());
        $signers = $this->signers($lease, $by, $kind);
        $key = $kind.':'.$lease->getReference();
        $common = ['level' => Level::tryFrom($this->level) ?? Level::SIMPLE, 'ordered' => true, 'expiresAt' => new \DateTimeImmutable('+'.self::DAYS.' days'), 'message' => sprintf('%s vous invite à signer %s.', $lease->getOrganisation()->getName(), mb_strtolower($what).' '.$lease->getReference()), 'key' => $key];
        if ($this->hasTemplate($kind)) {
            // A template of the provider's (DocuSeal's open-source edition), whose roles are the signers' (Bailleur, Locataire 1, Garant 1...).
            return new SignatureRequest($title, ...['signers' => $signers, 'template' => self::LEASE === $kind ? $this->template : $this->inventoryTemplate] + $common);
        }
        $body = self::LEASE === $kind ? $this->leasePdf($lease) : ($this->uploaded($lease, DocumentKind::INVENTORY) ?? throw new \DomainException('Déposez d\'abord l\'état des lieux du bail (document « État des lieux »).'));
        [$page, $fields] = $this->signaturePage($lease, $kind, $body, $signers);
        // Keys as roles: each field names its signer by key, as the providers match them.
        $signers = array_map(static fn (Signer $s) => new Signer($s->key, $s->name, $s->email, $s->phone, $s->locale, $s->order, $s->authentication), $signers);

        return new SignatureRequest($title, ...['documents' => [new SignedDocument('document', $body), new SignedDocument('signatures', $page)], 'signers' => $signers, 'fields' => $fields] + $common);
    }

    /**
     * The signature page: what it seals (the document's name and SHA-256) and one box per signer,
     * where their signature field is placed.
     *
     * @param list<Signer> $signers with their roles (Bailleur, Locataire 1...)
     *
     * @return array{File, list<Field>}
     */
    private function signaturePage(Lease $lease, string $kind, File $body, array $signers): array
    {
        $boxes = [];
        $fields = [];
        foreach ($signers as $i => $signer) {
            $box = ['x' => self::BOX['left'] + ($i % 2) * (self::BOX['width'] + self::BOX['gap']), 'y' => self::BOX['top'] + intdiv($i, 2) * self::BOX['row'], 'width' => self::BOX['width'], 'height' => self::BOX['height']];
            $boxes[] = $box + ['role' => $signer->role ?? '', 'name' => $signer->name];
            $fields[] = new Field($signer->key, 'signatures', page: 1, x: $box['x'], y: $box['y'], width: $box['width'], height: $box['height']);
        }
        $what = self::LEASE === $kind ? 'du bail' : 'de l\'état des lieux';
        $html = $this->twig->render('@Estate/pdf/signatures.html.twig', [
            'title' => 'Signatures '.$what.' '.$lease->getReference(),
            'heading' => 'Signatures '.$what.' '.$lease->getReference(),
            'intro' => sprintf('%s, %s. Chaque partie signe ci-dessous après avoir lu le document joint, dans l\'ordre : le bailleur, %s.', $lease->getUnit()->getName(), $lease->getUnit()->getCity() ?? '', self::LEASE === $kind ? 'les locataires, les garants' : 'les locataires'),
            'filename' => $body->filename,
            'sha256' => $body->sha256(),
            'boxes' => $boxes,
        ]);
        $pdf = $this->receipts->htmlToPdf($html) ?? throw new \LogicException('dompdf/dompdf is needed for the signature page.');

        return [new File($pdf, sprintf('signatures-%s-%s.pdf', $kind, self::slug($lease->getReference()))), $fields];
    }

    /** The latest file of $kind the team uploaded for the lease, unsigned (not one this class filed). */
    private function uploaded(Lease $lease, DocumentKind $kind): ?File
    {
        if (null === $lease->getId()) {
            return null;
        }
        $documents = $this->entityManager->getRepository(Document::class)->findBy(['lease' => $lease, 'kind' => $kind], ['id' => 'DESC']);
        foreach ($documents as $document) {
            if (str_ends_with($document->getTitle(), '(signé)')) {
                continue;
            }
            $file = Uploader::get($document, 'file');
            if ($file instanceof \SplFileInfo && $file->isFile() && 'pdf' === strtolower($file->getExtension() ?: pathinfo($file->getFilename(), \PATHINFO_EXTENSION))) {
                return File::fromPath($file->getPathname(), 'application/pdf');
            }
        }

        return null;
    }

    /** The signed PDF filed as the lease's document (Bail / État des lieux « (signé) »), seen by its tenants. */
    private function fileSigned(Lease $lease, Envelope $envelope, string $kind): void
    {
        try {
            $content = $this->signatures->signed($envelope);
            if (null === $content) {
                return;
            }
            $path = tempnam(sys_get_temp_dir(), 'estate-signed-');
            rename($path, $path .= '.pdf');
            file_put_contents($path, $content);
            $document = (new Document($lease->getOrganisation(), self::LEASE === $kind ? DocumentKind::LEASE : DocumentKind::INVENTORY, sprintf('%s %s (signé)', self::LEASE === $kind ? 'Bail' : 'État des lieux', $lease->getReference())))
                ->setLease($lease)->setFile(new UploadFile($path))->setTenantVisible(true);
            $this->entityManager->persist($document);
        } catch (\Throwable $e) {
            // The envelope keeps the signed PDF: filing a copy never stops the signature being recorded.
            $this->logger?->warning('The signed {kind} of lease {lease} was not filed as a document: {message}', ['kind' => $kind, 'lease' => $lease->getReference(), 'message' => $e->getMessage()]);
        }
    }

    private function hasTemplate(string $kind): bool
    {
        $template = self::LEASE === $kind ? $this->template : $this->inventoryTemplate;

        return null !== $template && '' !== $template;
    }

    private static function kindOf(Envelope $envelope): ?string
    {
        $key = (string) ($envelope->getEnvelope()['key'] ?? '');

        return str_starts_with($key, self::LEASE.':') ? self::LEASE : (str_starts_with($key, self::INVENTORY.':') ? self::INVENTORY : null);
    }

    private function partyKey(LeaseParty $party): string
    {
        return null !== $party->getId() ? (string) $party->getId() : substr(sha1($party->getEmail()), 0, 8);
    }

    private static function slug(string $text): string
    {
        return trim((string) preg_replace('~[^A-Za-z0-9]+~', '-', $text), '-') ?: 'bail';
    }
}
