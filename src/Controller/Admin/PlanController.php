<?php

namespace Base\Estate\Controller\Admin;

use Base\Estate\Entity\Unit;
use Base\Estate\Enum\RoomKind;
use Base\Estate\Security\Voter\EstateVoter;
use Base\Estate\Service\Plans;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * A home's plan in the back office: the editor (rooms drawn on a 10 cm grid,
 * named, measured; an architect's plan traced over), saved as JSON; the
 * background uploaded, streamed to the editor, removed.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/estate/unit/{id}/plan', requirements: ['id' => '\d+'])]
class PlanController extends AbstractController
{
    public function __construct(private readonly Plans $plans)
    {
    }

    #[Route('', name: 'estate_admin_plan', methods: ['GET'])]
    public function edit(Unit $unit): Response
    {
        $this->denyAccessUnlessGranted(EstateVoter::MANAGE, $unit->getOrganisation());

        return $this->render('@Estate/admin/plan.html.twig', [
            'unit' => $unit,
            'plan' => $this->plans->toArray($unit),
            'kinds' => RoomKind::cases(),
            'readsPdf' => Plans::readsPdf(),
        ]);
    }

    #[Route('', name: 'estate_admin_plan_save', methods: ['POST'])]
    public function save(Request $request, Unit $unit): JsonResponse
    {
        $this->denyAccessUnlessGranted(EstateVoter::MANAGE, $unit->getOrganisation());
        if (!$this->isCsrfTokenValid('estate_plan_'.$unit->getId(), (string) $request->headers->get('X-CSRF-Token'))) {
            return new JsonResponse(['error' => 'La page a expiré : rechargez-la.'], Response::HTTP_FORBIDDEN);
        }
        try {
            $plan = json_decode($request->getContent(), true, 16, \JSON_THROW_ON_ERROR);
            $this->plans->save($unit, \is_array($plan) ? $plan : []);
        } catch (\JsonException) {
            return new JsonResponse(['error' => 'Le plan envoyé est illisible.'], Response::HTTP_BAD_REQUEST);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse($this->plans->toArray($unit));
    }

    #[Route('/background', name: 'estate_admin_plan_background', methods: ['POST'])]
    public function background(Request $request, Unit $unit): RedirectResponse
    {
        $this->denyAccessUnlessGranted(EstateVoter::MANAGE, $unit->getOrganisation());
        if (!$this->isCsrfTokenValid('estate_plan_'.$unit->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        if ($request->request->getBoolean('remove')) {
            $this->plans->removeBackground($unit);
            $this->addFlash('success', 'Plan d\'architecte retiré.');

            return $this->redirectToRoute('estate_admin_plan', ['id' => $unit->getId()]);
        }
        $file = $request->files->get('background');
        if (!$file instanceof UploadedFile || !$file->isValid() || $file->getSize() > 20 * 1024 * 1024) {
            $this->addFlash('error', 'Choisissez une image ou un PDF de 20 Mo au plus.');

            return $this->redirectToRoute('estate_admin_plan', ['id' => $unit->getId()]);
        }
        try {
            $this->plans->background($unit, $file);
            $this->addFlash('success', 'Plan d\'architecte ajouté : donnez-lui son échelle (deux points et une longueur connue).');
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('estate_admin_plan', ['id' => $unit->getId()]);
    }

    #[Route('/background/image', name: 'estate_admin_plan_background_image', methods: ['GET'])]
    public function backgroundImage(Unit $unit): BinaryFileResponse
    {
        $this->denyAccessUnlessGranted(EstateVoter::MANAGE, $unit->getOrganisation());
        $file = $unit->getPlanBackgroundFile();
        if (!$file instanceof File || !$file->isFile()) {
            throw $this->createNotFoundException();
        }

        return (new BinaryFileResponse($file, 200, ['Content-Type' => $file->getMimeType() ?? 'image/png']))->setPrivate();
    }
}
