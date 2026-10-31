<?php

namespace Tests\Base\Estate\Entity;

use Base\Estate\Entity\Lease;
use Base\Estate\Entity\Payment;
use Base\Estate\Entity\RentCall;
use Base\Estate\Entity\Unit;
use Base\Estate\Enum\PaymentMethod;
use Base\Estate\Enum\RentStatus;
use PHPUnit\Framework\TestCase;

final class RentCallTest extends TestCase
{
    private function call(): RentCall
    {
        $unit = $this->createStub(Unit::class);
        $unit->method('getRent')->willReturn(92000);
        $unit->method('getCharges')->willReturn(9000);
        $unit->method('getDeposit')->willReturn(92000);

        return new RentCall(new Lease($unit, 'MA-2023-004', new \DateTimeImmutable('2023-10-01')), new \DateTimeImmutable('2026-11-01'));
    }

    public function testAPaymentTakenBackLeavesTheRentDueAgain(): void
    {
        $call = $this->call();
        $transfer = new Payment($call, 101000, PaymentMethod::TRANSFER, new \DateTimeImmutable('2025-10-04'), 'bank:35:20');
        $call->addPayment($transfer);
        self::assertTrue($call->isPaid());

        $call->removePayment($transfer);

        self::assertSame(RentStatus::CALLED, $call->getStatus());
        self::assertSame(0, $call->getPaid());
        self::assertNull($call->getPaidAt());
        self::assertCount(0, $call->getPayments());
    }

    public function testWhatIsLeftSaysPartialOrPaid(): void
    {
        $call = $this->call();
        $call->addPayment($half = new Payment($call, 50000, PaymentMethod::TRANSFER));
        $call->addPayment($rest = new Payment($call, 51000, PaymentMethod::CARD));

        $call->removePayment($rest);
        self::assertSame(RentStatus::PARTIAL, $call->getStatus());
        self::assertSame(50000, $call->getPaid());

        $call->removePayment($rest);
        self::assertSame(50000, $call->getPaid(), 'a payment the call does not have changes nothing');
    }
}
