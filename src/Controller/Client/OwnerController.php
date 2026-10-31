<?php

namespace Base\Estate\Controller\Client;

use Base\Entity\User;
use Base\Estate\Entity\Lease;
use Base\Estate\Entity\Mandate;
use Base\Estate\Entity\MandatePayout;
use Base\Estate\Model\Period;
use Base\Estate\Security\Voter\EstateVoter;
use Base\Estate\Service\Management;
use Base\Estate\Service\Spaces;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The owner's space: the homes the agency manages for them - each one's
 * lease and tenant, what was called and collected -, where their account
 * stands and what the next payout would be, their statements (comptes
 * rendus de gérance) by period as PDFs, their payouts, and their cloud
 * folder. Read-only. Only while the site runs the `management` activity.
 */
#[IsGranted('ROLE_USER')]
#[Route('/proprietaire')]
class OwnerController extends AbstractController
{
    public function __construct(
        private readonly Spaces $spaces,
        private readonly Management $management,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'estate_owner')]
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $mandates = $this->spaces->mandates($user);
        if (!$mandates) {
            throw $this->createAccessDeniedException('Not an owner under management.');
        }
        $mandate = $mandates[0];
        foreach ($mandates as $candidate) {
            if ($candidate->getId() === $request->query->getInt('mandat')) {
                $mandate = $candidate;
            }
        }
        $today = new \DateTimeImmutable('today');
        [$yearStart] = Period::parse($today->format('Y'));
        $statement = $this->management->statement($mandate, max($yearStart, $mandate->getStartsOn()), $today);
        $leases = [];
        foreach ($mandate->getUnits() as $unit) {
            $leases[$unit->getId()] = $this->entityManager->getRepository(Lease::class)->findBy(['unit' => $unit], ['startOn' => 'DESC'], 1)[0] ?? null;
        }
        $frequency = $mandate->getFrequency();

        return $this->render('@Estate/client/owner.html.twig', [
            'mandates' => $mandates,
            'mandate' => $mandate,
            'mandator' => $mandate->getMandator(),
            'statement' => $statement,
            'available' => $this->management->available($mandate, $today),
            'leases' => $leases,
            'payouts' => $this->entityManager->getRepository(MandatePayout::class)->findBy(['mandate' => $mandate], ['issuedOn' => 'DESC', 'id' => 'DESC'], 12),
            'periods' => array_values(array_filter(
                array_map(fn (string $key) => ['key' => $key] + array_combine(['from', 'to'], Period::parse($key)), [...$frequency->recent($today, 6), $today->modify('-1 year')->format('Y')]),
                fn (array $p) => $mandate->overlaps($p['from'], $p['to']),
            )),
            'folder' => $this->management->hasCloud() ? $mandate->getMandator()->getFolder() : null,
        ]);
    }

    /** Their statement for a period: a PDF, or the printable page without dompdf. */
    #[Route('/{id}/compte-rendu/{period}', name: 'estate_owner_statement', requirements: ['id' => '\d+', 'period' => '\d{4}(-(\d{2}|[QT][1-4]))?'])]
    public function statement(Mandate $mandate, string $period): Response
    {
        $this->denyAccessUnlessGranted(EstateVoter::MANDATE_VIEW, $mandate);
        if (!$this->management->isActive()) {
            throw $this->createNotFoundException('The site does not run the management activity.');
        }
        [$from, $to] = Period::parse($period);
        $statement = $this->management->statement($mandate, $from, $to);
        if ($pdf = $this->management->statementPdf($statement)) {
            return new Response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$this->management->statementFilename($statement).'"']);
        }

        return new Response($this->management->statementHtml($statement));
    }
}
