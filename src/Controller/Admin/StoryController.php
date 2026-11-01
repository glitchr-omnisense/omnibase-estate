<?php

namespace Base\Estate\Controller\Admin;

use Base\Estate\Entity\StoryEntry;
use Base\Estate\Entity\StoryPhoto;
use Base\Estate\Entity\WorkOrder;
use Base\Estate\Enum\PhotoPhase;
use Base\Estate\Security\Voter\EstateVoter;
use Base\Estate\Service\Stories;
use Base\Estate\Service\StoryImages;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * A story's entry in the back office: its photos (before or after, the view
 * that pairs them, "OK to publish" one by one), publishing it once nothing
 * keeps it from the public, and what goes to the networks - the images made
 * from the cleared pairs, their zip, a suggested text, the link with UTM
 * parameters. An entry is started from finished works too.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/estate')]
class StoryController extends AbstractController
{
    /** The networks a link is tagged for. */
    public const NETWORKS = ['instagram' => 'Instagram', 'facebook' => 'Facebook', 'linkedin' => 'LinkedIn'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Stories $stories,
        private readonly StoryImages $images,
    ) {
    }

    #[Route('/story/{id}', name: 'estate_admin_story', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(StoryEntry $entry): Response
    {
        $this->denyAccessUnlessGranted(EstateVoter::MANAGE, $entry->getOrganisation());

        return $this->render('@Estate/admin/story.html.twig', [
            'entry' => $entry,
            'problems' => $this->stories->problems($entry),
            'pairs' => $entry->getPairs(true),
            'images' => $this->images->images($entry),
            'formats' => StoryImages::FORMATS,
            'captions' => array_map(fn (string $network) => $this->stories->caption($entry, $network), array_combine(array_keys(self::NETWORKS), array_keys(self::NETWORKS))),
            'links' => array_map(fn (string $network) => $this->stories->url($entry, $network), array_combine(array_keys(self::NETWORKS), array_keys(self::NETWORKS))),
            'networks' => self::NETWORKS,
            'phases' => PhotoPhase::cases(),
            'suggested' => $this->stories->suggestedState($entry),
            'workOrders' => $this->entityManager->getRepository(WorkOrder::class)->findBy(['unit' => $entry->getUnit()], ['createdAt' => 'DESC']),
        ]);
    }

    /** Each photo's phase, view, caption, order and "OK to publish", in one form. */
    #[Route('/story/{id}/photos', name: 'estate_admin_story_photos', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function photos(Request $request, StoryEntry $entry): RedirectResponse
    {
        $this->check($request, $entry);
        $rows = $request->request->all('photos');
        foreach ($entry->getPhotos() as $photo) {
            $row = $rows[$photo->getId()] ?? null;
            if (!\is_array($row)) {
                continue;
            }
            if (!empty($row['remove'])) {
                $entry->removePhoto($photo);
                continue;
            }
            $photo->setPhase(PhotoPhase::tryFrom((string) ($row['phase'] ?? '')))
                ->setView(mb_substr(trim((string) ($row['view'] ?? '')), 0, 80) ?: null)
                ->setCaption(mb_substr(trim((string) ($row['caption'] ?? '')), 0, 160) ?: null)
                ->setPosition((int) ($row['position'] ?? $photo->getPosition()))
                ->setPublishable(!empty($row['publishable']));
        }
        $this->entityManager->flush();
        $this->addFlash('success', 'Photos enregistrées.');

        return $this->redirectToRoute('estate_admin_story', ['id' => $entry->getId(), '_fragment' => 'photos']);
    }

    /** A photo the team adds itself: private like the others until cleared. */
    #[Route('/story/{id}/upload', name: 'estate_admin_story_upload', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function upload(Request $request, StoryEntry $entry): RedirectResponse
    {
        $this->check($request, $entry);
        $position = \count($entry->getPhotos());
        foreach ((array) $request->files->get('images', []) as $file) {
            if (!$file instanceof UploadedFile || !$file->isValid() || !str_starts_with((string) $file->getMimeType(), 'image/') || $file->getSize() > 20 * 1024 * 1024) {
                $this->addFlash('error', 'Une photo est une image (JPEG, PNG) de 20 Mo au plus.');
                continue;
            }
            $entry->addPhoto((new StoryPhoto())->setImage($file)->setPhase(PhotoPhase::tryFrom((string) $request->request->get('phase')))
                ->setView(mb_substr(trim((string) $request->request->get('view')), 0, 80) ?: null)->setPosition($position++));
        }
        $this->entityManager->flush();

        return $this->redirectToRoute('estate_admin_story', ['id' => $entry->getId(), '_fragment' => 'photos']);
    }

    #[Route('/story/{id}/publish', name: 'estate_admin_story_publish', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function publish(Request $request, StoryEntry $entry): RedirectResponse
    {
        $this->check($request, $entry);
        try {
            if ($request->request->getBoolean('unpublish')) {
                $this->stories->unpublish($entry);
                $this->addFlash('success', 'L\'entrée n\'est plus publique.');
            } else {
                $this->stories->publish($entry);
                $this->addFlash('success', \count($entry->getPublicPhotos()) === \count($entry->getPhotos())
                    ? 'Entrée publiée.'
                    : sprintf('Entrée publiée, avec %d photo(s) sur %d : les autres restent privées.', \count($entry->getPublicPhotos()), \count($entry->getPhotos())));
            }
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('estate_admin_story', ['id' => $entry->getId()]);
    }

    /** Another work order of the home linked to the entry (its photos come along, private), or one unlinked. */
    #[Route('/story/{id}/works', name: 'estate_admin_story_works', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function works(Request $request, StoryEntry $entry): RedirectResponse
    {
        $this->check($request, $entry);
        $order = $this->entityManager->find(WorkOrder::class, $request->request->getInt('workOrder'));
        if ($order && $request->request->getBoolean('unlink')) {
            $entry->removeWorkOrder($order);
            $this->entityManager->flush();
        } elseif ($order) {
            $this->stories->link($entry, $order);
        }

        return $this->redirectToRoute('estate_admin_story', ['id' => $entry->getId(), '_fragment' => 'works']);
    }

    /** One ready-to-post image (a JPEG). */
    #[Route('/story/{id}/image/{pair}/{layout}/{format}.jpg', name: 'estate_admin_story_image', requirements: ['id' => '\d+', 'pair' => '\d+', 'layout' => 'pair|before|after', 'format' => 'square|portrait|landscape'], methods: ['GET'])]
    public function image(StoryEntry $entry, int $pair, string $layout, string $format): BinaryFileResponse
    {
        $this->denyAccessUnlessGranted(EstateVoter::MANAGE, $entry->getOrganisation());
        try {
            $path = $this->images->render($entry, $pair, $layout, $format);
        } catch (\InvalidArgumentException) {
            throw $this->createNotFoundException();
        }

        return (new BinaryFileResponse($path, 200, ['Content-Type' => 'image/jpeg']))->setPrivate();
    }

    /** Every image and the suggested text, zipped. */
    #[Route('/story/{id}/images.zip', name: 'estate_admin_story_zip', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function zip(StoryEntry $entry): Response
    {
        $this->denyAccessUnlessGranted(EstateVoter::MANAGE, $entry->getOrganisation());
        $path = $this->images->zip($entry);
        if (null === $path) {
            $this->addFlash('error', 'Aucune paire avant/après n\'est autorisée à la publication : rien à télécharger.');

            return $this->redirectToRoute('estate_admin_story', ['id' => $entry->getId()]);
        }
        $name = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower((string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $entry->getUnit()->getName().' '.$entry->getTitle()))), '-');

        return (new BinaryFileResponse($path, 200, ['Content-Type' => 'application/zip']))
            ->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, ($name ?: 'histoire').'.zip')
            ->deleteFileAfterSend()->setPrivate();
    }

    /** Works into the home's story, under way or done: the entry made once, then opened. */
    #[Route('/work-order/{id}/story', name: 'estate_admin_story_from_work', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function fromWork(Request $request, WorkOrder $order): RedirectResponse
    {
        $this->denyAccessUnlessGranted(EstateVoter::MANAGE, $order->getOrganisation());
        if (!$this->isCsrfTokenValid('estate_story_'.$order->getId(), (string) $request->query->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $entry = $this->stories->fromWorkOrder($order);

        return $this->redirectToRoute('estate_admin_story', ['id' => $entry->getId()]);
    }

    private function check(Request $request, StoryEntry $entry): void
    {
        $this->denyAccessUnlessGranted(EstateVoter::MANAGE, $entry->getOrganisation());
        if (!$this->isCsrfTokenValid('estate_story_entry_'.$entry->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
    }
}
