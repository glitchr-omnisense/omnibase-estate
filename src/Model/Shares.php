<?php

namespace Base\Estate\Model;

/**
 * Exact fractions for a shared ownership's shares (1/3 stays 1/3: a
 * numerator and a denominator, never a float), and the way an amount is
 * divided by them to the cent:
 *
 *   Shares::sum([[1, 2], [1, 4], [1, 4]])                    // [1, 1]
 *   Shares::allocate(600000, ['a' => [1, 2], 'b' => [1, 4], 'c' => [1, 4]])
 *   // ['a' => 300000, 'b' => 150000, 'c' => 150000]
 *
 * allocate() gives each its exact part rounded down, then the cents the
 * rounding left to the largest remainders (the first one on a tie), so the
 * parts always add up to the amount: 100,00 € by thirds is 33,34 + 33,33 +
 * 33,33.
 */
final class Shares
{
    /** @return array{0: int, 1: int} the fraction in lowest terms, its denominator positive */
    public static function reduce(int $numerator, int $denominator): array
    {
        if (0 === $denominator) {
            throw new \InvalidArgumentException('A share\'s denominator is never zero.');
        }
        if ($denominator < 0) {
            [$numerator, $denominator] = [-$numerator, -$denominator];
        }
        $gcd = self::gcd(abs($numerator), $denominator);

        return [intdiv($numerator, $gcd), intdiv($denominator, $gcd)];
    }

    /**
     * @param iterable<array{0: int, 1: int}> $fractions
     *
     * @return array{0: int, 1: int} their exact sum, in lowest terms ([0, 1] for none)
     */
    public static function sum(iterable $fractions): array
    {
        [$n, $d] = [0, 1];
        foreach ($fractions as [$numerator, $denominator]) {
            [$numerator, $denominator] = self::reduce($numerator, $denominator);
            $lcm = intdiv($d, self::gcd($d, $denominator)) * $denominator;
            [$n, $d] = self::reduce($n * intdiv($lcm, $d) + $numerator * intdiv($lcm, $denominator), $lcm);
        }

        return [$n, $d];
    }

    /** @param iterable<array{0: int, 1: int}> $fractions */
    public static function isWhole(iterable $fractions): bool
    {
        return [1, 1] === self::sum($fractions);
    }

    /** "1/4", "1" for a whole, "0" for nothing. */
    public static function format(int $numerator, int $denominator): string
    {
        [$numerator, $denominator] = self::reduce($numerator, $denominator);

        return 1 === $denominator ? (string) $numerator : $numerator.'/'.$denominator;
    }

    /** The fraction as a percentage, for reading only: 33.33 for 1/3. */
    public static function percent(int $numerator, int $denominator): float
    {
        return round(100 * $numerator / $denominator, 2);
    }

    /**
     * An amount in minor units divided by fractions that add up to one, to
     * the cent, the parts adding up to the amount exactly.
     *
     * @template K of array-key
     *
     * @param array<K, array{0: int, 1: int}> $fractions
     *
     * @return array<K, int> in the order given
     */
    public static function allocate(int $amount, array $fractions): array
    {
        if ([] === $fractions) {
            return [];
        }
        if (!self::isWhole($fractions)) {
            [$n, $d] = self::sum($fractions);
            throw new \InvalidArgumentException(sprintf('The shares add up to %s, not to one.', self::format($n, $d)));
        }
        $sign = $amount < 0 ? -1 : 1;
        $amount = abs($amount);

        $parts = [];
        $remainders = [];
        foreach ($fractions as $key => [$numerator, $denominator]) {
            [$numerator, $denominator] = self::reduce($numerator, $denominator);
            $exact = $amount * $numerator;
            $parts[$key] = intdiv($exact, $denominator);
            // The remainder over a common scale, so remainders of different denominators compare.
            $remainders[$key] = ($exact % $denominator) / $denominator;
        }
        $left = $amount - array_sum($parts);
        $order = array_keys($remainders);
        // Largest remainder first; on a tie, the order given.
        $position = array_flip($order);
        usort($order, fn ($a, $b) => [$remainders[$b], $position[$a]] <=> [$remainders[$a], $position[$b]]);
        for ($i = 0; $left > 0; ++$i, --$left) {
            ++$parts[$order[$i % \count($order)]];
        }

        return array_map(fn (int $part) => $sign * $part, $parts);
    }

    private static function gcd(int $a, int $b): int
    {
        while (0 !== $b) {
            [$a, $b] = [$b, $a % $b];
        }

        return 0 === $a ? 1 : $a;
    }
}
