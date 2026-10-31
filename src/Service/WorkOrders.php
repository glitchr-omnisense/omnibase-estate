<?php

namespace Base\Estate\Service;

use Base\Entity\User;
use Base\Estate\Entity\Contractor;
use Base\Estate\Entity\Member;
use Base\Estate\Entity\Unit;
use Base\Estate\Entity\WorkOrder;
use Base\Estate\Entity\WorkOrderEvent;
use Base\Estate\Enum\ActivityKind;
use Base\Estate\Enum\Urgency;
use Base\Estate\Enum\WorkOrderStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A problem from its report to its invoice: reported (by the tenant or the
 * management), given to a contractor, visited, done, closed. Every step is
 * a line of its timeline, a line of the associates' feed, and an e-mail to
 * whoever it concerns.
 */
class WorkOrders
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ActivityLog $activities,
        private readonly EstateMailer $mailer,
        private readonly ?Books $books = null,
        private readonly ?Management $management = null,
    ) {
    }

    public function report(Unit $unit, ?User $by, string $byName, string $title, string $description, string $category = 'Autre', Urgency $urgency = Urgency::NORMAL): WorkOrder
    {
        $order = (new WorkOrder($unit, $title, $description))
            ->setReportedBy($by)->setCategory($category)->setUrgency($urgency);
        $order->addEvent(new WorkOrderEvent($byName, 'Signalé : '.$description, $by));
        $this->entityManager->persist($order);
        $this->activities->log($unit->getOrganisation(), ActivityKind::WORK_REPORTED, sprintf('Problème signalé : %s', $title), $unit);
        $this->entityManager->flush();

        $this->mailer->send($this->managers($unit), sprintf('[Intervention #%d] %s — %s', $order->getId(), $title, $unit->getName()), 'work_reported', ['order' => $order]);

        return $order;
    }

    public function assign(WorkOrder $order, Contractor $contractor, ?\DateTimeImmutable $visitAt, ?User $by, string $byName = 'Gestion', ?string $note = null): void
    {
        $order->setContractor($contractor)->setVisitAt($visitAt)
            ->setStatus($visitAt ? WorkOrderStatus::SCHEDULED : WorkOrderStatus::ASSIGNED);
        $order->addEvent(new WorkOrderEvent($byName, sprintf('Confié à %s%s.%s', $contractor->getCompany(),
            $visitAt ? ', passage le '.$visitAt->format('d/m/Y à H\hi') : '', $note ? ' '.$note : ''), $by));
        $this->activities->log($order->getOrganisation(), ActivityKind::WORK_ASSIGNED, sprintf('%s confié à %s', $order->getTitle(), $contractor->getCompany()), $order->getUnit());
        $this->entityManager->flush();

        $this->mailer->send($contractor->getEmail(), sprintf('[Intervention #%d] %s — %s', $order->getId(), $order->getTitle(), $order->getUnit()->getName()), 'work_assigned', ['order' => $order]);
        $this->mailer->send($this->tenants($order), sprintf('Votre demande « %s » est confiée à %s', $order->getTitle(), $contractor->getCompany()), 'work_update', ['order' => $order]);
    }

    /** What the contractor says: a visit date, a quote, their report; done when they say so. */
    public function update(WorkOrder $order, ?User $by, string $byName, ?\DateTimeImmutable $visitAt = null, ?int $quote = null, ?string $report = null, bool $done = false, ?int $invoice = null, ?string $invoiceNumber = null): void
    {
        $said = [];
        if ($visitAt && $visitAt != $order->getVisitAt()) {
            $order->setVisitAt($visitAt)->setStatus(WorkOrderStatus::SCHEDULED);
            $said[] = 'Passage prévu le '.$visitAt->format('d/m/Y à H\hi').'.';
        }
        if (null !== $quote && $quote !== $order->getQuoteAmount()) {
            $order->setQuoteAmount($quote);
            $said[] = sprintf('Devis : %s €.', number_format($quote / 100, 2, ',', ' '));
        }
        if ($report) {
            $order->setReport($report);
            $said[] = $report;
        }
        if (null !== $invoice) {
            $order->setInvoiceAmount($invoice)->setInvoiceNumber($invoiceNumber);
            $said[] = sprintf('Facture %s : %s €.', $invoiceNumber ?? '', number_format($invoice / 100, 2, ',', ' '));
        }
        if ($done) {
            $order->setStatus(WorkOrderStatus::DONE);
            $said[] = 'Intervention terminée.';
            $this->activities->log($order->getOrganisation(), ActivityKind::WORK_DONE, sprintf('%s : terminé', $order->getTitle()), $order->getUnit(), $order->getInvoiceAmount() ? -$order->getInvoiceAmount() : null);
        }
        if (!$said) {
            return;
        }
        $order->addEvent(new WorkOrderEvent($byName, implode(' ', $said), $by));
        $this->entityManager->flush();

        $this->mailer->send($this->managers($order->getUnit()), sprintf('[Intervention #%d] %s', $order->getId(), $done ? 'terminée' : 'mise à jour'), 'work_update', ['order' => $order]);
        if ($done || $visitAt) {
            $this->mailer->send($this->tenants($order), sprintf('Votre demande « %s » : %s', $order->getTitle(), $done ? 'intervention terminée' : 'date de passage'), 'work_update', ['order' => $order]);
        }
    }

    public function note(WorkOrder $order, ?User $by, string $byName, string $body): void
    {
        $order->addEvent(new WorkOrderEvent($byName, $body, $by));
        $this->entityManager->flush();
    }

    /** Closed by the management: the invoice, if any, goes to the books - a managed home's to its owner's account. */
    public function close(WorkOrder $order, ?User $by, string $byName = 'Gestion'): void
    {
        $order->setStatus(WorkOrderStatus::CLOSED)->setClosedAt(new \DateTimeImmutable());
        $order->addEvent(new WorkOrderEvent($byName, 'Clôturée.', $by));
        $this->entityManager->flush();
        $this->books?->workInvoiced($order);
        $this->management?->workInvoiced($order);
    }

    /** @return list<string> */
    private function managers(Unit $unit): array
    {
        $emails = array_map(fn (Member $m) => $m->getEmail(), $this->entityManager->getRepository(Member::class)->findBy(['organisation' => $unit->getOrganisation()]));
        if ($unit->getOrganisation()->getEmail()) {
            $emails[] = $unit->getOrganisation()->getEmail();
        }

        return array_values(array_unique(array_filter($emails)));
    }

    /** @return list<string> */
    private function tenants(WorkOrder $order): array
    {
        $lease = $order->getUnit()->getCurrentLease();

        return $lease ? array_map(fn ($party) => $party->getEmail(), $lease->getTenants()) : [];
    }
}
