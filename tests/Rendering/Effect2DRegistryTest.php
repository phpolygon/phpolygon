<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\TestCase;
use PHPolygon\Rendering\Effect2D\Effect2D;
use PHPolygon\Rendering\Effect2D\Effect2DRegistry;
use PHPolygon\Rendering\Effect2D\EffectRenderer2DInterface;
use PHPolygon\Rendering\Effects\EffectDefinition;
use PHPolygon\Rendering\Effects\EffectFallback;
use PHPolygon\Rendering\Effects\EffectRegistry;
use PHPolygon\Rendering\Effects\EffectTier;
use PHPolygon\Rendering\Effects\FlashClass;
use PHPolygon\Rendering\Effects\ReducedMotion;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\NullRenderer2D;
use PHPolygon\Rendering\Renderer2DInterface;
use PHPolygon\Rendering\ScaledRenderer2D;
use PHPolygon\UI\UIScale;

/**
 * The 2D effect register without a GPU: definitions are checked, a renderer without
 * effects gets the fallback, a scaled renderer hands the effect on in physical pixels,
 * and the effect register asks for an entry per 2D effect and answers reduced motion.
 */
final class Effect2DRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        Effect2DRegistry::clear();
        EffectRegistry::clear();
    }

    protected function tearDown(): void
    {
        Effect2DRegistry::clear();
        EffectRegistry::clear();
    }

    public function testDefinitionsAreChecked(): void
    {
        foreach ([
            fn () => new Effect2D('Glow', 'x'),
            fn () => new Effect2D('glow', 'x', samplers: ['mask']),
            fn () => new Effect2D('glow', 'x', samplers: array_map(static fn (int $i): string => "u_s{$i}", range(0, 8))),
            fn () => new Effect2D('glow', 'x', padding: -1.0),
        ] as $i => $make) {
            try {
                $make();
                self::fail("definition {$i} was accepted");
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testARendererWithoutEffectsDrawsTheFallback(): void
    {
        $calls = [];
        Effect2DRegistry::register(new Effect2D('glow', 'x', fallback: function (Renderer2DInterface $r, float $x, float $y, float $w, float $h, array $p) use (&$calls): void {
            $calls[] = [$x, $y, $w, $h, $p];
        }));
        self::assertTrue(Effect2DRegistry::draw(new NullRenderer2D(), 'glow', 1.0, 2.0, 3.0, 4.0, ['u_k' => 0.5]));
        self::assertSame([[1.0, 2.0, 3.0, 4.0, ['u_k' => 0.5]]], $calls);

        Effect2DRegistry::register(new Effect2D('bare', 'x'));
        self::assertFalse(Effect2DRegistry::draw(new NullRenderer2D(), 'bare', 0.0, 0.0, 1.0, 1.0), 'no fallback: nothing drawn');
        self::assertFalse(Effect2DRegistry::draw(new NullRenderer2D(), 'unknown', 0.0, 0.0, 1.0, 1.0));
    }

    public function testAnEffectThatRunsSkipsTheFallbackAndOneThatCannotUsesIt(): void
    {
        $fallbacks = 0;
        Effect2DRegistry::register(new Effect2D('glow', 'x', fallback: function () use (&$fallbacks): void {
            $fallbacks++;
        }));
        $renderer = new EffectRecordingRenderer2D();
        self::assertTrue(Effect2DRegistry::draw($renderer, 'glow', 0.0, 0.0, 10.0, 10.0));
        self::assertSame(0, $fallbacks);
        self::assertCount(1, $renderer->effects);

        $renderer->runs = false;
        self::assertTrue(Effect2DRegistry::draw($renderer, 'glow', 0.0, 0.0, 10.0, 10.0));
        self::assertSame(1, $fallbacks, 'cannot run here: fallback');
    }

    public function testAScaledRendererHandsTheEffectOnInPhysicalPixels(): void
    {
        Effect2DRegistry::register(new Effect2D('glow', 'x', padding: 4.0));
        $inner = new EffectRecordingRenderer2D();
        $scale = new UIScale();
        $scale->set(2.0);
        Effect2DRegistry::draw(new ScaledRenderer2D($inner, $scale), 'glow', 10.0, 20.0, 30.0, 40.0, ['u_k' => 1.0]);
        self::assertSame([['glow', 20.0, 40.0, 60.0, 80.0, ['u_k' => 1.0], 8.0]], $inner->effects);

        self::assertFalse((new ScaledRenderer2D(new NullRenderer2D(), $scale))->drawEffect('glow', 0.0, 0.0, 1.0, 1.0), 'inner renderer without effects');
    }

    public function testEvery2dEffectNeedsAnEntry(): void
    {
        Effect2DRegistry::register(new Effect2D('glow', 'x'));
        self::assertSame(['glow'], EffectRegistry::missing());
        EffectRegistry::register(self::entry('glow', ReducedMotion::Frozen));
        self::assertSame([], EffectRegistry::missing());
    }

    public function testReducedMotionFollowsTheEntry(): void
    {
        $frozen = self::entry('a', ReducedMotion::Frozen);
        $reduced = self::entry('b', ReducedMotion::Reduced);
        $off = self::entry('c', ReducedMotion::Off);

        foreach ([$frozen, $reduced, $off, null] as $entry) {
            self::assertSame(1.0, EffectRegistry::motionScale($entry), 'reduced motion is off by default');
            self::assertFalse(EffectRegistry::timeFrozen($entry));
            self::assertFalse(EffectRegistry::motionSwitchesOff($entry));
        }

        EffectRegistry::setReducedMotion(true, 0.3);
        self::assertTrue(EffectRegistry::timeFrozen($frozen));
        self::assertSame(1.0, EffectRegistry::motionScale($frozen));
        self::assertSame(0.3, EffectRegistry::motionScale($reduced));
        self::assertFalse(EffectRegistry::timeFrozen($reduced));
        self::assertTrue(EffectRegistry::motionSwitchesOff($off));
        self::assertSame(1.0, EffectRegistry::motionScale(null), 'no entry: runs as it is');

        EffectRegistry::setReducedMotion(true, 7.0);
        self::assertSame(1.0, EffectRegistry::motionScale($reduced), 'scale is clamped to 0..1');
    }

    private static function entry(string $name, ReducedMotion $motion): EffectDefinition
    {
        return new EffectDefinition($name, EffectTier::Low, null, EffectFallback::Off, EffectFallback::Off, $motion, FlashClass::Gentle);
    }
}

/** A 2D renderer that records the effects it is asked to draw. */
final class EffectRecordingRenderer2D extends NullRenderer2D implements EffectRenderer2DInterface
{
    /** @var list<array{0: string, 1: float, 2: float, 3: float, 4: float, 5: array<string, mixed>, 6: float|null}> */
    public array $effects = [];

    public bool $runs = true;

    public function drawEffect(string $name, float $x, float $y, float $w, float $h, array $parameters = [], array $textures = [], ?float $padding = null): bool
    {
        if (!$this->runs) {
            return false;
        }
        $this->effects[] = [$name, $x, $y, $w, $h, $parameters, $padding];
        return true;
    }

    public function effectAvailable(string $name): bool
    {
        return $this->runs;
    }

    public function applyEffectSettings(GraphicsSettings $settings): void
    {
    }
}
