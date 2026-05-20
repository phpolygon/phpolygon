<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPolygon\Engine;
use PHPolygon\EngineConfig;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\NullRenderer2D;
use PHPolygon\Rendering\Renderer2DInterface;
use PHPolygon\Rendering\TextMetrics;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for `Engine::warmRender()`.
 *
 * The warm-render path lets games pre-rasterise glyph atlases and warm up
 * sprite textures during the splash phase without ever presenting the pixels
 * on the swapchain. On vio it routes draws into a private off-screen target;
 * on `NullRenderer2D` / GLFW fallback / GD it falls back to the default
 * surface but still executes the callback exactly once so glyph paths run.
 *
 * These tests exercise the contract on the `NullRenderer2D` backend (headless
 * mode) since CI has no GPU. The vio-specific allocate/bind path is covered
 * indirectly by the existing `VioOffscreenTarget` tests and the
 * `VioRenderer3D` HDR pipeline that uses the same primitives.
 */
final class WarmRenderTest extends TestCase
{
    public function testWarmRenderInvokesCallbackExactlyOnce(): void
    {
        $engine = new Engine(new EngineConfig(headless: true));

        $callCount = 0;
        $engine->warmRender(function () use (&$callCount): void {
            $callCount++;
        });

        $this->assertSame(1, $callCount, 'warmRender must invoke the callback exactly once');
    }

    public function testWarmRenderAcceptsBeginEndFramePairsInCallback(): void
    {
        $engine = new Engine(new EngineConfig(headless: true));

        $beginCalls = 0;
        $endCalls = 0;

        $engine->warmRender(function () use ($engine, &$beginCalls, &$endCalls): void {
            $engine->renderer2D->beginFrame();
            $beginCalls++;
            $engine->renderer2D->clear(new Color(0.0, 0.0, 0.0));
            $engine->renderer2D->drawText('warm', 0.0, 0.0, 16.0, new Color(1.0, 1.0, 1.0));
            $engine->renderer2D->endFrame();
            $endCalls++;
        });

        $this->assertSame(1, $beginCalls);
        $this->assertSame(1, $endCalls);
    }

    public function testWarmRenderIsRepeatable(): void
    {
        $engine = new Engine(new EngineConfig(headless: true));

        $calls = 0;
        for ($i = 0; $i < 5; $i++) {
            $engine->warmRender(function () use (&$calls): void {
                $calls++;
            });
        }

        $this->assertSame(5, $calls, 'each warmRender call must execute its own callback');
    }

    /**
     * Generator-based onInit is PHPolygon's cooperative-init pattern. warmRender
     * must be safe to call from inside such a callback — that's the entire
     * point of the API (warm during splash). This drives the generator
     * manually because the headless / skipSplash path is the supported route
     * for tests.
     */
    public function testWarmRenderIsSafeInsideGeneratorOnInit(): void
    {
        $engine = new Engine(new EngineConfig(headless: true));

        $warmInsideChunkA = 0;
        $warmInsideChunkB = 0;

        $engine->onInit(function (Engine $e) use (&$warmInsideChunkA, &$warmInsideChunkB) {
            $e->warmRender(function () use (&$warmInsideChunkA): void {
                $warmInsideChunkA++;
            });
            yield;
            $e->warmRender(function () use (&$warmInsideChunkB): void {
                $warmInsideChunkB++;
            });
        });

        $engine->onUpdate(function (Engine $e): void {
            $e->stop();
        });

        $engine->run();

        $this->assertSame(1, $warmInsideChunkA, 'warmRender must work in the first generator chunk');
        $this->assertSame(1, $warmInsideChunkB, 'warmRender must work after a yield');
    }

    public function testWarmRenderPropagatesExceptionsAndStillEndsOffscreenFrame(): void
    {
        $engine = new Engine(new EngineConfig(headless: true));

        $exception = new \RuntimeException('callback boom');
        $thrown = null;

        try {
            $engine->warmRender(function () use ($exception): void {
                throw $exception;
            });
        } catch (\RuntimeException $e) {
            $thrown = $e;
        }

        $this->assertSame($exception, $thrown, 'warmRender must not swallow callback exceptions');

        // A second warmRender must still succeed — proves endOffscreenFrame()
        // ran via the finally clause and the renderer is back in a clean state.
        $followUp = 0;
        $engine->warmRender(function () use (&$followUp): void {
            $followUp++;
        });
        $this->assertSame(1, $followUp);
    }

    public function testWarmRenderOnNullRendererShortCircuitsOffscreenPath(): void
    {
        // On NullRenderer2D, beginOffscreenFrame/endOffscreenFrame are no-ops.
        // The callback still runs (the API contract guarantees that), but no
        // GPU resources are allocated. Verify by tracking what the renderer
        // observed — a custom subclass records the calls.
        $recorder = new class extends NullRenderer2D {
            /** @var list<string> */
            public array $events = [];
            public function beginOffscreenFrame(int $width, int $height): void
            {
                $this->events[] = "beginOffscreenFrame:$width x $height";
            }
            public function endOffscreenFrame(): void
            {
                $this->events[] = 'endOffscreenFrame';
            }
            public function beginFrame(): void
            {
                $this->events[] = 'beginFrame';
            }
            public function endFrame(): void
            {
                $this->events[] = 'endFrame';
            }
        };

        $engine = new Engine(new EngineConfig(headless: true));
        $engine->renderer2D = $recorder;

        $engine->warmRender(function () use ($engine): void {
            $engine->renderer2D->beginFrame();
            $engine->renderer2D->endFrame();
        });

        $this->assertSame(
            ['beginOffscreenFrame:1280 x 720', 'beginFrame', 'endFrame', 'endOffscreenFrame'],
            $recorder->events,
            'warmRender must call begin/endOffscreenFrame around the callback',
        );
    }

    public function testWarmRenderFallsBackToRendererSizeWhenWindowReportsZero(): void
    {
        // When the window framebuffer is not yet sized (e.g. test contexts
        // before run() has initialised the window), warmRender should fall
        // back to the renderer's own logical dimensions so the offscreen
        // target is still well-defined.
        $recorder = new class extends NullRenderer2D {
            public int $observedW = -1;
            public int $observedH = -1;
            public function beginOffscreenFrame(int $width, int $height): void
            {
                $this->observedW = $width;
                $this->observedH = $height;
            }
        };

        $engine = new Engine(new EngineConfig(headless: true, width: 640, height: 480));
        $engine->renderer2D = $recorder;
        $recorder->setViewport(0, 0, 640, 480);

        $engine->warmRender(function (): void {});

        $this->assertGreaterThanOrEqual(1, $recorder->observedW);
        $this->assertGreaterThanOrEqual(1, $recorder->observedH);
    }
}
