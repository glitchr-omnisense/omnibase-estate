<?php

namespace Tests\Base\Estate\Service;

use Base\Database\Attribute\Vault;
use Base\Estate\Service\OnlinePayments;
use Base\Estate\Service\PaymentWebhook;
use Base\Estate\Service\Payments;
use Base\Service\SettingBagInterface;
use Omnitrade\Exception\InvalidNotificationException;
use Omnitrade\Registry;
use Omnitrade\Request\RegisterWebhook;
use Omnitrade\Stripe\StripeGatewayFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** The Stripe webhook the application registers itself, its secret sealed in the settings, Stripe answered by a mock. */
final class PaymentWebhookTest extends TestCase
{
    private const URL = 'https://rents.example.org/estate/payments/webhook';

    /** @var array<string, array<string, mixed>> Stripe's endpoints, by id */
    private array $endpoints = [];

    /** @var list<string> method and path of each call to Stripe */
    private array $calls = [];

    private int $made = 0;

    /** The settings, in memory: what is written, and which paths were sealed before their value. */
    private object $settings;

    protected function setUp(): void
    {
        if (!class_exists(StripeGatewayFactory::class) || !class_exists(RegisterWebhook::class)) {
            self::markTestSkipped('omnitrade/stripe with webhook registration is needed.');
        }
        $this->settings = new class implements SettingBagInterface {
            public array $values = [];
            public array $sealed = [];

            public function clearAll(): void {}
            public function all(?string $locale = null): array { return $this->values; }
            public function get(null|string|array $path = null, ?string $locale = null): array { return ['_self' => $this->values[$path] ?? null]; }
            public function getScalar(null|string|array $path, ?string $locale = null): mixed { return $this->values[$path] ?? null; }
            public function getBagParameters(): array { return []; }
            public function set(string $path, $value, ?string $locale = null): static
            {
                $this->values[$path] = $value;

                return $this;
            }
            public function setSecure(string $path, bool $flag = true): static
            {
                if (\array_key_exists($path, $this->values) && !\in_array($path, $this->sealed, true)) {
                    throw new \LogicException('sealed after its value was written');
                }
                $this->sealed = array_values(array_unique([...$this->sealed, $path]));

                return $this;
            }
        };
    }

    private function registry(array $options = []): Registry
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            parse_str((string) ($options['body'] ?? ''), $sent);
            $path = (string) parse_url($url, \PHP_URL_PATH);
            $this->calls[] = $method.' '.$path;
            $json = static fn (array $data, int $status = 200) => new MockResponse(json_encode($data), ['http_code' => $status]);
            $id = substr($path, \strlen('/v1/webhook_endpoints/'));

            return match (true) {
                'GET' === $method => $json(['object' => 'list', 'has_more' => false, 'data' => array_values($this->endpoints)]),
                'POST' === $method => $json(($this->endpoints[$made = 'we_'.++$this->made] = ['id' => $made, 'object' => 'webhook_endpoint', 'url' => $sent['url'], 'enabled_events' => $sent['enabled_events'], 'api_version' => $sent['api_version'] ?? null, 'status' => 'enabled']) + ['secret' => 'whsec_mock_'.$this->made]),
                'DELETE' === $method && isset($this->endpoints[$id]) => (function () use ($id, $json) { unset($this->endpoints[$id]); return $json(['id' => $id, 'deleted' => true]); })(),
                default => $json(['error' => ['message' => 'No such webhook endpoint', 'code' => 'resource_missing']], 404),
            };
        });

        return new Registry([new StripeGatewayFactory($http)], ['stripe' => ['factory' => 'stripe', 'options' => $options + ['api_key' => 'sk_test_x']]]);
    }

    private function webhook(Registry $registry, string $url = self::URL, bool $canSeal = true): PaymentWebhook
    {
        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturn($url);
        $vault = new class($canSeal) extends Vault {
            public function __construct(private readonly bool $can)
            {
            }

            public function getMarshaller(?string $vault = null): ?\Symfony\Component\Cache\Marshaller\MarshallerInterface
            {
                return $this->can ? new \Symfony\Component\Cache\Marshaller\SodiumMarshaller([sodium_crypto_box_keypair()]) : null;
            }
        };

        return new PaymentWebhook($router, $registry, $this->settings, 'stripe', $vault);
    }

    public function testRegisteredOnceItsSecretSealedThenKept(): void
    {
        $webhook = $this->webhook($this->registry());

        self::assertSame(PaymentWebhook::REGISTERED, $webhook->ensure());
        self::assertSame(['GET /v1/webhook_endpoints', 'POST /v1/webhook_endpoints'], $this->calls);
        self::assertSame(['api.webhook.estate_payment_webhook.stripe.secret'], $this->settings->sealed, 'sealed before written');
        self::assertSame('whsec_mock_1', $webhook->secret());
        self::assertSame(['id' => 'we_1', 'url' => self::URL], $webhook->registered());
        self::assertSame(['checkout.session.completed', 'checkout.session.async_payment_succeeded', 'checkout.session.async_payment_failed', 'checkout.session.expired'], $this->endpoints['we_1']['enabled_events']);

        // The next deploy: there, nothing done.
        $this->calls = [];
        self::assertSame(PaymentWebhook::KEPT, $webhook->ensure());
        self::assertSame(['GET /v1/webhook_endpoints'], $this->calls);

        // Deleted at Stripe meanwhile: registered again.
        $this->endpoints = [];
        self::assertSame(PaymentWebhook::REGISTERED, $webhook->ensure());
        self::assertSame('whsec_mock_2', $webhook->secret());

        // Forced: replaced, never doubled.
        self::assertSame(PaymentWebhook::REGISTERED, $webhook->ensure(force: true));
        self::assertSame(['we_3'], array_keys($this->endpoints));
        self::assertSame('whsec_mock_3', $webhook->secret());
    }

    public function testNothingRegisteredOnAnAddressNoProviderReaches(): void
    {
        foreach (['https://meyers.local/estate/payments/webhook', 'http://localhost/estate/payments/webhook', 'https://localhost:8463/estate/payments/webhook', 'https://192.168.1.20/estate/payments/webhook'] as $url) {
            $webhook = $this->webhook($this->registry(), $url);
            self::assertSame(PaymentWebhook::UNREACHABLE, $webhook->ensure(), $url);
            self::assertNotNull($webhook->unreachable());
        }
        self::assertSame([], $this->calls, 'Stripe never asked');
        self::assertSame([], $this->settings->values);
    }

    public function testAConfiguredSecretWinsAndNothingIsRegistered(): void
    {
        self::assertSame(PaymentWebhook::CONFIGURED, $this->webhook($this->registry(['webhook_secret' => 'whsec_from_env']))->ensure());
        self::assertSame([], $this->calls);
    }

    public function testNoSecretAskedForWhenTheVaultCannotSealIt(): void
    {
        self::assertSame(PaymentWebhook::NO_VAULT, $this->webhook($this->registry(), canSeal: false)->ensure());
        self::assertNotContains('POST /v1/webhook_endpoints', $this->calls);
        self::assertSame([], $this->settings->values);
    }

    public function testNoGatewayIsOff(): void
    {
        $router = $this->createStub(UrlGeneratorInterface::class);
        self::assertSame(PaymentWebhook::OFF, (new PaymentWebhook($router, null, $this->settings))->ensure());
        self::assertSame(PaymentWebhook::OFF, (new PaymentWebhook($router, $this->registry(), $this->settings, null))->ensure());
    }

    public function testTheWebhookIsCheckedWithTheSecretRegistered(): void
    {
        $registry = $this->registry();
        $webhook = $this->webhook($registry);
        $webhook->ensure();
        $online = new OnlinePayments((new \ReflectionClass(Payments::class))->newInstanceWithoutConstructor(), $registry, webhook: $webhook);

        self::assertSame(['call' => 20, 'reference' => 'cs_test_1'], $online->paidBy(...self::event('whsec_mock_1')));
        $this->expectException(InvalidNotificationException::class);
        $online->paidBy(...self::event('whsec_someone_else'));
    }

    public function testAConfiguredSecretStillChecksTheWebhook(): void
    {
        $this->settings->values['api.webhook.estate_payment_webhook.stripe.secret'] = 'whsec_stored';
        $registry = $this->registry(['webhook_secret' => 'whsec_from_env']);
        $online = new OnlinePayments((new \ReflectionClass(Payments::class))->newInstanceWithoutConstructor(), $registry, webhook: $this->webhook($registry));

        self::assertSame(['call' => 20, 'reference' => 'cs_test_1'], $online->paidBy(...self::event('whsec_from_env')));
        $this->expectException(InvalidNotificationException::class);
        $online->paidBy(...self::event('whsec_stored'));
    }

    /** @return array{string, array<string, list<string>>} a paid session's event, signed as Stripe signs it */
    private static function event(string $secret): array
    {
        $body = json_encode(['id' => 'evt_1', 'object' => 'event', 'type' => 'checkout.session.completed', 'data' => ['object' => [
            'id' => 'cs_test_1', 'object' => 'checkout.session', 'status' => 'complete', 'payment_status' => 'paid',
            'amount_total' => 101000, 'currency' => 'eur', 'created' => 1793400000, 'metadata' => ['rent_call' => '20'],
        ]]]);
        $time = time();

        return [$body, ['stripe-signature' => [sprintf('t=%d,v1=%s', $time, hash_hmac('sha256', $time.'.'.$body, $secret))]]];
    }
}
