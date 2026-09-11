<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering\Quality;

use PHPUnit\Framework\TestCase;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Quality\AdaptiveTierStack;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ScreenSpaceAO;
use PHPolygon\Rendering\Quality\ScreenSpaceReflections;
use PHPolygon\Rendering\Quality\ShaderQuality;
use PHPolygon\Rendering\Quality\ShadingRate;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\Quality\SurfaceRelief;

/**
 * Baselines that probe a later step set surfaceRelief: Off, otherwise the default
 * Parallax is dropped first (right after SSR) - SurfaceReliefUpscalerSettingsTest
 * covers the relief steps themselves.
 */
final class AdaptiveTierStackTest extends TestCase
{
    public function testDowngradeStartsWithVolumetricFog(): void
    {
        // VolumetricFog is the most expensive per-fragment effect, so the
        // adaptive controller drops it first when frame budget slips.
        $top = (new GraphicsSettings())->with(volumetricFog: true);
        $next = AdaptiveTierStack::downgrade($top);
        $this->assertNotNull($next);
        $this->assertFalse($next->volumetricFog);
    }

    public function testDowngradeMovesToRenderScaleAfterVolumetricFog(): void
    {
        $atFloor = (new GraphicsSettings())->with(renderScale: 1.0, volumetricFog: false, surfaceRelief: SurfaceRelief::Off);
        $next = AdaptiveTierStack::downgrade($atFloor);
        $this->assertNotNull($next);
        $this->assertSame(0.9, $next->renderScale);
    }

    public function testDowngradeMovesToShadowsAfterRenderScale(): void
    {
        $atFloor = (new GraphicsSettings())->with(
            renderScale: 0.5,
            shadowQuality: ShadowQuality::High,
            volumetricFog: false,
            ambientOcclusion: ScreenSpaceAO::Off,
            surfaceRelief: SurfaceRelief::Off,
        );
        $next = AdaptiveTierStack::downgrade($atFloor);
        $this->assertNotNull($next);
        $this->assertSame(ShadowQuality::Medium, $next->shadowQuality);
    }

    public function testDowngradeReachesAntiAliasingAndShader(): void
    {
        $s = new GraphicsSettings(
            renderScale: 0.5,
            shadowQuality: ShadowQuality::Off,
            viewDistance: 75.0,
            antiAliasing: AntiAliasing::Msaa4x,
            ambientOcclusion: ScreenSpaceAO::Off,
            volumetricFog: false,
            surfaceRelief: SurfaceRelief::Off,
        );
        $next = AdaptiveTierStack::downgrade($s);
        $this->assertNotNull($next);
        $this->assertSame(AntiAliasing::Msaa2x, $next->antiAliasing);
    }

    public function testDowngradeReturnsNullAtAbsoluteFloor(): void
    {
        $floor = new GraphicsSettings(
            renderScale: 0.5,
            shadowQuality: ShadowQuality::Off,
            viewDistance: 75.0,
            antiAliasing: AntiAliasing::Off,
            anisotropy: 1,
            cloudShadows: false,
            bloom: false,
            shaderQuality: ShaderQuality::Unlit,
            ambientOcclusion: ScreenSpaceAO::Off,
            vignetteIntensity: 0.0,
            volumetricFog: false,
            surfaceRelief: SurfaceRelief::Off,
        );
        $this->assertNull(AdaptiveTierStack::downgrade($floor));
    }

    public function testUpgradeReversesDowngradeOrder(): void
    {
        $s = new GraphicsSettings(
            renderScale: 0.7,
            shadowQuality: ShadowQuality::Low,
            viewDistance: 100.0,
            antiAliasing: AntiAliasing::Off,
            anisotropy: 4,
            cloudShadows: false,
            bloom: false,
            shaderQuality: ShaderQuality::Unlit,
        );
        // Anisotropy should be upgraded first (it sits at the bottom of the
        // downgrade stack, so it is at the top of the upgrade order).
        $next = AdaptiveTierStack::upgrade($s);
        $this->assertNotNull($next);
        $this->assertSame(8, $next->anisotropy);
    }

    public function testDowngradeLowersTheShadingRateBeforeTheRenderScale(): void
    {
        AdaptiveTierStack::setShadingRateAvailable(true);
        try {
            $s = new GraphicsSettings(renderScale: 1.0, volumetricFog: false, ssr: ScreenSpaceReflections::Off, surfaceRelief: SurfaceRelief::Off);
            $next = AdaptiveTierStack::downgrade($s);
            $this->assertNotNull($next);
            $this->assertSame(ShadingRate::Half, $next->shadingRate, '2x2 shading comes before any render-scale step');
            $this->assertSame(1.0, $next->renderScale);

            // Once the render scale is at its floor, the next cut is 4x4.
            $floor = $next->with(renderScale: 0.5);
            $quarter = AdaptiveTierStack::downgrade($floor);
            $this->assertNotNull($quarter);
            $this->assertSame(ShadingRate::Quarter, $quarter->shadingRate);

            // The way back: 4x4 -> 2x2 before the render scale climbs, 2x2 -> full after it.
            $up = AdaptiveTierStack::upgrade($quarter->with(ambientOcclusion: ScreenSpaceAO::High, shadowQuality: ShadowQuality::High, viewDistance: 200.0, antiAliasing: AntiAliasing::Msaa4x, anisotropy: 16, surfaceRelief: SurfaceRelief::Parallax));
            $this->assertNotNull($up);
            $this->assertSame(ShadingRate::Half, $up->shadingRate);
            $this->assertSame(0.5, $up->renderScale);
        } finally {
            AdaptiveTierStack::setShadingRateAvailable(false);
        }
    }

    public function testDowngradeSkipsTheShadingRateWhereTheBackendCannotApplyIt(): void
    {
        AdaptiveTierStack::setShadingRateAvailable(false);
        try {
            $s = new GraphicsSettings(renderScale: 1.0, volumetricFog: false, ssr: ScreenSpaceReflections::Off, surfaceRelief: SurfaceRelief::Off);
            $next = AdaptiveTierStack::downgrade($s);
            $this->assertNotNull($next);
            $this->assertSame(ShadingRate::Full, $next->shadingRate, 'no step that would change nothing');
            $this->assertSame(0.9, $next->renderScale, 'the render scale is the first real cut instead');
        } finally {
            AdaptiveTierStack::setShadingRateAvailable(false);
        }
    }

    public function testUpgradeReturnsNullAtMax(): void
    {
        $top = new GraphicsSettings(
            renderScale: 1.0,
            shadowQuality: ShadowQuality::High,
            viewDistance: 200.0,
            antiAliasing: AntiAliasing::Msaa4x,
            anisotropy: 16,
            cloudShadows: true,
            bloom: true,
            shaderQuality: ShaderQuality::Full,
            ambientOcclusion: ScreenSpaceAO::High,
            volumetricFog: true,
            ssr: ScreenSpaceReflections::High,
        );
        $this->assertNull(AdaptiveTierStack::upgrade($top));
    }
}
