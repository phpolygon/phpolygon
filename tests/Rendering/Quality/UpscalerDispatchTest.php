<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering\Quality;

use PHPUnit\Framework\TestCase;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Quality\TemporalCamera;
use PHPolygon\Rendering\Quality\TemporalJitter;
use PHPolygon\Rendering\Quality\UpscaleQuality;
use PHPolygon\Rendering\Quality\Upscaler;
use PHPolygon\Rendering\Quality\UpscalerDispatch;

/**
 * The engine's temporal conventions translated into what a native upscaler
 * (php-vio vio_upscaler_dispatch) expects: jitter in render pixels with y
 * pointing down, motion as previous minus current position in render pixels,
 * camera data from the unjittered projection, provider and quality ids.
 */
final class UpscalerDispatchTest extends TestCase
{
    public function testJitterIsTheEngineOffsetWithYPointingDown(): void
    {
        $frame = $this->frame(frameIndex: 3);
        $dispatch = UpscalerDispatch::fromFrame($frame, renderTargetYDown: true, sharpness: 0.5, frameTimeMs: 16.0);

        // Engine: NDC offset (+Y up). vio: image rows (+Y down).
        $this->assertSame($frame->jitter->pixelX, $dispatch->jitter[0]);
        $this->assertSame(-$frame->jitter->pixelY, $dispatch->jitter[1]);
        // Independent of the render-target orientation: the jitter is a camera offset.
        $gl = UpscalerDispatch::fromFrame($frame, renderTargetYDown: false, sharpness: 0.5, frameTimeMs: 16.0);
        $this->assertSame($dispatch->jitter, $gl->jitter);
    }

    public function testJitterMovesGeometryTheWayThePixelConventionSays(): void
    {
        // vio: "geometry moves by +jitter" in image pixels (x right, y down).
        // Project a point with the jittered and the plain projection, convert
        // the NDC shift to image pixels and compare.
        $frame = $this->frame(frameIndex: 5);
        $dispatch = UpscalerDispatch::fromFrame($frame, true, 0.0, 16.0);
        $view = Mat4::lookAt(new Vec3(0, 1, 5), new Vec3(0, 1, 0), new Vec3(0, 1, 0));
        $p = [0.3, 1.2, -2.0, 1.0];
        $a = self::project($frame->jitter->projection->multiply($view), $p);
        $b = self::project($frame->jitter->unjitteredProjection->multiply($view), $p);
        $shiftPx = [($a[0] - $b[0]) * 0.5 * 160, -($a[1] - $b[1]) * 0.5 * 90];
        $this->assertEqualsWithDelta($dispatch->jitter[0], $shiftPx[0], 1e-6);
        $this->assertEqualsWithDelta($dispatch->jitter[1], $shiftPx[1], 1e-6);
    }

    public function testMotionScaleTurnsTextureSpaceIntoRenderPixels(): void
    {
        $frame = $this->frame();
        // The motion attachment holds prevUv - uv in the scene target's texture
        // space; on a y-down target that is already image orientation.
        $down = UpscalerDispatch::fromFrame($frame, renderTargetYDown: true, sharpness: 0.0, frameTimeMs: 16.0);
        $this->assertSame([160.0, 90.0], $down->motionScale);
        // A y-up target (OpenGL) stores v upwards: flip it into image rows.
        $up = UpscalerDispatch::fromFrame($frame, renderTargetYDown: false, sharpness: 0.0, frameTimeMs: 16.0);
        $this->assertSame([160.0, -90.0], $up->motionScale);
    }

    public function testCameraDataComesFromTheUnjitteredProjection(): void
    {
        $dispatch = UpscalerDispatch::fromFrame($this->frame(), true, 0.0, 16.0);
        $this->assertNotNull($dispatch->near);
        $this->assertNotNull($dispatch->far);
        $this->assertNotNull($dispatch->fovY);
        $this->assertEqualsWithDelta(0.1, $dispatch->near, 1e-6);
        $this->assertEqualsWithDelta(200.0, $dispatch->far, 1e-3);
        $this->assertEqualsWithDelta(deg2rad(60.0), $dispatch->fovY, 1e-6);
    }

    public function testOrthographicCameraLeavesTheCameraDataOut(): void
    {
        $camera = new TemporalCamera();
        $frame = $camera->begin(Mat4::identity(), Mat4::orthographic(-1, 1, -1, 1, 0.1, 10), false, 160, 90, 320, 180, true);
        $inputs = UpscalerDispatch::fromFrame($frame, true, 0.0, 16.0)->toInputs();
        $this->assertArrayNotHasKey('near', $inputs);
        $this->assertArrayNotHasKey('fov_y', $inputs);
    }

    public function testInputsCarryResetSharpnessAndFrameTime(): void
    {
        $camera = new TemporalCamera();
        $first = $camera->begin(Mat4::identity(), self::projection(), false, 160, 90, 320, 180, true);
        $second = $camera->begin(Mat4::identity(), self::projection(), false, 160, 90, 320, 180, true);

        $inputs = UpscalerDispatch::fromFrame($first, true, 0.7, 12.5)->toInputs();
        $this->assertTrue($inputs['reset'], 'the first frame has no history');
        $this->assertSame(0.7, $inputs['sharpness']);
        $this->assertSame(12.5, $inputs['frame_time_ms']);
        $this->assertSame([160, 90], [$inputs['render_width'], $inputs['render_height']]);
        $this->assertCount(2, $inputs['jitter']);
        $this->assertCount(2, $inputs['mv_scale']);

        $this->assertFalse(UpscalerDispatch::fromFrame($second, true, 0.7, 12.5)->toInputs()['reset']);
    }

    public function testSharpnessAndFrameTimeAreClamped(): void
    {
        $d = UpscalerDispatch::fromFrame($this->frame(), true, 3.0, -4.0);
        $this->assertSame(1.0, $d->sharpness);
        $this->assertGreaterThan(0.0, $d->frameTimeMs);
    }

    public function testProviderIds(): void
    {
        // VIO_UPSCALER_FSR3 = 1, VIO_UPSCALER_DLSS = 2
        $this->assertSame(1, UpscalerDispatch::providerId(Upscaler::Fsr3));
        $this->assertSame(2, UpscalerDispatch::providerId(Upscaler::Dlss));
        $this->assertNull(UpscalerDispatch::providerId(Upscaler::Taau));
        $this->assertNull(UpscalerDispatch::providerId(Upscaler::Fsr1));
        $this->assertNull(UpscalerDispatch::providerId(Upscaler::Off));
    }

    public function testQualityModes(): void
    {
        // VIO_UPSCALE_NATIVE_AA .. ULTRA_PERFORMANCE = 0 .. 4
        $this->assertSame(0, UpscalerDispatch::qualityMode(UpscaleQuality::NativeAa, 1.0));
        $this->assertSame(1, UpscalerDispatch::qualityMode(UpscaleQuality::Quality, 1.0));
        $this->assertSame(2, UpscalerDispatch::qualityMode(UpscaleQuality::Balanced, 1.0));
        $this->assertSame(3, UpscalerDispatch::qualityMode(UpscaleQuality::Performance, 1.0));
        $this->assertSame(4, UpscalerDispatch::qualityMode(UpscaleQuality::UltraPerformance, 1.0));
        // Custom: the mode whose ratio is nearest the slider's render scale.
        $this->assertSame(0, UpscalerDispatch::qualityMode(UpscaleQuality::Custom, 0.95));
        $this->assertSame(1, UpscalerDispatch::qualityMode(UpscaleQuality::Custom, 0.7));
        $this->assertSame(3, UpscalerDispatch::qualityMode(UpscaleQuality::Custom, 0.5));
        $this->assertSame(4, UpscalerDispatch::qualityMode(UpscaleQuality::Custom, 0.34));
    }

    public function testProviderJitterPhasesOverrideTheEngineSequenceLength(): void
    {
        // FSR / DLSS truncate 8 * ratio^2, the engine rounds up: the provider wins.
        $camera = new TemporalCamera();
        $frame = $camera->begin(Mat4::identity(), self::projection(), false, 170, 128, 256, 192, true, 0.0, 18);
        $this->assertSame(18, $frame->jitter->phaseCount);
        $this->assertSame(19, TemporalJitter::phaseCount(170, 256));
    }

    private function frame(int $frameIndex = 0): \PHPolygon\Rendering\Quality\TemporalFrame
    {
        $camera = new TemporalCamera();
        $view = Mat4::lookAt(new Vec3(0, 1, 5), new Vec3(0, 1, 0), new Vec3(0, 1, 0));
        $frame = null;
        for ($i = 0; $i <= $frameIndex; $i++) {
            $frame = $camera->begin($view, self::projection(), false, 160, 90, 320, 180, true);
        }
        self::assertNotNull($frame);
        return $frame;
    }

    private static function projection(): Mat4
    {
        return Mat4::perspective(deg2rad(60.0), 16 / 9, 0.1, 200.0);
    }

    /**
     * @param list<float> $p
     * @return array{0: float, 1: float}
     */
    private static function project(Mat4 $m, array $p): array
    {
        $a = $m->toArray();
        $clip = [0.0, 0.0, 0.0, 0.0];
        for ($row = 0; $row < 4; $row++) {
            for ($col = 0; $col < 4; $col++) {
                $clip[$row] += $a[$col * 4 + $row] * $p[$col];
            }
        }
        return [$clip[0] / $clip[3], $clip[1] / $clip[3]];
    }
}
