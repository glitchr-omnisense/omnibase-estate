<?php

namespace Base\Estate\Service;

use Base\Database\Attribute\Vault;
use Base\Service\SettingBagInterface;
use Omnitrade\Http\PublicUrl;
use Omnitrade\Model\Webhook;
use Omnitrade\Registry;
use Omnitrade\Request\FetchWebhooks;
use Omnitrade\Request\RegisterWebhook;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The payment provider's webhook, registered by the application on
 * estate_payment_webhook - nobody copies a signing secret from a console.
 * The secret the provider answers is kept in omnibase's settings, sealed by
 * its #[Vault] (as /admin/api-key keeps the API keys), and read when a
 * webhook comes (OnlinePayments::paidBy()). A webhook_secret configured (an
 * env value) wins: then nothing is registered, the endpoint it belongs to is
 * left as it is. The webhook is optional: estate:payment:check, every 15
 * minutes, records the rents without it.
 */
class PaymentWebhook
{
    public const ROUTE = 'estate_payment_webhook';
    public const SETTINGS = 'api.webhook.'.self::ROUTE;

    public const OFF = 'off';
    public const UNSUPPORTED = 'unsupported';
    public const CONFIGURED = 'configured';
    public const UNREACHABLE = 'unreachable';
    public const NO_VAULT = 'no_vault';
    public const KEPT = 'kept';
    public const REGISTERED = 'registered';

    public function __construct(
        private readonly UrlGeneratorInterface $router,
        private readonly ?Registry $registry = null,
        private readonly ?SettingBagInterface $settings = null,
        #[Autowire('%estate.payment_gateway%')] private readonly ?string $gateway = 'stripe',
        private readonly ?Vault $vault = null,
        private readonly ?LockFactory $locks = null,
    ) {
    }

    public function gateway(): ?string
    {
        return $this->gateway ?: null;
    }

    /** Where the provider posts: the route as the router builds it outside a request (its hosts, or framework.router.default_uri). */
    public function url(): string
    {
        return $this->router->generate(self::ROUTE, [], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    /** Why no provider can reach url(), or null. */
    public function unreachable(): ?string
    {
        return !class_exists(PublicUrl::class) ? null : PublicUrl::unreachable($this->url());
    }

    /** The secret configured for the gateway (an env value), which wins over the one registered. */
    public function configuredSecret(): ?string
    {
        if (null === $this->registry || null === $this->gateway() || !$this->registry->has($this->gateway())) {
            return null;
        }
        $secret = $this->registry->options($this->gateway())['webhook_secret'] ?? null;

        return \is_string($secret) && '' !== $secret ? $secret : null;
    }

    /** The secret the provider answered when the application registered its webhook, unsealed; null when none. */
    public function secret(): ?string
    {
        $secret = $this->stored('secret');

        return '' === (string) $secret ? null : (string) $secret;
    }

    /**
     * Makes sure the provider posts to url(), signed with a secret kept here:
     * an endpoint already registered on this address, with its secret kept,
     * is kept (KEPT); otherwise it is registered - the provider replaces the
     * one on the same address - and its secret sealed (REGISTERED). Every
     * other answer says why nothing was done. $force registers again.
     *
     * @return self::* what was done
     */
    public function ensure(bool $force = false): string
    {
        $gateway = $this->gateway();
        if (null === $this->registry || null === $gateway || !$this->registry->has($gateway)) {
            return self::OFF;
        }
        if (!class_exists(RegisterWebhook::class)) {
            return self::UNSUPPORTED;   // an omnisense/omnitrade older than webhook registration
        }
        $url = $this->url();
        if (null !== PublicUrl::unreachable($url)) {
            return self::UNREACHABLE;
        }
        $provider = $this->registry->get($gateway);
        if (!$provider->supports(RegisterWebhook::class)) {
            return self::UNSUPPORTED;
        }
        if (null !== $this->configuredSecret()) {
            return self::CONFIGURED;
        }
        // Two deploys at once (several web containers) would each replace the other's endpoint:
        // one at a time, over the site's lock store (LOCK_DSN, shared between the hosts).
        $lock = $this->locks?->createLock('estate.payment.webhook.'.$gateway, 120);
        $lock?->acquire(true);
        try {
            return $this->register($provider, $url, $force);
        } finally {
            $lock?->release();
        }
    }

    /** @return self::KEPT|self::NO_VAULT|self::REGISTERED */
    private function register(\Omnitrade\GatewayInterface $provider, string $url, bool $force): string
    {
        if (!$force && null !== $this->secret() && $url === $this->stored('url') && $this->isRegistered((string) $this->stored('id'), $url)) {
            return self::KEPT;
        }
        // A key pair, not Vault::canSeal(): that one also answers true when clear text is allowed,
        // and omnibase's allowsPlaintext() reads its parameter as the whole bag (always true).
        if (null === $this->settings || null === ($this->vault ?? new Vault())->getMarshaller()) {
            return self::NO_VAULT;   // a secret that cannot be sealed is not asked for
        }

        $provider->execute($request = new RegisterWebhook($url));
        $webhook = $request->getWebhook();
        $this->store($webhook);

        return self::REGISTERED;
    }

    /** The webhook registered, as kept here (id, url; never the secret). @return array{id: ?string, url: ?string} */
    public function registered(): array
    {
        return ['id' => $this->stored('id'), 'url' => $this->stored('url')];
    }

    private function isRegistered(string $id, string $url): bool
    {
        $provider = $this->registry->get((string) $this->gateway());
        if ('' === $id || !$provider->supports(FetchWebhooks::class)) {
            return false;
        }
        $provider->execute($list = new FetchWebhooks());
        foreach ($list->getWebhooks() as $webhook) {
            if ($webhook->id === $id && $webhook->url === $url) {
                return true;
            }
        }

        return false;
    }

    private function store(?Webhook $webhook): void
    {
        if (null === $webhook || null === $webhook->secret) {
            throw new \RuntimeException(sprintf('The "%s" gateway registered its webhook without answering its secret.', $this->gateway()));
        }
        $path = self::SETTINGS.'.'.$this->gateway();
        if (method_exists($this->settings, 'setSecure')) {
            $this->settings->setSecure($path.'.secret');   // the vault set before the value: sealed when written
        }
        $this->settings->set($path.'.secret', $webhook->secret);
        $this->settings->set($path.'.id', $webhook->id);
        $this->settings->set($path.'.url', $webhook->url);
    }

    private function stored(string $key): ?string
    {
        if (null === $this->settings || null === $this->gateway()) {
            return null;
        }
        try {
            $value = $this->settings->getScalar(self::SETTINGS.'.'.$this->gateway().'.'.$key);
        } catch (\Throwable) {
            return null;   // no settings table yet (a fresh install)
        }

        return \is_scalar($value) ? (string) $value : null;
    }
}
