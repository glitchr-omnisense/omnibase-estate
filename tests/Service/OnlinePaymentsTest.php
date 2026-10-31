<?php

namespace Tests\Base\Estate\Service;

use Base\Estate\Entity\Lease;
use Base\Estate\Entity\RentCall;
use Base\Estate\Entity\Unit;
use Base\Estate\Service\OnlinePayments;
use Base\Estate\Service\Payments;
use Omnitrade\Registry;
use Omnitrade\Stripe\StripeGatewayFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** A rent paid by card or SEPA debit through omnitrade's Stripe gateway, Stripe answered by a mock. */
final class OnlinePaymentsTest extends TestCase
{
    /** @var list<string> the Idempotency-Key of each Checkout session asked */
    private array $keys = [];

    protected function setUp(): void
    {
        if (!class_exists(StripeGatewayFactory::class)) {
            self::markTestSkipped('omnitrade/stripe is needed.');
        }
    }

    private function online(): OnlinePayments
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            foreach ($options['headers'] ?? [] as $header) {
                if (str_starts_with(strtolower($header), 'idempotency-key:')) {
                    $this->keys[] = trim(substr($header, \strlen('idempotency-key:')));
                }
            }

            return new MockResponse(json_encode(['id' => 'cs_test_1', 'object' => 'checkout.session', 'status' => 'open', 'payment_status' => 'unpaid', 'amount_total' => 101000, 'currency' => 'eur', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_1', 'metadata' => []]));
        });
        $registry = new Registry([new StripeGatewayFactory($http)], ['stripe' => ['factory' => 'stripe', 'options' => ['api_key' => 'sk_test_x']]]);

        // start() records nothing: Payments, which has a method named "method" PHPUnit cannot double, is not called.
        return new OnlinePayments((new \ReflectionClass(Payments::class))->newInstanceWithoutConstructor(), $registry);
    }

    private function call(int $paid = 0): RentCall
    {
        $unit = $this->createStub(Unit::class);
        $unit->method('getName')->willReturn('Appartement Krutenau');
        $lease = $this->createStub(Lease::class);
        $lease->method('getUnit')->willReturn($unit);
        $call = $this->createStub(RentCall::class);
        $call->method('getReference')->willReturn('AVIS-2026-11-MA-2023-004');
        $call->method('getPaid')->willReturn($paid);
        $call->method('getRemaining')->willReturn(101000 - $paid);
        $call->method('getPeriod')->willReturn(new \DateTimeImmutable('2026-11-01'));
        $call->method('getLease')->willReturn($lease);
        $call->method('getId')->willReturn(20);

        return $call;
    }

    public function testASecondClickAsksForTheSamePage(): void
    {
        $online = $this->online();
        $at = new \DateTimeImmutable('2026-10-31 10:01');
        self::assertSame('https://checkout.stripe.com/c/pay/cs_test_1', $online->start($this->call(), 'card', 'https://app.example/back', 'https://app.example/cancel', null, $at));
        $online->start($this->call(), 'card', 'https://app.example/back', 'https://app.example/cancel', null, $at->modify('+20 seconds'));

        self::assertSame(['AVIS-2026-11-MA-2023-004-0-card-20261031100', 'AVIS-2026-11-MA-2023-004-0-card-20261031100'], $this->keys);
    }

    public function testAnotherMethodALaterTryOrAPartPaidAskAgain(): void
    {
        $online = $this->online();
        $at = new \DateTimeImmutable('2026-10-31 10:01');
        $online->start($this->call(), 'card', 'https://app.example/back', 'https://app.example/cancel', null, $at);
        $online->start($this->call(), 'sepa_debit', 'https://app.example/back', 'https://app.example/cancel', null, $at);
        $online->start($this->call(), 'card', 'https://app.example/back', 'https://app.example/cancel', null, $at->modify('+15 minutes'));
        $online->start($this->call(50000), 'card', 'https://app.example/back', 'https://app.example/cancel', null, $at);
        $online->start($this->call(), 'paypal', 'https://app.example/back', 'https://app.example/cancel', null, $at);

        self::assertCount(5, array_unique($this->keys), 'a refusal kept under the first key does not answer the others');
        self::assertSame('AVIS-2026-11-MA-2023-004-0-any-20261031100', $this->keys[4], 'a method not offered: Checkout\'s choice');
    }
}
