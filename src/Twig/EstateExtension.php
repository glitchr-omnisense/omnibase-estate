<?php

namespace Base\Estate\Twig;

use Base\Estate\Service\Activities;
use Base\Estate\Service\OnlinePayments;
use Base\Estate\Service\Spaces;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Attribute\AsTwigFilter;
use Twig\Attribute\AsTwigFunction;

/** The spaces the header offers, money in euros as the pages write it, a cloud folder's link (estate_folder), estate_once() (a style shared by the dashboard's cards, written once a page), and the activities the site runs (estate_activity('ownership'), estate_activities()). */
class EstateExtension implements ResetInterface
{
    /** @var array<string, true> */
    private array $once = [];

    public function __construct(
        private readonly Spaces $spaces,
        private readonly Security $security,
        private readonly OnlinePayments $online,
        private readonly ?Activities $activities = null,
    ) {
    }

    /** Whether the site runs $activity (estate.activities). */
    #[AsTwigFunction('estate_activity')]
    public function activity(string $activity): bool
    {
        return ($this->activities ?? new Activities())->has($activity);
    }

    /** @return list<string> the activities the site runs, as configured */
    #[AsTwigFunction('estate_activities')]
    public function activities(): array
    {
        return array_map(fn ($activity) => $activity->value, ($this->activities ?? new Activities())->all());
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

    /**
     * A cloud folder's path as the cloud's links write it: through
     * omnibase/drive's drive_slug filter when the site has it (drive.slugs),
     * as it is otherwise - estate does not require omnibase/drive.
     */
    #[AsTwigFilter('estate_folder', needsEnvironment: true)]
    public function folder(\Twig\Environment $twig, ?string $path): string
    {
        $slug = $twig->getFilter('drive_slug')?->getCallable();

        return \is_callable($slug) ? (string) $slug((string) $path) : (string) $path;
    }
}
