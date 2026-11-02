<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\TestCase;
use PHPolygon\Rendering\Effects\EffectCost;
use PHPolygon\Rendering\Effects\EffectDefinition;
use PHPolygon\Rendering\Effects\EffectFallback;
use PHPolygon\Rendering\Effects\EffectRegistry;
use PHPolygon\Rendering\Effects\EffectTier;
use PHPolygon\Rendering\Effects\FlashClass;
use PHPolygon\Rendering\Effects\ReducedMotion;
use PHPolygon\Rendering\PostProcess\FullscreenPass;
use PHPolygon\Rendering\PostProcess\FullscreenPassRegistry;
use PHPolygon\Rendering\PostProcess\PostStage;
use PHPolygon\Rendering\ProcModeShaderRegistry;

final class EffectRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        EffectRegistry::clear();
        FullscreenPassRegistry::clear();
        ProcModeShaderRegistry::clear();
    }

    protected function tearDown(): void
    {
        EffectRegistry::clear();
        FullscreenPassRegistry::clear();
        ProcModeShaderRegistry::clear();
    }

    private static function effect(string $name, EffectTier $tier, ?float $ms, FlashClass $flash = FlashClass::None): EffectDefinition
    {
        return new EffectDefinition(
            $name,
            $tier,
            $ms === null ? null : new EffectCost($ms, 'Test Adapter', 'd3d12', 1920, 1080, '2026-11-02'),
            EffectFallback::Off,
            EffectFallback::Simplified,
            ReducedMotion::Frozen,
            $flash,
        );
    }

    public function testReducedMotionGatesTheShaderTimeByEntry(): void
    {
        $make = static fn (string $name, ReducedMotion $m): EffectDefinition => new EffectDefinition($name, EffectTier::Low, null, EffectFallback::Same, EffectFallback::Off, $m, FlashClass::None);
        $frozen = $make('scan', ReducedMotion::Frozen);
        $reduced = $make('water', ReducedMotion::Reduced);
        $static = $make('logo', ReducedMotion::Static);
        $off = $make('glitch', ReducedMotion::Off);

        foreach ([$frozen, $reduced, $static, $off, null] as $e) {
            self::assertSame(12.5, EffectRegistry::effectTime($e, 12.5), 'without reduced motion every effect runs on engine time');
        }
        EffectRegistry::setReducedMotion(true, 0.25);
        self::assertSame(0.0, EffectRegistry::effectTime($frozen, 12.5));
        self::assertSame(0.0, EffectRegistry::effectTime($off, 12.5), 'a 3D effect cannot be taken out, it is held');
        self::assertSame(3.125, EffectRegistry::effectTime($reduced, 12.5));
        self::assertSame(12.5, EffectRegistry::effectTime($static, 12.5));
        self::assertSame(12.5, EffectRegistry::effectTime(null, 12.5), 'no entry: untouched');
    }

    public function testTheFlashLimitHoldsFlashingEffects(): void
    {
        $flashing = self::effect('lightning', EffectTier::Low, null, FlashClass::Flashing);
        $gentle = self::effect('pulse', EffectTier::Low, null, FlashClass::Gentle);
        self::assertSame(4.0, EffectRegistry::effectTime($flashing, 4.0));
        EffectRegistry::setFlashLimit(true);
        self::assertTrue(EffectRegistry::flashLimited($flashing));
        self::assertFalse(EffectRegistry::flashLimited($gentle));
        self::assertSame(0.0, EffectRegistry::effectTime($flashing, 4.0), 'held, with or without reduced motion');
        self::assertSame(4.0, EffectRegistry::effectTime($gentle, 4.0));
        EffectRegistry::clear();
        self::assertFalse(EffectRegistry::flashLimit());
    }

    public function testATierHoldsEverythingFromItDown(): void
    {
        EffectRegistry::register(self::effect('cheap', EffectTier::Low, 0.1));
        EffectRegistry::register(self::effect('dear', EffectTier::High, 0.5));

        self::assertSame(['cheap'], array_map(static fn (EffectDefinition $e): string => $e->name, EffectRegistry::atTier(EffectTier::Medium)));
        self::assertCount(2, EffectRegistry::atTier(EffectTier::Ultra));
    }

    public function testTheBudgetAddsWhatIsMeasuredAndNamesWhatIsNot(): void
    {
        EffectRegistry::register(self::effect('a', EffectTier::Low, 0.25));
        EffectRegistry::register(self::effect('b', EffectTier::Low, 0.5));
        EffectRegistry::register(self::effect('c', EffectTier::Low, null));
        EffectRegistry::register(self::effect('d', EffectTier::Ultra, 9.0));

        self::assertSame(['gpuMs' => 0.75, 'unmeasured' => ['c']], EffectRegistry::budget(EffectTier::Low));
    }

    public function testFlashingEffectsCanBeListed(): void
    {
        EffectRegistry::register(self::effect('calm', EffectTier::Low, 0.1));
        EffectRegistry::register(self::effect('bolt', EffectTier::Low, 0.1, FlashClass::Flashing));
        self::assertSame('bolt', EffectRegistry::flashing()[0]->name);
        self::assertCount(1, EffectRegistry::flashing());
    }

    public function testEveryPassAndVariantNeedsAnEntry(): void
    {
        EffectRegistry::assertComplete();

        FullscreenPassRegistry::register(new FullscreenPass('grain', PostStage::Final, 'x'));
        ProcModeShaderRegistry::defineVariant('wet', [2]);
        self::assertSame(['wet', 'grain'], EffectRegistry::missing());

        EffectRegistry::register(self::effect('grain', EffectTier::Low, 0.1));
        EffectRegistry::register(self::effect('wet', EffectTier::Low, 0.1));
        EffectRegistry::assertComplete();
        self::assertSame([], EffectRegistry::missing());
    }

    public function testAMissingEntryStopsTheCheck(): void
    {
        ProcModeShaderRegistry::defineVariant('wet', [2]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('wet');
        EffectRegistry::assertComplete();
    }

    public function testAnEffectIsRegisteredOnce(): void
    {
        EffectRegistry::register(self::effect('a', EffectTier::Low, 0.1));
        $this->expectException(\InvalidArgumentException::class);
        EffectRegistry::register(self::effect('a', EffectTier::Low, 0.1));
    }

    public function testACostCarriesItsDate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new EffectCost(0.1, 'Test Adapter', 'd3d12', 1920, 1080, 'yesterday');
    }
}
