<?php

namespace Tests\Base\Estate\Service;

use Base\Estate\Service\OnlinePayments;
use Base\Estate\Service\Payments;
use Omnitrade\Exception\InvalidNotificationException;
use Omnitrade\Registry;
use Omnitrade\Stripe\StripeGatewayFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;

/** Stripe's webhook for a rent paid online, signed as Stripe signs it. */
final class OnlinePaymentsWebhookTest extends TestCase
{
    private const SECRET = 'whsec_test';

    protected function setUp(): void
    {
        if (!class_exists(StripeGatewayFactory::class)) {
            self::markTestSkipped('omnitrade/stripe is needed.');
        }
    }

    private function online(): OnlinePayments
    {
        $registry = new Registry([new StripeGatewayFactory(new MockHttpClient())], ['stripe' => ['factory' => 'stripe', 'options' => ['api_key' => 'sk_test_x', 'webhook_secret' => self::SECRET]]]);

        // paidBy() records nothing: Payments, which has a method named "method" PHPUnit cannot double, is not called.
        return new OnlinePayments((new \ReflectionClass(Payments::class))->newInstanceWithoutConstructor(), $registry);
    }

    /** @return array{string, array<string, list<string>>} the body and its headers */
    private static function event(string $type, string $paymentStatus, array $metadata = ['rent_call' => '20'], ?string $secret = null): array
    {
        $body = json_encode(['id' => 'evt_1', 'object' => 'event', 'type' => $type, 'data' => ['object' => [
            'id' => 'cs_test_1', 'object' => 'checkout.session', 'status' => 'complete', 'payment_status' => $paymentStatus,
            'amount_total' => 101000, 'currency' => 'eur', 'payment_method_types' => ['sepa_debit'], 'created' => 1793400000, 'metadata' => $metadata,
        ]]]);
        $time = time();

        return [$body, ['stripe-signature' => [sprintf('t=%d,v1=%s', $time, hash_hmac('sha256', $time.'.'.$body, $secret ?? self::SECRET))]]];
    }

    public function testAPaidSessionNamesItsRentCall(): void
    {
        self::assertSame(['call' => 20, 'reference' => 'cs_test_1'], $this->online()->paidBy(...self::event('checkout.session.completed', 'paid')));
        // A SEPA debit through, days after the tenant left the site.
        self::assertSame(['call' => 20, 'reference' => 'cs_test_1'], $this->online()->paidBy(...self::event('checkout.session.async_payment_succeeded', 'paid')));
    }

    public function testWhatPaysNoRentIsNothing(): void
    {
        self::assertNull($this->online()->paidBy(...self::event('checkout.session.completed', 'unpaid')), 'a SEPA debit not through yet');
        self::assertNull($this->online()->paidBy(...self::event('checkout.session.completed', 'paid', [])), 'a payment that is not a rent');
        self::assertNull($this->online()->paidBy(...self::event('checkout.session.expired', 'unpaid')));
    }

    public function testAnEventNotSignedWithTheSecretIsRefused(): void
    {
        $this->expectException(InvalidNotificationException::class);
        $this->online()->paidBy(...self::event('checkout.session.completed', 'paid', secret: 'whsec_someone_else'));
    }
}
