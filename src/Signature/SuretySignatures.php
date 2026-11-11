<?php

namespace Base\Estate\Signature;

use Base\Entity\Signature\Envelope;
use Base\Entity\User;
use Base\Estate\Entity\Document;
use Base\Estate\Entity\Lease;
use Base\Estate\Entity\LeaseParty;
use Base\Estate\Enum\ActivityKind;
use Base\Estate\Enum\DocumentKind;
use Base\Estate\Enum\RevisionRule;
use Base\Estate\Service\ActivityLog;
use Base\Estate\Service\EstateMailer;
use Base\Estate\Service\Receipts;
use Base\Event\SignatureEvent;
use Base\Service\Signatures;
use Doctrine\ORM\EntityManagerInterface;
use Omnisign\Model\Document as SignedDocument;
use Omnisign\Model\Envelope as SignatureRequest;
use Omnisign\Model\Field;
use Omnisign\Model\FieldType;
use Omnisign\Model\File;
use Omnisign\Model\Level;
use Omnisign\Model\Signer;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\File\File as UploadFile;
use Twig\Environment;

/**
 * A guarantor's deed (acte de cautionnement), one per guarantor of a lease, generated from the
 * record (@Estate/pdf/surety.html.twig) and signed by the guarantor through omnisense/omnisign -
 * its own envelope about the guarantor (the LeaseParty), its state, the signed PDF and its evidence
 * kept by the core's Base\Service\Signatures, the signed deed filed as the lease's document
 * « Acte de cautionnement », the pattern of LeaseSignatures.
 *
 * What the deed carries comes from the texts as read on Légifrance on 2026-11-11 (docs/legal.md):
 *
 * - loi n° 89-462 du 6 juillet 1989, art. 22-1, last paragraph (version in force since 1 January
 *   2022, as amended by ordonnance n° 2021-1192 du 15 septembre 2021, art. 35), for a home's lease:
 *   the guarantor signs the deed « faisant apparaître le montant du loyer et les conditions de sa
 *   révision tels qu'ils figurent au contrat de location » (@Estate/pdf/_revision_terms.html.twig,
 *   the same words as the generated lease), « ainsi que la reproduction de l'avant-dernier alinéa
 *   du présent article » (PARAGRAPH_4, word for word), and « doit apposer la mention prévue par
 *   l'article 2297 du code civil »; the landlord gives the guarantor a copy of the lease (attached
 *   to the envelope, not to sign). « Ces formalités sont prescrites à peine de nullité du
 *   cautionnement. »
 * - code civil, art. 2297 (version in force since 1 January 2022, ordonnance n° 2021-1192, art. 3),
 *   for any lease: the guarantor, a natural person, « appose elle-même la mention qu'elle s'engage
 *   en qualité de caution à payer au créancier ce que lui doit le débiteur en cas de défaillance de
 *   celui-ci, dans la limite d'un montant en principal et accessoires exprimé en toutes lettres et
 *   en chiffres »; a guarantor without the benefits of discussion and division acknowledges it in
 *   that mention (statement()).
 * - code civil, art. 1174, al. 2 (version in force since 1 October 2016): a mention to be written
 *   by the person's own hand may be put electronically « si les conditions de cette apposition sont
 *   de nature à garantir qu'elle ne peut être effectuée que par lui-même »: the guarantor types the
 *   mention in the provider's text fields, during their own signing session - not printed for them.
 *
 * The guarantor alone signs: art. 22-1 asks the guarantor's signature, the text read asks none of
 * the landlord. Art. 22-1, al. 1 to 3 (no guarantee where the landlord insured the rent, save a
 * student or an apprentice; a landlord company other than a family SCI only from a listed body or
 * for a student without a grant; no refusal for nationality or residence) are said on the screen,
 * never decided: estate cannot know them. For a commercial or professional lease art. 22-1 does
 * not apply (« contrat de location conclu en application du présent titre »): the deed carries
 * art. 2297's mention only.
 *
 * **A starting point, to be validated by a lawyer**: the back office says so until the site's owner
 * confirms it (Organisation::isSuretyTemplateConfirmed()).
 *
 *     estate:
 *         signature:
 *             surety_template: ~      # a provider's template instead (DocuSeal's open-source edition), role « Garant »
 */
#[AsEventListener(event: SignatureEvent::COMPLETED, method: 'onCompleted')]
#[AsEventListener(event: SignatureEvent::DECLINED, method: 'onStopped')]
#[AsEventListener(event: SignatureEvent::EXPIRED, method: 'onStopped')]
class SuretySignatures
{
    public const KIND = 'surety';

    /** The version of the texts the template was written from (docs/legal.md). */
    public const TEXTS = 'loi n° 89-462 du 6 juillet 1989, art. 22-1 (version du 1er janvier 2022) ; code civil, art. 2297 (version du 1er janvier 2022) et 1174 (version du 1er octobre 2016) ; lus sur Légifrance le 11 novembre 2026';

    /** Loi du 6 juillet 1989, art. 22-1, its last paragraph but one, as Légifrance gives it in its version of 1 January 2022: reproduced word for word in a home's deed. */
    public const PARAGRAPH_4 = 'Lorsque le cautionnement d\'obligations résultant d\'un contrat de location conclu en application du présent titre ne comporte aucune indication de durée ou lorsque la durée du cautionnement est stipulée indéterminée, la caution peut le résilier unilatéralement. La résiliation prend effet au terme du contrat de location, qu\'il s\'agisse du contrat initial ou d\'un contrat reconduit ou renouvelé, au cours duquel le bailleur reçoit notification de la résiliation.';

    /** The signature page: the mention's fields, then the signature, in points (A4: 595 × 842). */
    private const MENTION = ['x' => 51, 'y' => 330, 'width' => 493, 'height' => 110, 'gap' => 18];
    private const SIGNATURE = ['x' => 51, 'width' => 238, 'height' => 72];

    private const DAYS = 30;

    public function __construct(
        private readonly Signatures $signatures,
        private readonly LeaseSignatures $leases,
        private readonly Receipts $receipts,
        private readonly EntityManagerInterface $entityManager,
        private readonly ActivityLog $activities,
        private readonly EstateMailer $mailer,
        private readonly Environment $twig,
        private readonly ?LoggerInterface $logger = null,
        #[Autowire('%estate.signature.surety_template%')] private readonly ?string $template = null,
        #[Autowire('%estate.signature.level%')] private readonly string $level = 'simple',
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->leases->isEnabled();
    }

    public function gateway(): ?string
    {
        return $this->leases->gateway();
    }

    /** The guarantor's latest envelope, if one was sent. */
    public function latest(LeaseParty $guarantor): ?Envelope
    {
        if (null === $guarantor->getId()) {
            return null;
        }
        foreach ($this->signatures->of($guarantor) as $envelope) {
            if (str_starts_with((string) ($envelope->getEnvelope()['key'] ?? ''), self::KIND.':')) {
                return $envelope;
            }
        }

        return null;
    }

    /**
     * Where the guarantor's deed stands, for the screens.
     *
     * @return array{status: string, label: string, signed: int, total: int, signers: list<array<string, mixed>>, sentAt: \DateTimeImmutable, completedAt: ?\DateTimeImmutable, envelope: Envelope}|null
     */
    public function state(LeaseParty $guarantor): ?array
    {
        $envelope = $this->latest($guarantor);
        if (null === $envelope) {
            return null;
        }
        $signers = $envelope->getSigners();
        $signed = \count(array_filter($signers, static fn (array $s) => 'signed' === $s['status']));

        return [
            'status' => $envelope->getStatus(),
            'label' => LeaseSignatures::label($envelope->getStatus(), $signed, \count($signers), $envelope->getCompletedAt()),
            'signed' => $signed,
            'total' => \count($signers),
            'signers' => $signers,
            'sentAt' => $envelope->getCreatedAt(),
            'completedAt' => $envelope->getCompletedAt(),
            'envelope' => $envelope,
        ];
    }

    /** The guarantors of $lease, with their deed's state and what stops it being sent. */
    public function deeds(Lease $lease): array
    {
        $deeds = [];
        foreach ($lease->getParties() as $party) {
            if ($party->isGuarantor()) {
                $deeds[] = ['guarantor' => $party, 'state' => $this->state($party), 'missing' => $this->missing($party), 'canSend' => $this->canSend($party)];
            }
        }

        return $deeds;
    }

    /**
     * What the deed still needs before it can be sent: the limit of the commitment (art. 2297), the
     * guarantor's e-mail, and for a revisable rent its reference index (its revision terms, art. 22-1).
     *
     * @return list<string>
     */
    public function missing(LeaseParty $guarantor): array
    {
        $lease = $guarantor->getLease();
        $missing = [];
        if (null === $guarantor->getSuretyLimit()) {
            $missing[] = 'le montant maximal de l\'engagement (principal et accessoires)';
        }
        if ('' === $guarantor->getEmail()) {
            $missing[] = 'l\'e-mail de la caution';
        }
        if (null !== $lease && RevisionRule::NONE !== $lease->getRevisionRule() && (null === $lease->getIndexQuarter() || null === $lease->getIndexValue())) {
            $missing[] = 'l\'indice de référence du bail (les conditions de révision du loyer)';
        }

        return $missing;
    }

    public function canSend(LeaseParty $guarantor): bool
    {
        $running = $this->latest($guarantor);

        return $this->isEnabled() && $guarantor->isGuarantor() && [] === $this->missing($guarantor) && (null === $running || $running->isOver());
    }

    /** Sent to the guarantor to sign: the deed, a copy of the lease, the signature page with the mention to type. */
    public function send(LeaseParty $guarantor, User $by): Envelope
    {
        if (!$this->isEnabled()) {
            throw new \LogicException('No electronic signature: estate.signature.gateway names no configured omnisign gateway.');
        }
        if (!$guarantor->isGuarantor() || null === $lease = $guarantor->getLease()) {
            throw new \DomainException('Seule une caution du bail signe un acte de cautionnement.');
        }
        if ([] !== $missing = $this->missing($guarantor)) {
            throw new \DomainException('Il manque à l\'acte de cautionnement : '.implode(', ', $missing).'.');
        }
        $running = $this->latest($guarantor);
        if (null !== $running && !$running->isOver()) {
            throw new \DomainException('Une demande de signature est déjà en cours pour cet acte.');
        }
        $envelope = $this->signatures->send($guarantor, $this->request($guarantor), $this->gateway());
        $this->activities->log($lease->getOrganisation(), ActivityKind::DOCUMENT, sprintf('Acte de cautionnement de %s (bail %s) envoyé à la signature', $guarantor->getName(), $lease->getReference()), $lease->getUnit());
        $this->entityManager->flush();

        return $envelope;
    }

    public function refresh(LeaseParty $guarantor): ?Envelope
    {
        $envelope = $this->latest($guarantor);

        return null === $envelope ? null : $this->signatures->refresh($envelope);
    }

    public function cancel(LeaseParty $guarantor): ?Envelope
    {
        $envelope = $this->latest($guarantor);

        return null === $envelope || $envelope->isOver() ? $envelope : $this->signatures->cancel($envelope, 'Annulé par le bailleur.');
    }

    public function remind(LeaseParty $guarantor): void
    {
        $envelope = $this->latest($guarantor);
        if (null !== $envelope && !$envelope->isOver()) {
            $this->signatures->remind($envelope);
        }
    }

    /** The signed deed, or its evidence ("evidence"), once completed. */
    public function file(LeaseParty $guarantor, string $file = 'document'): ?string
    {
        $envelope = $this->latest($guarantor);
        if (null === $envelope || !$envelope->isCompleted()) {
            return null;
        }

        return 'evidence' === $file ? $this->signatures->evidence($envelope) : $this->signatures->signed($envelope);
    }

    public function onCompleted(SignatureEvent $event): void
    {
        $guarantor = $event->getSubject();
        if (!$guarantor instanceof LeaseParty || !self::isSurety($event->getEnvelope()) || null === $lease = $guarantor->getLease()) {
            return;
        }
        $this->activities->log($lease->getOrganisation(), ActivityKind::DOCUMENT, sprintf('Acte de cautionnement de %s (bail %s) signé', $guarantor->getName(), $lease->getReference()), $lease->getUnit());
        $this->fileSigned($guarantor, $event->getEnvelope());
        $this->entityManager->flush();
        $this->mailer->send((string) $lease->getOrganisation()->getEmail(), sprintf('Acte de cautionnement de %s signé — bail %s', $guarantor->getName(), $lease->getReference()), 'surety_signature', ['lease' => $lease, 'guarantor' => $guarantor, 'organisation' => $lease->getOrganisation(), 'status' => Envelope::STATUS_COMPLETED]);
    }

    public function onStopped(SignatureEvent $event): void
    {
        $guarantor = $event->getSubject();
        if (!$guarantor instanceof LeaseParty || !self::isSurety($event->getEnvelope()) || null === $lease = $guarantor->getLease()) {
            return;
        }
        $status = $event->getEnvelope()->getStatus();
        $label = mb_strtolower(LeaseSignatures::label($status, 0, 0));
        $this->activities->log($lease->getOrganisation(), ActivityKind::DOCUMENT, sprintf('Acte de cautionnement de %s (bail %s) : %s', $guarantor->getName(), $lease->getReference(), $label), $lease->getUnit());
        $this->entityManager->flush();
        $this->mailer->send((string) $lease->getOrganisation()->getEmail(), sprintf('Acte de cautionnement de %s : %s — bail %s', $guarantor->getName(), $label, $lease->getReference()), 'surety_signature', ['lease' => $lease, 'guarantor' => $guarantor, 'organisation' => $lease->getOrganisation(), 'status' => $status]);
    }

    /**
     * The mention the guarantor types (code civil, art. 2297): the engagement and its limit in words
     * and figures, then - a joint guarantee - the benefits of discussion and division given up.
     *
     * @return list<string> one sentence a field
     */
    public function statement(LeaseParty $guarantor): array
    {
        $lease = $guarantor->getLease() ?? throw new \LogicException('A guarantor without a lease.');
        $organisation = $lease->getOrganisation();
        $tenants = $lease->getTenants();
        $one = 1 === \count($tenants);
        // Art. 2297's words, the creditor and the debtor named: « ce que lui doit le débiteur en cas de défaillance de celui-ci ».
        $sentences = [sprintf(
            'Je m\'engage en qualité de caution à payer à %s ce que lui %s, %s, en cas de défaillance de %s, dans la limite de la somme de %s (%s) en principal et accessoires.',
            $organisation->getLegalName() ?? $organisation->getName(),
            $one ? 'doit le débiteur' : 'doivent les débiteurs',
            $lease->getTenantNames(),
            $one ? 'celui-ci' : 'ceux-ci',
            self::inWords((int) $guarantor->getSuretyLimit()),
            self::figures((int) $guarantor->getSuretyLimit()),
        )];
        if ($guarantor->isSuretyJoint()) {
            $sentences[] = 'Je reconnais ne pouvoir exiger du créancier qu\'il poursuive d\'abord le débiteur ou qu\'il divise ses poursuites entre les cautions.';
        }

        return $sentences;
    }

    /** The deed's PDF, generated from the record. */
    public function deedPdf(LeaseParty $guarantor): File
    {
        $html = $this->deedHtml($guarantor);
        $pdf = $this->receipts->htmlToPdf($html) ?? throw new \LogicException('dompdf/dompdf is needed to generate the guarantor\'s deed.');

        return new File($pdf, sprintf('acte-de-cautionnement-%s-%s.pdf', self::slug((string) $guarantor->getLease()?->getReference()), self::slug($guarantor->getName())));
    }

    public function deedHtml(LeaseParty $guarantor): string
    {
        $lease = $guarantor->getLease() ?? throw new \LogicException('A guarantor without a lease.');

        return $this->twig->render('@Estate/pdf/surety.html.twig', ['lease' => $lease, 'guarantor' => $guarantor, 'organisation' => $lease->getOrganisation(), 'statement' => $this->statement($guarantor), 'paragraph4' => self::PARAGRAPH_4, 'texts' => self::TEXTS, 'limit_words' => self::inWords((int) $guarantor->getSuretyLimit()), 'limit_figures' => self::figures((int) $guarantor->getSuretyLimit())]);
    }

    /** « vingt-quatre mille euros », « mille deux cent trente-quatre euros et cinquante centimes » */
    public static function inWords(int $cents): string
    {
        $spell = new \NumberFormatter('fr', \NumberFormatter::SPELLOUT);
        $euros = intdiv($cents, 100);
        $rest = $cents % 100;
        $words = $spell->format($euros).' euro'.(1 < $euros ? 's' : '');

        return $rest > 0 ? $words.' et '.$spell->format($rest).' centime'.(1 < $rest ? 's' : '') : $words;
    }

    public static function figures(int $cents): string
    {
        return number_format($cents / 100, 2, ',', "\u{00A0}")."\u{00A0}€";
    }

    private function request(LeaseParty $guarantor): SignatureRequest
    {
        $lease = $guarantor->getLease();
        $title = sprintf('Acte de cautionnement — %s — bail %s', $guarantor->getName(), $lease->getReference());
        $signer = new Signer('guarantor-'.$guarantor->getId(), $guarantor->getName(), $guarantor->getEmail(), $guarantor->getPhone(), order: 1, role: 'Garant');
        $common = ['level' => Level::tryFrom($this->level) ?? Level::SIMPLE, 'ordered' => true, 'expiresAt' => new \DateTimeImmutable('+'.self::DAYS.' days'), 'message' => sprintf('%s vous invite à signer votre acte de cautionnement du bail %s.', $lease->getOrganisation()->getName(), $lease->getReference()), 'key' => sprintf('%s:%s:%d', self::KIND, $lease->getReference(), $guarantor->getId())];
        if (null !== $this->template && '' !== $this->template) {
            return new SignatureRequest($title, ...['signers' => [$signer], 'template' => $this->template] + $common);
        }
        $deed = $this->deedPdf($guarantor);
        [$page, $fields] = $this->signaturePage($guarantor, $deed, $signer);
        // The lease itself, for the guarantor to keep (art. 22-1: « Le bailleur remet à la caution un exemplaire du contrat de location »): read, not signed.
        $documents = [new SignedDocument('deed', $deed), new SignedDocument('lease', $this->leases->leasePdf($lease), false), new SignedDocument('signatures', $page)];
        $signer = new Signer($signer->key, $signer->name, $signer->email, $signer->phone, $signer->locale, $signer->order, $signer->authentication);

        return new SignatureRequest($title, ...['documents' => $documents, 'signers' => [$signer], 'fields' => $fields] + $common);
    }

    /**
     * The page the guarantor signs: what it seals (the deed's SHA-256), the mention to type in each
     * text field - one sentence a field, each within the providers' limits -, the signature box.
     *
     * @return array{File, list<Field>}
     */
    private function signaturePage(LeaseParty $guarantor, File $deed, Signer $signer): array
    {
        $boxes = [];
        $fields = [];
        $y = self::MENTION['y'];
        foreach ($this->statement($guarantor) as $i => $sentence) {
            $height = 0 === $i ? self::MENTION['height'] : (int) (self::MENTION['height'] * 0.6);
            $boxes[] = ['x' => self::MENTION['x'], 'y' => $y, 'width' => self::MENTION['width'], 'height' => $height, 'text' => $sentence];
            $fields[] = new Field($signer->key, 'signatures', FieldType::TEXT, 1, self::MENTION['x'], $y, self::MENTION['width'], $height, label: 0 === $i ? 'Mention de la caution' : 'Mention de la caution (solidarité)');
            $y += $height + self::MENTION['gap'] + 40;
        }
        $signature = ['x' => self::SIGNATURE['x'], 'y' => $y, 'width' => self::SIGNATURE['width'], 'height' => self::SIGNATURE['height']];
        $fields[] = new Field($signer->key, 'signatures', page: 1, x: $signature['x'], y: $signature['y'], width: $signature['width'], height: $signature['height']);
        $html = $this->twig->render('@Estate/pdf/surety_signatures.html.twig', [
            'title' => 'Signature de l\'acte de cautionnement',
            'guarantor' => $guarantor,
            'lease' => $guarantor->getLease(),
            'filename' => $deed->filename,
            'sha256' => $deed->sha256(),
            'mentions' => $boxes,
            'signature' => $signature,
        ]);
        $pdf = $this->receipts->htmlToPdf($html) ?? throw new \LogicException('dompdf/dompdf is needed for the signature page.');

        return [new File($pdf, sprintf('signature-cautionnement-%s.pdf', self::slug($guarantor->getName()))), $fields];
    }

    /** The signed deed filed as the lease's document « Acte de cautionnement … (signé) », not shown to the tenants. */
    private function fileSigned(LeaseParty $guarantor, Envelope $envelope): void
    {
        try {
            $content = $this->signatures->signed($envelope);
            if (null === $content) {
                return;
            }
            $path = tempnam(sys_get_temp_dir(), 'estate-surety-');
            rename($path, $path .= '.pdf');
            file_put_contents($path, $content);
            $lease = $guarantor->getLease();
            $document = (new Document($lease->getOrganisation(), DocumentKind::GUARANTEE, sprintf('Acte de cautionnement de %s, bail %s (signé)', $guarantor->getName(), $lease->getReference())))
                ->setLease($lease)->setFile(new UploadFile($path))->setTenantVisible(false);
            $this->entityManager->persist($document);
        } catch (\Throwable $e) {
            $this->logger?->warning('The signed guarantee deed of {guarantor} was not filed as a document: {message}', ['guarantor' => $guarantor->getName(), 'message' => $e->getMessage()]);
        }
    }

    private static function isSurety(Envelope $envelope): bool
    {
        return str_starts_with((string) ($envelope->getEnvelope()['key'] ?? ''), self::KIND.':');
    }

    private static function slug(string $text): string
    {
        return trim((string) preg_replace('~[^A-Za-z0-9]+~', '-', iconv('UTF-8', 'ASCII//TRANSLIT', $text) ?: $text), '-') ?: 'caution';
    }
}
