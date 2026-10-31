<?php

namespace Base\Estate\Admin\Widget\Dashboard;

use Base\Admin\Config\Menu\MenuItem;
use Base\Admin\Widget\PaletteDashboardWidgetTypeInterface;
use Base\Estate\Entity\Organisation;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A card of the property company's dashboard: one of omnibase/admin's
 * palette widget types, in its dashboard card (the title above the white
 * panel), for every organisation together. A dashboard places it by name:
 *
 *   MenuItem::block('estate_rents', 'estate_widget.rents', 'fa-solid fa-chart-column')->setSize(2)
 *
 * A compact card - one figure and its context - sits two to a row on a
 * phone.
 */
abstract class AbstractEstateWidgetType implements PaletteDashboardWidgetTypeInterface
{
    protected const COMPACT = false;

    public function __construct(protected readonly EntityManagerInterface $entityManager)
    {
    }

    /** The template's name under @Estate/admin/widget/dashboard/. */
    abstract protected function template(): string;

    /** @param list<Organisation> $organisations @return array<string, mixed> */
    abstract protected function data(array $organisations, array $params): array;

    public function getTemplate(): string
    {
        return '@Estate/admin/widget/dashboard/'.$this->template().'.html.twig';
    }

    public function getTemplateVars(MenuItem $widget): array
    {
        $organisations = $this->entityManager->getRepository(Organisation::class)->findBy([], ['name' => 'ASC']);

        return ['card' => $this->template(), 'compact' => static::COMPACT, 'organisations' => $organisations] + $this->data($organisations, $widget->getParams());
    }

    /**
     * A scale from zero ending on a round number above $max, and its ticks.
     *
     * @return array{max: int, ticks: list<int>}
     */
    public static function scale(int $max, int $steps = 2): array
    {
        if ($max <= 0) {
            return ['max' => 1, 'ticks' => [0]];
        }
        $raw = $max / $steps;
        $magnitude = 10 ** floor(log10($raw));
        $step = $raw;
        foreach ([1, 2, 2.5, 5, 10] as $nice) {
            if ($nice * $magnitude >= $raw) {
                $step = $nice * $magnitude;
                break;
            }
        }
        $step = (int) ceil($step);

        return ['max' => $steps * $step, 'ticks' => array_map(static fn ($i) => $i * $step, range(0, $steps))];
    }
}
