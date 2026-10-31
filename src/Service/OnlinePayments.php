<?php

namespace Base\Estate\Service;

use Base\Estate\Entity\RentCall;
use Base\Estate\Enum\PaymentMethod;
use Doctrine\ORM\EntityManagerInterface;
use Omnitrade\Model\Money;
use Omnitrade\Model\Payment as OmnitradePayment;
use Omnitrade\Model\Status;
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
        private readonly ?EntityManagerInterface $entityManager = null,
        private readonly ?PaymentWebhook $webhook = null,
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

    /**
     * Where to send the tenant: the provider's page. The idempotency key is
     * the rent, what is paid of it, the method and the ten minutes the
     * request falls in: a second click opens the same page, while another
     * method, or a new try after a failure, asks again - a provider keeps a
     * key's answer, a refusal too (Stripe for 24 hours).
     */
    public function start(RentCall $call, string $method, string $returnUrl, string $cancelUrl, ?string $email = null, ?\DateTimeImmutable $now = null): string
    {
        $method = \in_array($method, $this->methods, true) ? $method : null;
        $now ??= new \DateTimeImmutable();
        $transaction = $this->registry->get($this->gateway)->purchase(new OmnitradePayment(
            amount: Money::of($call->getRemaining(), 'EUR'),
            reference: $call->getReference(),
            description: sprintf('Loyer %s — %s', $call->getPeriod()->format('m/Y'), $call->getLease()->getUnit()->getName()),
            returnUrl: $returnUrl,
            cancelUrl: $cancelUrl,
            idempotencyKey: sprintf('%s-%d-%s-%s%d', $call->getReference(), $call->getPaid(), $method ?? 'any', $now->format('YmdH'), intdiv((int) $now->format('i'), 10)),
            method: $method,
            metadata: ['rent_call' => (string) $call->getId()],
            locale: 'fr',
        ));

        // Kept on the call: estate:payment:check reads the session again, should neither the tenant nor the webhook come back.
        if (null !== $transaction->reference && null !== $this->entityManager) {
            $call->setOnline($transaction->reference, $now);
            $this->entityManager->flush();
        }

        return $transaction->redirectUrl ?? $returnUrl;
    }

    /**
     * The online payments started and not seen paid, read again from the
     * provider: those paid since are recorded - a page closed before the
     * return, a SEPA debit through days later, no webhook. A session older
     * than $days (a SEPA debit takes up to two weeks) is given up. Returns
     * how many rents were recorded.
     */
    public function catchUp(int $days = 15, ?\DateTimeImmutable $now = null): int
    {
        if (null === $this->entityManager || !$this->isAvailable()) {
            return 0;
        }
        $now ??= new \DateTimeImmutable();
        $recorded = 0;
        foreach ($this->entityManager->getRepository(RentCall::class)->findOnlinePending() as $call) {
            if ($call->getOnlineStartedAt() < $now->modify(sprintf('-%d days', $days))) {
                $call->setOnline(null);
                continue;
            }
            try {
                $recorded += $this->complete($call, (string) $call->getOnlineReference()) ? 1 : 0;
            } catch (\Throwable) {
                // The provider unreachable, or the session gone: read again next time, until it is given up.
            }
        }
        $this->entityManager->flush();

        return $recorded;
    }

    /**
     * The provider's webhook, checked against its signature (an
     * InvalidNotificationException when it does not match): the rent call a
     * paid session names (its metadata "rent_call") and the session's
     * reference, for complete(); null when the event pays no rent - another
     * event, a SEPA debit not through yet, a payment that is not a rent.
     *
     * @param array<string, string|list<string>> $headers
     *
     * @return array{call: int, reference: string}|null
     */
    public function paidBy(string $body, array $headers): ?array
    {
        $notification = $this->notifier()->notify($body, $headers);
        $call = $notification->transaction?->metadata['rent_call'] ?? null;
        if (Status::PAID !== $notification->status || null === $notification->reference || !is_numeric($call)) {
            return null;
        }

        return ['call' => (int) $call, 'reference' => $notification->reference];
    }

    /**
     * The gateway that checks a webhook: over the configured options, with
     * the secret the application registered (PaymentWebhook, sealed in the
     * settings) - unless a webhook_secret is configured, which wins.
     */
    private function notifier(): \Omnitrade\GatewayInterface
    {
        if (null === $this->webhook || null !== $this->webhook->configuredSecret() || null === $secret = $this->webhook->secret()) {
            return $this->registry->get($this->gateway);
        }

        return $this->registry->create($this->gateway, ['webhook_secret' => $secret]);
    }

    /** Back from the provider, or told by its webhook: recorded when it is paid (SEPA debits come later, through the webhook or the next look). */
    public function complete(RentCall $call, string $reference): bool
    {
        $transaction = $this->registry->get($this->gateway)->fetch($reference);
        if (!$transaction->isPaid()) {
            return false;
        }
        $method = 'sepa_debit' === $transaction->method ? PaymentMethod::SEPA_DEBIT : PaymentMethod::CARD;
        $this->payments->record($call, $transaction->amount?->amount ?? $call->getRemaining(), $method, new \DateTimeImmutable('today'), $transaction->reference);
        $call->setOnline(null);
        $this->entityManager?->flush();

        return true;
    }
}
