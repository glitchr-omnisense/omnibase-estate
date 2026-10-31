<?php

namespace Base\Estate\Controller\Client;

use Base\Database\Attribute\Uploader;
use Base\Entity\User;
use Base\Estate\Entity\Offer;
use Base\Estate\Entity\SaleEvent;
use Base\Estate\Enum\OfferStatus;
use Base\Estate\Security\Voter\EstateVoter;
use Base\Estate\Service\Spaces;
use Base\Estate\Service\Transactions;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The sales' spaces, read-only. The seller's (/vendeur): their sale
 * mandates - the property, the price, the terms -, the visits (their date
 * and the feedback, never who visited), the offers (the buyer named once
 * one is accepted), the sale's steps with their documents, the cloud
 * folder. The buyer's (/acquereur): their offers and, for the one
 * accepted, the sale's steps, its documents and the folder. Only while
 * the site runs the `transaction` activity.
 */
#[IsGranted('ROLE_USER')]
class TransactionController extends AbstractController
{
    public function __construct(
        private readonly Spaces $spaces,
        private readonly Transactions $transactions,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/vendeur', name: 'estate_seller')]
    public function seller(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $mandates = $this->spaces->saleMandates($user);
        if (!$mandates) {
            throw $this->createAccessDeniedException('Not a seller.');
        }
        $mandate = $mandates[0];
        foreach ($mandates as $candidate) {
            if ($candidate->getId() === $request->query->getInt('mandat')) {
                $mandate = $candidate;
            }
        }

        return $this->render('@Estate/client/seller.html.twig', [
            'mandates' => $mandates,
            'mandate' => $mandate,
            'visits' => $this->transactions->visits($mandate),
            'offers' => $this->transactions->offers($mandate),
            'sale' => $this->transactions->saleOf($mandate),
            'fee' => $this->transactions->fee($mandate, $mandate->getPrice()),
            'folder' => $this->transactions->hasCloud() ? $mandate->getFolder() : null,
        ]);
    }

    #[Route('/acquereur', name: 'estate_buyer')]
    public function buyer(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $buyers = $this->spaces->buyers($user);
        if (!$buyers) {
            throw $this->createAccessDeniedException('Not a buyer.');
        }
        $offers = [];
        foreach ($buyers as $buyer) {
            array_push($offers, ...$this->entityManager->getRepository(Offer::class)->findBy(['buyer' => $buyer], ['madeOn' => 'DESC', 'id' => 'DESC']));
        }
        $accepted = array_values(array_filter($offers, fn (Offer $o) => OfferStatus::ACCEPTED === $o->getStatus()));
        $sale = null;
        foreach ($accepted as $offer) {
            $candidate = $this->transactions->saleOfOffer($offer);
            if (null !== $candidate && (null === $sale || $candidate->getStep()->isLive())) {
                $sale = $candidate;
            }
        }

        return $this->render('@Estate/client/buyer.html.twig', [
            'buyer' => $buyers[0],
            'offers' => $offers,
            'sale' => $sale,
            'folder' => null !== $sale && $sale->getStep()->isLive() && $this->transactions->hasCloud() ? $sale->getMandate()->getFolder() : null,
        ]);
    }

    /** A step's document: the agency, the sale's sellers and its buyer. */
    #[Route('/vente/document/{id}', name: 'estate_transaction_document', requirements: ['id' => '\d+'])]
    public function document(SaleEvent $event): BinaryFileResponse
    {
        $this->denyAccessUnlessGranted(EstateVoter::SALE_VIEW, $event);
        if (!$this->transactions->isActive()) {
            throw $this->createNotFoundException('The site does not run the transaction activity.');
        }
        $file = Uploader::get($event, 'file');
        if (!$file instanceof File || !$file->isFile()) {
            throw $this->createNotFoundException('The file is missing.');
        }
        $extension = $file->guessExtension() ?: $file->getExtension();
        $name = trim(preg_replace('/[^\pL\pN _.-]+/u', '', (string) ($event->getFileName() ?? $event->getStep()->label()))) ?: 'document';
        $name = preg_replace('/\.[a-z0-9]{2,4}$/i', '', $name);

        return (new BinaryFileResponse($file, 200, ['Content-Type' => $file->getMimeType() ?? 'application/octet-stream']))
            ->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $name.($extension ? '.'.$extension : ''), 'document'.($extension ? '.'.$extension : ''));
    }
}
