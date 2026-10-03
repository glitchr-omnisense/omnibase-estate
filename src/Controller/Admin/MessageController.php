<?php

namespace Base\Estate\Controller\Admin;

use Base\Entity\User;
use Base\Estate\Entity\Lease;
use Base\Estate\Entity\Organisation;
use Base\Estate\Form\MessageType;
use Base\Estate\Repository\MessageRepository;
use Base\Estate\Service\Messages;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The management's side of the tenants' messages: every lease's thread, the latest
 * first with its unread count, and a thread to read and answer - the answer is
 * e-mailed to the lease's tenants and shown in their space.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/estate/messages')]
class MessageController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    #[Route('', name: 'estate_admin_messages')]
    public function index(MessageRepository $messages): Response
    {
        $threads = [];
        foreach ($this->entityManager->getRepository(Organisation::class)->findBy([], ['name' => 'ASC']) as $organisation) {
            foreach ($messages->threads($organisation) as $thread) {
                $threads[] = $thread;
            }
        }
        usort($threads, fn ($a, $b) => $b['last']->getCreatedAt() <=> $a['last']->getCreatedAt());

        return $this->render('@Estate/admin/messages.html.twig', ['threads' => $threads]);
    }

    #[Route('/{id}', name: 'estate_admin_messages_thread', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function thread(Request $request, Lease $lease, Messages $messages): Response
    {
        $form = $this->createForm(MessageType::class);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var User $user */
            $user = $this->getUser();
            $messages->post($lease, $form->get('body')->getData(), true, $user, $lease->getOrganisation()->getName());
            $this->addFlash('success', 'Réponse envoyée à '.$lease->getTenantNames().'.');

            return $this->redirectToRoute('estate_admin_messages_thread', ['id' => $lease->getId()]);
        }

        return $this->render('@Estate/admin/message_thread.html.twig', [
            'lease' => $lease,
            'thread' => $messages->read($lease, true),
            'form' => $form,
        ]);
    }
}
