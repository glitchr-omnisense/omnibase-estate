<?php

namespace Base\Estate\Service;

use Base\Database\Attribute\Uploader;
use Base\Estate\Entity\Document;
use Base\Estate\Entity\LeaseParty;
use Base\Estate\Entity\StoryEntry;
use Base\Estate\Entity\StoryPhoto;
use Base\Estate\Entity\Unit;
use Base\Estate\Entity\WorkOrder;
use Base\Estate\Enum\ActivityKind;
use Base\Estate\Enum\DocumentKind;
use Base\Estate\Enum\StoryState;
use Base\Estate\Enum\WorkOrderStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The homes' stories: an entry started from works (its title, the
 * contractor's report, the before/after photos, in progress or done), published by the team once
 * it is fit for the public - a title, no tenant's name anywhere -, and what
 * goes with it to the networks: a suggested text and the public link with
 * UTM parameters. Only the photos the team cleared are ever public.
 */
class Stories
{
    /** What replaces a tenant's name in a text taken from the works: the team rewrites it before publishing. */
    public const REMOVED = '[nom retiré]';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UrlGeneratorInterface $urls,
        private readonly ?ActivityLog $activities = null,
    ) {
    }

    /**
     * The entry telling works: made once (the same entry afterwards, found through its work
     * orders), in progress or done as the works stand (StoryState, the team decides after), started
     * the day they were reported or visited, dated the day they were closed; its text the
     * contractor's report with the tenants' names taken out, its amount the invoice (else the
     * quote) - hidden until the team ticks it -, its photos the work order's, each one private
     * until the team clears it.
     */
    public function fromWorkOrder(WorkOrder $order): StoryEntry
    {
        $entry = $this->entityManager->getRepository(StoryEntry::class)->findOneByWorkOrder($order);
        if ($entry) {
            return $entry;
        }

        $names = $this->tenantNames($order->getUnit());
        $done = \in_array($order->getStatus(), [WorkOrderStatus::DONE, WorkOrderStatus::CLOSED], true);
        $entry = (new StoryEntry($order->getUnit(), $this->scrub($order->getTitle(), $names), $order->getClosedAt() ?? new \DateTimeImmutable('today')))
            ->addWorkOrder($order)
            ->setState($done ? StoryState::DONE : StoryState::IN_PROGRESS)
            ->setStartedOn(($order->getVisitAt() ?? $order->getCreatedAt())->setTime(0, 0))
            ->setAmount($order->getInvoiceAmount() ?? $order->getQuoteAmount())
            ->setText($this->scrub((string) ($order->getReport() ?: $order->getDescription()), $names));
        $this->entityManager->persist($entry);
        $this->addPhotos($entry, $order);
        $this->entityManager->flush();

        return $entry;
    }

    /** Another work order into an entry: its photos come along, private. */
    public function link(StoryEntry $entry, WorkOrder $order): void
    {
        if ($order->getUnit()->getId() !== $entry->getUnit()->getId() || $entry->getWorkOrders()->contains($order)) {
            return;
        }
        $entry->addWorkOrder($order);
        $this->addPhotos($entry, $order);
        $this->entityManager->flush();
    }

    /** What the linked work orders suggest: done when every one is done or closed, in progress otherwise. */
    public function suggestedState(StoryEntry $entry): ?StoryState
    {
        if ($entry->getWorkOrders()->isEmpty()) {
            return null;
        }
        foreach ($entry->getWorkOrders() as $order) {
            if (!\in_array($order->getStatus(), [WorkOrderStatus::DONE, WorkOrderStatus::CLOSED, WorkOrderStatus::CANCELLED], true)) {
                return StoryState::IN_PROGRESS;
            }
        }

        return StoryState::DONE;
    }

    private function addPhotos(StoryEntry $entry, WorkOrder $order): void
    {
        $known = array_filter(array_map(fn (StoryPhoto $p) => $p->getDocumentId(), $entry->getPhotos()->toArray()));
        $documents = $this->entityManager->getRepository(Document::class)->findBy(['workOrder' => $order, 'kind' => DocumentKind::PHOTO], ['createdAt' => 'ASC', 'id' => 'ASC']);
        $position = \count($entry->getPhotos());
        foreach ($documents as $document) {
            $file = Uploader::get($document, 'file');
            if (\in_array($document->getId(), $known, true) || !$file instanceof \SplFileInfo || !$file->isFile() || !str_starts_with((string) mime_content_type($file->getPathname()), 'image/')) {
                continue;
            }
            // The uploader moves what it is given: a copy, the document keeps its own.
            $copy = tempnam(sys_get_temp_dir(), 'story').'.'.(pathinfo($file->getFilename(), \PATHINFO_EXTENSION) ?: 'jpg');
            copy($file->getPathname(), $copy);
            $entry->addPhoto((new StoryPhoto())->setImage(new File($copy))->setPhase($document->getPhase())->setView($document->getView())
                ->setDocumentId($document->getId())->setPosition($position++));
        }
    }

    /**
     * What keeps an entry from the public: no title, a tenant's name (or the mark left where one was
     * taken out) in its title, text or a photo's caption or view.
     *
     * @return list<string>
     */
    public function problems(StoryEntry $entry): array
    {
        $problems = [];
        if ('' === trim($entry->getTitle())) {
            $problems[] = 'Donnez-lui un titre.';
        }
        $names = $this->tenantNames($entry->getUnit());
        $texts = ['le titre' => $entry->getTitle(), 'le texte' => (string) $entry->getText()];
        foreach ($entry->getPhotos() as $i => $photo) {
            $texts[sprintf('la photo %d', $i + 1)] = trim($photo->getCaption().' '.$photo->getView());
        }
        foreach ($texts as $where => $text) {
            if (str_contains($text, self::REMOVED)) {
                $problems[] = sprintf('Réécrivez %s : un nom en a été retiré.', $where);
            } elseif (null !== $name = $this->nameIn($text, $names)) {
                $problems[] = sprintf('%s nomme un locataire (%s) : l\'histoire ne nomme personne.', ucfirst($where), $name);
            }
        }

        return $problems;
    }

    /** @throws \InvalidArgumentException with the problems, when there are */
    public function publish(StoryEntry $entry): void
    {
        if ($problems = $this->problems($entry)) {
            throw new \InvalidArgumentException(implode(' ', $problems));
        }
        $entry->setPublished(true);
        $this->activities?->log($entry->getOrganisation(), ActivityKind::OTHER, sprintf('Histoire de %s : « %s » publiée', $entry->getUnit()->getName(), $entry->getTitle()), $entry->getUnit());
        $this->entityManager->flush();
    }

    public function unpublish(StoryEntry $entry): void
    {
        $entry->setPublished(false);
        $this->entityManager->flush();
    }

    /**
     * The names of everyone who lived there or signed for it (every lease's parties, guarantors
     * too): first and last names, a surname alone when three letters or more.
     *
     * @return list<string>
     */
    public function tenantNames(Unit $unit): array
    {
        $names = [];
        foreach ($unit->getLeases() as $lease) {
            foreach ($lease->getParties() as $party) {
                /** @var LeaseParty $party */
                $names[] = trim($party->getFirstName().' '.$party->getLastName());
                $names[] = trim($party->getLastName());
            }
        }

        return array_values(array_unique(array_filter($names, fn (string $name) => mb_strlen($name) >= 3)));
    }

    /** The text with every tenant's name replaced by Stories::REMOVED. */
    public function scrub(string $text, array $names): string
    {
        usort($names, fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));
        foreach ($names as $name) {
            $text = (string) preg_replace('/(?<![\pL])'.preg_quote($name, '/').'(?![\pL])/iu', self::REMOVED, $text);
        }

        return $text;
    }

    private function nameIn(string $text, array $names): ?string
    {
        foreach ($names as $name) {
            if (preg_match('/(?<![\pL])'.preg_quote($name, '/').'(?![\pL])/iu', $text)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Where an entry is read: the works page (a home's page shows no works), at the entry; with the UTM parameters of a network when one is named.
     */
    public function url(StoryEntry $entry, ?string $network = null): string
    {
        $query = null === $network ? [] : ['utm_source' => $network, 'utm_medium' => 'social', 'utm_campaign' => 'histoire-'.$entry->getId()];
        $url = $this->urls->generate('estate_works', $query, UrlGeneratorInterface::ABSOLUTE_URL);

        return $url.'#histoire-'.$entry->getId();
    }

    /** Where the home is, as the public may read it: its neighbourhood and city, never its address. */
    public function place(StoryEntry $entry): string
    {
        return implode(', ', array_filter([$entry->getUnit()->getDistrict(), $entry->getUnit()->getCity()]));
    }

    /**
     * A text to post with the images: the title, where, what was done (the entry's text, cut at a
     * sentence near 280 characters), the amount invested when the team shows it, the link with the
     * network's UTM parameters.
     */
    public function caption(StoryEntry $entry, ?string $network = null): string
    {
        $text = trim((string) $entry->getText());
        if (mb_strlen($text) > 280) {
            $cut = mb_substr($text, 0, 280);
            $end = max((int) mb_strrpos($cut, '. '), (int) mb_strrpos($cut, ".\n"));
            $text = $end > 120 ? mb_substr($cut, 0, $end + 1) : rtrim(mb_substr($cut, 0, (int) mb_strrpos($cut, ' '))).'…';
        }
        $place = $this->place($entry);

        $amount = $entry->getPublicAmount();

        return implode("\n\n", array_filter([
            $entry->getTitle().($place ? ' — '.$place : ''),
            $text,
            null === $amount ? null : str_replace(' ', "\u{202F}", number_format($amount / 100, 0, ',', ' '))."\u{00A0}€ investis.",
            $entry->getOrganisation()->getName().' · '.$this->url($entry, $network),
        ]));
    }
}
