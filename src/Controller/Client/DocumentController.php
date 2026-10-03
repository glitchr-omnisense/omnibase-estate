<?php

namespace Base\Estate\Controller\Client;

use Base\Estate\Entity\Document;
use Base\Estate\Security\Voter\EstateVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** A document's file, streamed to whoever may open it (EstateVoter::DOCUMENT_VIEW): the uploads stay out of public/. */
#[IsGranted('ROLE_USER')]
class DocumentController extends AbstractController
{
    #[Route('/fichier/{id}', name: 'estate_document', requirements: ['id' => '\d+'])]
    public function show(Document $document): BinaryFileResponse
    {
        $this->denyAccessUnlessGranted(EstateVoter::DOCUMENT_VIEW, $document);
        $file = $document->getFileFile();
        if (!$file instanceof File || !$file->isFile()) {
            throw $this->createNotFoundException('The file is missing.');
        }
        $extension = $file->guessExtension() ?: $file->getExtension();
        $name = trim(preg_replace('/[^\pL\pN _.-]+/u', '', $document->getTitle())) ?: 'document';

        return (new BinaryFileResponse($file, 200, ['Content-Type' => $file->getMimeType() ?? 'application/octet-stream']))
            ->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $name.($extension ? '.'.$extension : ''), 'document'.($extension ? '.'.$extension : ''));
    }
}
