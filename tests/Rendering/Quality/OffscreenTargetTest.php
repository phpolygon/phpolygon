<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering\Quality;

use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\NullRenderer3D;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPUnit\Framework\TestCase;

/**
 * Phase 1.5 wiring tests.
 *
 * These exercise the renderer-side bookkeeping that backs the off-screen FBO
 * pipeline (render-scale, MSAA sample count, FXAA toggle). NullRenderer3D
 * mirrors the same math as OpenGLRenderer3D::beginOffscreenIfRequired() so
 * we can assert behaviour without a GL context.
 *
 * Pixel-level VRT for the OpenGL FBO path lives outside CI - it requires a
 * live GL context and is run locally during release validation.
 */
final class OffscreenTargetTest extends TestCase
{
    public function testDefaultsKeepOffscreenAllocationForFxaa(): void
    {
        $renderer = new NullRenderer3D(1280, 720);
        $renderer->applySettings(new GraphicsSettings());

        // Defaults ship with FXAA enabled and renderScale 1.0 -> offscreen
        // pipeline active at native resolution, single sample.
        [$w, $h, $samples] = $renderer->getOffscreenSize();
        self::assertSame(1280, $w);
        self::assertSame(720, $h);
        self::assertSame(1, $samples);
        self::assertSame(1, $renderer->getOffscreenResizeCount());
    }

    public function testOffPlusUnitScaleSkipsOffscreenAllocation(): void
    {
        $renderer = new NullRenderer3D(1280, 720);
        $renderer->applySettings(
            (new GraphicsSettings())->with(antiAliasing: AntiAliasing::Off)
        );

        // renderScale == 1.0 AND AA Off -> fast path, no offscreen FBO.
        [$w, $h, $samples] = $renderer->getOffscreenSize();
        self::assertSame(0, $w);
        self::assertSame(0, $h);
        self::assertSame(1, $samples);
        self::assertSame(0, $renderer->getOffscreenResizeCount());
    }

    public function testRenderScaleHalvedAllocatesScaledTarget(): void
    {
        $renderer = new NullRenderer3D(1920, 1080);
        $renderer->applySettings(
            (new GraphicsSettings())->with(
                renderScale: 0.5,
                antiAliasing: AntiAliasing::Off,
            )
        );

        [$w, $h, $samples] = $renderer->getOffscreenSize();
        self::assertSame(960, $w);
        self::assertSame(540, $h);
        self::assertSame(1, $samples);
    }

    public function testMsaa4xPropagatesSampleCount(): void
    {
        $renderer = new NullRenderer3D(1280, 720);
        $renderer->applySettings(
            (new GraphicsSettings())->with(antiAliasing: AntiAliasing::Msaa4x)
        );

        [$w, $h, $samples] = $renderer->getOffscreenSize();
        self::assertSame(1280, $w);
        self::assertSame(720, $h);
        self::assertSame(4, $samples);
    }

    public function testRenderScalePlusMsaaCombinesBothDimensions(): void
    {
        $renderer = new NullRenderer3D(1920, 1080);
        $renderer->applySettings(
            (new GraphicsSettings())->with(
                renderScale: 0.5,
                antiAliasing: AntiAliasing::Msaa4x,
            )
        );

        [$w, $h, $samples] = $renderer->getOffscreenSize();
        self::assertSame(960, $w);
        self::assertSame(540, $h);
        self::assertSame(4, $samples);
    }

    public function testRepeatApplyDoesNotResizeWhenSettingsAreIdentical(): void
    {
        $renderer = new NullRenderer3D(1280, 720);
        $settings = (new GraphicsSettings())->with(renderScale: 0.75, antiAliasing: AntiAliasing::Msaa2x);

        $renderer->applySettings($settings);
        $resizesAfterFirst = $renderer->getOffscreenResizeCount();

        $renderer->applySettings($settings);
        $renderer->applySettings($settings);
        $resizesAfterThird = $renderer->getOffscreenResizeCount();

        self::assertSame(1, $resizesAfterFirst);
        self::assertSame(1, $resizesAfterThird, 'identical settings must not trigger redundant resizes');
        self::assertSame(3, $renderer->getApplySettingsCallCount());
    }

    public function testChangingAntiAliasingTierTriggersResize(): void
    {
        $renderer = new NullRenderer3D(1280, 720);
        $renderer->applySettings(
            (new GraphicsSettings())->with(antiAliasing: AntiAliasing::Msaa2x)
        );
        $renderer->applySettings(
            (new GraphicsSettings())->with(antiAliasing: AntiAliasing::Msaa4x)
        );

        self::assertSame(2, $renderer->getOffscreenResizeCount());
        [, , $samples] = $renderer->getOffscreenSize();
        self::assertSame(4, $samples);
    }

    public function testApplyDoesNotCrashWithExtremeRenderScale(): void
    {
        $renderer = new NullRenderer3D(1280, 720);

        // Clamp to engine-allowed range [0.5, 2.0] is GraphicsSettings'
        // responsibility; the renderer must accept whatever it receives.
        $renderer->applySettings(
            (new GraphicsSettings())->with(renderScale: 2.0, antiAliasing: AntiAliasing::Off)
        );
        [$w, $h, $samples] = $renderer->getOffscreenSize();
        self::assertSame(2560, $w);
        self::assertSame(1440, $h);
        self::assertSame(1, $samples);

        $renderer->applySettings(
            (new GraphicsSettings())->with(renderScale: 0.5, antiAliasing: AntiAliasing::Off)
        );
        [$w, $h] = $renderer->getOffscreenSize();
        self::assertSame(640, $w);
        self::assertSame(360, $h);
    }

    public function testFxaaToggleDoesNotChangeSampleCount(): void
    {
        $renderer = new NullRenderer3D(1280, 720);
        $renderer->applySettings(
            (new GraphicsSettings())->with(antiAliasing: AntiAliasing::Fxaa)
        );

        // FXAA is a post-process pass, not a multisample renderbuffer.
        [, , $samples] = $renderer->getOffscreenSize();
        self::assertSame(1, $samples);
    }
}
