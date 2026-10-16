<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Audio;

use PHPolygon\Audio\StemSchedule;
use PHPUnit\Framework\TestCase;

final class StemScheduleTest extends TestCase
{
    public function testStartFadesInToItsGain(): void
    {
        $s = new StemSchedule();
        $s->start('a', 1.0, fadeIn: 2.0, gain: 0.4);

        self::assertSame(0.0, $s->gainAt('a', 0.5));
        self::assertSame(0.0, $s->gainAt('a', 1.0));
        self::assertEqualsWithDelta(0.2, $s->gainAt('a', 2.0), 1e-12);
        self::assertEqualsWithDelta(0.4, $s->gainAt('a', 5.0), 1e-12);
        self::assertSame(1.0, $s->startOf('a'));
        self::assertNull($s->startOf('b'));
        self::assertSame(0.0, $s->gainAt('b', 5.0));
    }

    public function testOnlyTheFirstStartCounts(): void
    {
        $s = new StemSchedule();
        self::assertTrue($s->start('a', 1.0));
        self::assertFalse($s->start('a', 3.0));
        self::assertSame(1.0, $s->startOf('a'));
    }

    public function testCrossfade(): void
    {
        $s = new StemSchedule();
        $s->start('a', 0.0, 0.0, 0.5);
        $s->crossfade('a', 'b', 4.0, 1.0, gain: 0.5);

        self::assertEqualsWithDelta(0.25, $s->gainAt('a', 4.5), 1e-12);
        self::assertEqualsWithDelta(0.25, $s->gainAt('b', 4.5), 1e-12);
        self::assertTrue($s->isOver('a', 5.0));
        self::assertFalse($s->isOver('b', 5.0));
        self::assertSame(5.0, $s->endOf('a'));
        self::assertSame(['a', 'b'], $s->stems());
    }

    public function testCutShortensAFadeAndSparesLaterStems(): void
    {
        $s = new StemSchedule();
        $s->start('a', 0.0, 0.0, 1.0);
        $s->fadeOut('a', 2.0, 2.0);           // slow fade from 2 s …
        $s->start('late', 3.0, 0.0, 1.0);     // scheduled to start after the cut
        $s->cut(2.5, 0.1);                     // … cut at 2.5 s

        self::assertEqualsWithDelta(0.725 * 0.5, $s->gainAt('a', 2.55), 1e-12);
        self::assertTrue($s->isOver('a', 2.6));
        self::assertSame(1.0, $s->gainAt('late', 3.5));
        self::assertFalse($s->isOver('late', 10.0));
    }

    public function testCutOnlySelectedStems(): void
    {
        $s = new StemSchedule();
        $s->start('a', 0.0);
        $s->start('b', 0.0);
        $s->cut(1.0, StemSchedule::CUT_SECONDS, ['a']);

        self::assertTrue($s->isOver('a', 1.0 + StemSchedule::CUT_SECONDS));
        self::assertFalse($s->isOver('b', 5.0));
    }

    public function testNumericIdsStayStrings(): void
    {
        $s = new StemSchedule();
        $s->start('1', 0.0);
        self::assertSame(['1'], $s->stems());
    }
}
