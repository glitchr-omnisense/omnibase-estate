<?php

namespace Tests\Base\Estate\Service;

use Base\Estate\Entity\Lease;
use Base\Estate\Entity\LeaseParty;
use Base\Estate\Entity\Organisation;
use Base\Estate\Entity\StoryEntry;
use Base\Estate\Entity\StoryPhoto;
use Base\Estate\Entity\Unit;
use Base\Estate\Entity\WorkOrder;
use Base\Estate\Enum\PartyRole;
use Base\Estate\Enum\PhotoPhase;
use Base\Estate\Enum\StoryState;
use Base\Estate\Enum\WorkOrderStatus;
use Base\Estate\Service\Stories;
use Base\Estate\Service\StoryImages;
use Doctrine\ORM\EntityManagerInterface;
use Imagine\Image\Box;
use Imagine\Image\Palette\RGB;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * A home's story: nothing of an entry is public until the team publishes it, and of its photos only
 * those it cleared; no tenant's name gets through - taken out of the works' text, refused at
 * publishing; the networks' text and link; the images' sizes.
 */
final class StoriesTest extends TestCase
{
    private function stories(): Stories
    {
        $routes = new RouteCollection();
        $routes->add('estate_unit', new Route('/biens/{slug}'));
        $routes->add('estate_works', new Route('/travaux'));

        return new Stories($this->createStub(EntityManagerInterface::class), new UrlGenerator($routes, new RequestContext('', 'GET', 'meyers.example', 'https')));
    }

    private function home(): Unit
    {
        $unit = (new Unit(new Organisation('Meyers & Associée', 'meyers'), 'Appartement Orangerie', 'appartement-orangerie'))
            ->setDistrict('Orangerie')->setCity('Strasbourg')->setPublished(true);
        $lease = new Lease($unit, 'L-1', new \DateTimeImmutable('-1 year'));
        $lease->addParty(new LeaseParty('Léa', 'Muller', 'lea@example.org'));
        $lease->addParty(new LeaseParty('Anne', 'Schmitt', 'anne@example.org', PartyRole::GUARANTOR));
        $unit->getLeases()->add($lease);

        return $unit;
    }

    private function photo(PhotoPhase $phase, string $view, bool $cleared): StoryPhoto
    {
        // The column's value as the uploader keeps it: a stored file's name.
        return (new StoryPhoto())->setImage(uniqid('photo', true).'.jpg')->setPhase($phase)->setView($view)->setPublishable($cleared);
    }

    public function testNothingIsPublicUntilPublishedAndOnlyTheClearedPhotos(): void
    {
        $entry = new StoryEntry($this->home(), 'Cuisine refaite', new \DateTimeImmutable('2026-08-28'));
        $entry->addPhoto($kitchenBefore = $this->photo(PhotoPhase::BEFORE, 'La cuisine', true));
        $entry->addPhoto($kitchenAfter = $this->photo(PhotoPhase::AFTER, 'La cuisine', true));
        $entry->addPhoto($bathBefore = $this->photo(PhotoPhase::BEFORE, 'La salle de bains', false));
        $entry->addPhoto($this->photo(PhotoPhase::AFTER, 'La salle de bains', true));

        self::assertFalse($entry->isPublished(), 'private when made');
        self::assertFalse($bathBefore->isPublishable(), 'a photo is private by default');
        self::assertFalse((new StoryPhoto())->isPublishable());
        self::assertNotContains($bathBefore, $entry->getPublicPhotos(), 'a photo not cleared never shows');
        self::assertCount(1, $entry->getPairs(true), 'the bathroom\'s pair lacks its cleared before');
        self::assertSame([$kitchenBefore, $kitchenAfter], [$entry->getPairs(true)[0]['before'], $entry->getPairs(true)[0]['after']]);
        self::assertCount(2, $entry->getPairs(), 'the team sees both pairs');
        self::assertNotContains($bathBefore, $entry->getSinglePhotos(true));

        $this->stories()->publish($entry);
        self::assertTrue($entry->isPublished());
        self::assertNotNull($entry->getPublishedAt());
    }

    public function testNoTenantNameGetsThrough(): void
    {
        $stories = $this->stories();
        $unit = $this->home();
        $order = (new WorkOrder($unit, 'Fuite chez Mme Muller'))->setReport('Joint changé, Léa Muller prévenue ; la garante Anne Schmitt aussi.');
        $names = $stories->tenantNames($unit);

        self::assertContains('Léa Muller', $names);
        self::assertContains('Schmitt', $names, 'a guarantor too');
        $text = $stories->scrub((string) $order->getReport(), $names);
        self::assertStringNotContainsString('Muller', $text);
        self::assertStringNotContainsString('Schmitt', $text);
        self::assertStringContainsString(Stories::REMOVED, $text);

        $entry = (new StoryEntry($unit, 'Fuite réparée'))->setText($text);
        self::assertNotEmpty($stories->problems($entry), 'the mark left where a name was must be rewritten');
        $entry->setText('Joint changé chez muller.');
        self::assertNotEmpty($stories->problems($entry), 'a name, whatever its case');
        $entry->setText('Joint de la cuisine changé.');
        $entry->addPhoto($this->photo(PhotoPhase::AFTER, 'La cuisine de Léa Muller', true));
        try {
            $stories->publish($entry);
            self::fail('A caption naming a tenant is refused.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('photo 1', $e->getMessage());
        }
        self::assertFalse($entry->isPublished());

        $entry->getPhotos()->first()->setView('La cuisine');
        $stories->publish($entry);
        self::assertTrue($entry->isPublished());
        self::assertStringNotContainsString('Muller', $stories->caption($entry, 'instagram'));
    }

    public function testTheNetworksTextAndItsLink(): void
    {
        $stories = $this->stories();
        $entry = (new StoryEntry($this->home(), 'Cuisine refaite'))->setText(str_repeat('Une phrase sur les travaux de la cuisine. ', 12));
        (new \ReflectionProperty(StoryEntry::class, 'id'))->setValue($entry, 7);

        $link = $stories->url($entry, 'instagram');
        self::assertSame('https://meyers.example/travaux?utm_source=instagram&utm_medium=social&utm_campaign=histoire-7#histoire-7', $link, 'the works page: a home\'s page shows no works');
        $caption = $stories->caption($entry, 'instagram');
        self::assertStringStartsWith("Cuisine refaite — Orangerie, Strasbourg\n\n", $caption);
        self::assertStringEndsWith('Meyers & Associée · '.$link, $caption);
        $text = explode("\n\n", $caption)[1];
        self::assertLessThanOrEqual(281, mb_strlen($text));
        self::assertStringEndsWith('cuisine.', $text, 'cut at a sentence');

        self::assertStringNotContainsString('investis', $caption, 'the amount hidden by default');
        $entry->setAmount(1812000);
        self::assertStringNotContainsString('investis', $stories->caption($entry), 'still hidden: not ticked');
        $entry->setShowAmount(true);
        self::assertStringContainsString("18\u{202F}120\u{00A0}€ investis.", $stories->caption($entry));

    }

    public function testWorksUnderWayTheirStateAndTheirAmount(): void
    {
        $stories = $this->stories();
        $unit = $this->home();
        $facade = (new WorkOrder($unit, 'Ravalement'))->setStatus(WorkOrderStatus::SCHEDULED);
        $roof = (new WorkOrder($unit, 'Gouttières'))->setStatus(WorkOrderStatus::DONE);
        $entry = (new StoryEntry($unit, 'Façade ravalée'))->addWorkOrder($facade)->addWorkOrder($roof);

        self::assertSame(StoryState::DONE, $entry->getState(), 'done unless the team says otherwise');
        self::assertSame(StoryState::IN_PROGRESS, $stories->suggestedState($entry), 'a work order still open');
        $facade->setStatus(WorkOrderStatus::CLOSED);
        self::assertSame(StoryState::DONE, $stories->suggestedState($entry));
        self::assertNull($stories->suggestedState(new StoryEntry($unit, 'Construit en 1932')), 'no works, no suggestion');

        $entry->setState(StoryState::IN_PROGRESS)->setAmount(2450000);
        $entry->addPhoto($before = $this->photo(PhotoPhase::BEFORE, 'La façade', true));
        $entry->addPhoto($during = $this->photo(PhotoPhase::DURING, 'Le pignon', true));
        $entry->addPhoto($this->photo(PhotoPhase::DURING, 'L\'échafaudage', false));
        self::assertTrue($entry->isInProgress());
        self::assertSame([$during], $entry->getPublicPhotosOf(PhotoPhase::DURING), 'progress photos, the cleared ones');
        self::assertSame([$before], $entry->getPublicPhotosOf(PhotoPhase::BEFORE));
        self::assertSame([], $entry->getPairs(true), 'a progress photo pairs with nothing');
        self::assertNull($entry->getPublicAmount(), 'the amount hidden until ticked');
        $entry->setShowAmount(true);
        self::assertSame(2450000, $entry->getPublicAmount());
    }

    public function testTheImagesSizes(): void
    {
        $images = new StoryImages($this->stories(), sys_get_temp_dir().'/estate-story-test');
        $palette = new RGB();
        $before = $images->imagine()->create(new Box(1600, 1200), $palette->color('#7a8a6a'));
        $after = $images->imagine()->create(new Box(900, 1400), $palette->color('#d9d4c7'));

        foreach (StoryImages::FORMATS as $format => [$width, $height]) {
            foreach (StoryImages::LAYOUTS as $layout) {
                $size = $images->compose($before, $after, $layout, $format, 'Cuisine et salle de bains refaites, un titre bien trop long pour la bande', 'Orangerie, Strasbourg · Meyers & Associée')->getSize();
                self::assertSame([$width, $height], [$size->getWidth(), $size->getHeight()], $layout.' '.$format);
            }
        }
        self::assertSame(['square' => [1080, 1080], 'portrait' => [1080, 1350], 'landscape' => [1200, 630]], array_map(fn (array $f) => [$f[0], $f[1]], StoryImages::FORMATS));
    }
}
