<?php

namespace Base\Estate\Service;

use Base\Estate\Entity\Room;
use Base\Estate\Entity\Unit;
use Base\Estate\Enum\RoomKind;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\File;

/**
 * A home's plan, as the back office's editor saves it: its rooms (each one's
 * name, kind, level, corners on the 10 cm grid, measured figure, whether it
 * counts toward the living area), the architect's plan traced over and its
 * scale. A plan is private: never on the public listing. Rooms sent with their id are updated,
 * those left out deleted, new ones added - all or nothing.
 */
class Plans
{
    /** Rooms on one home's plan at most: a mistake past that. */
    private const MAX_ROOMS = 80;

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * @param array{rooms?: list<array<string, mixed>>, background?: array{scale?: float|null, x?: int, y?: int}} $plan
     *
     * @throws \InvalidArgumentException naming the room at fault; nothing is saved then
     */
    public function save(Unit $unit, array $plan): void
    {
        $rows = $plan['rooms'] ?? [];
        if (!\is_array($rows) || \count($rows) > self::MAX_ROOMS) {
            throw new \InvalidArgumentException(sprintf('Un plan compte %d pièces au plus.', self::MAX_ROOMS));
        }
        $existing = [];
        foreach ($unit->getPlanRooms() as $room) {
            $existing[$room->getId()] = $room;
        }

        $kept = [];
        $staged = [];
        foreach (array_values($rows) as $position => $row) {
            $name = trim((string) ($row['name'] ?? ''));
            $label = '' !== $name ? $name : sprintf('pièce %d', $position + 1);
            $kind = RoomKind::tryFrom((string) ($row['kind'] ?? '')) ?? RoomKind::OTHER;
            $room = isset($row['id']) && isset($existing[(int) $row['id']]) ? $existing[(int) $row['id']] : new Room($unit);
            try {
                $polygon = \Base\Estate\Model\Polygon::from($row['polygon'] ?? null)->points();
            } catch (\InvalidArgumentException $e) {
                throw new \InvalidArgumentException(sprintf('« %s » : %s', $label, $e->getMessage()), 0, $e);
            }
            $measured = $row['measured'] ?? null;
            if (null !== $measured && '' !== $measured && (!is_numeric(str_replace(',', '.', (string) $measured)) || (float) str_replace(',', '.', (string) $measured) < 0 || (float) str_replace(',', '.', (string) $measured) > 10000)) {
                throw new \InvalidArgumentException(sprintf('« %s » : la surface mesurée est un nombre de m².', $label));
            }
            $staged[] = [$room, $name ?: $label, $kind, (int) ($row['level'] ?? 0), $polygon, $measured, $row['counted'] ?? null, $position];
            if ($room->getId()) {
                $kept[$room->getId()] = true;
            }
        }

        // Every room checked: now written.
        foreach ($staged as [$room, $name, $kind, $level, $polygon, $measured, $counted, $position]) {
            $room->setName($name)->setKind($kind)->setLevel(max(-5, min(50, $level)))->setPolygon($polygon)
                ->setMeasuredSurface(\is_string($measured) || is_numeric($measured) ? (string) $measured : null)->setPosition($position);
            if (null !== $counted) {
                $room->setCounted((bool) $counted);
            }
            if (!$room->getId()) {
                $unit->addPlanRoom($room);
                $this->entityManager->persist($room);
            }
        }
        foreach ($existing as $id => $room) {
            if (!isset($kept[$id])) {
                $unit->removePlanRoom($room);
                $this->entityManager->remove($room);
            }
        }

        if (isset($plan['background']) && \is_array($plan['background'])) {
            $background = $plan['background'];
            if (\array_key_exists('scale', $background)) {
                $scale = null === $background['scale'] ? null : (float) $background['scale'];
                $unit->setPlanBackgroundScale(null !== $scale && $scale > 0 && $scale < 1000 ? $scale : null);
            }
            $unit->setPlanBackgroundOffset((int) round((float) ($background['x'] ?? $unit->getPlanBackgroundX())), (int) round((float) ($background['y'] ?? $unit->getPlanBackgroundY())));
        }

        $this->entityManager->flush();
    }

    /**
     * The architect's plan to trace over: an image as it is, a PDF's first
     * page turned into a PNG at 150 dpi when Imagick reads PDFs (Ghostscript
     * in the image). Its scale is asked again.
     *
     * @throws \InvalidArgumentException when the file cannot be used
     */
    public function background(Unit $unit, File $file): void
    {
        $type = (string) $file->getMimeType();
        if ('application/pdf' === $type) {
            $file = $this->firstPage($file);
        } elseif (!str_starts_with($type, 'image/')) {
            throw new \InvalidArgumentException('Le plan est une image (JPEG, PNG) ou un PDF.');
        }
        $unit->setPlanBackground($file)->setPlanBackgroundScale(null)->setPlanBackgroundOffset(0, 0);
        $this->entityManager->flush();
    }

    public function removeBackground(Unit $unit): void
    {
        $unit->setPlanBackground(null)->setPlanBackgroundScale(null)->setPlanBackgroundOffset(0, 0);
        $this->entityManager->flush();
    }

    /** Whether a PDF plan can be turned into an image here. */
    public static function readsPdf(): bool
    {
        return class_exists(\Imagick::class) && [] !== \Imagick::queryFormats('PDF');
    }

    private function firstPage(File $pdf): File
    {
        if (!self::readsPdf()) {
            throw new \InvalidArgumentException('Ce serveur ne lit pas les PDF : envoyez le plan en image (JPEG, PNG).');
        }
        try {
            $image = new \Imagick();
            $image->setResolution(150, 150);
            $image->readImage($pdf->getPathname().'[0]');
            $image->setImageBackgroundColor('white');
            $image = $image->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
            $image->setImageFormat('png');
            $target = tempnam(sys_get_temp_dir(), 'plan').'.png';
            $image->writeImage($target);
            $image->clear();
        } catch (\ImagickException $e) {
            throw new \InvalidArgumentException('Ce PDF ne se lit pas : envoyez le plan en image (JPEG, PNG).', 0, $e);
        }

        return new File($target);
    }

    /** The editor's JSON: the rooms, the background, the totals, the declared surface. */
    public function toArray(Unit $unit): array
    {
        return [
            'rooms' => array_map(fn (Room $room) => $room->toArray(), $unit->getPlanRooms()->toArray()),
            'background' => [
                'scale' => $unit->getPlanBackgroundScale(),
                'x' => $unit->getPlanBackgroundX(),
                'y' => $unit->getPlanBackgroundY(),
            ],
            'livingArea' => $unit->getLivingArea(),
            'totalArea' => $unit->getPlanSurface(),
            'declared' => $unit->getSurface(),
            'gap' => $unit->getSurfaceGap(),
        ];
    }
}
