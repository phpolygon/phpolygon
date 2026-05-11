<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering\Quality;

use PHPUnit\Framework\TestCase;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\MeshLodTier;
use PHPolygon\Rendering\Quality\QualityMode;
use PHPolygon\Rendering\Quality\ShaderQuality;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\Quality\TextureQuality;

final class GraphicsSettingsTest extends TestCase
{
    public function testDefaultsMirrorPreExistingBehaviour(): void
    {
        $s = new GraphicsSettings();
        $this->assertSame(QualityMode::Manual, $s->mode);
        $this->assertSame(60.0, $s->targetFps);
        $this->assertSame(1.0, $s->renderScale);
        $this->assertSame(ShadowQuality::Medium, $s->shadowQuality);
        $this->assertSame(2048, $s->shadowQuality->resolution());
        $this->assertSame(50.0, $s->shadowDistance);
        $this->assertSame(200.0, $s->viewDistance);
        $this->assertSame(AntiAliasing::Fxaa, $s->antiAliasing);
        $this->assertSame(4, $s->anisotropy);
        $this->assertTrue($s->vsync);
        $this->assertSame(0, $s->fpsCap);
        $this->assertSame(TextureQuality::Full, $s->textureQuality);
        $this->assertSame(ShaderQuality::Full, $s->shaderQuality);
        $this->assertTrue($s->cloudShadows);
        $this->assertTrue($s->bloom);
        $this->assertTrue($s->fog);
        $this->assertSame(MeshLodTier::High, $s->meshLod);
    }

    public function testWithProducesNewInstanceWithSelectedFieldsReplaced(): void
    {
        $s = new GraphicsSettings();
        $next = $s->with(shadowQuality: ShadowQuality::Low, renderScale: 0.8);
        $this->assertNotSame($s, $next);
        $this->assertSame(ShadowQuality::Low, $next->shadowQuality);
        $this->assertSame(0.8, $next->renderScale);
        // Untouched fields stay
        $this->assertSame($s->antiAliasing, $next->antiAliasing);
        $this->assertSame($s->vsync, $next->vsync);
    }

    public function testWithClampsRenderScaleToValidRange(): void
    {
        $s = new GraphicsSettings();
        $this->assertSame(0.5, $s->with(renderScale: 0.1)->renderScale);
        $this->assertSame(2.0, $s->with(renderScale: 5.0)->renderScale);
    }

    public function testWithSnapsAnisotropyToAllowedValues(): void
    {
        $s = new GraphicsSettings();
        $this->assertSame(8, $s->with(anisotropy: 7)->anisotropy);
        $this->assertSame(16, $s->with(anisotropy: 32)->anisotropy);
        $this->assertSame(1, $s->with(anisotropy: 0)->anisotropy);
    }

    public function testWithClampsFpsCapToZeroOrAllowedValue(): void
    {
        $s = new GraphicsSettings();
        $this->assertSame(0, $s->with(fpsCap: 45)->fpsCap);
        $this->assertSame(60, $s->with(fpsCap: 60)->fpsCap);
        $this->assertSame(0, $s->with(fpsCap: -1)->fpsCap);
    }

    public function testJsonRoundtripPreservesAllFields(): void
    {
        $s = new GraphicsSettings(
            mode: QualityMode::Adaptive,
            targetFps: 120.0,
            renderScale: 0.75,
            shadowQuality: ShadowQuality::High,
            shadowDistance: 60.0,
            viewDistance: 150.0,
            antiAliasing: AntiAliasing::Msaa4x,
            anisotropy: 16,
            vsync: false,
            fpsCap: 144,
            textureQuality: TextureQuality::Half,
            shaderQuality: ShaderQuality::Unlit,
            cloudShadows: false,
            bloom: false,
            fog: false,
            meshLod: MeshLodTier::Low,
        );

        $json = $s->toJson();
        $rebuilt = GraphicsSettings::fromJson($json);

        $this->assertSame($s->toJson(), $rebuilt->toJson());
        $this->assertSame(QualityMode::Adaptive, $rebuilt->mode);
        $this->assertSame(120.0, $rebuilt->targetFps);
        $this->assertSame(0.75, $rebuilt->renderScale);
        $this->assertSame(ShadowQuality::High, $rebuilt->shadowQuality);
        $this->assertSame(AntiAliasing::Msaa4x, $rebuilt->antiAliasing);
        $this->assertSame(MeshLodTier::Low, $rebuilt->meshLod);
        $this->assertFalse($rebuilt->vsync);
        $this->assertSame(144, $rebuilt->fpsCap);
    }

    public function testFromJsonFallsBackToDefaultsForMissingOrInvalidFields(): void
    {
        $rebuilt = GraphicsSettings::fromJson(['mode' => 'invalid_value']);
        $defaults = new GraphicsSettings();
        // Invalid enum -> default
        $this->assertSame($defaults->mode, $rebuilt->mode);
        // Missing fields -> default
        $this->assertSame($defaults->targetFps, $rebuilt->targetFps);
        $this->assertSame($defaults->shadowQuality, $rebuilt->shadowQuality);
    }

    public function testFromJsonHandlesEmptyArray(): void
    {
        $rebuilt = GraphicsSettings::fromJson([]);
        $this->assertEquals((new GraphicsSettings())->toJson(), $rebuilt->toJson());
    }

    public function testShadowQualityResolution(): void
    {
        $this->assertSame(0, ShadowQuality::Off->resolution());
        $this->assertSame(1024, ShadowQuality::Low->resolution());
        $this->assertSame(2048, ShadowQuality::Medium->resolution());
        $this->assertSame(4096, ShadowQuality::High->resolution());
    }

    public function testAntiAliasingSampleCount(): void
    {
        $this->assertSame(1, AntiAliasing::Off->sampleCount());
        $this->assertSame(1, AntiAliasing::Fxaa->sampleCount());
        $this->assertSame(2, AntiAliasing::Msaa2x->sampleCount());
        $this->assertSame(4, AntiAliasing::Msaa4x->sampleCount());
    }

    public function testTextureQualityLodBias(): void
    {
        $this->assertSame(0.0, TextureQuality::Full->lodBias());
        $this->assertSame(1.0, TextureQuality::Half->lodBias());
        $this->assertSame(2.0, TextureQuality::Quarter->lodBias());
    }

    public function testShaderQualityShaderId(): void
    {
        $this->assertNull(ShaderQuality::Full->shaderId());
        $this->assertSame('unlit', ShaderQuality::Unlit->shaderId());
    }
}
