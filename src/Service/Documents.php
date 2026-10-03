<?php

namespace Base\Estate\Service;

use Base\Entity\User;
use Base\Estate\Entity\Document;
use Base\Estate\Entity\Lease;
use Base\Estate\Entity\WorkOrder;
use Base\Estate\Enum\ActivityKind;
use Base\Estate\Enum\DocumentKind;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\File;

/**
 * The files people add from their space: a tenant's insurance certificate (the
 * lease's insurance date follows), a contractor's quote, invoice or photos (the
 * work order's amounts follow). Each lands in the activity feed and, when it is
 * not the management's own, in its mailbox.
 */
class Documents
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Activities $activities,
        private readonly EstateMailer $mailer,
    ) {
    }

    /** A tenant's certificate of home insurance, valid until $until: the lease's insurance date moves with it. */
    public function insurance(Lease $lease, File $file, \DateTimeImmutable $until, User $user, string $name): Document
    {
        $document = (new Document($lease->getOrganisation(), DocumentKind::INSURANCE, 'Attestation d\'assurance habitation — '.$until->format('d/m/Y')))
            ->setLease($lease)->setFile($file)->setValidUntil($until)->setUploadedBy($user, $name);
        $this->entityManager->persist($document);
        if (null === $lease->getInsuranceUntil() || $until > $lease->getInsuranceUntil()) {
            $lease->setInsuranceUntil($until);
        }
        $this->activities->log($lease->getOrganisation(), ActivityKind::DOCUMENT, 'Attestation d\'assurance reçue de '.$name.' (jusqu\'au '.$until->format('d/m/Y').')', $lease->getUnit());
        $this->entityManager->flush();
        $this->mailer->send($lease->getOrganisation()->getEmail(), 'Attestation d\'assurance — '.$lease->getUnit()->getName(), 'document_added', ['document' => $document, 'who' => $name]);

        return $document;
    }

    /** A contractor's file on a work order: a quote, an invoice (its amount and number then on the order) or a photo. */
    public function work(WorkOrder $order, DocumentKind $kind, File $file, ?string $title, User $user, string $name, bool $tenantVisible = false): Document
    {
        $document = (new Document($order->getOrganisation(), $kind, $title ?: $this->defaultTitle($kind, $order)))
            ->setWorkOrder($order)->setFile($file)->setTenantVisible($tenantVisible)->setUploadedBy($user, $name);
        $this->entityManager->persist($document);
        $this->activities->log($order->getOrganisation(), ActivityKind::DOCUMENT, $document->getTitle().' ajouté par '.$name, $order->getUnit());
        $this->entityManager->flush();
        $this->mailer->send($order->getOrganisation()->getEmail(), $document->getTitle(), 'document_added', ['document' => $document, 'who' => $name]);

        return $document;
    }

    private function defaultTitle(DocumentKind $kind, WorkOrder $order): string
    {
        return match ($kind) {
            DocumentKind::QUOTE => 'Devis',
            DocumentKind::INVOICE => 'Facture',
            DocumentKind::PHOTO => 'Photo',
            default => 'Document',
        }.' — '.$order->getTitle();
    }
}
