<?php

namespace Base\Estate\Controller\Admin;

use Base\Entity\User;
use Base\Estate\Entity\Lease;
use Base\Estate\Entity\LeaseParty;
use Base\Estate\Form\SuretyTermsType;
use Base\Estate\Model\SuretyTerms;
use Base\Estate\Signature\SuretySignatures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * A lease's guarantors' deeds (actes de cautionnement, Signature\SuretySignatures): each
 * guarantor's commitment (the limit, the duration, joint or not), the deed to read before it goes,
 * sent to be signed through omnisign, its state, a reminder, a cancel, the signed deed and its
 * evidence. Until the site's owner confirms a lawyer validated the template, the page says it is
 * a starting point. Without omnisign the deed can still be read, not sent.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/estate/lease/{id}/surety', requirements: ['id' => '\d+'])]
class SuretyController extends AbstractController
{
    #[Route('', name: 'estate_admin_surety', methods: ['GET'])]
    public function show(Lease $lease, ?SuretySignatures $sureties = null): Response
    {
        $forms = [];
        foreach ($lease->getParties() as $party) {
            if ($party->isGuarantor()) {
                $forms[$party->getId()] = $this->terms($party)->createView();
            }
        }

        return $this->render('@Estate/admin/surety.html.twig', [
            'lease' => $lease,
            'organisation' => $lease->getOrganisation(),
            'deeds' => $sureties?->deeds($lease) ?? array_values(array_map(static fn (LeaseParty $p) => ['guarantor' => $p, 'state' => null, 'missing' => [], 'canSend' => false], array_filter($lease->getParties()->toArray(), static fn (LeaseParty $p) => $p->isGuarantor()))),
            'forms' => $forms,
            'installed' => null !== $sureties,
            'enabled' => (bool) $sureties?->isEnabled(),
            'gateway' => $sureties?->gateway(),
        ]);
    }

    #[Route('/{party}/terms', name: 'estate_admin_surety_terms', requirements: ['party' => '\d+'], methods: ['POST'])]
    public function saveTerms(Request $request, Lease $lease, #[MapEntity(id: 'party')] LeaseParty $party, EntityManagerInterface $entityManager): Response
    {
        $this->assertGuarantorOf($party, $lease);
        $form = $this->terms($party);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var SuretyTerms $terms */
            $terms = $form->getData();
            $terms->applyTo($party);
            $entityManager->flush();
            $this->addFlash('success', sprintf('Engagement de %s enregistré.', $party->getName()));
        } else {
            foreach ($form->getErrors(true) as $error) {
                $this->addFlash('warning', $error->getMessage());
            }
        }

        return $this->redirectToRoute('estate_admin_surety', ['id' => $lease->getId(), '_fragment' => 'caution-'.$party->getId()]);
    }

    /** The deed as it would be sent, to read first. */
    #[Route('/{party}/deed', name: 'estate_admin_surety_deed', requirements: ['party' => '\d+'], methods: ['GET'])]
    public function deed(Lease $lease, #[MapEntity(id: 'party')] LeaseParty $party, ?SuretySignatures $sureties = null): Response
    {
        $this->assertGuarantorOf($party, $lease);
        if (null === $sureties) {
            throw $this->createNotFoundException('omnisense/omnisign is not installed.');
        }
        if ([] !== $missing = $sureties->missing($party)) {
            $this->addFlash('warning', 'Il manque à l\'acte : '.implode(', ', $missing).'.');

            return $this->redirectToRoute('estate_admin_surety', ['id' => $lease->getId()]);
        }
        $file = $sureties->deedPdf($party);
        $response = new Response($file->content, 200, ['Content-Type' => 'application/pdf']);
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $file->filename));

        return $response;
    }

    #[Route('/{party}/{do}', name: 'estate_admin_surety_do', requirements: ['party' => '\d+', 'do' => 'send|refresh|remind|cancel'], methods: ['POST'])]
    public function act(Request $request, Lease $lease, #[MapEntity(id: 'party')] LeaseParty $party, string $do, ?SuretySignatures $sureties = null): Response
    {
        $this->assertGuarantorOf($party, $lease);
        if (!$this->isCsrfTokenValid('estate_surety_'.$party->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        if (null === $sureties || !$sureties->isEnabled()) {
            throw $this->createNotFoundException('No electronic signature: omnisense/omnisign and estate.signature.gateway.');
        }
        try {
            switch ($do) {
                case 'send':
                    /** @var User $user */
                    $user = $this->getUser();
                    $sureties->send($party, $user);
                    $this->addFlash('success', sprintf('Acte de cautionnement envoyé à %s, qui reçoit le lien par e-mail.', $party->getName()));
                    break;
                case 'refresh':
                    $sureties->refresh($party);
                    $this->addFlash('success', 'État mis à jour auprès du service de signature.');
                    break;
                case 'remind':
                    $sureties->remind($party);
                    $this->addFlash('success', 'Relance envoyée à la caution.');
                    break;
                case 'cancel':
                    $sureties->cancel($party);
                    $this->addFlash('success', 'Demande de signature annulée.');
                    break;
            }
        } catch (\DomainException $e) {
            $this->addFlash('warning', $e->getMessage());
        } catch (\Throwable $e) {
            $this->addFlash('danger', 'Le service de signature n\'a pas répondu : '.$e->getMessage());
        }

        return $this->redirectToRoute('estate_admin_surety', ['id' => $lease->getId(), '_fragment' => 'caution-'.$party->getId()]);
    }

    #[Route('/{party}/file/{file}', name: 'estate_admin_surety_file', requirements: ['party' => '\d+', 'file' => 'document|evidence'], methods: ['GET'])]
    public function download(Lease $lease, #[MapEntity(id: 'party')] LeaseParty $party, string $file, ?SuretySignatures $sureties = null): Response
    {
        $this->assertGuarantorOf($party, $lease);
        $content = $sureties?->file($party, $file) ?? throw $this->createNotFoundException();
        $response = new Response($content, 200, ['Content-Type' => 'application/pdf']);
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_INLINE, sprintf('acte-de-cautionnement-%d%s.pdf', $party->getId(), 'evidence' === $file ? '-preuve' : '-signe')));

        return $response;
    }

    private function terms(LeaseParty $party): \Symfony\Component\Form\FormInterface
    {
        return $this->container->get('form.factory')->createNamed('surety_'.$party->getId(), SuretyTermsType::class, SuretyTerms::of($party), [
            'action' => $this->generateUrl('estate_admin_surety_terms', ['id' => $party->getLease()?->getId(), 'party' => $party->getId()]),
        ]);
    }

    /** The guarantor is one of this lease's (ids compared: the entity managers differ across requests). */
    private function assertGuarantorOf(LeaseParty $party, Lease $lease): void
    {
        if (!$party->isGuarantor() || $party->getLease()?->getId() !== $lease->getId()) {
            throw $this->createNotFoundException();
        }
    }
}
