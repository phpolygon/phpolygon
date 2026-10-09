<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering\Quality;

use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Quality\TemporalCamera;
use PHPolygon\Rendering\Quality\TemporalJitter;
use PHPUnit\Framework\TestCase;

/**
 * The renderer's frame-to-frame camera memory for motion vectors and the
 * temporal resolve: last frame's UNJITTERED view-projection, the jitter
 * sequence position, and when the history has to be dropped (first frame,
 * camera cut, render/display size change, explicit reset).
 */
final class TemporalCameraTest extends TestCase
{
    public function testFirstFrameHasNoHistoryAndReprojectsOntoItself(): void
    {
        $camera = new TemporalCamera();
        $frame = $camera->begin(self::view(0.0), self::projection(), false, 320, 180, 320, 180, true);

        $this->assertFalse($frame->historyValid, 'nothing to reproject on the first frame');
        $this->assertMatrixEquals($frame->jitter->unjitteredViewProjection, $frame->prevViewProjection);
        $this->assertMatrixEquals(Mat4::identity(), $frame->reprojection);
    }

    public function testSecondFrameReprojectsWithLastFramesUnjitteredViewProjection(): void
    {
        $camera = new TemporalCamera();
        $first = $camera->begin(self::view(0.0), self::projection(), false, 320, 180, 320, 180, true);
        $second = $camera->begin(self::view(0.5), self::projection(), false, 320, 180, 320, 180, true);

        $this->assertTrue($second->historyValid);
        $this->assertMatrixEquals($first->jitter->unjitteredViewProjection, $second->prevViewProjection);
        $this->assertNotEquals($first->jitter->projection->toArray(), $first->jitter->unjitteredProjection->toArray(),
            'the rasterised projection is jittered');
        // reprojection = prevVP * inverse(currentVP): maps this frame's clip space to last frame's.
        $expected = $first->jitter->unjitteredViewProjection->multiply($second->jitter->unjitteredViewProjection->inverse());
        $this->assertMatrixEquals($expected, $second->reprojection);
    }

    public function testJitterWalksTheSequenceAndScalesItsLengthWithTheUpscaleRatio(): void
    {
        $camera = new TemporalCamera();
        $phases = [];
        for ($i = 0; $i < 3; $i++) {
            $frame = $camera->begin(self::view(0.0), self::projection(), false, 160, 90, 320, 180, true);
            $phases[] = $frame->jitter->phase;
            $this->assertSame(TemporalJitter::phaseCount(160, 320), $frame->jitter->phaseCount);
        }
        $this->assertSame([0, 1, 2], $phases);
        $this->assertSame(32, TemporalJitter::phaseCount(160, 320));
    }

    public function testJitterOffKeepsTheCamerasProjection(): void
    {
        $camera = new TemporalCamera();
        $frame = $camera->begin(self::view(0.0), self::projection(), false, 320, 180, 320, 180, false);
        $this->assertMatrixEquals(self::projection(), $frame->jitter->projection);
        $this->assertSame(0.0, $frame->jitter->ndcX);
    }

    public function testCutDropsTheHistoryAndCameraMotion(): void
    {
        $camera = new TemporalCamera();
        $camera->begin(self::view(0.0), self::projection(), false, 320, 180, 320, 180, true);
        $cut = $camera->begin(self::view(30.0), self::projection(), true, 320, 180, 320, 180, true);

        $this->assertFalse($cut->historyValid);
        $this->assertMatrixEquals($cut->jitter->unjitteredViewProjection, $cut->prevViewProjection,
            'after a cut the camera has no motion of its own');
        $this->assertSame('cut', $cut->resetReason);

        $after = $camera->begin(self::view(30.1), self::projection(), false, 320, 180, 320, 180, true);
        $this->assertTrue($after->historyValid, 'the frame after the cut accumulates again');
    }

    public function testResizeOfRenderOrDisplayTargetDropsTheHistory(): void
    {
        $camera = new TemporalCamera();
        $camera->begin(self::view(0.0), self::projection(), false, 320, 180, 320, 180, true);
        $this->assertSame('resize', $camera->begin(self::view(0.0), self::projection(), false, 160, 90, 320, 180, true)->resetReason);
        $this->assertSame('resize', $camera->begin(self::view(0.0), self::projection(), false, 160, 90, 640, 360, true)->resetReason);
        $this->assertTrue($camera->begin(self::view(0.0), self::projection(), false, 160, 90, 640, 360, true)->historyValid);
    }

    public function testExplicitResetDropsTheHistoryOnce(): void
    {
        $camera = new TemporalCamera();
        $camera->begin(self::view(0.0), self::projection(), false, 320, 180, 320, 180, true);
        $camera->reset('settings');
        $frame = $camera->begin(self::view(0.1), self::projection(), false, 320, 180, 320, 180, true);
        $this->assertFalse($frame->historyValid);
        $this->assertSame('settings', $frame->resetReason);
        $this->assertTrue($camera->begin(self::view(0.2), self::projection(), false, 320, 180, 320, 180, true)->historyValid);
    }

    public function testPreviousFrameTimeFollowsTheFrameClock(): void
    {
        $camera = new TemporalCamera();
        $first = $camera->begin(self::view(0.0), self::projection(), false, 320, 180, 320, 180, true, time: 2.0);
        $this->assertSame(2.0, $first->prevTime, 'no previous frame: animation has no motion');
        $second = $camera->begin(self::view(0.0), self::projection(), false, 320, 180, 320, 180, true, time: 2.25);
        $this->assertSame(2.0, $second->prevTime);
    }

    private static function view(float $x): Mat4
    {
        return Mat4::lookAt(new Vec3($x, 2.0, 6.0), new Vec3($x, 0.0, 0.0), new Vec3(0.0, 1.0, 0.0));
    }

    private static function projection(): Mat4
    {
        return Mat4::perspective(deg2rad(60.0), 16.0 / 9.0, 0.1, 200.0);
    }

    private function assertMatrixEquals(Mat4 $expected, Mat4 $actual, string $message = ''): void
    {
        $e = $expected->toArray();
        $a = $actual->toArray();
        for ($i = 0; $i < 16; $i++) {
            $this->assertEqualsWithDelta($e[$i], $a[$i], 1e-9, $message . " (element $i)");
        }
    }
}
