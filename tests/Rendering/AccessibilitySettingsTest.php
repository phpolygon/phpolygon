<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\TestCase;
use PHPolygon\Rendering\Accessibility\AccessibilitySettings;
use PHPolygon\Rendering\Accessibility\ColorVision;
use PHPolygon\Rendering\Accessibility\ColorVisionMode;
use PHPolygon\Rendering\Effects\EffectDefinition;
use PHPolygon\Rendering\Effects\EffectFallback;
use PHPolygon\Rendering\Effects\EffectRegistry;
use PHPolygon\Rendering\Effects\EffectTier;
use PHPolygon\Rendering\Effects\FlashClass;
use PHPolygon\Rendering\Effects\ReducedMotion;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\GraphicsSettingsManager;
use PHPolygon\Rendering\PostProcess\VioAccessibilityPass;

final class AccessibilitySettingsTest extends TestCase
{
    public function testDefaultsChangeNothingAndAreNotSaved(): void
    {
        $a = new AccessibilitySettings();
        self::assertTrue($a->isDefault());
        self::assertFalse($a->changesImage());
        self::assertArrayNotHasKey('accessibility', (new GraphicsSettings())->toJson(), 'a save file without a choice stays as it was');
    }

    public function testRoundTripThroughGraphicsSettings(): void
    {
        $a = new AccessibilitySettings(colorVision: ColorVisionMode::Tritanopia, colorVisionStrength: 0.6, colorVisionSimulate: true);
        $settings = (new GraphicsSettings())->with(accessibility: $a);
        /** @var array<string, mixed> $json */
        $json = json_decode((string) json_encode($settings->toJson()), true);
        self::assertIsArray($json);
        self::assertArrayHasKey('accessibility', $json);
        $back = GraphicsSettings::fromJson($json);
        self::assertSame($a->toJson(), $back->accessibility->toJson());
        self::assertTrue($back->accessibility->changesImage());
    }

    public function testQualityChangesKeepTheAccessibilityChoice(): void
    {
        $a = new AccessibilitySettings(colorVision: ColorVisionMode::Protanopia);
        $settings = (new GraphicsSettings())->with(accessibility: $a)->with(bloom: false, renderScale: 0.75);
        self::assertSame($a, $settings->accessibility);
    }

    public function testBrokenValuesFallBackToDefaults(): void
    {
        $a = AccessibilitySettings::fromJson(['colorVision' => 'purple', 'colorVisionStrength' => 'x', 'colorVisionSimulate' => 1]);
        self::assertTrue($a->isDefault());
        self::assertTrue(AccessibilitySettings::fromJson('nonsense')->isDefault());
        self::assertSame(1.0, AccessibilitySettings::fromJson(['colorVisionStrength' => 7])->colorVisionStrength, 'clamped to 0..1');
        self::assertSame(0.0, (new AccessibilitySettings())->with(colorVisionStrength: -2.0)->colorVisionStrength);
    }

    public function testTheManagerHandsReducedMotionToTheEffectRegister(): void
    {
        $path = sys_get_temp_dir() . '/phpolygon-a11y-settings-' . getmypid() . '.json';
        @unlink($path);
        try {
            $manager = new GraphicsSettingsManager($path);
            $manager->update(fn (GraphicsSettings $s): GraphicsSettings => $s->with(accessibility: $s->accessibility->with(reducedMotion: true, motionScale: 0.4)));
            self::assertTrue(EffectRegistry::reducedMotion());
            $reduced = new EffectDefinition('fx', EffectTier::Low, null, EffectFallback::Same, EffectFallback::Off, ReducedMotion::Reduced, FlashClass::None);
            self::assertEqualsWithDelta(0.4, EffectRegistry::motionScale($reduced), 1e-9);

            $reloaded = new GraphicsSettingsManager($path);
            self::assertTrue($reloaded->settings()->accessibility->reducedMotion, 'persisted');
            $reloaded->update(fn (GraphicsSettings $s): GraphicsSettings => $s->with(accessibility: $s->accessibility->with(reducedMotion: false)));
            self::assertFalse(EffectRegistry::reducedMotion());
        } finally {
            @unlink($path);
            EffectRegistry::clear();
        }
    }

    public function testShaderUniformsFollowTheSettings(): void
    {
        $u = VioAccessibilityPass::uniforms(new AccessibilitySettings(colorVision: ColorVisionMode::Deuteranopia, colorVisionStrength: 0.4));
        self::assertSame(2.0, $u['u_cvd_mode']);
        self::assertSame(0.4, $u['u_cvd_strength']);
        self::assertSame(0.0, $u['u_cvd_simulate']);
    }

    public function testColourVisionModelKeepsGreysAndOffIsIdentity(): void
    {
        foreach ([ColorVisionMode::Protanopia, ColorVisionMode::Deuteranopia, ColorVisionMode::Tritanopia] as $mode) {
            foreach ([[1.0, 1.0, 1.0], [0.2, 0.2, 0.2]] as $grey) {
                $sim = ColorVision::simulate($mode, $grey);
                $fix = ColorVision::correct($mode, $grey);
                for ($i = 0; $i < 3; $i++) {
                    self::assertEqualsWithDelta($grey[$i], $sim[$i], 1e-4, "{$mode->value}: a grey stays grey");
                    self::assertEqualsWithDelta($grey[$i], $fix[$i], 1e-4, "{$mode->value}: nothing to correct on a grey");
                }
            }
        }
        self::assertSame([0.3, 0.6, 0.1], ColorVision::correct(ColorVisionMode::Off, [0.3, 0.6, 0.1]));
    }

    public function testCorrectionRestoresTheRedGreenDifferenceForDeuteranopes(): void
    {
        $mode = ColorVisionMode::Deuteranopia;
        $red = ColorVision::decode([0.85, 0.2, 0.2]);
        $green = ColorVision::decode([0.2, 0.65, 0.2]);
        $before = ColorVision::deltaE(ColorVision::simulate($mode, $red), ColorVision::simulate($mode, $green));
        $after = ColorVision::deltaE(ColorVision::simulate($mode, ColorVision::correct($mode, $red)), ColorVision::simulate($mode, ColorVision::correct($mode, $green)));
        self::assertEqualsWithDelta(6.0, $before, 0.1);
        self::assertEqualsWithDelta(53.6, $after, 0.1);
    }
}
