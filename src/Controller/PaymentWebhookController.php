<?php

namespace Base\Estate\Controller;

use Base\Estate\Entity\RentCall;
use Base\Estate\Service\OnlinePayments;
use Doctrine\ORM\EntityManagerInterface;
use Omnitrade\Exception\InvalidNotificationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Where the payment provider says a rent was paid (Stripe: a Checkout
 * session completed, a SEPA debit through): public, checked by the gateway
 * against its signature. The rent is recorded even when the tenant never
 * came back to the site - a page closed, a debit paid days later.
 */
class PaymentWebhookController extends AbstractController
{
    public function __construct(
        private readonly OnlinePayments $online,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/estate/payments/webhook', name: 'estate_payment_webhook', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        if (!$this->online->isAvailable()) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }
        try {
            $paid = $this->online->paidBy($request->getContent(), $request->headers->all());
        } catch (InvalidNotificationException $e) {
            return new Response($e->getMessage(), Response::HTTP_BAD_REQUEST);
        }

        $call = null !== $paid ? $this->entityManager->find(RentCall::class, $paid['call']) : null;
        if (null !== $call && !$call->isPaid()) {
            // The session's reference is the payment's: the tenant's return records nothing twice.
            $this->online->complete($call, $paid['reference']);
        }

        return new Response('', Response::HTTP_NO_CONTENT);
    }
}
