<?php

namespace Tests\Base\Estate\Service;

use Base\Estate\Enum\Activity;
use Base\Estate\Service\Activities;
use PHPUnit\Framework\TestCase;

final class ActivitiesTest extends TestCase
{
    public function testASiteRunsItsOwnAssetsByDefault(): void
    {
        $activities = new Activities();

        self::assertSame([Activity::ASSETS], $activities->all());
        self::assertTrue($activities->has('assets'));
        self::assertFalse($activities->has(Activity::MANAGEMENT));
    }

    public function testTheActivitiesCombineInTheirOwnOrder(): void
    {
        $activities = new Activities(['transaction', 'management', 'management']);

        self::assertSame([Activity::MANAGEMENT, Activity::TRANSACTION], $activities->all());
        self::assertTrue($activities->any(...Activity::RENTAL), 'management lets homes');
        self::assertFalse((new Activities(['transaction']))->any(...Activity::RENTAL));
    }

    public function testAnUnknownActivityIsRefused(): void
    {
        $this->expectException(\ValueError::class);
        new Activities(['co-ownership']);
    }
}
