<?php

namespace Base\Estate\Service;

use Base\Estate\Entity\RentCall;
use Base\Estate\Enum\PaymentMethod;
use Omnitrade\Model\Money;
use Omnitrade\Model\Payment as OmnitradePayment;
use Omnitrade\Registry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * A rent paid online through omnitrade (Stripe Checkout: card or SEPA
 * debit): the tenant goes to the provider's page, comes back, and the
 * payment is recorded once the provider says it is paid. Off when no
 * gateway is configured or omnitrade is not installed.
 */
class OnlinePayments
{
    public function __construct(
        private readonly Payments $payments,
        private readonly ?Registry $registry = null,
        #[Autowire('%estate.payment_gateway%')] private readonly ?string $gateway = 'stripe',
        #[Autowire('%estate.payment_methods%')] private readonly array $methods = ['card', 'sepa_debit'],
    ) {
    }

    public function isAvailable(): bool
    {
        if (!$this->registry || !$this->gateway) {
            return false;
        }
        try {
            $this->registry->get($this->gateway);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return list<string> */
    public function methods(): array
    {
        return $this->methods;
    }

    /** Where to send the tenant: the provider's page. */
    public function start(RentCall $call, string $method, string $returnUrl, string $cancelUrl, ?string $email = null): string
    {
        $transaction = $this->registry->get($this->gateway)->purchase(new OmnitradePayment(
            amount: Money::of($call->getRemaining(), 'EUR'),
            reference: $call->getReference(),
            description: sprintf('Loyer %s — %s', $call->getPeriod()->format('m/Y'), $call->getLease()->getUnit()->getName()),
            returnUrl: $returnUrl,
            cancelUrl: $cancelUrl,
            idempotencyKey: $call->getReference().'-'.$call->getPaid(),
            method: \in_array($method, $this->methods, true) ? $method : null,
            metadata: ['rent_call' => (string) $call->getId()],
            locale: 'fr',
        ));

        return $transaction->redirectUrl ?? $returnUrl;
    }

    /** Back from the provider: recorded when it is paid (SEPA debits come later, through the webhook or the next look). */
    public function complete(RentCall $call, string $reference): bool
    {
        $transaction = $this->registry->get($this->gateway)->fetch($reference);
        if (!$transaction->isPaid()) {
            return false;
        }
        $method = 'sepa_debit' === $transaction->method ? PaymentMethod::SEPA_DEBIT : PaymentMethod::CARD;
        $this->payments->record($call, $transaction->amount?->amount ?? $call->getRemaining(), $method, new \DateTimeImmutable('today'), $transaction->reference);

        return true;
    }
}
