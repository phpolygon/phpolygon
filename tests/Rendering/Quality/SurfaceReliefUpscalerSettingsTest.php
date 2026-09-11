<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering\Quality;

use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Quality\AdaptiveTierStack;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ScreenSpaceAO;
use PHPolygon\Rendering\Quality\ScreenSpaceReflections;
use PHPolygon\Rendering\Quality\ShaderQuality;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\Quality\SurfaceRelief;
use PHPolygon\Rendering\Quality\Upscaler;
use PHPUnit\Framework\TestCase;

final class SurfaceReliefUpscalerSettingsTest extends TestCase
{
    public function testDefaultsKeepRenderingUnchangedForMaterialsWithoutRelief(): void
    {
        $s = new GraphicsSettings();

        $this->assertSame(SurfaceRelief::Parallax, $s->surfaceRelief);
        $this->assertSame(Upscaler::Off, $s->upscaler);
        $this->assertEqualsWithDelta(0.9, $s->upscaleSharpness, 1e-9);
    }

    public function testReliefTiersGateCavityAndParallax(): void
    {
        $this->assertFalse(SurfaceRelief::Off->cavityEnabled());
        $this->assertFalse(SurfaceRelief::Off->parallaxEnabled());
        $this->assertTrue(SurfaceRelief::Cavity->cavityEnabled());
        $this->assertFalse(SurfaceRelief::Cavity->parallaxEnabled());
        $this->assertTrue(SurfaceRelief::Parallax->cavityEnabled());
        $this->assertTrue(SurfaceRelief::Parallax->parallaxEnabled());
    }

    public function testWithClampsTheSharpness(): void
    {
        $s = (new GraphicsSettings())->with(surfaceRelief: SurfaceRelief::Cavity, upscaler: Upscaler::Fsr1, upscaleSharpness: 1.7);

        $this->assertSame(SurfaceRelief::Cavity, $s->surfaceRelief);
        $this->assertSame(Upscaler::Fsr1, $s->upscaler);
        $this->assertSame(1.0, $s->upscaleSharpness);
        $this->assertSame(0.0, $s->with(upscaleSharpness: -0.5)->upscaleSharpness);
    }

    public function testJsonRoundTripAndFallbacks(): void
    {
        $s = (new GraphicsSettings())->with(surfaceRelief: SurfaceRelief::Off, upscaler: Upscaler::Fsr1, upscaleSharpness: 0.4);
        $json = $s->toJson();

        $this->assertSame('off', $json['surfaceRelief']);
        $this->assertSame('fsr1', $json['upscaler']);
        $this->assertEquals($s, GraphicsSettings::fromJson($json));

        $broken = GraphicsSettings::fromJson(['surfaceRelief' => 'bumpy', 'upscaler' => 'dlss', 'upscaleSharpness' => 9]);
        $this->assertSame(SurfaceRelief::Parallax, $broken->surfaceRelief);
        $this->assertSame(Upscaler::Off, $broken->upscaler);
        $this->assertSame(1.0, $broken->upscaleSharpness);
    }

    public function testAdaptiveStackDropsParallaxRightAfterReflections(): void
    {
        // Defaults: no volumetric fog, SSR off - parallax is the first thing to go.
        $next = AdaptiveTierStack::downgrade(new GraphicsSettings());

        $this->assertNotNull($next);
        $this->assertSame(SurfaceRelief::Cavity, $next->surfaceRelief);
    }

    public function testAdaptiveStackDropsCavitiesAfterTheCheapEffects(): void
    {
        $bare = new GraphicsSettings(
            renderScale: 0.5,
            shadowQuality: ShadowQuality::Off,
            viewDistance: 75.0,
            antiAliasing: AntiAliasing::Off,
            cloudShadows: false,
            bloom: false,
            ambientOcclusion: ScreenSpaceAO::Off,
            vignetteIntensity: 0.0,
            ssr: ScreenSpaceReflections::Off,
            surfaceRelief: SurfaceRelief::Cavity,
        );

        $next = AdaptiveTierStack::downgrade($bare);

        $this->assertNotNull($next);
        $this->assertSame(SurfaceRelief::Off, $next->surfaceRelief);
        $this->assertSame(ShaderQuality::Full, $next->shaderQuality, 'relief goes before the unlit shader');
    }

    public function testAdaptiveStackRestoresParallaxBeforeReflections(): void
    {
        $rich = new GraphicsSettings(
            renderScale: 1.0,
            shadowQuality: ShadowQuality::High,
            viewDistance: 200.0,
            antiAliasing: AntiAliasing::Msaa4x,
            anisotropy: 16,
            ambientOcclusion: ScreenSpaceAO::High,
            ssr: ScreenSpaceReflections::Off,
            surfaceRelief: SurfaceRelief::Cavity,
        );

        $next = AdaptiveTierStack::upgrade($rich);

        $this->assertNotNull($next);
        $this->assertSame(SurfaceRelief::Parallax, $next->surfaceRelief);
        $this->assertSame(ScreenSpaceReflections::Off, $next->ssr);
    }
}
