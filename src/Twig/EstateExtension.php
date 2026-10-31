<?php

namespace Base\Estate\Twig;

use Base\Estate\Service\OnlinePayments;
use Base\Estate\Service\Spaces;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Attribute\AsTwigFilter;
use Twig\Attribute\AsTwigFunction;

/** The spaces the header offers, money in euros as the pages write it, and estate_once() (a style shared by the dashboard's cards, written once a page). */
class EstateExtension implements ResetInterface
{
    /** @var array<string, true> */
    private array $once = [];

    public function __construct(
        private readonly Spaces $spaces,
        private readonly Security $security,
        private readonly OnlinePayments $online,
    ) {
    }

    /** @return list<array{key: string, route: string}> */
    #[AsTwigFunction('estate_spaces')]
    public function spaces(): array
    {
        $user = $this->security->getUser();

        return $user instanceof \Base\Entity\User ? $this->spaces->of($user) : [];
    }

    /** True the first time it is asked in a request only. */
    #[AsTwigFunction('estate_once')]
    public function once(string $key): bool
    {
        if (isset($this->once[$key])) {
            return false;
        }

        return $this->once[$key] = true;
    }

    public function reset(): void
    {
        $this->once = [];
    }

    #[AsTwigFunction('estate_online_payment')]
    public function onlinePayment(): bool
    {
        return $this->online->isAvailable();
    }

    /** 123456 → "1 234,56 €" (cents in); `round` drops the cents. */
    #[AsTwigFilter('euros')]
    public function euros(?int $cents, bool $round = false): string
    {
        if (null === $cents) {
            return '—';
        }
        $formatted = number_format($cents / 100, $round ? 0 : 2, ',', ' ');

        return str_replace(' ', "\u{202F}", $formatted)."\u{00A0}€";
    }
}
