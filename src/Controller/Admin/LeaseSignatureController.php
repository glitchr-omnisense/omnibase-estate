<?php

namespace Base\Estate\Controller\Admin;

use Base\Entity\User;
use Base\Estate\Entity\Lease;
use Base\Estate\Signature\LeaseSignatures;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * A lease, or its inventory of fixtures, signed electronically (Signature\LeaseSignatures, with
 * omnisense/omnisign): where it stands, each signer's state, send it, ask the provider again,
 * remind, cancel, and once signed the signed PDF and its evidence. Without omnisign the page says
 * how to turn it on.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/estate/lease/{id}/signature/{kind}', requirements: ['id' => '\d+', 'kind' => 'lease|inventory'], defaults: ['kind' => 'lease'])]
class LeaseSignatureController extends AbstractController
{
    #[Route('', name: 'estate_admin_signature', methods: ['GET'])]
    public function show(Lease $lease, string $kind, ?LeaseSignatures $signatures = null): Response
    {
        return $this->render('@Estate/admin/signature.html.twig', [
            'lease' => $lease,
            'kind' => $kind,
            'enabled' => (bool) $signatures?->isEnabled(),
            'installed' => null !== $signatures,
            'state' => $signatures?->state($lease, $kind),
            'other' => $signatures?->state($lease, LeaseSignatures::LEASE === $kind ? LeaseSignatures::INVENTORY : LeaseSignatures::LEASE),
            'can_send' => (bool) $signatures?->canSend($lease, $kind),
            'gateway' => $signatures?->gateway(),
        ]);
    }

    #[Route('/{do}', name: 'estate_admin_signature_do', requirements: ['do' => 'send|refresh|remind|cancel'], methods: ['POST'])]
    public function act(Request $request, Lease $lease, string $kind, string $do, ?LeaseSignatures $signatures = null): Response
    {
        if (!$this->isCsrfTokenValid('estate_signature_'.$lease->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        if (null === $signatures || !$signatures->isEnabled()) {
            throw $this->createNotFoundException('No electronic signature: omnisense/omnisign and estate.signature.gateway.');
        }
        try {
            switch ($do) {
                case 'send':
                    /** @var User $user */
                    $user = $this->getUser();
                    $envelope = $signatures->send($lease, $user, $kind);
                    $this->addFlash('success', sprintf('Envoyé à la signature : %s. Chacun reçoit le lien par e-mail, dans l\'ordre.', implode(', ', array_map(static fn (array $s) => $s['name'], $envelope->getSigners()))));
                    break;
                case 'refresh':
                    $signatures->refresh($lease, $kind);
                    $this->addFlash('success', 'État mis à jour auprès du service de signature.');
                    break;
                case 'remind':
                    $signatures->remind($lease, $kind);
                    $this->addFlash('success', 'Relance envoyée aux signataires en attente.');
                    break;
                case 'cancel':
                    $signatures->cancel($lease, $kind);
                    $this->addFlash('success', 'Demande de signature annulée.');
                    break;
            }
        } catch (\DomainException $e) {
            $this->addFlash('warning', $e->getMessage());
        } catch (\Throwable $e) {
            // The provider unreachable or refusing: said, nothing else changes.
            $this->addFlash('danger', 'Le service de signature n\'a pas répondu : '.$e->getMessage());
        }

        return $this->redirectToRoute('estate_admin_signature', ['id' => $lease->getId(), 'kind' => $kind]);
    }

    #[Route('/file/{file}', name: 'estate_admin_signature_file', requirements: ['file' => 'document|evidence'], methods: ['GET'])]
    public function download(Lease $lease, string $kind, string $file, ?LeaseSignatures $signatures = null): Response
    {
        $content = $signatures?->file($lease, $kind, $file) ?? throw $this->createNotFoundException();
        $name = sprintf('%s-%s%s.pdf', LeaseSignatures::LEASE === $kind ? 'bail' : 'etat-des-lieux', preg_replace('~[^A-Za-z0-9]+~', '-', $lease->getReference()), 'evidence' === $file ? '-preuve' : '-signe');
        $response = new Response($content, 200, ['Content-Type' => 'application/pdf']);
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $name));

        return $response;
    }
}
