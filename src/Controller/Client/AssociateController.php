<?php

namespace Base\Estate\Controller\Client;

use Base\Entity\User;
use Base\Estate\Entity\Associate;
use Base\Estate\Entity\Deadline;
use Base\Estate\Ledger\CompanyAccounts;
use Base\Estate\Repository\ActivityRepository;
use Base\Estate\Repository\UnitRepository;
use Base\Estate\Service\Figures;
use Base\Estate\Service\Spaces;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The associates' space: the company's activity as it happens - what is
 * let and what is free, rents called and received, what is late, works and
 * their cost, the next deadlines, the day-to-day, the documents - and, with
 * omnibase/ledger, its accounts: the year's result and each one's share, the
 * bank. Read-only.
 */
#[IsGranted('ROLE_USER')]
#[Route('/associes')]
class AssociateController extends AbstractController
{
    public function __construct(
        private readonly Spaces $spaces,
        #[Autowire('%estate.documents_route%')] private readonly ?string $documentsRoute = null,
    ) {
    }

    #[Route('', name: 'estate_associate')]
    public function index(Request $request, Figures $figures, ActivityRepository $activities, UnitRepository $units, EntityManagerInterface $entityManager, ?CompanyAccounts $accounts = null): Response
    {
        $associate = $this->associate($request);
        $organisation = $associate->getOrganisation();
        $deadlines = $entityManager->getRepository(Deadline::class)->findBy(['organisation' => $organisation]);
        usort($deadlines, fn (Deadline $a, Deadline $b) => $a->next() <=> $b->next());
        $associates = $entityManager->getRepository(Associate::class)->findBy(['organisation' => $organisation], ['shares' => 'DESC']);

        return $this->render('@Estate/client/associate.html.twig', [
            'associate' => $associate,
            'associateships' => $this->spaces->associateships($this->getUser()),
            'organisation' => $organisation,
            'figures' => $figures->of($organisation),
            'accounts' => $accounts?->of($organisation, $associates),
            'activities' => $activities->latest($organisation, 15),
            'units' => $units->findByOrganisation($organisation),
            'deadlines' => \array_slice($deadlines, 0, 6),
            'associates' => $associates,
            'shares' => array_sum(array_map(fn (Associate $a) => $a->getShares(), $associates)),
            'documentsRoute' => $this->documentsRoute,
        ]);
    }

    private function associate(Request $request): Associate
    {
        /** @var User $user */
        $user = $this->getUser();
        $associateships = $this->spaces->associateships($user);
        if (!$associateships) {
            throw $this->createAccessDeniedException('Not an associate.');
        }
        foreach ($associateships as $associate) {
            if ($associate->getOrganisation()->getSlug() === $request->query->get('societe')) {
                return $associate;
            }
        }

        return $associateships[0];
    }
}
