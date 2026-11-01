<?php

namespace Tests\Base\Estate\Admin;

use Base\Admin\Config\Menu\MenuItem;
use Base\Admin\Widget\PaletteDashboardWidgetTypeInterface;
use Base\Estate\Admin\Widget\Dashboard;
use Base\Estate\Entity\Lease;
use Base\Estate\Entity\Organisation;
use Base\Estate\Entity\RentCall;
use Base\Estate\Entity\Unit;
use Base\Estate\Entity\WorkOrder;
use Base\Estate\Enum\LeaseStatus;
use Base\Estate\Enum\UnitKind;
use Base\Estate\Enum\WorkOrderStatus;
use Doctrine\Common\Collections\ArrayCollection;
use Base\Estate\Repository\ApplicationRepository;
use Base\Estate\Repository\MessageRepository;
use Base\Estate\Repository\RentCallRepository;
use Base\Estate\Repository\UnitRepository;
use Base\Estate\Repository\WorkOrderRepository;
use Base\Estate\Service\Figures;
use Base\Estate\Service\RentRevisions;
use Base\Estate\Twig\EstateExtension;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * The property company's dashboard cards rendered as the dashboard renders
 * them - each widget type's variables into its template, in French: the
 * occupancy, the rents month by month, the late rents, the works, what
 * waits, the money in.
 */
class DashboardWidgetsTest extends TestCase
{
    private Environment $twig;
    private Organisation $organisation;

    protected function setUp(): void
    {
        if (!class_exists(MenuItem::class) || !class_exists(Environment::class) || !class_exists(\Twig\Extra\Intl\IntlExtension::class)) {
            self::markTestSkipped('omnibase/admin, Twig and its intl extension are needed.');
        }
        \Locale::setDefault('fr');
        $root = \dirname(__DIR__, 2);
        $loader = new FilesystemLoader();
        $loader->addPath($root.'/templates', 'Estate');
        $translator = new Translator('fr');
        $translator->addLoader('yaml', new YamlFileLoader());
        $translator->addResource('yaml', $root.'/translations/estate+intl-icu.fr.yaml', 'fr', 'estate+intl-icu');
        $translator->addResource('yaml', $root.'/translations/admin.fr.yaml', 'fr', 'admin');
        $this->twig = new Environment($loader, ['strict_variables' => true]);
        $this->twig->addExtension(new TranslationExtension($translator));
        $this->twig->addExtension(new \Twig\Extra\Intl\IntlExtension());
        $this->twig->addExtension(new \Twig\Extension\AttributeExtension(EstateExtension::class));
        $extension = (new \ReflectionClass(EstateExtension::class))->newInstanceWithoutConstructor();
        $this->twig->addRuntimeLoader(new \Twig\RuntimeLoader\FactoryRuntimeLoader([EstateExtension::class => fn () => $extension]));
        $this->twig->addFunction(new TwigFunction('path', fn (string $route, array $parameters = []) => '/'.$route.'?'.http_build_query($parameters)));
        $this->twig->addFunction(new TwigFunction('csrf_token', fn (string $id) => 'token-'.$id));
        $this->organisation = new Organisation('Meyers & Associée', 'meyers');
    }

    private function entityManager(array $leases = []): EntityManagerInterface
    {
        $organisations = $this->createStub(EntityRepository::class);
        $organisations->method('findBy')->willReturn([$this->organisation]);
        $leaseRepository = $this->createStub(EntityRepository::class);
        $leaseRepository->method('findBy')->willReturn($leases);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnCallback(fn (string $class) => Lease::class === $class ? $leaseRepository : $organisations);

        return $em;
    }

    private function render(PaletteDashboardWidgetTypeInterface $type, array $params = []): string
    {
        $item = (new MenuItem(MenuItem::TYPE_BLOCK, $type->getDefaultLabel(), $type->getDefaultIcon()))->setBlockName($type::getName())->setParams($params);

        return $this->twig->render($type->getTemplate(), $type->getTemplateVars($item) + ['widget' => $item]);
    }

    /** Five homes and a parking space: one vacant since July, one never let, one with notice given, two let. */
    private function units(): UnitRepository
    {
        $home = function (string $name, ?Lease $current, array $leases = [], string $kind = 'apartment'): Unit {
            $unit = $this->createStub(Unit::class);
            $unit->method('getName')->willReturn($name);
            $unit->method('getId')->willReturn(crc32($name));
            $unit->method('getKind')->willReturn(UnitKind::from($kind));
            $unit->method('getCurrentLease')->willReturn($current);
            $unit->method('getLeases')->willReturn(new ArrayCollection($leases));

            return $unit;
        };
        $lease = function (string $tenants, LeaseStatus $status = LeaseStatus::ACTIVE, ?string $endOn = null): Lease {
            $lease = $this->createStub(Lease::class);
            $lease->method('getTenantNames')->willReturn($tenants);
            $lease->method('getStatus')->willReturn($status);
            $lease->method('getEndOn')->willReturn($endOn ? new \DateTimeImmutable($endOn) : null);

            return $lease;
        };
        $units = $this->createStub(UnitRepository::class);
        $units->method('findByOrganisation')->willReturn([
            $home('T2 Esplanade', $lease('Paul Klein')),
            $home('Appartement Orangerie', null, [$lease('Anne Roth', LeaseStatus::ENDED, '2026-06-30')]),
            $home('Studio Krutenau', $lease('Léa Muller', LeaseStatus::NOTICE)),
            $home('Maison de la Robertsau', $lease('Thomas et Julie Weber')),
            $home('Studio Neudorf', null),
            $home('Parking Neudorf', null, [], 'parking'),
        ]);

        return $units;
    }

    private function figures(): Figures
    {
        $vacant = $this->createStub(Unit::class);
        $vacant->method('getName')->willReturn('Appartement Orangerie');
        $figures = $this->createStub(Figures::class);
        $figures->method('of')->willReturn(['let' => 3, 'lettable' => 5, 'vacant' => [$vacant, $vacant]]);
        $figures->method('months')->willReturnCallback(function (Organisation $o, int $count) {
            $months = [];
            for ($i = $count - 1; $i >= 0; --$i) {
                $months[] = ['month' => (new \DateTimeImmutable('first day of this month'))->modify("-$i months"), 'called' => 345000, 'received' => 0 === $i ? 101000 : 345000];
            }

            return $months;
        });

        return $figures;
    }

    private function lateCall(): RentCall
    {
        $unit = $this->createStub(Unit::class);
        $unit->method('getName')->willReturn('Appartement Krutenau');
        $lease = $this->createStub(Lease::class);
        $lease->method('getTenantNames')->willReturn('Léa Muller');
        $lease->method('getUnit')->willReturn($unit);
        $lease->method('getOrganisation')->willReturn($this->organisation);
        $call = $this->createStub(RentCall::class);
        $call->method('getLease')->willReturn($lease);
        $call->method('getDueOn')->willReturn(new \DateTimeImmutable('2026-10-05'));
        $call->method('getRemaining')->willReturn(101000);
        $call->method('isPaid')->willReturn(false);
        $call->method('getId')->willReturn(20);

        return $call;
    }

    public function testOccupancyACompactDonut(): void
    {
        $html = $this->render(new Dashboard\OccupancyWidgetType($this->entityManager(), $this->units()));

        self::assertStringContainsString('dash-card--compact', $html, 'two to a row on a phone');
        self::assertStringContainsString('<h2 class="dash-card-title">Occupation</h2>', $html, 'the title above the card, from the admin domain');
        self::assertStringContainsString('60 %', $html);
        self::assertMatchesRegularExpression('/Loués<\/span><strong>3/', $html);
        self::assertMatchesRegularExpression('/Vacants<\/span><strong>2/', $html);
        self::assertStringContainsString('stroke-dasharray="58.5 41.5"', $html, 'the let arc, a gap before the vacant');
        self::assertStringNotContainsString('text-transform', $html);
        self::assertStringNotContainsString('Parking', $html, 'a parking space is no home');

        // Each home and where it stands, the vacant first, then the lease ending, then the let.
        preg_match_all('/<span class="dash-row-label">([^<]+)<small>(.*?)<\/small>/s', $html, $rows);
        self::assertSame(['Appartement Orangerie', 'Studio Neudorf', 'Studio Krutenau', 'Maison de la Robertsau', 'T2 Esplanade'], array_map('trim', $rows[1]));
        self::assertStringContainsString('vacant depuis le 1 juil. 2026', $rows[2][0]);
        self::assertStringContainsString('jamais loué', $rows[2][1]);
        self::assertStringContainsString('Léa Muller · préavis donné', $rows[2][2]);
        self::assertStringContainsString('es-status is-vacant">Vacant', $html);
        self::assertStringContainsString('es-status is-ending">Fin proche', $html);
        self::assertSame(2, substr_count($html, 'es-status is-let">Loué'));
    }

    public function testSummaryOneBandOfTheFiguresReadFirst(): void
    {
        $figures = $this->createStub(Figures::class);
        $figures->method('of')->willReturn(['let' => 4, 'lettable' => 6, 'monthly' => 345000, 'lateTotal' => 0, 'late' => [], 'openWorkOrders' => [1, 2, 3], 'worksSpent' => 235000]);
        $figures->method('months')->willReturn([
            ['month' => new \DateTimeImmutable('first day of last month'), 'called' => 345000, 'received' => 345000],
            ['month' => new \DateTimeImmutable('first day of this month'), 'called' => 345000, 'received' => 101000],
        ]);
        $html = $this->render(new Dashboard\SummaryWidgetType($this->entityManager(), $figures));

        self::assertSame(5, preg_match_all('/class="es-kpi( is-bad)?"/', $html), 'occupancy, the month, late, the rent roll, the works');
        self::assertStringContainsString('<h2 class="dash-card-title">En bref</h2>', $html);
        self::assertStringContainsString('67 %', $html);
        self::assertStringContainsString('4 biens loués sur 6', $html);
        self::assertMatchesRegularExpression('/reçus sur 3\s450\s€ appelés \(29 %\)/u', $html, 'the current month, its rents called');
        self::assertStringContainsString('width: 29%', $html);
        self::assertStringContainsString('tout est à jour', $html);
        self::assertMatchesRegularExpression('/2\s350\s€ dépensés en \d{4}/u', $html);
    }

    public function testRentsMonthByMonthWithLegendAxisAndTable(): void
    {
        $html = $this->render(new Dashboard\RentsWidgetType($this->entityManager(), $this->figures()), ['months' => 12]);

        self::assertStringContainsString('value="12" checked', $html);
        self::assertSame(2, substr_count($html, 'class="es-chart"'), '6 and 12 months, switched in the card');
        self::assertStringContainsString('Encaissés', $html);
        self::assertStringContainsString('Appelés', $html);
        self::assertStringContainsString('class="es-tick"', $html, 'an axis');
        self::assertStringContainsString('class="es-tip"', $html, 'a tooltip per month');
        self::assertStringContainsString('<table class="es-sr">', $html);
        self::assertMatchesRegularExpression('/Taux <strong>9\d %/', $html, 'the period\'s rate: the month in progress partly paid');
    }

    public function testLateRentsWithTheirTenantAndAmount(): void
    {
        $calls = $this->createStub(RentCallRepository::class);
        $calls->method('findOverdue')->willReturn([$this->lateCall()]);
        $html = $this->render(new Dashboard\LateRentsWidgetType($this->entityManager(), $calls));

        self::assertStringContainsString('dash-card--compact', $html);
        self::assertStringContainsString('Léa Muller', $html);
        self::assertMatchesRegularExpression('/1\s010\s€/u', $html);
        self::assertStringContainsString('1 loyer', $html);

        $none = $this->createStub(RentCallRepository::class);
        $none->method('findOverdue')->willReturn([]);
        $quiet = $this->render(new Dashboard\LateRentsWidgetType($this->entityManager(), $none));
        self::assertStringContainsString('Tous les loyers échus sont réglés.', $quiet);
        self::assertStringContainsString('tout est à jour', $quiet, 'not « aucun loyer » beside « 0 € dus »');
        self::assertStringContainsString('Aucun loyer appelé en', $quiet);
    }

    public function testLateRentsCardSaysHowTheMonthGoes(): void
    {
        $call = function (string $tenants, int $total, int $paid, string $dueOn, ?string $paidAt = null): RentCall {
            $unit = $this->createStub(Unit::class);
            $unit->method('getName')->willReturn('Studio Krutenau');
            $lease = $this->createStub(Lease::class);
            $lease->method('getTenantNames')->willReturn($tenants);
            $lease->method('getUnit')->willReturn($unit);
            $call = $this->createStub(RentCall::class);
            $call->method('getId')->willReturn(crc32($tenants));
            $call->method('getLease')->willReturn($lease);
            $call->method('getTotal')->willReturn($total);
            $call->method('getPaid')->willReturn($paid);
            $call->method('getRemaining')->willReturn($total - $paid);
            $call->method('isPaid')->willReturn($paid >= $total);
            $call->method('getDueOn')->willReturn(new \DateTimeImmutable($dueOn));
            $call->method('getPaidAt')->willReturn($paidAt ? new \DateTimeImmutable($paidAt) : null);

            return $call;
        };
        $calls = $this->createStub(RentCallRepository::class);
        $calls->method('findOverdue')->willReturn([]);
        $calls->method('findForOrganisation')->willReturn([
            $call('Paul Klein', 80000, 80000, '2026-11-01', '2026-10-30'),
            $call('Léa Muller', 101000, 0, '2026-11-05'),
        ]);
        $html = $this->render(new Dashboard\LateRentsWidgetType($this->entityManager(), $calls));

        self::assertMatchesRegularExpression('/<b>800\s€<\/b> \/ 1\s810\s€/u', $html, 'received over called');
        self::assertStringContainsString('width: 44%', $html);
        preg_match_all('/<span class="dash-row-label">([^<]+)<small>(.*?)<\/small>/s', $html, $rows);
        self::assertSame(['Léa Muller', 'Paul Klein'], array_map('trim', $rows[1]), 'what is still due first');
        self::assertStringContainsString('dû le 5 nov.', $rows[2][0]);
        self::assertStringContainsString('payé le 30 oct.', $rows[2][1]);
        self::assertStringContainsString('es-status is-due">À venir', $html);
        self::assertStringContainsString('es-status is-paid">Payé', $html);
    }

    public function testWorksInProgressAndTheYearsCost(): void
    {
        $unit = $this->createStub(Unit::class);
        $unit->method('getName')->willReturn('Maison de la Robertsau');
        $order = $this->createStub(WorkOrder::class);
        $order->method('getTitle')->willReturn('Prise du séjour qui chauffe');
        $order->method('getUnit')->willReturn($unit);
        $order->method('getStatus')->willReturn(WorkOrderStatus::SCHEDULED);
        $order->method('getContractor')->willReturn(null);
        $works = $this->createStub(WorkOrderRepository::class);
        $works->method('findOpen')->willReturn([$order]);
        $works->method('spent')->willReturn(235000);
        $html = $this->render(new Dashboard\WorksWidgetType($this->entityManager(), $works));

        self::assertStringContainsString('Prise du séjour qui chauffe', $html);
        self::assertStringContainsString('Planifié', $html);
        self::assertMatchesRegularExpression('/2\s350\s€ de travaux en \d{4}/u', $html);
    }

    public function testWhatWaitsAsRowsWithTheirAction(): void
    {
        $applications = $this->createStub(ApplicationRepository::class);
        $applications->method('countNew')->willReturn(2);
        $messages = $this->createStub(MessageRepository::class);
        $messages->method('unreadByManagement')->willReturn(1);
        $calls = $this->createStub(RentCallRepository::class);
        $calls->method('findOverdue')->willReturn([$this->lateCall()]);
        $revisions = $this->createStub(RentRevisions::class);
        $revisions->method('due')->willReturn([]);
        $html = $this->render(new Dashboard\TodoWidgetType($this->entityManager(), $revisions, $messages, $applications, $calls));

        self::assertStringContainsString('Appeler les loyers de', $html, 'the month\'s rents, always first');
        self::assertStringContainsString('2 candidatures à étudier', $html);
        self::assertStringContainsString('1 message de locataire à lire', $html);
        self::assertStringContainsString('1 loyer en retard à encaisser', $html);
        self::assertStringContainsString('href="/estate_admin_payment?id=20"', $html);
        self::assertSame(4, substr_count($html, 'class="dash-row-action"'), 'each row its action, a button, not an underlined link');
        self::assertStringContainsString('token-estate_rents_call_0', $html);
    }

    public function testManagementPayoutsStatementsAndEndingMandates(): void
    {
        $mandator = new \Base\Estate\Entity\Mandator('Hélène Roth');
        $mandate = (new \Base\Estate\Entity\Mandate($this->organisation, $mandator, new \DateTimeImmutable('2024-01-01')))->setNumber('MG-2024-0002')->setEndsOn(new \DateTimeImmutable('+20 days'));
        (new \ReflectionProperty($mandate, 'id'))->setValue($mandate, 3);
        $management = $this->createStub(\Base\Estate\Service\Management::class);
        $management->method('payoutsDue')->willReturn([['mandate' => $mandate, 'balance' => 72440, 'reserve' => 20000, 'amount' => 52440]]);
        $management->method('statementsToSend')->willReturn([['mandate' => $mandate, 'period' => '2026-10']]);
        $mandates = $this->createStub(\Base\Estate\Repository\MandateRepository::class);
        $mandates->method('ending')->willReturn([$mandate]);
        $payouts = $this->createStub(\Base\Estate\Repository\MandatePayoutRepository::class);
        $payouts->method('findUnpaid')->willReturn([(new \Base\Estate\Entity\MandatePayout($mandate, new \DateTimeImmutable('2026-09-30'), 30000, 0, 30000))->setReference('RV-2026-0003')]);

        $html = $this->render(new Dashboard\ManagementWidgetType($this->entityManager(), $management, $mandates, $payouts, new \Base\Estate\Service\Activities(['management'])));

        self::assertStringContainsString('<h2 class="dash-card-title">Gérance</h2>', $html);
        self::assertMatchesRegularExpression('/524\s€/u', $html, 'what the owners are due at the end of last month');
        self::assertStringContainsString('à reverser à 1 propriétaire', $html);
        self::assertStringContainsString('1 reversement à virer', $html);
        self::assertStringContainsString('1 compte rendu à envoyer', $html);
        self::assertStringContainsString('MG-2024-0002 prend fin le', $html);

        $off = $this->render(new Dashboard\ManagementWidgetType($this->entityManager(), $management, $mandates, $payouts, new \Base\Estate\Service\Activities(['assets'])));
        self::assertStringContainsString('aucun compte rendu à envoyer', $off, 'the activity off: nothing read');
    }

    public function testTransactionFeesSalesOffersAndEndingMandates(): void
    {
        $seller = new \Base\Estate\Entity\Mandator('Hélène Roth');
        $mandate = (new \Base\Estate\Entity\SaleMandate($this->organisation, new \DateTimeImmutable('2026-09-01')))->setNumber('VT-2026-0001')->addSeller($seller)
            ->setKind(\Base\Estate\Enum\UnitKind::APARTMENT)->setRooms(3)->setCity('Strasbourg')->setPrice(31000000);
        (new \ReflectionProperty($mandate, 'id'))->setValue($mandate, 9);
        $ending = (new \Base\Estate\Entity\SaleMandate($this->organisation, new \DateTimeImmutable('2026-08-01')))->setNumber('VT-2026-0004')->setKind(\Base\Estate\Enum\UnitKind::HOUSE)->setCity('Obernai')->setEndsOn(new \DateTimeImmutable('2026-11-20'));
        (new \ReflectionProperty($ending, 'id'))->setValue($ending, 12);
        $offer = new \Base\Estate\Entity\Offer($mandate, new \Base\Estate\Entity\Buyer('Lucas Martin'), 30000000);
        $sale = (new \Base\Estate\Entity\Sale($offer, new \DateTimeImmutable('2026-10-08')))->setStep(\Base\Estate\Enum\SaleStep::COMPROMISE)->setWithdrawalEndsOn(new \DateTimeImmutable('2026-11-02'));
        $transactions = $this->createStub(\Base\Estate\Service\Transactions::class);
        $transactions->method('isActive')->willReturn(true);
        $transactions->method('feesOfQuarter')->willReturn(['period' => '2026-Q4', 'invoiced' => 800000, 'expected' => 1500000, 'total' => 2300000, 'count' => 2]);
        $transactions->method('salesInProgress')->willReturn([$sale]);
        $transactions->method('offersWaiting')->willReturn([new \Base\Estate\Entity\Offer($ending, new \Base\Estate\Entity\Buyer('Inès Roux'), 41000000)]);
        $transactions->method('ending')->willReturn([$ending]);

        $html = $this->render(new Dashboard\TransactionWidgetType($this->entityManager(), $transactions));

        self::assertStringContainsString('<h2 class="dash-card-title">Transactions</h2>', $html);
        self::assertStringContainsString('trimestre 2026-Q4', $html);
        self::assertMatchesRegularExpression('/23\s000\s€/u', $html, 'the quarter\'s fees, invoiced and expected');
        self::assertStringContainsString('ce trimestre, 2 ventes', $html);
        self::assertStringContainsString('Appartement 3 pièces, Strasbourg', $html);
        self::assertStringContainsString('Délai de rétractation purgé : 02/11/2026', $html, 'the sale\'s next step and its date');
        self::assertStringContainsString('1 offre à répondre', $html);
        self::assertStringContainsString('VT-2026-0004 prend fin le', $html);

        $off = $this->createStub(\Base\Estate\Service\Transactions::class);
        $off->method('feesOfQuarter')->willReturn(['period' => '2026-Q4', 'invoiced' => 0, 'expected' => 0, 'total' => 0, 'count' => 0]);
        $html = $this->render(new Dashboard\TransactionWidgetType($this->entityManager(), $off));
        self::assertStringContainsString('Aucune vente en cours', $html, 'the activity off: Transactions answers nothing');
        self::assertStringNotContainsString('estate_admin_transaction_sales', $html, 'and no link to its pages');
    }
}
