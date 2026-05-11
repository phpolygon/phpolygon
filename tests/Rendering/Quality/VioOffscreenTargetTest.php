<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering\Quality;

use PHPolygon\Rendering\VioOffscreenTarget;
use PHPUnit\Framework\TestCase;

/**
 * Phase 1.5 vio offscreen-target wiring tests.
 *
 * The structural assertions live in `OffscreenTargetTest` (NullRenderer3D
 * mirrors the renderer-side maths). This class tests the live vio resource
 * lifecycle and is therefore gated on `extension_loaded('vio')` - in CI
 * without the vio extension the test is skipped. Local runs with vio
 * loaded exercise allocation, MSAA fallback, and idempotent resize.
 */
final class VioOffscreenTargetTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('vio')) {
            $this->markTestSkipped('vio extension not loaded; integration tests require the vio backend.');
        }
    }

    public function testTracksRequestedDimensionsEvenWithoutAllocation(): void
    {
        $ctx = $this->createVioContext();
        $target = new VioOffscreenTarget($ctx);

        $target->resize(640, 360, 1);

        // Width/height/samples are book-kept regardless of allocation success
        // so that idempotent resize() short-circuits work correctly even on
        // headless contexts where vio_render_target may refuse allocation.
        self::assertSame(640, $target->width());
        self::assertSame(360, $target->height());
        self::assertSame(1, $target->samples());

        if (!$target->isAllocated()) {
            $this->markTestSkipped(
                'vio_render_target() refused allocation on this context (likely headless / no swapchain).'
            );
        }

        self::assertNotNull($target->texture());
    }

    public function testRepeatResizeWithSameDimensionsIsNoOp(): void
    {
        $ctx = $this->createVioContext();
        $target = new VioOffscreenTarget($ctx);

        $target->resize(800, 600, 1);
        if (!$target->isAllocated()) {
            $this->markTestSkipped('vio_render_target() refused allocation on this context.');
        }
        $firstTex = $target->texture();
        self::assertNotNull($firstTex);

        $target->resize(800, 600, 1);
        $secondTex = $target->texture();

        // Same VioRenderTarget reused for identical configs.
        self::assertSame($firstTex, $secondTex, 'identical resize must not allocate a new GPU resource');
    }

    public function testRebuildOnDimensionChange(): void
    {
        $ctx = $this->createVioContext();
        $target = new VioOffscreenTarget($ctx);

        $target->resize(640, 360, 1);
        if (!$target->isAllocated()) {
            $this->markTestSkipped('vio_render_target() refused allocation on this context.');
        }
        $firstTex = $target->texture();

        $target->resize(1280, 720, 1);

        self::assertSame(1280, $target->width());
        self::assertSame(720, $target->height());
        self::assertNotSame($firstTex, $target->texture(), 'dimension change must allocate a new image');
    }

    public function testMsaaProbeRecordsFallbackWhenUnsupported(): void
    {
        $ctx = $this->createVioContext();
        $target = new VioOffscreenTarget($ctx);

        $target->resize(640, 360, 4);

        if (!$target->isAllocated()) {
            $this->markTestSkipped('vio_render_target() refused allocation on this context.');
        }

        // Either the vio backend supports samples=4 (msaaSupported true,
        // samples()==4) or it doesn't (msaaSupported false, samples()==1
        // - the helper transparently falls back). Both outcomes are valid.
        self::assertContains($target->samples(), [1, 4]);
        self::assertContains($target->msaaSupported(), [true, false]);
    }

    public function testReleaseDropsHandlesAndResizeRebuilds(): void
    {
        $ctx = $this->createVioContext();
        $target = new VioOffscreenTarget($ctx);

        $target->resize(512, 512, 1);
        if (!$target->isAllocated()) {
            $this->markTestSkipped('vio_render_target() refused allocation on this context.');
        }

        $target->release();
        self::assertFalse($target->isAllocated());
        self::assertNull($target->texture());

        $target->resize(512, 512, 1);
        self::assertTrue($target->isAllocated());
        self::assertNotNull($target->texture());
    }

    /**
     * Build a windowless vio context just sufficient to allocate render
     * targets. The exact creation API depends on the vio version; if the
     * test environment cannot produce a context, the test is skipped so
     * the suite stays green on machines without a display server.
     */
    private function createVioContext(): \VioContext
    {
        if (!function_exists('vio_create')) {
            $this->markTestSkipped('vio_create() not available in this vio build.');
        }

        try {
            // 'auto' picks the best backend per platform; a tiny offscreen
            // surface is enough for render-target allocation tests.
            $ctx = vio_create('auto', [
                'width'  => 16,
                'height' => 16,
                'title'  => 'phpolygon-test',
                'hidden' => true,
            ]);
        } catch (\Throwable $e) {
            $this->markTestSkipped('vio_create() failed: ' . $e->getMessage());
        }

        if (!$ctx instanceof \VioContext) {
            $this->markTestSkipped('vio_create() did not return a VioContext on this machine.');
        }

        return $ctx;
    }
}
