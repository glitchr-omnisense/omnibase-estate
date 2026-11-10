<?php

namespace Tests\Base\Estate\Service;

use Base\Estate\Entity\Lease;
use Base\Estate\Entity\Organisation;
use Base\Estate\Entity\Unit;
use Base\Estate\Index\OfficialIndex;
use Base\Estate\Model\IndexCaps;
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

/**
 * The yearly revision proposed from INSEE's IRL, on the BDM's answer recorded on 2026-11-10
 * (tests/Fixtures/insee-irl.xml, omnistate/insee's own: 2024-Q1 to 2026-Q3) - no live call.
 */
final class RentRevisionsTest extends TestCase
{
    /** @var list<string> the URLs INSEE was asked */
    private array $asked = [];

    private function revisions(?MockHttpClient $http = null, bool $index = true): RentRevisions
    {
        $official = null;
        if ($index) {
            if (!class_exists(Insee::class)) {
                self::markTestSkipped('omnistate/insee is needed.');
            }
            $http ??= new MockHttpClient(function (string $method, string $url) {
                $this->asked[] = $url;

                return new MockResponse((string) file_get_contents(__DIR__.'/../Fixtures/insee-irl.xml'), ['response_headers' => ['content-type' => 'application/xml']]);
            });
            $official = new OfficialIndex(new Omnistate(statistics: [new Insee($http)]));
        }

        return new RentRevisions($this->createStub(EntityManagerInterface::class), $this->createStub(LeaseRepository::class), $this->createStub(ActivityLog::class), $this->createStub(EstateMailer::class), $official);
    }

    /** A lease of 850 € signed on 2025-09-01 on the IRL of T2 2025 (146.68). */
    private static function lease(string $start = '2025-09-01', string $quarter = 'T2 2025', string $value = '146.68', ?string $revisedOn = null, ?string $postcode = '67000'): Lease
    {
        $unit = (new Unit(new Organisation('SCI Test', 'sci-test'), 'Krutenau', 'krutenau'))->setPostcode($postcode);
        $lease = (new Lease($unit, 'B-1', new \DateTimeImmutable($start)))->setRent(85000)->setIndexQuarter($quarter)->setIndexValue($value);

        return null === $revisedOn ? $lease : $lease->setRevisedOn(new \DateTimeImmutable($revisedOn));
    }

    public function testTheAnniversaryRevisionIsProposedFromInseesValue(): void
    {
        $proposal = $this->revisions()->propose(self::lease(), new \DateTimeImmutable('2026-08-20'));

        self::assertSame(RevisionProposal::READY, $proposal->status);
        self::assertSame('2026-09-01', $proposal->on->format('Y-m-d'));
        self::assertSame('T2 2025', $proposal->fromQuarter);
        self::assertSame('146.68', $proposal->fromValue);
        self::assertSame('T2 2026', $proposal->quarter);
        self::assertSame('148.37', $proposal->value->value);
        // 850 × 148.37 / 146.68 = 859.79, rounded to the cent by estate.
        self::assertSame(85979, $proposal->rent);
        self::assertNull($proposal->capped);
        self::assertSame(0, $proposal->waived);
        self::assertSame('Insee, BDM, série 001515333, extraction au 10/11/2026', $proposal->value->credit);
        self::assertSame('Licence Ouverte 2.0', $proposal->value->licence);
        self::assertSame('2026-07-12', $proposal->value->publishedOn?->format('Y-m-d'));
        self::assertCount(1, $this->asked);
        self::assertStringContainsString('/data/SERIES_BDM/001515333', $this->asked[0]);
        self::assertStringContainsString('startPeriod=2026-Q2', $this->asked[0]);
    }

    public function testAQuarterNotYetPublishedWaits(): void
    {
        // Signed on the IRL of T4 2025: its anniversary asks for T4 2026, published in January.
        $proposal = $this->revisions()->propose(self::lease('2025-12-01', 'T4 2025', '145.78'), new \DateTimeImmutable('2026-11-20'));

        self::assertSame(RevisionProposal::WAITING, $proposal->status);
        self::assertSame('T4 2026', $proposal->quarter);
        self::assertFalse($proposal->value->isPublished());
        self::assertNull($proposal->rent);
    }

    public function testInseeSilentFallsBackOnTheValueTypedByHand(): void
    {
        $proposal = $this->revisions(new MockHttpClient(new MockResponse('', ['http_code' => 503])))->propose(self::lease(), new \DateTimeImmutable('2026-08-20'));

        self::assertSame(RevisionProposal::UNAVAILABLE, $proposal->status);
        self::assertSame('T2 2026', $proposal->quarter);
        self::assertNull($proposal->rent);
    }

    public function testWithoutOmnistateTheQuarterIsProposedAndTheValueTyped(): void
    {
        $revisions = $this->revisions(index: false);
        $proposal = $revisions->propose(self::lease(), new \DateTimeImmutable('2026-08-20'));

        self::assertFalse($revisions->readsIndex());
        self::assertSame(RevisionProposal::MANUAL, $proposal->status);
        self::assertSame('T2 2026', $proposal->quarter);
        self::assertSame(85979, $revisions->rentAt(self::lease(), '148,37', 'T2 2026'));
    }

    public function testALeaseWithoutReferenceHasNothingProposed(): void
    {
        $lease = self::lease()->setIndexValue(null);

        self::assertSame(RevisionProposal::NO_REFERENCE, $this->revisions()->propose($lease, new \DateTimeImmutable('2026-08-20'))->status);
        self::assertSame([], $this->asked);
    }

    public function testAYearNotClaimedIsLostAndTheChangeMeasuredFromTheYearBefore(): void
    {
        // Signed 2024-09-01 on T2 2024 (145.17), never revised: in 2026 the 2025 revision is lost;
        // the change runs from T2 2025 (146.68) to T2 2026 (148.37), not from T2 2024.
        $proposal = $this->revisions()->propose(self::lease('2024-09-01', 'T2 2024', '145.17'), new \DateTimeImmutable('2026-08-20'));

        self::assertSame(RevisionProposal::READY, $proposal->status);
        self::assertSame(1, $proposal->waived);
        self::assertSame('T2 2025', $proposal->fromQuarter);
        self::assertSame('146.68', $proposal->fromValue);
        self::assertSame('T2 2026', $proposal->quarter);
        self::assertSame(85979, $proposal->rent);
    }

    public function testTheShieldCapsTheChange(): void
    {
        self::assertSame(0.035, IndexCaps::limit('IRL', 'T3 2022', '67000'));
        self::assertSame(0.025, IndexCaps::limit('IRL', 'T1 2024', '97400'));
        self::assertNull(IndexCaps::limit('IRL', 'T2 2024', '67000'));
        self::assertNull(IndexCaps::limit('IRL', 'T2 2022', '67000'));

        // At La Réunion, T3 2023 over T3 2022 at +3.49 % is capped at +2.5 %: 850 → 871.25.
        $revisions = $this->revisions(index: false);
        self::assertSame(87125, $revisions->rentAt(self::lease('2022-10-01', 'T3 2022', '136.27', postcode: '97400'), '141.03', 'T3 2023'));
        // In Strasbourg INSEE's value already moves by 3.5 % at most: nothing to cap.
        self::assertSame(87969, $revisions->rentAt(self::lease('2022-10-01', 'T3 2022', '136.27'), '141.03', 'T3 2023'));
    }
}
