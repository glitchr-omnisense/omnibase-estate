<?php

namespace Base\Estate\Controller\Client;

use Base\Entity\User;
use Base\Estate\Entity\WorkOrder;
use Base\Estate\Form\ContractorUpdateType;
use Base\Estate\Form\WorkFileType;
use Base\Estate\Repository\DocumentRepository;
use Base\Estate\Service\Documents;
use Base\Estate\Repository\WorkOrderRepository;
use Base\Estate\Security\Voter\EstateVoter;
use Base\Estate\Service\Spaces;
use Base\Estate\Service\WorkOrders;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The contractor's space: the jobs given to them, and for each the address,
 * how to get in, who lives there, the timeline - and a form to say when they
 * come, what it costs, what they did.
 */
#[IsGranted('ROLE_USER')]
#[Route('/artisan')]
class ContractorController extends AbstractController
{
    public function __construct(private readonly Spaces $spaces)
    {
    }

    #[Route('', name: 'estate_contractor')]
    public function index(WorkOrderRepository $orders): Response
    {
        $contractorships = $this->spaces->contractorships($this->getUser());
        if (!$contractorships) {
            throw $this->createAccessDeniedException('Not a contractor.');
        }

        return $this->render('@Estate/client/contractor.html.twig', [
            'contractors' => $contractorships,
            'orders' => $orders->findForContractors($contractorships),
        ]);
    }

    #[Route('/intervention/{id}', name: 'estate_work_order', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function show(Request $request, WorkOrder $order, WorkOrders $orders, Documents $documents, DocumentRepository $documentRepository): Response
    {
        $this->denyAccessUnlessGranted(EstateVoter::WORK_DO, $order);
        /** @var User $user */
        $user = $this->getUser();
        $form = $this->createForm(ContractorUpdateType::class, ['visitAt' => $order->getVisitAt(), 'quote' => $order->getQuoteAmount(), 'invoice' => $order->getInvoiceAmount(), 'invoiceNumber' => $order->getInvoiceNumber()]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $orders->update($order, $user, $order->getContractor()->getCompany(), $data['visitAt'], $data['quote'], $data['report'] ?: null, (bool) $data['done'],
                $data['invoice'] !== $order->getInvoiceAmount() ? $data['invoice'] : null, $data['invoiceNumber']);
            $this->addFlash('success', 'contractor.saved');

            return $this->redirectToRoute('estate_work_order', ['id' => $order->getId()]);
        }

        $file = $this->container->get('form.factory')->createNamed('file', WorkFileType::class);
        $file->handleRequest($request);
        if ($file->isSubmitted() && $file->isValid()) {
            $documents->work($order, $file->get('kind')->getData(), $file->get('file')->getData(), $file->get('title')->getData(), $user, $order->getContractor()->getCompany());
            $this->addFlash('success', 'contractor.file_added');

            return $this->redirectToRoute('estate_work_order', ['id' => $order->getId(), '_fragment' => 'files']);
        }

        return $this->render('@Estate/client/work_order.html.twig', [
            'order' => $order,
            'lease' => $order->getUnit()->getCurrentLease(),
            'form' => $form,
            'file' => $file,
            'files' => $documentRepository->forWorkOrder($order),
        ], new Response(null, ($form->isSubmitted() && !$form->isValid()) || ($file->isSubmitted() && !$file->isValid()) ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
