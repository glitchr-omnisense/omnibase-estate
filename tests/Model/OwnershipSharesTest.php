<?php

namespace Tests\Base\Estate\Model;

use Base\Estate\Entity\Organisation;
use Base\Estate\Entity\Ownership;
use Base\Estate\Entity\OwnershipShare;
use Base\Estate\Model\Shares;
use PHPUnit\Framework\TestCase;

/**
 * A shared ownership's shares are exact fractions that add up to one on
 * every date they change; an amount divided by them adds up to the amount,
 * to the cent.
 */
final class OwnershipSharesTest extends TestCase
{
    private static function ownership(): Ownership
    {
        return new Ownership(new Organisation('Meyers & Associée'), 'Indivision Robertsau');
    }

    public function testSharesAddUpToOneExactly(): void
    {
        self::assertSame([1, 1], Shares::sum([[1, 2], [1, 4], [1, 4]]));
        self::assertSame([1, 1], Shares::sum([[1, 3], [1, 3], [1, 3]]), 'thirds are exact, never 0.333…');
        self::assertSame([3, 4], Shares::sum([[1, 2], [2, 8]]));
        self::assertSame('1/4', Shares::format(2, 8));

        $ownership = self::ownership();
        $start = new \DateTimeImmutable('2026-01-01');
        (new OwnershipShare($ownership, 'Meyers & Associée', 1, 2, $start))->setCompany(true);
        (new OwnershipShare($ownership, 'Claire Hoffmann', 1, 4, $start))->setEmail('claire@example.org');
        self::assertFalse($ownership->isBalanced());
        self::assertSame('3/4', $ownership->problems()[0]['sum']);

        (new OwnershipShare($ownership, 'Antoine Schaller', 1, 4, $start))->setEmail('antoine@example.org');
        self::assertTrue($ownership->isBalanced());
        self::assertSame(['company' => [1, 2], 'email:claire@example.org' => [1, 4], 'email:antoine@example.org' => [1, 4]], $ownership->fractionsOn($start));
    }

    public function testAShareSoldOnADateIsCheckedThatDay(): void
    {
        $ownership = self::ownership();
        $start = new \DateTimeImmutable('2026-01-01');
        $sale = new \DateTimeImmutable('2026-07-01');
        (new OwnershipShare($ownership, 'Meyers & Associée', 1, 2, $start))->setCompany(true);
        (new OwnershipShare($ownership, 'Claire Hoffmann', 1, 2, $start))->setEmail('claire@example.org')->setEndsOn($sale);
        (new OwnershipShare($ownership, 'Claire Hoffmann', 1, 4, $sale))->setEmail('claire@example.org');
        $problems = $ownership->problems();
        self::assertSame([['on' => '2026-07-01', 'sum' => '3/4']], array_map(fn (array $p) => ['on' => $p['on']->format('Y-m-d'), 'sum' => $p['sum']], $problems), 'half of her share sold, nobody bought it yet');

        (new OwnershipShare($ownership, 'Antoine Schaller', 1, 4, $sale))->setEmail('antoine@example.org');
        self::assertTrue($ownership->isBalanced());
        self::assertSame([1, 2], $ownership->fractionsOn(new \DateTimeImmutable('2026-06-30'))['email:claire@example.org']);
        self::assertSame([1, 4], $ownership->fractionsOn($sale)['email:claire@example.org']);
    }

    public function testAnAmountIsDividedToTheCent(): void
    {
        self::assertSame(['a' => 300000, 'b' => 150000, 'c' => 150000], Shares::allocate(600000, ['a' => [1, 2], 'b' => [1, 4], 'c' => [1, 4]]));
        self::assertSame(['a' => 3334, 'b' => 3333, 'c' => 3333], Shares::allocate(10000, ['a' => [1, 3], 'b' => [1, 3], 'c' => [1, 3]]));
        self::assertSame(10001, array_sum(Shares::allocate(10001, ['a' => [1, 3], 'b' => [1, 6], 'c' => [1, 2]])));

        $this->expectException(\InvalidArgumentException::class);
        Shares::allocate(10000, ['a' => [1, 2], 'b' => [1, 4]]);
    }

    public function testAFractionIsBetweenZeroAndOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new OwnershipShare(self::ownership(), 'Trop', 5, 4);
    }
}
