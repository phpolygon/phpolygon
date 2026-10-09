<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering\Quality;

use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Quality\GraphicsCapabilities;
use PHPolygon\Rendering\Quality\UpscaleQuality;
use PHPolygon\Rendering\Quality\Upscaler;
use PHPUnit\Framework\TestCase;

/**
 * Settings side of temporal upscaling: which upscalers are temporal, how a
 * missing one falls back, what render scale a quality preset asks for and how
 * the settings record persists it without breaking older graphics.json files.
 */
final class TemporalUpscalerSettingsTest extends TestCase
{
    public function testOnlyTheMotionVectorUpscalersAreTemporal(): void
    {
        $this->assertFalse(Upscaler::Off->isTemporal());
        $this->assertFalse(Upscaler::Fsr1->isTemporal());
        $this->assertTrue(Upscaler::Taau->isTemporal());
        $this->assertTrue(Upscaler::Fsr3->isTemporal());
        $this->assertTrue(Upscaler::Dlss->isTemporal());
    }

    public function testFallbackChainEndsInOffAndStartsWithItself(): void
    {
        $this->assertSame(
            [Upscaler::Dlss, Upscaler::Fsr3, Upscaler::Taau, Upscaler::Fsr1, Upscaler::Off],
            Upscaler::Dlss->fallbackChain(),
        );
        $this->assertSame([Upscaler::Fsr3, Upscaler::Taau, Upscaler::Fsr1, Upscaler::Off], Upscaler::Fsr3->fallbackChain());
        $this->assertSame([Upscaler::Taau, Upscaler::Fsr1, Upscaler::Off], Upscaler::Taau->fallbackChain());
        $this->assertSame([Upscaler::Fsr1, Upscaler::Off], Upscaler::Fsr1->fallbackChain());
        $this->assertSame([Upscaler::Off], Upscaler::Off->fallbackChain());
    }

    public function testEveryUpscalerHasALabel(): void
    {
        foreach (Upscaler::cases() as $upscaler) {
            $this->assertNotSame('', $upscaler->label(), $upscaler->value);
        }
    }

    public function testQualityPresetsMapToTheVendorRenderScales(): void
    {
        $this->assertSame(1.0, UpscaleQuality::NativeAa->renderScale());
        $this->assertEqualsWithDelta(1.0 / 1.5, UpscaleQuality::Quality->renderScale() ?? 0.0, 1e-9);
        $this->assertEqualsWithDelta(1.0 / 1.7, UpscaleQuality::Balanced->renderScale() ?? 0.0, 1e-9);
        $this->assertSame(0.5, UpscaleQuality::Performance->renderScale());
        $this->assertEqualsWithDelta(1.0 / 3.0, UpscaleQuality::UltraPerformance->renderScale() ?? 0.0, 1e-9);
        $this->assertNull(UpscaleQuality::Custom->renderScale(), 'custom keeps the render-scale slider');
        foreach (UpscaleQuality::cases() as $quality) {
            $this->assertNotSame('', $quality->label(), $quality->value);
        }
    }

    public function testCapabilitiesResolveAMissingUpscalerAlongItsChain(): void
    {
        $fsr1Only = new GraphicsCapabilities(upscalers: [Upscaler::Off, Upscaler::Fsr1]);
        $this->assertSame(Upscaler::Fsr1, $fsr1Only->resolveUpscaler(Upscaler::Dlss));
        $this->assertSame(Upscaler::Fsr1, $fsr1Only->resolveUpscaler(Upscaler::Taau));
        $this->assertSame(Upscaler::Off, $fsr1Only->resolveUpscaler(Upscaler::Off));

        $withTaau = new GraphicsCapabilities(upscalers: [Upscaler::Off, Upscaler::Fsr1, Upscaler::Taau]);
        $this->assertSame(Upscaler::Taau, $withTaau->resolveUpscaler(Upscaler::Dlss));

        $nothing = new GraphicsCapabilities(upscalers: []);
        $this->assertSame(Upscaler::Off, $nothing->resolveUpscaler(Upscaler::Fsr3), 'Off is the floor even if unlisted');
    }

    public function testCapabilitiesCarryNotesForUnavailableUpscalers(): void
    {
        $caps = new GraphicsCapabilities(
            upscalers: [Upscaler::Off],
            upscalerNotes: ['dlss' => 'needs an RTX GPU'],
        );

        $this->assertSame('needs an RTX GPU', $caps->upscalerNote(Upscaler::Dlss));
        $this->assertNull($caps->upscalerNote(Upscaler::Fsr3));
        $this->assertSame([], GraphicsCapabilities::unknown()->upscalerNotes);
    }

    public function testDefaultsLeaveTheRenderScaleUntouched(): void
    {
        $s = new GraphicsSettings();

        $this->assertSame(UpscaleQuality::Custom, $s->upscaleQuality);
        $this->assertSame(1.0, $s->effectiveRenderScale());
        $this->assertSame(0.75, $s->with(renderScale: 0.75)->effectiveRenderScale());
    }

    public function testPresetDrivesTheRenderScaleOfAnUpscaler(): void
    {
        $s = (new GraphicsSettings())->with(
            renderScale: 0.9,
            upscaler: Upscaler::Taau,
            upscaleQuality: UpscaleQuality::UltraPerformance,
        );

        $this->assertEqualsWithDelta(1.0 / 3.0, $s->effectiveRenderScale(), 1e-9);
        $this->assertSame(0.9, $s->renderScale, 'the slider value survives for Custom');
        $this->assertSame(0.9, $s->with(upscaleQuality: UpscaleQuality::Custom)->effectiveRenderScale());
        $this->assertSame(0.9, $s->with(upscaler: Upscaler::Off)->effectiveRenderScale(), 'presets need an upscaler');
        $this->assertSame(1.0, $s->with(upscaleQuality: UpscaleQuality::NativeAa)->effectiveRenderScale());
    }

    public function testThirdResolutionIsOnlyAllowedForTemporalUpscalers(): void
    {
        $temporal = (new GraphicsSettings())->with(upscaler: Upscaler::Fsr3, renderScale: 0.2);
        $this->assertSame(GraphicsSettings::TEMPORAL_RENDER_SCALE_MIN, $temporal->renderScale);
        $this->assertSame(0.33, $temporal->effectiveRenderScale());

        $spatial = (new GraphicsSettings())->with(upscaler: Upscaler::Fsr1, renderScale: 0.2);
        $this->assertSame(GraphicsSettings::RENDER_SCALE_MIN, $spatial->renderScale);

        $this->assertSame(0.5, $temporal->with(upscaler: Upscaler::Fsr1)->renderScale, 'leaving temporal re-clamps');
        $this->assertSame(
            0.5,
            (new GraphicsSettings())->with(upscaler: Upscaler::Fsr1, upscaleQuality: UpscaleQuality::UltraPerformance)
                ->effectiveRenderScale(),
            'a spatial upscaler cannot go below half resolution',
        );
    }

    public function testEffectiveScaleFollowsTheFallbackOfTheRenderer(): void
    {
        $s = (new GraphicsSettings())->with(upscaler: Upscaler::Dlss, upscaleQuality: UpscaleQuality::UltraPerformance);
        $fsr1Only = new GraphicsCapabilities(upscalers: [Upscaler::Off, Upscaler::Fsr1]);
        $withDlss = new GraphicsCapabilities(upscalers: [Upscaler::Off, Upscaler::Dlss]);

        $this->assertSame(0.5, $s->effectiveRenderScale($fsr1Only), 'falls back to FSR 1 and its floor');
        $this->assertEqualsWithDelta(1.0 / 3.0, $s->effectiveRenderScale($withDlss), 1e-9);
        $this->assertSame(Upscaler::Fsr1, $s->effectiveUpscaler($fsr1Only));
        $this->assertSame(Upscaler::Dlss, $s->effectiveUpscaler());
    }

    public function testJsonRoundTripKeepsQualityAndOldFilesStillLoad(): void
    {
        $s = (new GraphicsSettings())->with(
            upscaler: Upscaler::Fsr3,
            upscaleQuality: UpscaleQuality::Balanced,
            renderScale: 0.4,
        );
        $json = $s->toJson();

        $this->assertSame('fsr3', $json['upscaler']);
        $this->assertSame('balanced', $json['upscaleQuality']);
        $this->assertEquals($s, GraphicsSettings::fromJson($json));

        $old = GraphicsSettings::fromJson(['renderScale' => 0.7, 'upscaler' => 'fsr1']);
        $this->assertSame(UpscaleQuality::Custom, $old->upscaleQuality);
        $this->assertSame(0.7, $old->effectiveRenderScale());

        $clamped = GraphicsSettings::fromJson(['renderScale' => 0.1, 'upscaler' => 'taau', 'upscaleQuality' => 'nonsense']);
        $this->assertSame(0.33, $clamped->renderScale);
        $this->assertSame(UpscaleQuality::Custom, $clamped->upscaleQuality);
        $this->assertSame(0.5, GraphicsSettings::fromJson(['renderScale' => 0.1, 'upscaler' => 'fsr1'])->renderScale);
    }
}
