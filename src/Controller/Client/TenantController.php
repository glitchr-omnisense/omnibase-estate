<?php

namespace Base\Estate\Controller\Client;

use Base\Entity\User;
use Base\Estate\Entity\Lease;
use Base\Estate\Entity\RentCall;
use Base\Estate\Form\InsuranceType;
use Base\Estate\Form\MessageType;
use Base\Estate\Form\ReportType;
use Base\Estate\Repository\DocumentRepository;
use Base\Estate\Repository\RentCallRepository;
use Base\Estate\Repository\WorkOrderRepository;
use Base\Estate\Security\Voter\EstateVoter;
use Base\Estate\Service\Documents;
use Base\Estate\Service\Messages;
use Base\Estate\Service\OnlinePayments;
use Base\Estate\Service\Receipts;
use Base\Estate\Service\Spaces;
use Base\Estate\Service\WorkOrders;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The tenant's space: their lease, its rents (to pay online, their notices
 * and receipts to download, a certificate of rent up to date), the lease's
 * documents and their insurance certificate, their messages with the
 * management, the problems they reported and a form to report one, the
 * notices of their building.
 */
#[IsGranted('ROLE_USER')]
#[Route('/locataire')]
class TenantController extends AbstractController
{
    public function __construct(
        private readonly Spaces $spaces,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'estate_tenant', methods: ['GET', 'POST'])]
    public function index(Request $request, WorkOrderRepository $workOrders, RentCallRepository $calls, WorkOrders $orders, OnlinePayments $online, DocumentRepository $documentRepository, Documents $documents, Messages $messages): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $leases = $this->spaces->tenancies($user);
        if (!$leases) {
            throw $this->createAccessDeniedException('Not a tenant.');
        }
        $lease = $this->pick($leases, $request->query->getInt('bail'));

        $form = $this->createForm(ReportType::class);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->denyAccessUnlessGranted(EstateVoter::UNIT_REPORT, $lease->getUnit());
            $data = $form->getData();
            $order = $orders->report($lease->getUnit(), $user, $this->partyName($lease, $user), $data['title'], $data['description'], $data['category'], $data['urgency']);
            $this->addFlash('success', 'tenant.reported');

            return $this->redirectToRoute('estate_tenant', ['bail' => $lease->getId(), '_fragment' => 'work-'.$order->getId()]);
        }

        $insurance = $this->container->get('form.factory')->createNamed('insurance', InsuranceType::class);
        $insurance->handleRequest($request);
        if ($insurance->isSubmitted() && $insurance->isValid()) {
            $this->denyAccessUnlessGranted(EstateVoter::LEASE_VIEW, $lease);
            $documents->insurance($lease, $insurance->get('file')->getData(), $insurance->get('until')->getData(), $user, $this->partyName($lease, $user));
            $this->addFlash('success', 'tenant.insurance_received');

            return $this->redirectToRoute('estate_tenant', ['bail' => $lease->getId(), '_fragment' => 'documents']);
        }

        $message = $this->container->get('form.factory')->createNamed('message', MessageType::class);
        $message->handleRequest($request);
        if ($message->isSubmitted() && $message->isValid()) {
            $this->denyAccessUnlessGranted(EstateVoter::LEASE_VIEW, $lease);
            $messages->post($lease, $message->get('body')->getData(), false, $user, $this->partyName($lease, $user));
            $this->addFlash('success', 'tenant.message_sent');

            return $this->redirectToRoute('estate_tenant', ['bail' => $lease->getId(), '_fragment' => 'messages']);
        }
        $invalid = ($form->isSubmitted() && !$form->isValid()) || ($insurance->isSubmitted() && !$insurance->isValid()) || ($message->isSubmitted() && !$message->isValid());

        $notices = $this->entityManager->createQueryBuilder()->select('n')->from(\Base\Estate\Entity\Notice::class, 'n')
            ->where('n.organisation = :organisation')->setParameter('organisation', $lease->getOrganisation())
            ->andWhere('n.building IS NULL OR n.building = :building')->setParameter('building', $lease->getUnit()->getBuilding())
            ->andWhere('n.sentAt IS NOT NULL')->orderBy('n.sentAt', 'DESC')->setMaxResults(5)
            ->getQuery()->getResult();

        return $this->render('@Estate/client/tenant.html.twig', [
            'leases' => $leases,
            'lease' => $lease,
            'calls' => $calls->findBy(['lease' => $lease], ['period' => 'DESC']),
            'workOrders' => $workOrders->findBy(['unit' => $lease->getUnit()], ['createdAt' => 'DESC']),
            'notices' => $notices,
            'form' => $form,
            'insurance' => $insurance,
            'message' => $message,
            'documents' => $documentRepository->forTenants($lease),
            'thread' => $messages->read($lease, false),
            'online' => $online->isAvailable(),
            'methods' => $online->methods(),
        ], new Response(null, $invalid ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/loyer/{id}/payer', name: 'estate_rent_pay', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function pay(Request $request, RentCall $call, OnlinePayments $online): Response
    {
        $this->denyAccessUnlessGranted(EstateVoter::RENT_VIEW, $call);
        if (!$this->isCsrfTokenValid('rent_pay_'.$call->getId(), (string) $request->request->get('_token')) || $call->isPaid() || !$online->isAvailable()) {
            return $this->redirectToRoute('estate_tenant');
        }

        try {
            return $this->redirect($online->start($call, (string) $request->request->get('method'),
                $this->generateUrl('estate_rent_paid', ['id' => $call->getId()], 0),
                $this->generateUrl('estate_tenant', ['bail' => $call->getLease()->getId()], 0),
                $this->getUser()?->getEmail()));
        } catch (\Throwable $e) {
            $this->addFlash('error', 'tenant.payment_unavailable');

            return $this->redirectToRoute('estate_tenant', ['bail' => $call->getLease()->getId()]);
        }
    }

    #[Route('/loyer/{id}/retour', name: 'estate_rent_paid', requirements: ['id' => '\d+'])]
    public function paid(Request $request, RentCall $call, OnlinePayments $online): Response
    {
        $this->denyAccessUnlessGranted(EstateVoter::RENT_VIEW, $call);
        if ($session = $request->query->get('session')) {
            try {
                $this->addFlash($online->complete($call, (string) $session) ? 'success' : 'info', $call->isPaid() ? 'tenant.paid' : 'tenant.payment_pending');
            } catch (\Throwable) {
                $this->addFlash('error', 'tenant.payment_unavailable');
            }
        }

        return $this->redirectToRoute('estate_tenant', ['bail' => $call->getLease()->getId()]);
    }

    /** The notice (avis d'échéance) or, paid, the receipt (quittance): a PDF, or the printable page without dompdf. */
    #[Route('/loyer/{id}/{kind}', name: 'estate_rent_document', requirements: ['id' => '\d+', 'kind' => 'avis|quittance'])]
    public function document(RentCall $call, string $kind, Receipts $receipts): Response
    {
        $this->denyAccessUnlessGranted(EstateVoter::RENT_VIEW, $call);
        $kind = 'quittance' === $kind ? 'receipt' : 'notice';
        if ('receipt' === $kind && !$call->isPaid()) {
            throw $this->createNotFoundException('Not paid yet: no receipt.');
        }
        $name = ('receipt' === $kind ? $call->getReceiptNumber() : $call->getReference()).'.pdf';
        if ($pdf = $receipts->pdf($call, $kind)) {
            return new Response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$name.'"']);
        }

        return new Response($receipts->html($call, $kind));
    }

    /** The certificate of rent up to date: only when nothing is owed today. */
    #[Route('/bail/{id}/attestation', name: 'estate_tenant_attestation', requirements: ['id' => '\d+'])]
    public function attestation(Lease $lease, Receipts $receipts, RentCallRepository $calls): Response
    {
        $this->denyAccessUnlessGranted(EstateVoter::LEASE_VIEW, $lease);
        $today = new \DateTimeImmutable('today');
        foreach ($calls->findBy(['lease' => $lease]) as $call) {
            if (!$call->isPaid() && $call->getDueOn() < $today) {
                $this->addFlash('error', 'tenant.attestation_unavailable');

                return $this->redirectToRoute('estate_tenant', ['bail' => $lease->getId()]);
            }
        }
        if ($pdf = $receipts->attestationPdf($lease, $today)) {
            return new Response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="attestation-'.$lease->getReference().'.pdf"']);
        }

        return new Response($receipts->attestationHtml($lease, $today));
    }

    /** @param list<Lease> $leases */
    private function pick(array $leases, int $id): Lease
    {
        foreach ($leases as $lease) {
            if ($lease->getId() === $id) {
                return $lease;
            }
        }

        return $leases[0];
    }

    private function partyName(Lease $lease, User $user): string
    {
        foreach ($lease->getParties() as $party) {
            if ($party->getUser() === $user) {
                return $party->getName();
            }
        }

        return (string) $user;
    }
}
