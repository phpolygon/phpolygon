<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering\Quality;

use PHPUnit\Framework\TestCase;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Quality\AdaptiveTierStack;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ShaderQuality;
use PHPolygon\Rendering\Quality\ShadowQuality;

final class AdaptiveTierStackTest extends TestCase
{
    public function testDowngradeStartsWithRenderScale(): void
    {
        $top = new GraphicsSettings(renderScale: 1.0);
        $next = AdaptiveTierStack::downgrade($top);
        $this->assertNotNull($next);
        $this->assertSame(0.9, $next->renderScale);
    }

    public function testDowngradeMovesToShadowsAfterRenderScale(): void
    {
        $atFloor = (new GraphicsSettings())->with(renderScale: 0.5, shadowQuality: ShadowQuality::High);
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
        );
        $this->assertNull(AdaptiveTierStack::upgrade($top));
    }
}
