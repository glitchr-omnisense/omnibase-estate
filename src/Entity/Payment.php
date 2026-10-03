<?php

namespace Base\Estate\Entity;

use Base\Estate\Enum\PaymentMethod;
use Doctrine\ORM\Mapping as ORM;

/** Money received for a rent call: a transfer seen at the bank, a card or SEPA payment online, a cheque. */
#[ORM\Entity]
#[ORM\Table(name: 'estate_payment')]
class Payment
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: RentCall::class, inversedBy: 'payments')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private RentCall $rentCall;

    #[ORM\Column]
    private int $amount = 0;

    #[ORM\Column(length: 16, enumType: PaymentMethod::class)]
    private PaymentMethod $method = PaymentMethod::TRANSFER;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $paidOn;

    /** The provider's reference (a Checkout session), the bank line's, the cheque's number. */
    #[ORM\Column(length: 128, nullable: true, unique: true)]
    private ?string $reference = null;

    public function __construct(RentCall $rentCall, int $amount, PaymentMethod $method, ?\DateTimeImmutable $paidOn = null, ?string $reference = null)
    {
        $this->rentCall = $rentCall;
        $this->amount = $amount;
        $this->method = $method;
        $this->paidOn = $paidOn ?? new \DateTimeImmutable('today');
        $this->reference = $reference;
    }

    public function __toString(): string { return sprintf('%s · %.2f €', $this->rentCall->getReference(), $this->amount / 100); }
    public function getId(): ?int { return $this->id; }
    public function getRentCall(): RentCall { return $this->rentCall; }
    public function getAmount(): int { return $this->amount; }
    public function getMethod(): PaymentMethod { return $this->method; }
    public function getPaidOn(): \DateTimeImmutable { return $this->paidOn; }
    public function getReference(): ?string { return $this->reference; }
}
