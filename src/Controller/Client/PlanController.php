<?php

namespace Base\Estate\Controller\Client;

use Base\Estate\Entity\Unit;
use Base\Estate\Model\FloorPlan;
use Base\Estate\Security\Voter\EstateVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A home's plan for those who may see it (EstateVoter::UNIT_PLAN): its
 * tenants while their lease runs, its associates, co-owners and owners, the
 * team - on its own page and as a printable A4 sheet. Private: a visitor or
 * anyone else gets a 404, as for a home without a plan.
 */
#[Route('/plan/{id}', requirements: ['id' => '\d+'])]
class PlanController extends AbstractController
{
    #[Route('', name: 'estate_unit_plan')]
    public function show(Unit $unit): Response
    {
        $this->check($unit);

        return $this->render('@Estate/client/plan.html.twig', ['unit' => $unit, 'plan' => FloorPlan::of($unit)]);
    }

    #[Route('/imprimer', name: 'estate_unit_plan_print')]
    public function print(Unit $unit): Response
    {
        $this->check($unit);

        return $this->render('@Estate/client/plan_print.html.twig', ['unit' => $unit, 'plan' => FloorPlan::of($unit)]);
    }

    /** Someone else's home answers as a home without a plan: not found - what exists is not told. */
    private function check(Unit $unit): void
    {
        if (!$this->isGranted(EstateVoter::UNIT_PLAN, $unit) || !$unit->hasPlan()) {
            throw $this->createNotFoundException('No plan here.');
        }
    }
}
