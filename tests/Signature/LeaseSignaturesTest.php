<?php

namespace Tests\Base\Estate\Signature;

use Base\Entity\Signature\Envelope;
use Base\Entity\User;
use Base\Estate\Entity\Lease;
use Base\Estate\Entity\LeaseParty;
use Base\Estate\Entity\Organisation;
use Base\Estate\Entity\Unit;
use Base\Estate\Enum\PartyRole;
use Base\Estate\Repository\RentCallRepository;
use Base\Estate\Service\ActivityLog;
use Base\Estate\Service\EstateMailer;
use Base\Estate\Service\Receipts;
use Base\Estate\Signature\LeaseSignatures;
use Base\Service\Signatures;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Omnisign\Model\Envelope as SignatureRequest;
use Omnisign\Model\Level;
use PHPUnit\Framework\TestCase;
use Twig\Environment;

/** What a lease sent to be signed asks the provider: its documents, its signers in order, their fields. */
final class LeaseSignaturesTest extends TestCase
{
    private ?SignatureRequest $sent = null;

    protected function setUp(): void
    {
        if (!class_exists(\Omnisign\Registry::class) || !class_exists(\Dompdf\Dompdf::class)) {
            self::markTestSkipped('omnisense/omnisign and dompdf/dompdf are needed.');
        }
    }

    private function signatures(?string $template = null): LeaseSignatures
    {
        $core = $this->createStub(Signatures::class);
        $core->method('isEnabled')->willReturn(true);
        $core->method('of')->willReturn([]);
        $core->method('send')->willReturnCallback(function (object $subject, SignatureRequest $request, ?string $gateway) {
            $this->sent = $request;
            self::assertSame('docuseal', $gateway);

            return new Envelope(Lease::class, '1', $request->title, 'docuseal');
        });
        $repository = $this->createStub(EntityRepository::class);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);
        $twig = $this->createStub(Environment::class);
        $twig->method('render')->willReturnCallback(static fn (string $name, array $context) => '<html><body><h1>'.$name.'</h1>'.htmlspecialchars((string) ($context['sha256'] ?? '')).'</body></html>');

        return new LeaseSignatures($core, new Receipts($this->createStub(RentCallRepository::class), $twig), $entityManager, $this->createStub(ActivityLog::class), $this->createStub(EstateMailer::class), $twig, null, 'docuseal', $template);
    }

    private static function lease(): Lease
    {
        $unit = (new Unit(new Organisation('SCI Test', 'sci-test'), 'A', 'a'))->setPostcode('67000');
        $lease = (new Lease($unit, 'B-1', new \DateTimeImmutable('2026-12-01')))->setRent(85000);
        $lease->addParty(new LeaseParty('Paul', 'Garant', 'paul@example.org', PartyRole::GUARANTOR));
        $lease->addParty(new LeaseParty('Léa', 'Muller', 'lea@example.org'));
        $lease->addParty(new LeaseParty('Hugo', 'Muller', 'hugo@example.org', PartyRole::COTENANT));

        return $lease;
    }

    private static function user(): User
    {
        $user = (new \ReflectionClass(User::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(User::class, 'email'))->setValue($user, 'gestion@example.org');

        return $user;
    }

    public function testTheLeaseGoesWithItsSignaturePageToTheLandlordTenantsAndGuarantorsInOrder(): void
    {
        $this->signatures()->send(self::lease(), self::user());

        $request = $this->sent;
        self::assertSame('Bail B-1 — A', $request->title);
        self::assertSame('lease:B-1', $request->key);
        self::assertTrue($request->ordered);
        self::assertFalse($request->embedded, 'each signer is e-mailed by the provider');
        self::assertSame(Level::SIMPLE, $request->level);
        self::assertNotNull($request->expiresAt);
        self::assertSame(['document', 'signatures'], array_map(static fn ($d) => $d->key, $request->documents));
        self::assertStringStartsWith('%PDF', $request->documents[0]->file->content);
        self::assertSame('bail-B-1.pdf', $request->documents[0]->file->filename);
        self::assertStringStartsWith('%PDF', $request->documents[1]->file->content);

        self::assertSame(['gestion@example.org', 'lea@example.org', 'hugo@example.org', 'paul@example.org'], array_map(static fn ($s) => $s->email, $request->signers));
        self::assertSame([1, 2, 2, 3], array_map(static fn ($s) => $s->order, $request->signers));
        self::assertSame('landlord', $request->signers[0]->key);
        self::assertSame('gestion@example.org pour SCI Test', $request->signers[0]->name);
        self::assertNull($request->signers[1]->role, 'on a PDF the key is the role its fields name');

        // One signature box each, on the signature page, two to a row.
        self::assertSame(array_map(static fn ($s) => $s->key, $request->signers), array_map(static fn ($f) => $f->signer, $request->fields));
        self::assertSame(['signatures'], array_values(array_unique(array_map(static fn ($f) => $f->document, $request->fields))));
        self::assertSame([[51, 250], [306, 250], [51, 368], [306, 368]], array_map(static fn ($f) => [$f->x, $f->y], $request->fields));
    }

    public function testOnATemplateTheSignersTakeItsRoles(): void
    {
        $this->signatures('7')->send(self::lease(), self::user());

        self::assertSame('7', $this->sent->template);
        self::assertSame([], $this->sent->documents);
        self::assertSame(['Bailleur', 'Locataire 1', 'Locataire 2', 'Garant 1'], array_map(static fn ($s) => $s->role, $this->sent->signers));
    }

    public function testTheInventoryNeedsItsUploadedFileAndNoGuarantor(): void
    {
        $signatures = $this->signatures();
        self::assertFalse($signatures->canSend(self::lease(), LeaseSignatures::INVENTORY));
        self::assertSame(['landlord', 'tenant', 'tenant'], array_map(static fn ($s) => explode('-', $s->key)[0], $signatures->signers(self::lease(), self::user(), LeaseSignatures::INVENTORY)));

        $this->expectException(\DomainException::class);
        $signatures->send(self::lease(), self::user(), LeaseSignatures::INVENTORY);
    }

    public function testTheStatesInWords(): void
    {
        self::assertSame('Envoyé, 1 signature(s) sur 4', LeaseSignatures::label('sent', 1, 4));
        self::assertSame('Signé le 02/12/2026', LeaseSignatures::label('completed', 4, 4, new \DateTimeImmutable('2026-12-02')));
        self::assertSame('Signature refusée', LeaseSignatures::label('declined', 1, 4));
        self::assertSame('Expiré sans toutes les signatures', LeaseSignatures::label('expired', 1, 4));
    }
}
