<?php

namespace Tests\Base\Estate\Admin;

use Base\Estate\Entity\Lease;
use Base\Estate\Entity\Mandate;
use Base\Estate\Entity\MandateExpense;
use Base\Estate\Entity\MandateFee;
use Base\Estate\Entity\MandatePayout;
use Base\Estate\Entity\Mandator;
use Base\Estate\Entity\Organisation;
use Base\Estate\Entity\Payment;
use Base\Estate\Entity\RentCall;
use Base\Estate\Entity\Unit;
use Base\Estate\Enum\PaymentMethod;
use Base\Estate\Model\MandateStatement;
use Base\Estate\Model\Period;
use Base\Estate\Twig\EstateExtension;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * The owner's statement (compte rendu de gérance) as the PDF is drawn from
 * it, in French: the opening balance, each home's rents called and
 * collected, the fees and their VAT, the expenses, the payout, the closing
 * balance and what a tenant still owes - and the e-mail that carries it.
 */
final class ManagementStatementPdfTest extends TestCase
{
    public function testTheStatementsPage(): void
    {
        if (!class_exists(Environment::class) || !class_exists(\Twig\Extra\Intl\IntlExtension::class)) {
            self::markTestSkipped('Twig and its intl extension are needed.');
        }
        \Locale::setDefault('fr');
        $loader = new FilesystemLoader();
        $loader->addPath(\dirname(__DIR__, 2).'/templates', 'Estate');
        // The host's e-mail frame, its blocks only.
        $frame = new \Twig\Loader\ArrayLoader(['email.html.twig' => '{% block subject %}{% endblock %} [{% block action_text %}{% endblock %}: {% block action_url %}{% endblock %}] {% block content %}{% endblock %}']);
        $twig = new Environment(new \Twig\Loader\ChainLoader([$frame, $loader]), ['strict_variables' => true]);
        $twig->addFunction(new \Twig\TwigFunction('url', fn (string $route) => 'https://example.org/'.$route));
        $twig->addExtension(new \Twig\Extra\Intl\IntlExtension());
        $twig->addExtension(new \Twig\Extension\AttributeExtension(EstateExtension::class));
        $extension = (new \ReflectionClass(EstateExtension::class))->newInstanceWithoutConstructor();
        $twig->addRuntimeLoader(new \Twig\RuntimeLoader\FactoryRuntimeLoader([EstateExtension::class => fn () => $extension]));

        $agency = (new Organisation('Agence Kléber'))->setCity('Strasbourg')->setEmail('gestion@kleber.example');
        $unit = (new Unit($agency, 'Appartement Orangerie'))->setAddress('12 rue de l\'Orangerie')->setPostcode('67000')->setCity('Strasbourg');
        (new \ReflectionProperty($unit, 'id'))->setValue($unit, 12);
        $lease = new Lease($unit, 'MA-2026-012', new \DateTimeImmutable('2025-06-01'));
        $owner = (new Mandator('Hélène Roth'))->setAddress('3 quai des Bateliers')->setPostcode('67000')->setCity('Strasbourg')->setIban('FR7610278060000002027280196');
        $mandate = (new Mandate($agency, $owner, new \DateTimeImmutable('2026-01-01')))->setNumber('MG-2026-0001')->addUnit($unit)->setReserve(20000);
        $october = (new RentCall($lease, new \DateTimeImmutable('2026-10-01')))->setRent(85000)->setCharges(5000);
        $october->addPayment(new Payment($october, 90000, PaymentMethod::TRANSFER, new \DateTimeImmutable('2026-10-04')));
        $september = (new RentCall($lease, new \DateTimeImmutable('2026-09-01')))->setRent(85000)->setCharges(5000);
        $september->addPayment(new Payment($september, 50000, PaymentMethod::TRANSFER, new \DateTimeImmutable('2026-09-06')));
        [$from, $to] = Period::parse('2026-10');
        $statement = MandateStatement::of($mandate, $from, $to, [$september, $october],
            [new MandateFee($mandate, new \DateTimeImmutable('2026-10-04'), 'Loyer 10/2026 — Appartement Orangerie', 90000, 700, 6300, 1260, 'V20')],
            [(new MandateExpense($mandate, 'Chauffe-eau', 42000, new \DateTimeImmutable('2026-10-15')))->setSupplierName('Plomberie Ott')->markPosted()],
            [(new MandatePayout($mandate, new \DateTimeImmutable('2026-09-30'), 50000, 0, 50000, new \DateTimeImmutable('2026-10-01')))->setReference('RV-2026-0001')]);

        $html = $twig->render('@Estate/pdf/mandate_statement.html.twig', ['statement' => $statement, 'mandate' => $mandate, 'mandator' => $owner, 'organisation' => $agency]);
        $text = preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace('<', ' <', preg_replace('#<style.*?</style>#s', '', $html)))));

        self::assertStringContainsString('Compte rendu de gérance', $text);
        self::assertStringContainsString('Octobre 2026', $text);
        self::assertStringContainsString('Hélène Roth', $text);
        self::assertStringContainsString('Appartement Orangerie', $text);
        self::assertMatchesRegularExpression('/Loyers et charges encaissés \(appelés 900,00\s€\) 900,00\s€/u', $text);
        self::assertMatchesRegularExpression('/63,00\s€ HT, TVA 12,60\s€\) - 75,60\s€/u', $text);
        self::assertStringContainsString('Chauffe-eau — Plomberie Ott', $text);
        self::assertStringContainsString('Reversement RV-2026-0001', $text);
        // 500 opening + 900 - 75,60 - 420 - 500 = 404,40; less the 200 reserve: 204,40.
        self::assertMatchesRegularExpression('/Solde au 31 octobre 2026 \(en votre faveur\) 404,40\s€/u', $text);
        self::assertMatchesRegularExpression('/Disponible pour reversement 204,40\s€/u', $text);
        self::assertMatchesRegularExpression('/Total restant dû \(non compris dans votre solde\) 400,00\s€/u', $text, 'September\'s rest');

        $email = $twig->render('@Estate/email/mandate_statement.html.twig', ['statement' => $statement, 'mandate' => $mandate, 'mandator' => $owner, 'organisation' => $agency, 'subject' => 'Compte rendu de gérance octobre 2026']);
        $text = preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace('<', ' <', $email))));
        self::assertStringContainsString('[Voir mon espace propriétaire: https://example.org/estate_owner]', $text);
        self::assertStringContainsString('Bonjour Hélène Roth', $text);
        self::assertMatchesRegularExpression('/Solde au 31 octobre 2026 404,40\s€/u', $text);
        self::assertMatchesRegularExpression('/Disponible pour reversement : 204,40\s€ , viré sur votre compte se terminant par 0196/u', $text);
        self::assertMatchesRegularExpression('/Restent dus par les locataires : 400,00\s€/u', $text);
    }
}
