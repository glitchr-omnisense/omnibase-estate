<?php

namespace Base\Estate\Controller\Client;

use Base\Entity\User;
use Base\Estate\Form\InvitationAcceptType;
use Base\Estate\Service\Invitations;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * An invitation's link: a new account (a username, a password), or - signed
 * in already, or with an account for that address - the account attached.
 * Then the space it opens.
 */
class InvitationController extends AbstractController
{
    #[Route('/invitation/{token}', name: 'estate_invitation', requirements: ['token' => '[a-f0-9]{48}'], methods: ['GET', 'POST'])]
    public function accept(Request $request, string $token, Invitations $invitations, Security $security, EntityManagerInterface $entityManager, TranslatorInterface $translator): Response
    {
        $invitation = $invitations->find($token);
        if (!$invitation) {
            return $this->render('@Estate/client/invitation_expired.html.twig', [], new Response(null, Response::HTTP_GONE));
        }

        /** @var User|null $user */
        $user = $this->getUser();
        if ($user && $request->isMethod('POST') && $this->isCsrfTokenValid('invitation_'.$invitation->getId(), (string) $request->request->get('_token'))) {
            $invitations->accept($invitation, $user);

            return $this->redirectToRoute($this->space($invitation->getKind()->value));
        }

        $existing = $user ? null : $invitations->existingUser($invitation);
        $form = $this->createForm(InvitationAcceptType::class);
        if (!$user && !$existing) {
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                $data = $form->getData();
                $taken = $entityManager->getRepository($this->getParameter('estate.user_class'))->findOneBy(['username' => $data['username']]);
                if ($taken) {
                    $form->get('username')->addError(new FormError($translator->trans('invitation.username_taken', [], 'estate')));
                } else {
                    $user = $invitations->createUser($invitation, $data['username'], $data['password']);
                    $invitations->accept($invitation, $user);
                    $security->login($user, 'form_login', 'main');

                    return $this->redirectToRoute($this->space($invitation->getKind()->value));
                }
            }
        }

        return $this->render('@Estate/client/invitation.html.twig', [
            'invitation' => $invitation,
            'target' => $invitations->target($invitation),
            'form' => $form,
            'existing' => $existing,
            'token' => $token,
        ], new Response(null, $form->isSubmitted() && !$form->isValid() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    private function space(string $kind): string
    {
        return match ($kind) {
            'associate' => 'estate_associate',
            'tenant' => 'estate_tenant',
            'contractor' => 'estate_contractor',
            default => 'admin',
        };
    }
}
