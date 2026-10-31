<?php

namespace Base\Estate\Ledger;

use Base\Estate\Entity\Payment;
use Base\Estate\Service\Payments;
use Base\Ledger\Event\BankLineUnreconciled;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * A bank line unreconciled in the books: the rent payments BankSettlement
 * recorded from it ("bank:<line>:<call>") are taken back, their calls due
 * again. Only loaded with omnibase/ledger.
 */
#[AsEventListener(event: BankLineUnreconciled::class)]
final class BankUnsettlement
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Payments $payments,
    ) {
    }

    public function __invoke(BankLineUnreconciled $event): void
    {
        $recorded = $this->entityManager->getRepository(Payment::class)->createQueryBuilder('p')
            ->where('p.reference LIKE :prefix')->setParameter('prefix', 'bank:'.$event->line->getId().':%')
            ->getQuery()->getResult();
        foreach ($recorded as $payment) {
            $this->payments->cancel($payment);
        }
    }
}
