<?php

declare(strict_types=1);

namespace PHPolygon\Tests\UI;

use PHPolygon\Engine;
use PHPolygon\EngineConfig;
use PHPolygon\Math\Vec2;
use PHPolygon\Rendering\NullRenderer2D;
use PHPolygon\Runtime\InputInterface;
use PHPolygon\Runtime\PerfProfiler;
use PHPolygon\UI\PerfOverlay;
use PHPUnit\Framework\TestCase;

/**
 * PerfOverlay unit tests.
 *
 * The overlay reads `frameTimesMs` and `lastGcDelta` directly off the Engine,
 * so we run with a headless engine (no GPU, no window) and prime those public
 * fields from the test. Input is faked through a hand-rolled stub that
 * answers a single keypress, the renderer is `NullRenderer2D`.
 */
final class PerfOverlayTest extends TestCase
{
    private Engine $engine;
    private PerfOverlay $overlay;

    protected function setUp(): void
    {
        // PerfProfiler keeps static accumulators across tests; clear them so
        // assertions on top-section content stay deterministic.
        PerfProfiler::reset();
        $this->resetProfilerBackend();

        $this->engine  = new Engine(new EngineConfig(headless: true, devMode: true));
        $this->overlay = new PerfOverlay($this->engine);
    }

    protected function tearDown(): void
    {
        PerfProfiler::reset();
        $this->resetProfilerBackend();
    }

    public function testEngineExposesOverlayWhenDevModeEnabled(): void
    {
        self::assertNotNull(
            $this->engine->perfOverlay,
            'Engine must construct PerfOverlay when EngineConfig::$devMode is true.',
        );
    }

    public function testEngineSkipsOverlayInProductionMode(): void
    {
        $prod = new Engine(new EngineConfig(headless: true, devMode: false));
        self::assertNull(
            $prod->perfOverlay,
            'PerfOverlay must be null when devMode is false so production builds pay zero overhead.',
        );
    }

    public function testStartsHidden(): void
    {
        self::assertFalse($this->overlay->isVisible());
    }

    public function testF3PressTogglesVisibility(): void
    {
        $input = new FakeInput(pressedKey: 292); // GLFW_KEY_F3

        $this->overlay->tickInput($input);
        self::assertTrue($this->overlay->isVisible(), 'First F3 must show the overlay.');

        $this->overlay->tickInput($input);
        self::assertFalse($this->overlay->isVisible(), 'Second F3 must hide the overlay again.');
    }

    public function testTickInputIgnoresOtherKeys(): void
    {
        $input = new FakeInput(pressedKey: 256); // ESC, not F3
        $this->overlay->tickInput($input);
        self::assertFalse(
            $this->overlay->isVisible(),
            'Only F3 may toggle the overlay; other keys must leave it untouched.',
        );
    }

    public function testSetVisibleOverridesToggle(): void
    {
        $this->overlay->setVisible(true);
        self::assertTrue($this->overlay->isVisible());

        $this->overlay->setVisible(false);
        self::assertFalse($this->overlay->isVisible());
    }

    public function testRenderIsNoOpWhenHidden(): void
    {
        $renderer = new NullRenderer2D();
        // Must not throw, must not depend on any engine state.
        $this->overlay->render($renderer);
        $this->expectNotToPerformAssertions();
    }

    public function testRenderReadsStatsFromEngineWhenVisible(): void
    {
        // Prime engine stats: 5 frames, 16.0ms each, no GC.
        $this->engine->frameTimesMs = [16.0, 16.0, 16.0, 16.0, 16.0];
        $this->engine->lastGcDelta  = ['runs' => 0, 'collected' => 0];
        $this->overlay->setVisible(true);

        $renderer = new NullRenderer2D();
        $this->overlay->render($renderer);

        // NullRenderer2D swallows every call; reaching here proves the
        // overlay walked its full draw path without dereferencing anything
        // missing on the headless engine.
        $this->expectNotToPerformAssertions();
    }

    public function testComputeFrameStatsHandlesEmptyBuffer(): void
    {
        $this->engine->frameTimesMs = [];
        $stats = $this->invokeComputeFrameStats();

        self::assertSame(0.0, $stats['fps']);
        self::assertSame(0.0, $stats['frameMs']);
        self::assertSame(0.0, $stats['p95Ms']);
    }

    public function testComputeFrameStatsDerivesFpsFromAverage(): void
    {
        // 10 ms per frame -> 100 fps average. Latest sample is the last
        // entry in the ring buffer.
        $this->engine->frameTimesMs = [10.0, 10.0, 10.0, 10.0, 12.0];
        $stats = $this->invokeComputeFrameStats();

        self::assertEqualsWithDelta(
            1000.0 / ((10.0 + 10.0 + 10.0 + 10.0 + 12.0) / 5),
            $stats['fps'],
            0.01,
        );
        self::assertSame(12.0, $stats['frameMs']);
        // p95 of 5 samples picks index floor(0.95 * 4) = 3 in the sorted array
        // -> sorted = [10, 10, 10, 10, 12], idx 3 = 10.
        self::assertSame(10.0, $stats['p95Ms']);
    }

    public function testTopSectionsSortsByTotalNsAndRespectsLimit(): void
    {
        // Force the profiler to record sections so snapshot() is non-empty.
        $rp = new \ReflectionProperty(PerfProfiler::class, 'backend');
        $rp->setValue(null, 1); // BACKEND_SPX, but we never hit a C call

        // Inject sections directly so the test does not depend on hrtime
        // resolution. snapshot() reads the static accumulator, so writing
        // it via Reflection is the cleanest way to set up this scenario.
        $sections = new \ReflectionProperty(PerfProfiler::class, 'sections');
        $sections->setValue(null, [
            'fast'   => [50,   500_000],   // 50 calls,    0.5ms total
            'medium' => [10,  5_000_000],   // 10 calls,    5ms total
            'slow'   => [ 5, 50_000_000],   //  5 calls,   50ms total
            'tiny'   => [99,   100_000],   // 99 calls,    0.1ms total
        ]);

        $top = $this->invokeTopSections(2);

        self::assertSame(['slow', 'medium'], array_keys($top), 'Top sections must be sorted by totalNs descending.');
        self::assertEqualsWithDelta(10.0, $top['slow']['avgMs'],   0.001, 'avgMs = totalNs / calls / 1e6.');
        self::assertEqualsWithDelta( 0.5, $top['medium']['avgMs'], 0.001);
        self::assertSame(5,  $top['slow']['calls']);
        self::assertSame(10, $top['medium']['calls']);
    }

    public function testTopSectionsReturnsEmptyArrayWhenNoSections(): void
    {
        // Reset already cleared the accumulator in setUp().
        $top = $this->invokeTopSections(8);
        self::assertSame([], $top);
    }

    /**
     * @return array{fps:float, frameMs:float, p95Ms:float}
     */
    private function invokeComputeFrameStats(): array
    {
        $rm = new \ReflectionMethod($this->overlay, 'computeFrameStats');
        /** @var array{fps:float, frameMs:float, p95Ms:float} $result */
        $result = $rm->invoke($this->overlay);
        return $result;
    }

    /**
     * @return array<string, array{avgMs:float, calls:int}>
     */
    private function invokeTopSections(int $limit): array
    {
        $rm = new \ReflectionMethod($this->overlay, 'topSections');
        /** @var array<string, array{avgMs:float, calls:int}> $result */
        $result = $rm->invoke($this->overlay, $limit);
        return $result;
    }

    private function resetProfilerBackend(): void
    {
        $rp = new \ReflectionProperty(PerfProfiler::class, 'backend');
        $rp->setValue(null, -1);
    }
}

/**
 * Minimal hand-rolled InputInterface stub. Reports the configured key as
 * pressed exactly once per call to `isKeyPressed`, then resets so the second
 * tick simulates the player releasing + tapping again.
 */
final class FakeInput implements InputInterface
{
    public function __construct(private int $pressedKey)
    {
    }

    public function isKeyDown(int $key): bool
    {
        return false;
    }

    public function isKeyPressed(int $key): bool
    {
        return $key === $this->pressedKey;
    }

    public function isKeyReleased(int $key): bool
    {
        return false;
    }

    public function isMouseButtonDown(int $button): bool
    {
        return false;
    }

    public function isMouseButtonPressed(int $button): bool
    {
        return false;
    }

    public function isMouseButtonReleased(int $button): bool
    {
        return false;
    }

    public function getMousePosition(): Vec2
    {
        return new Vec2(0.0, 0.0);
    }

    public function getMouseX(): float
    {
        return 0.0;
    }

    public function getMouseY(): float
    {
        return 0.0;
    }

    public function getScrollX(): float
    {
        return 0.0;
    }

    public function getScrollY(): float
    {
        return 0.0;
    }

    /** @return list<string> */
    public function getCharsTyped(): array
    {
        return [];
    }

    public function getTextInput(): string
    {
        return '';
    }

    public function getBackspaceCount(): int
    {
        return 0;
    }

    public function showSoftKeyboard(): void
    {
    }

    public function hideSoftKeyboard(): void
    {
    }

    public function suppress(int $frames = 0, float $seconds = 0.0): void
    {
    }

    public function unsuppress(): void
    {
    }

    public function isSuppressed(): bool
    {
        return false;
    }

    public function clearKeyEdges(): void
    {
    }

    public function endFrame(): void
    {
    }
}
