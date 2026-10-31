<?php

namespace Base\Estate\Ledger;

use Base\Estate\Entity\OwnershipCall;
use Base\Estate\Service\Ownerships;
use Base\Ledger\Event\BankLineReconciled;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * A co-owner's transfer, seen at the bank and reconciled with their call
 * for funds (omnibase/ledger's Matcher finds it by the call's reference,
 * or their IBAN): the call is paid, without posting it a second time. The
 * open items it cleared say which call ("estate:ownership:call:<id>").
 * Only loaded with omnibase/ledger.
 */
#[AsEventListener(event: BankLineReconciled::class)]
final class OwnershipSettlement
{
    private const SOURCE = 'estate:ownership:call:';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Ownerships $ownerships,
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
            if (!str_starts_with($source, self::SOURCE) || $left <= 0) {
                continue;
            }
            $call = $this->entityManager->find(OwnershipCall::class, (int) substr($source, \strlen(self::SOURCE)));
            if (!$call || $call->isPaid()) {
                continue;
            }
            $amount = min($left, $call->getRemaining());
            $this->ownerships->recordPayment($call, $amount, $line->getBookedOn(), 'bank:'.$line->getId().':'.$call->getId(), false);
            $left -= $amount;
        }
    }
}
