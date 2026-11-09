<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Motion;

use PHPolygon\Motion\MotionTime;
use PHPolygon\Rendering\Effects\EffectDefinition;
use PHPolygon\Rendering\Effects\EffectFallback;
use PHPolygon\Rendering\Effects\EffectRegistry;
use PHPolygon\Rendering\Effects\EffectTier;
use PHPolygon\Rendering\Effects\FlashClass;
use PHPolygon\Rendering\Effects\ReducedMotion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The time factor every CPU-driven motion (signal tracks, spins, pose clips) takes from
 * the player's reduced-motion choice, and the drive ramp they share.
 */
final class MotionTimeTest extends TestCase
{
    protected function setUp(): void
    {
        EffectRegistry::clear();
        foreach (ReducedMotion::cases() as $mode) {
            EffectRegistry::register(new EffectDefinition('motion_' . strtolower($mode->name), EffectTier::Low, null, EffectFallback::Same, EffectFallback::Same, $mode, FlashClass::None));
        }
    }

    protected function tearDown(): void
    {
        EffectRegistry::setReducedMotion(false);
        EffectRegistry::clear();
    }

    public function testWithoutReducedMotionEverythingRunsAtFullSpeed(): void
    {
        EffectRegistry::setReducedMotion(false, 0.25);
        foreach (ReducedMotion::cases() as $mode) {
            self::assertSame(1.0, MotionTime::scale('motion_' . strtolower($mode->name)), $mode->name);
        }
        self::assertFalse(MotionTime::reduced());
    }

    /** @return iterable<string, array{ReducedMotion, float}> */
    public static function modes(): iterable
    {
        yield 'reduced runs at the player scale' => [ReducedMotion::Reduced, 0.25];
        yield 'frozen stands still' => [ReducedMotion::Frozen, 0.0];
        yield 'off stands still' => [ReducedMotion::Off, 0.0];
        yield 'static keeps full speed' => [ReducedMotion::Static, 1.0];
    }

    #[DataProvider('modes')]
    public function testReducedMotionFollowsTheRegisterEntry(ReducedMotion $mode, float $expected): void
    {
        EffectRegistry::setReducedMotion(true, 0.25);
        self::assertSame($expected, MotionTime::scale('motion_' . strtolower($mode->name)));
        self::assertTrue(MotionTime::reduced());
    }

    public function testAMotionWithoutEntryKeepsFullSpeed(): void
    {
        EffectRegistry::setReducedMotion(true, 0.25);
        self::assertSame(1.0, MotionTime::scale(null));
        self::assertSame(1.0, MotionTime::scale(''));
        self::assertSame(1.0, MotionTime::scale('not_registered'));
    }

    public function testDriveRampsLinearlyAndEaseIsSmooth(): void
    {
        // Two seconds up: a quarter second is an eighth of the swing.
        self::assertEqualsWithDelta(0.125, MotionTime::drive(0.0, true, 2.0, 1.0, 0.25), 1e-12);
        self::assertSame(1.0, MotionTime::drive(0.95, true, 2.0, 1.0, 0.25), 'clamped at full');
        self::assertSame(0.0, MotionTime::drive(0.1, false, 2.0, 1.0, 0.25), 'clamped at rest');
        self::assertSame(1.0, MotionTime::drive(0.0, true, 0.0, 1.0, 0.01), 'no ramp: at once');
        self::assertSame(0.0, MotionTime::drive(1.0, false, 2.0, 0.0, 0.01), 'no ramp down: at once');
        self::assertSame(0.0, MotionTime::ease(-1.0));
        self::assertSame(0.5, MotionTime::ease(0.5));
        self::assertSame(1.0, MotionTime::ease(2.0));
        self::assertLessThan(0.05, MotionTime::ease(0.1), 'starts without a jerk');
    }
}
