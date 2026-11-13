<?php

namespace Tests\Base\Estate\Service;

use Base\Estate\Entity\Lease;
use Base\Estate\Entity\Organisation;
use Base\Estate\Entity\Unit;
use Base\Estate\Enum\LeaseKind;
use Base\Estate\Enum\RevisionRule;
use Base\Estate\Enum\UnitKind;
use Base\Estate\Index\OfficialIndex;
use Base\Estate\Model\IndexCaps;
use Base\Estate\Model\LeaseRules;
use Base\Estate\Model\RevisionProposal;
use Base\Estate\Repository\LeaseRepository;
use Base\Estate\Service\ActivityLog;
use Base\Estate\Service\EstateMailer;
use Base\Estate\Service\RentRevisions;
use Doctrine\ORM\EntityManagerInterface;
use Omnistate\Insee\Insee;
use Omnistate\Omnistate;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Validator\Validation;

/**
 * Commercial and professional leases revised on the ILC or the ILAT, on the BDM's answers
 * recorded on 2026-11-11 (tests/Fixtures/insee-ilc.xml, insee-ilat.xml: 2021-Q1 to 2026-Q2) -
 * no live call.
 */
final class CommercialRevisionsTest extends TestCase
{
    /** @var list<string> the URLs INSEE was asked */
    private array $asked = [];

    private function revisions(): RentRevisions
    {
        if (!class_exists(Insee::class)) {
            self::markTestSkipped('omnistate/insee is needed.');
        }
        $http = new MockHttpClient(function (string $method, string $url) {
            $this->asked[] = $url;
            $file = match (true) {
                str_contains($url, '001532540') => 'insee-ilc.xml',
                str_contains($url, '001617112') => 'insee-ilat.xml',
                default => 'insee-irl.xml',
            };

            return new MockResponse((string) file_get_contents(__DIR__.'/../Fixtures/'.$file), ['response_headers' => ['content-type' => 'application/xml']]);
        });

        return new RentRevisions($this->createStub(EntityManagerInterface::class), $this->createStub(LeaseRepository::class), $this->createStub(ActivityLog::class), $this->createStub(EstateMailer::class), new OfficialIndex(new Omnistate(statistics: [new Insee($http)])));
    }

    /** A shop let 2 000 € a month (excluding VAT and charges). */
    private static function lease(string $start, string $quarter, string $value, string $index = 'ILC', RevisionRule $rule = RevisionRule::INDEXATION, LeaseKind $kind = LeaseKind::COMMERCIAL, int $rent = 200000): Lease
    {
        $unit = (new Unit(new Organisation('SCI Test', 'sci-test'), 'Boutique A', 'boutique'))->setKind(UnitKind::COMMERCIAL)->setPostcode('67000');

        return (new Lease($unit, 'C-1', new \DateTimeImmutable($start)))->setKind($kind)->setIndexName($index)->setRevisionRule($rule)
            ->setRent($rent)->setIndexQuarter($quarter)->setIndexValue($value);
    }

    public function testAnIndexationClauseFollowsTheIlc(): void
    {
        $proposal = $this->revisions()->propose(self::lease('2025-07-01', 'T1 2025', '135.87'), new \DateTimeImmutable('2026-06-20'));

        self::assertSame(RevisionProposal::READY, $proposal->status);
        self::assertSame('ILC', $proposal->index);
        self::assertSame('2026-07-01', $proposal->on->format('Y-m-d'));
        self::assertSame('T1 2026', $proposal->quarter);
        self::assertSame('135.26', $proposal->value->value);
        // 2 000 × 135.26 / 135.87 = 1 991.02: the ILC fell, so does the rent.
        self::assertSame(199102, $proposal->rent);
        self::assertStringContainsString('/data/SERIES_BDM/001532540', $this->asked[0]);
    }

    public function testAProfessionalLeaseFollowsTheIlat(): void
    {
        $proposal = $this->revisions()->propose(self::lease('2025-10-01', 'T2 2025', '137.15', 'ILAT', kind: LeaseKind::PROFESSIONAL, rent: 150000), new \DateTimeImmutable('2026-09-20'));

        self::assertSame(RevisionProposal::READY, $proposal->status);
        self::assertSame('T2 2026', $proposal->quarter);
        self::assertSame('138.01', $proposal->value->value);
        // 1 500 × 138.01 / 137.15 = 1 509.41.
        self::assertSame(150941, $proposal->rent);
        self::assertStringContainsString('/data/SERIES_BDM/001617112', $this->asked[0]);
    }

    public function testNoYearIsLostUnderAClause(): void
    {
        // Never revised since 2024: the clause measures from the reference, art. 17-1's lost year is a home's.
        $proposal = $this->revisions()->propose(self::lease('2024-07-01', 'T1 2024', '134.58'), new \DateTimeImmutable('2026-06-20'));

        self::assertSame(0, $proposal->waived);
        self::assertSame('T1 2024', $proposal->fromQuarter);
        self::assertSame('T1 2026', $proposal->quarter);
        self::assertSame((int) round(200000 * 135.26 / 134.58), $proposal->rent);
    }

    public function testTheIlcOfASmallBusinessIsCappedOverThePeriodTheLawSets(): void
    {
        self::assertSame(0.035, IndexCaps::limit('ILC', 'T2 2022', '67000', true));
        self::assertSame(0.035, IndexCaps::limit('ILC', 'T1 2024', '97400', true));
        self::assertNull(IndexCaps::limit('ILC', 'T1 2022', '67000', true));
        self::assertNull(IndexCaps::limit('ILC', 'T2 2024', '67000', true));
        self::assertNull(IndexCaps::limit('ILC', 'T3 2023', '67000', false));
        self::assertNull(IndexCaps::limit('ILAT', 'T3 2023', '67000', true));

        // T2 2022 over T2 2021: +4.43 %. A small business: +3.5 %, 2 000 → 2 070; another tenant: 2 088.51.
        $revisions = $this->revisions();
        $proposal = $revisions->propose(self::lease('2021-07-01', 'T2 2021', '118.41')->setSmallBusiness(true), new \DateTimeImmutable('2022-06-20'));
        self::assertSame(RevisionProposal::READY, $proposal->status);
        self::assertSame(207000, $proposal->rent);
        self::assertSame(0.035, $proposal->capped);
        self::assertSame(208851, $revisions->propose(self::lease('2021-07-01', 'T2 2021', '118.41'), new \DateTimeImmutable('2022-06-20'))->rent);
    }

    public function testAChangeOfSeveralYearsAcrossTheCappedPeriodIsLeftToTheTeam(): void
    {
        // A small business never revised from T2 2021 to T2 2024: the cap is « définitivement acquis », not computed here.
        $proposal = $this->revisions()->propose(self::lease('2021-07-01', 'T2 2021', '118.41')->setSmallBusiness(true), new \DateTimeImmutable('2024-06-20'));
        self::assertSame(RevisionProposal::MANUAL, $proposal->status);
        self::assertSame(RevisionProposal::NOTE_CAP_SPAN, $proposal->note);
        self::assertSame([], $this->asked);

        // Another tenant: the whole change, uncapped.
        self::assertSame(230926, $this->revisions()->propose(self::lease('2021-07-01', 'T2 2021', '118.41'), new \DateTimeImmutable('2024-06-20'))->rent);
    }

    public function testATriennialRevisionGivesItsDateAndLeavesTheQuarterToTheTeam(): void
    {
        $revisions = $this->revisions();
        $lease = self::lease('2023-04-01', 'T2 2023', '131.81', rule: RevisionRule::TRIENNIAL);
        $proposal = $revisions->propose($lease, new \DateTimeImmutable('2026-11-11'));

        // Art. L145-38: three years at least after the entry.
        self::assertSame('2026-04-01', $proposal->on->format('Y-m-d'));
        self::assertSame(RevisionProposal::MANUAL, $proposal->status);
        self::assertSame(RevisionProposal::NOTE_TRIENNIAL, $proposal->note);
        self::assertSame('T2 2023', $proposal->fromQuarter);
        self::assertNull($proposal->quarter);
        self::assertSame([], $this->asked);
        // The ceiling on the quarter the team chooses: 2 000 × 137.16 / 131.81, no yearly cap over three years.
        self::assertSame(208118, $revisions->rentAt($lease, '137.16', 'T2 2026'));
        self::assertSame('2029-04-01', $revisions->nextOn($lease->setRevisedOn(new \DateTimeImmutable('2026-04-01')))->format('Y-m-d'));
    }

    public function testALeaseWithoutRevisionHasNothingProposed(): void
    {
        $proposal = $this->revisions()->propose(self::lease('2025-07-01', 'T1 2025', '135.87', rule: RevisionRule::NONE), new \DateTimeImmutable('2026-06-20'));

        self::assertSame(RevisionProposal::NONE, $proposal->status);
        self::assertSame([], $this->asked);
    }

    public function testTheIndexAndTheRuleMustFitTheKind(): void
    {
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        self::assertCount(0, $validator->validate(self::lease('2025-07-01', 'T1 2025', '135.87')));
        self::assertCount(0, $validator->validate(self::lease('2025-07-01', 'T1 2025', '135.87', rule: RevisionRule::TRIENNIAL)));
        $wrong = $validator->validate(self::lease('2025-07-01', 'T1 2025', '135.87', 'IRL', RevisionRule::YEARLY));
        self::assertSame(['indexName', 'revisionRule'], array_map(fn ($v) => $v->getPropertyPath(), iterator_to_array($wrong)));
        // A professional lease has no triennial revision (art. L145-38 is the commercial lease's); a home no ILC.
        self::assertCount(1, $validator->validate(self::lease('2025-07-01', 'T1 2025', '135.87', 'ILAT', RevisionRule::TRIENNIAL, LeaseKind::PROFESSIONAL)));
        self::assertCount(1, $validator->validate(self::lease('2025-07-01', 'T1 2025', '135.87', 'ILC', RevisionRule::YEARLY, LeaseKind::RESIDENTIAL)));
    }

    public function testADepositAboveAQuarterIsFlaggedOnALeaseMadeSinceTheLaw(): void
    {
        $lease = self::lease('2026-06-01', 'T1 2026', '135.26')->setDeposit(800000);
        self::assertCount(1, LeaseRules::warnings($lease));
        self::assertStringContainsString('art. L145-40', LeaseRules::warnings($lease)[0]);
        // A quarter's rent, or a lease made before 26 May 2026: nothing said.
        self::assertSame([], LeaseRules::warnings((clone $lease)->setDeposit(600000)));
        self::assertSame([], LeaseRules::warnings(self::lease('2026-05-01', 'T1 2026', '135.26')->setDeposit(800000)));
    }
}
