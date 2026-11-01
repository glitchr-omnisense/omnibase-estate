<?php

namespace Base\Estate\Admin\Widget\Dashboard;

use Base\Estate\Entity\Lease;
use Base\Estate\Entity\Unit;
use Base\Estate\Enum\LeaseStatus;
use Base\Estate\Repository\UnitRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Occupancy: a donut of the homes let and vacant, then each home and where it stands - vacant since
 * when, its lease ending (notice given or an end date within three months), or let and to whom.
 * The vacant and ending homes first: they are what the team acts on. A compact card.
 */
final class OccupancyWidgetType extends AbstractEstateWidgetType
{
    protected const COMPACT = true;
    private const ENDING_WITHIN = '+3 months';

    public function __construct(EntityManagerInterface $entityManager, private readonly UnitRepository $units)
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
        $homes = [];
        foreach ($organisations as $organisation) {
            foreach ($this->units->findByOrganisation($organisation) as $unit) {
                if ('parking' !== $unit->getKind()->value) {
                    $homes[] = $this->home($unit, $today);
                }
            }
        }
        $order = ['vacant' => 0, 'ending' => 1, 'let' => 2];
        usort($homes, static fn (array $a, array $b) => [$order[$a['state']], $a['unit']->getName()] <=> [$order[$b['state']], $b['unit']->getName()]);

        $lettable = \count($homes);
        $let = \count(array_filter($homes, static fn (array $h) => 'vacant' !== $h['state']));
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
        $ending = LeaseStatus::NOTICE === $lease->getStatus() || (null !== $end && $end <= $today->modify(self::ENDING_WITHIN));

        return ['unit' => $unit, 'state' => $ending ? 'ending' : 'let', 'lease' => $lease, 'since' => null, 'until' => $end];
    }
}
