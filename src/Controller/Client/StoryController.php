<?php

namespace Base\Estate\Controller\Client;

use Base\Estate\Repository\StoryEntryRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The works: what the company invests in its homes - the works under way first (each home, what is
 * done, since when, until when, a photo before and the progress), then the works done with their
 * before/after, each leading to its home's story. Only entries the team published, only the photos
 * it cleared, an amount only when it ticked it.
 */
class StoryController extends AbstractController
{
    #[Route('/travaux', name: 'estate_works')]
    public function index(StoryEntryRepository $entries): Response
    {
        return $this->render('@Estate/client/works.html.twig', [
            'inProgress' => $entries->findInProgress(),
            'done' => $entries->findDoneWorks(24),
        ]);
    }
}
