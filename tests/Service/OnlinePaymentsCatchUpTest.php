<?php

namespace Tests\Base\Estate\Service;

use Base\Estate\Entity\Lease;
use Base\Estate\Entity\RentCall;
use Base\Estate\Entity\Unit;
use Base\Estate\Repository\RentCallRepository;
use Base\Estate\Service\OnlinePayments;
use Base\Estate\Service\Payments;
use Doctrine\ORM\EntityManagerInterface;
use Omnitrade\Registry;
use Omnitrade\Stripe\StripeGatewayFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** An online payment kept on its rent call when it starts, read again later: no webhook needed. */
final class OnlinePaymentsCatchUpTest extends TestCase
{
    /** @var list<string> the sessions read from Stripe */
    private array $read = [];

    protected function setUp(): void
    {
        if (!class_exists(StripeGatewayFactory::class)) {
            self::markTestSkipped('omnitrade/stripe is needed.');
        }
    }

    private function online(EntityManagerInterface $entityManager): OnlinePayments
    {
        $http = new MockHttpClient(function (string $method, string $url): MockResponse {
            if ('GET' === $method) {
                $this->read[] = basename((string) parse_url($url, \PHP_URL_PATH));
            }

            // Open and unpaid: a SEPA debit not through yet.
            return new MockResponse(json_encode(['id' => 'cs_test_1', 'object' => 'checkout.session', 'status' => 'open', 'payment_status' => 'unpaid', 'amount_total' => 101000, 'currency' => 'eur', 'payment_method_types' => ['sepa_debit'], 'created' => 1793400000, 'url' => 'https://checkout.stripe.com/c/pay/cs_test_1', 'metadata' => ['rent_call' => '20']]));
        });
        $registry = new Registry([new StripeGatewayFactory($http)], ['stripe' => ['factory' => 'stripe', 'options' => ['api_key' => 'sk_test_x']]]);

        // Nothing is paid here: Payments, which has a method named "method" PHPUnit cannot double, is not called.
        return new OnlinePayments((new \ReflectionClass(Payments::class))->newInstanceWithoutConstructor(), $registry, 'stripe', ['card', 'sepa_debit'], $entityManager);
    }

    public function testTheSessionStartedIsKeptOnTheCall(): void
    {
        $unit = $this->createStub(Unit::class);
        $unit->method('getName')->willReturn('Appartement Krutenau');
        $lease = $this->createStub(Lease::class);
        $lease->method('getUnit')->willReturn($unit);
        $call = $this->createMock(RentCall::class);
        $call->method('getReference')->willReturn('AVIS-2026-11-MA-2023-004');
        $call->method('getRemaining')->willReturn(101000);
        $call->method('getPeriod')->willReturn(new \DateTimeImmutable('2026-11-01'));
        $call->method('getLease')->willReturn($lease);
        $at = new \DateTimeImmutable('2026-10-31 10:01');
        $call->expects(self::once())->method('setOnline')->with('cs_test_1', $at);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $this->online($entityManager)->start($call, 'sepa_debit', 'https://app.example/back', 'https://app.example/cancel', null, $at);
    }

    public function testOpenSessionsAreReadAgainAndOldOnesGivenUp(): void
    {
        $now = new \DateTimeImmutable('2026-11-10 12:00');
        $recent = $this->createMock(RentCall::class);
        $recent->method('getOnlineReference')->willReturn('cs_test_1');
        $recent->method('getOnlineStartedAt')->willReturn($now->modify('-3 days'));
        $recent->expects(self::never())->method('setOnline');
        $old = $this->createMock(RentCall::class);
        $old->method('getOnlineReference')->willReturn('cs_test_old');
        $old->method('getOnlineStartedAt')->willReturn($now->modify('-20 days'));
        $old->expects(self::once())->method('setOnline')->with(null);

        $repository = $this->createStub(RentCallRepository::class);
        $repository->method('findOnlinePending')->willReturn([$recent, $old]);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);
        $entityManager->expects(self::once())->method('flush');

        self::assertSame(0, $this->online($entityManager)->catchUp(15, $now), 'not paid yet: nothing recorded');
        self::assertSame(['cs_test_1'], $this->read, 'the old one is not asked for');
    }
}
