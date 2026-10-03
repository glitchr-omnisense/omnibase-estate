<?php

namespace Base\Estate\Enum;

enum PaymentMethod: string
{
    case TRANSFER = 'transfer';
    case CARD = 'card';
    case SEPA_DEBIT = 'sepa_debit';
    case CHEQUE = 'cheque';
    case CASH = 'cash';

    /** Paid through the payment provider: it lands on the clearing account (5112) until the payout. */
    public function isOnline(): bool
    {
        return \in_array($this, [self::CARD, self::SEPA_DEBIT], true);
    }
}
