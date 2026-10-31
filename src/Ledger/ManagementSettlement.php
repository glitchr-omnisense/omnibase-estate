<?php

namespace Base\Estate\Ledger;

use Base\Estate\Entity\MandatePayout;
use Base\Estate\Service\Management;
use Base\Ledger\Event\BankLineReconciled;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * An owner's payout, seen leaving the escrow account and reconciled with
 * it (omnibase/ledger's Matcher finds it by its reference, RV-2026-0001,
 * the end-to-end id of the SEPA file): the payout is paid, without posting
 * it a second time. The open items it cleared say which payout
 * ("estate:management:payout:<id>"). Only loaded with omnibase/ledger.
 */
#[AsEventListener(event: BankLineReconciled::class)]
final class ManagementSettlement
{
    private const SOURCE = 'estate:management:payout:';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Management $management,
    ) {
    }

    public function __invoke(BankLineReconciled $event): void
    {
        $line = $event->line;
        if ($line->getAmount() >= 0) {
            return;
        }
        foreach ($event->openItems as $item) {
            $source = $item->getEntry()->getSource();
            if (!str_starts_with($source, self::SOURCE) || !ctype_digit($id = substr($source, \strlen(self::SOURCE)))) {
                continue;
            }
            $payout = $this->entityManager->find(MandatePayout::class, (int) $id);
            if ($payout && !$payout->isPaid()) {
                $this->management->markPaid($payout, $line->getBookedOn(), 'bank:'.$line->getId(), false);
            }
        }
    }
}
