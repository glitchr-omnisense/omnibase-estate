<?php

namespace Base\Estate\Ledger;

use Base\Estate\Entity\RentCall;
use Base\Estate\Enum\PaymentMethod;
use Base\Estate\Service\Payments;
use Base\Ledger\Event\BankLineReconciled;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * A tenant's transfer, seen at the bank and reconciled with their rent
 * call (omnibase/ledger): the rent call is paid - its receipt numbered and
 * sent - without posting it a second time. The open items it cleared say
 * which call: their entries' source ("estate:rent:<reference>").
 * Only loaded with omnibase/ledger.
 */
#[AsEventListener(event: BankLineReconciled::class)]
final class BankSettlement
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Payments $payments,
    ) {
    }

    public function __invoke(BankLineReconciled $event): void
    {
        $line = $event->line;
        if ($line->getAmount() <= 0) {
            return;
        }
        $left = $line->getAmount();
        foreach ($event->openItems as $item) {
            $source = $item->getEntry()->getSource();
            if (!str_starts_with($source, 'estate:rent:') || $left <= 0) {
                continue;
            }
            $call = $this->entityManager->getRepository(RentCall::class)->findOneBy(['reference' => substr($source, \strlen('estate:rent:'))]);
            if (!$call || $call->isPaid()) {
                continue;
            }
            $amount = min($left, $call->getRemaining());
            $this->payments->record($call, $amount, PaymentMethod::TRANSFER, $line->getBookedOn(), 'bank:'.$line->getId().':'.$call->getId(), true, false);
            $left -= $amount;
        }
    }
}
