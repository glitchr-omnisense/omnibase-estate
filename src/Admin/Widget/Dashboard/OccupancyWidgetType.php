<?php

namespace Base\Estate\Admin\Widget\Dashboard;

use Base\Estate\Entity\Lease;
use Base\Estate\Entity\Unit;
use Base\Estate\Enum\LeaseStatus;
use Base\Estate\Repository\UnitRepository;
use Base\Estate\Service\PortfolioFigures;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Occupancy: a donut of the homes let and vacant, then each home and where it stands - vacant since
 * when, its lease ending (notice given or an end date within three months), or let and to whom.
 * The vacant and ending homes first: they are what the team acts on. A compact card. Where every
 * home stands is read as scalars for every organisation together (PortfolioFigures::homes()); only
 * the five it lists are read as entities, with their leases.
 */
final class OccupancyWidgetType extends AbstractEstateWidgetType
{
    protected const COMPACT = true;
    /** The homes the card lists; the donut counts them all. */
    private const ROWS = 5;

    public function __construct(EntityManagerInterface $entityManager, private readonly PortfolioFigures $figures, private readonly UnitRepository $units)
    {
        parent::__construct($entityManager);
    }

    public static function getName(): string { return 'estate_occupancy'; }
    public function getDefaultLabel(): string { return 'estate_widget.occupancy'; }
    public function getDefaultIcon(): ?string { return 'fa-solid fa-house-circle-check'; }
    protected function template(): string { return 'occupancy'; }

    protected function data(array $organisations, array $params): array
    {
        $today = new \DateTimeImmutable('today');
        $all = $this->figures->homes($organisations, $today);
        $order = ['vacant' => 0, 'ending' => 1, 'let' => 2];
        usort($all, static fn (array $a, array $b) => [$order[$a['state']], $a['name']] <=> [$order[$b['state']], $b['name']]);
        $homes = array_map(fn (Unit $unit) => $this->home($unit, $today), $this->units->findWithLeases(array_column(\array_slice($all, 0, self::ROWS), 'unit')));

        $lettable = \count($all);
        $let = \count(array_filter($all, static fn (array $h) => 'vacant' !== $h['state']));
        $share = $lettable > 0 ? $let / $lettable * 100 : 0.0;

        return [
            'let' => $let,
            'lettable' => $lettable,
            'homes' => $homes,
            'share' => (int) round($share),
            // A circle of circumference 100: the let arc, a gap before the vacant one.
            'letDash' => $lettable > 0 && $let < $lettable ? max(0, round($share - 1.5, 2)) : round($share, 2),
        ];
    }

    /** @return array{unit: Unit, state: 'vacant'|'ending'|'let', lease: ?Lease, since: ?\DateTimeImmutable, until: ?\DateTimeImmutable} */
    private function home(Unit $unit, \DateTimeImmutable $today): array
    {
        $lease = $unit->getCurrentLease($today);
        if (null === $lease) {
            $last = null;
            foreach ($unit->getLeases() as $past) {
                if (null !== $past->getEndOn() && $past->getEndOn() < $today && (null === $last || $past->getEndOn() > $last)) {
                    $last = $past->getEndOn();
                }
            }

            return ['unit' => $unit, 'state' => 'vacant', 'lease' => null, 'since' => $last?->modify('+1 day'), 'until' => null];
        }
        $end = $lease->getEndOn();
        $ending = PortfolioFigures::isEnding(LeaseStatus::NOTICE === $lease->getStatus(), $end, $today);

        return ['unit' => $unit, 'state' => $ending ? 'ending' : 'let', 'lease' => $lease, 'since' => null, 'until' => $end];
    }
}
