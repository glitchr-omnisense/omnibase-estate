<?php

namespace Base\Estate\Service;

use Base\Estate\Entity\Payment;
use Base\Estate\Entity\RentCall;
use Base\Estate\Enum\ActivityKind;
use Base\Estate\Enum\PaymentMethod;
use Base\Estate\Repository\RentCallRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Money received for a rent: recorded once per reference, posted to the
 * books, and - the rent paid in full - its receipt numbered and sent.
 */
class Payments
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RentCallRepository $calls,
        private readonly Activities $activities,
        private readonly EstateMailer $mailer,
        private readonly Receipts $receipts,
        private readonly ?Books $books = null,
    ) {
    }

    /** @param bool $post false when the books have it already (a bank line reconciled against the rent call) */
    public function record(RentCall $call, int $amount, PaymentMethod $method, ?\DateTimeImmutable $paidOn = null, ?string $reference = null, bool $notify = true, bool $post = true): Payment
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('A payment is a positive amount.');
        }
        if ($reference && $existing = $this->entityManager->getRepository(Payment::class)->findOneBy(['reference' => $reference])) {
            return $existing;
        }

        $wasPaid = $call->isPaid();
        $payment = new Payment($call, $amount, $method, $paidOn, $reference);
        $this->entityManager->persist($payment);
        $call->addPayment($payment);

        $lease = $call->getLease();
        $this->activities->log($lease->getOrganisation(), ActivityKind::RENT_PAID,
            sprintf('Loyer reçu (%s) : %s', $this->method($method), $lease->getTenantNames() ?: $lease->getReference()),
            $lease->getUnit(), $amount, \DateTimeImmutable::createFromInterface($payment->getPaidOn())->setTime(12, 0));

        if (!$wasPaid && $call->isPaid()) {
            $this->receipts->number($call);
        }
        $this->entityManager->flush();
        if ($post) {
            $this->books?->paymentReceived($payment);
        }

        if ($notify && !$wasPaid && $call->isPaid()) {
            $this->mailer->send(
                array_map(fn ($party) => $party->getEmail(), $lease->getTenants()),
                sprintf('Quittance de loyer %s — %s', $call->getReceiptNumber(), $lease->getUnit()->getName()),
                'receipt',
                ['call' => $call, 'lease' => $lease, 'organisation' => $lease->getOrganisation()],
                $lease->getOrganisation()->getEmail(),
                $this->receipts->pdf($call) ? [$call->getReceiptNumber().'.pdf' => $this->receipts->pdf($call)] : [],
            );
        }

        return $payment;
    }

    public function method(PaymentMethod $method): string
    {
        return match ($method) {
            PaymentMethod::TRANSFER => 'virement',
            PaymentMethod::CARD => 'carte bancaire',
            PaymentMethod::SEPA_DEBIT => 'prélèvement SEPA',
            PaymentMethod::CHEQUE => 'chèque',
            PaymentMethod::CASH => 'espèces',
        };
    }
}
