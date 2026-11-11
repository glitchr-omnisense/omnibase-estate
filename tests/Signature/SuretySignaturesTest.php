<?php

namespace Tests\Base\Estate\Signature;

use Base\Entity\Signature\Envelope;
use Base\Entity\User;
use Base\Estate\Entity\Lease;
use Base\Estate\Entity\LeaseParty;
use Base\Estate\Entity\Organisation;
use Base\Estate\Entity\Unit;
use Base\Estate\Enum\PartyRole;
use Base\Estate\Enum\RevisionRule;
use Base\Estate\Repository\RentCallRepository;
use Base\Estate\Service\ActivityLog;
use Base\Estate\Service\EstateMailer;
use Base\Estate\Service\Receipts;
use Base\Estate\Signature\LeaseSignatures;
use Base\Estate\Signature\SuretySignatures;
use Base\Service\Signatures;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Omnisign\Docuseal\DocusealGatewayFactory;
use Omnisign\Model\Capabilities;
use Omnisign\Model\Envelope as SignatureRequest;
use Omnisign\Model\FieldType;
use Omnisign\Model\Level;
use Omnisign\Model\StatementLimit;
use Omnisign\Model\StatementMode;
use Omnisign\Registry;
use Omnisign\Yousign\YousignGatewayFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Twig\Environment;

/** What a guarantor's deed sent to be signed asks the provider, and what the deed says. */
final class SuretySignaturesTest extends TestCase
{
    private ?SignatureRequest $sent = null;

    /** @var array<string, array<string, mixed>> the templates rendered, by name: their context */
    private array $rendered = [];

    protected function setUp(): void
    {
        if (!class_exists(\Omnisign\Registry::class) || !class_exists(\Dompdf\Dompdf::class)) {
            self::markTestSkipped('omnisense/omnisign and dompdf/dompdf are needed.');
        }
        if (!class_exists(StatementMode::class) || !class_exists(DocusealGatewayFactory::class) || !class_exists(YousignGatewayFactory::class)) {
            self::markTestSkipped('omnisense/omnisign 1.2 (its statements), omnisign/docuseal and omnisign/yousign are needed.');
        }
    }

    /**
     * The signature gateways as a site configures them, nothing sent (a MockHttpClient that answers
     * nothing): DocuSeal and Yousign as they declare their statements, and a provider whose typed
     * field takes $narrow characters, or none at all (0).
     */
    private static function registry(?int $narrow = null): Registry
    {
        $http = new MockHttpClient([]);
        $narrowed = null === $narrow ? [] : ['omnisign.capabilities' => new Capabilities([Level::SIMPLE], statements: 0 === $narrow ? [] : [new StatementLimit(StatementMode::TYPED, $narrow)])];

        return new Registry([new DocusealGatewayFactory($http), new YousignGatewayFactory($http)], [
            'docuseal' => ['factory' => 'docuseal', 'options' => ['url' => 'https://sign.example', 'api_key' => 'k']],
            'yousign' => ['factory' => 'yousign', 'options' => ['api_key' => 'k', 'sandbox' => true]],
            'narrow' => ['factory' => 'docuseal', 'options' => ['url' => 'https://sign.example', 'api_key' => 'k'] + $narrowed],
        ]);
    }

    private function sureties(string $gateway = 'docuseal', ?Registry $registry = null): SuretySignatures
    {
        $core = $this->createStub(Signatures::class);
        $core->method('isEnabled')->willReturn(true);
        $core->method('of')->willReturn([]);
        $core->method('send')->willReturnCallback(function (object $subject, SignatureRequest $request, ?string $gateway) {
            $this->sent = $request;
            self::assertInstanceOf(LeaseParty::class, $subject, 'the envelope is about the guarantor');

            return new Envelope(LeaseParty::class, '7', $request->title, (string) $gateway);
        });
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($this->createStub(EntityRepository::class));
        $twig = $this->createStub(Environment::class);
        $twig->method('render')->willReturnCallback(function (string $name, array $context) {
            $this->rendered[$name] = $context;

            return '<html><body><h1>'.$name.'</h1></body></html>';
        });
        $receipts = new Receipts($this->createStub(RentCallRepository::class), $twig);
        $leases = new LeaseSignatures($core, $receipts, $entityManager, $this->createStub(ActivityLog::class), $this->createStub(EstateMailer::class), $twig, null, $gateway);

        return new SuretySignatures($core, $leases, $receipts, $entityManager, $this->createStub(ActivityLog::class), $this->createStub(EstateMailer::class), $twig, registry: $registry ?? self::registry());
    }

    /** @return array{Lease, LeaseParty} */
    private static function lease(int $tenants = 1): array
    {
        $organisation = (new Organisation('Meyers & Associée', 'meyers'))->setLegalName('MEYERS & ASSOCIEE');
        $unit = (new Unit($organisation, 'Krutenau', 'krutenau'))->setPostcode('67000');
        $lease = (new Lease($unit, 'B-1', new \DateTimeImmutable('2026-12-01')))->setRent(85000)->setIndexQuarter('T2 2026')->setIndexValue('148.37');
        $guarantor = (new LeaseParty('Paul', 'Garant', 'paul@example.org', PartyRole::GUARANTOR))->setSuretyLimit(2400000);
        (new \ReflectionProperty(LeaseParty::class, 'id'))->setValue($guarantor, 7);
        $lease->addParty($guarantor);
        $lease->addParty(new LeaseParty('Léa', 'Muller', 'lea@example.org'));
        if ($tenants > 1) {
            $lease->addParty(new LeaseParty('Hugo', 'Muller', 'hugo@example.org', PartyRole::COTENANT));
        }

        return [$lease, $guarantor];
    }

    public function testTheMentionFollowsArticle2297InWordsAndFigures(): void
    {
        [, $guarantor] = self::lease();

        self::assertSame([
            'Je m\'engage en qualité de caution à payer à MEYERS & ASSOCIEE ce que lui doit le débiteur, Léa Muller, en cas de défaillance de celui-ci, dans la limite de la somme de vingt-quatre mille euros (24 000,00 €) en principal et accessoires.',
            'Je reconnais ne pouvoir exiger du créancier qu\'il poursuive d\'abord le débiteur ou qu\'il divise ses poursuites entre les cautions.',
        ], array_map(static fn (string $s) => str_replace("\u{00A0}", ' ', $s), $this->sureties()->statement($guarantor)));
        // Not joint: the benefits kept, nothing to acknowledge.
        self::assertCount(1, $this->sureties()->statement($guarantor->setSuretyJoint(false)));
        [, $two] = self::lease(2);
        self::assertStringContainsString('ce que lui doivent les débiteurs, Léa Muller, Hugo Muller, en cas de défaillance de ceux-ci', $this->sureties()->statement($two)[0]);
        self::assertSame('mille deux cent trente-quatre euros et cinquante centimes', SuretySignatures::inWords(123450));
        self::assertSame('un euro', SuretySignatures::inWords(100));
    }

    public function testTheDeedIsSentToTheGuarantorAloneWithACopyOfTheLeaseAndTheMentionToType(): void
    {
        [$lease, $guarantor] = self::lease();
        $user = (new \ReflectionClass(User::class))->newInstanceWithoutConstructor();
        $this->sureties()->send($guarantor, $user);

        $request = $this->sent;
        self::assertSame('surety:B-1:7', $request->key);
        self::assertSame(['guarantor-7'], array_map(static fn ($s) => $s->key, $request->signers));
        self::assertSame(['deed', 'lease', 'signatures'], array_map(static fn ($d) => $d->key, $request->documents));
        self::assertSame([true, false, true], array_map(static fn ($d) => $d->signable, $request->documents), 'the lease is given to read, not to sign');
        // DocuSeal states no limit: the whole mention in one field, a statement the guarantor types.
        $mention = implode(' ', $this->sureties()->statement($guarantor));
        self::assertSame([FieldType::TEXT, FieldType::SIGNATURE], array_map(static fn ($f) => $f->type, $request->fields));
        self::assertSame([$mention, StatementMode::TYPED, 'Mention de la caution'], [$request->fields[0]->statement, $request->fields[0]->mode(), $request->fields[0]->label]);
        self::assertSame(['signatures'], array_values(array_unique(array_map(static fn ($f) => $f->document, $request->fields))));

        // A home's deed reproduces art. 22-1's paragraph and states the rent's revision; the page names what to copy.
        $deed = $this->rendered['@Estate/pdf/surety.html.twig'];
        self::assertStringStartsWith('Lorsque le cautionnement d\'obligations résultant d\'un contrat de location conclu en application du présent titre ne comporte aucune indication de durée', $deed['paragraph4']);
        self::assertStringEndsWith('au cours duquel le bailleur reçoit notification de la résiliation.', $deed['paragraph4']);
        self::assertSame('vingt-quatre mille euros', $deed['limit_words']);
        self::assertSame([$mention], array_column($this->rendered['@Estate/pdf/surety_signatures.html.twig']['mentions'], 'text'));
        self::assertTrue($lease->isResidential());
    }

    public function testTheMentionGoesInAsFewFieldsAsTheProviderTakesTypedAndNeverAcknowledged(): void
    {
        [, $guarantor] = self::lease();
        $user = (new \ReflectionClass(User::class))->newInstanceWithoutConstructor();
        $sentences = $this->sureties()->statement($guarantor);
        $statements = fn () => array_map(static fn ($f) => [$f->statement, $f->mode()], array_values(array_filter($this->sent->fields, static fn ($f) => FieldType::TEXT === $f->type)));
        self::assertGreaterThan(300, mb_strlen(implode(' ', $sentences)));
        self::assertLessThan(300, max(array_map('mb_strlen', $sentences)));

        // Yousign takes 32,767 characters in a typed field: the whole mention in one.
        $this->sureties('yousign')->send($guarantor, $user);
        self::assertSame([[implode(' ', $sentences), StatementMode::TYPED]], $statements());

        // A provider whose typed field takes 300 characters: one field a sentence, each a statement typed.
        $narrow = $this->sureties('narrow', self::registry(300));
        self::assertSame($sentences, $narrow->mentions($guarantor));
        $narrow->send($guarantor, $user);
        self::assertSame([[$sentences[0], StatementMode::TYPED], [$sentences[1], StatementMode::TYPED]], $statements());
        self::assertSame(['Mention de la caution', 'Mention de la caution (solidarité)'], array_map(static fn ($f) => $f->label, array_slice($this->sent->fields, 0, 2)));

        // One whose field takes 100, or that carries no typed statement (an acknowledged one would not do, art. 2297):
        // said among what is missing, never sent.
        foreach ([100, 0] as $limit) {
            $sureties = $this->sureties('narrow', self::registry($limit));
            self::assertNull($sureties->mentions($guarantor));
            self::assertStringContainsString('« narrow » ne prend pas une phrase de la mention', implode(' ', $sureties->missing($guarantor)));
            self::assertFalse($sureties->canSend($guarantor));
        }
        $this->sent = null;
        try {
            $sureties->send($guarantor, $user);
            self::fail('A deed whose mention the provider cannot take is not sent.');
        } catch (\DomainException) {
        }
        self::assertNull($this->sent);
    }

    public function testWhatTheDeedNeedsBeforeItGoes(): void
    {
        [$lease, $guarantor] = self::lease();
        $sureties = $this->sureties();
        self::assertSame([], $sureties->missing($guarantor));
        self::assertTrue($sureties->canSend($guarantor));

        $guarantor->setSuretyLimit(null);
        $lease->setIndexValue(null);
        self::assertSame(['le montant maximal de l\'engagement (principal et accessoires)', 'l\'indice de référence du bail (les conditions de révision du loyer)'], $sureties->missing($guarantor));
        self::assertFalse($sureties->canSend($guarantor));
        $this->expectException(\DomainException::class);
        $sureties->send($guarantor, (new \ReflectionClass(User::class))->newInstanceWithoutConstructor());
    }

    public function testARentWithoutRevisionNeedsNoIndexAndATenantSignsNoDeed(): void
    {
        [$lease, $guarantor] = self::lease();
        $lease->setRevisionRule(RevisionRule::NONE)->setIndexValue(null);
        self::assertSame([], $this->sureties()->missing($guarantor));
        self::assertFalse($this->sureties()->canSend($lease->getTenants()[0]));
    }
}
