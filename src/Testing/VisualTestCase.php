<?php

declare(strict_types=1);

namespace PHPolygon\Testing;

/**
 * PHPUnit trait providing Playwright-style visual regression testing.
 *
 * Usage:
 *   class MyVisualTest extends TestCase {
 *       use VisualTestCase;
 *
 *       public function testMainMenu(): void {
 *           $renderer = new GdRenderer2D(800, 600);
 *           $renderer->beginFrame();
 *           // ... draw your scene ...
 *           $renderer->endFrame();
 *
 *           $this->assertScreenshot($renderer, 'main-menu');
 *       }
 *   }
 *
 * First run: saves reference screenshot and passes.
 * Subsequent runs: compares against reference, fails on diff.
 *
 * Update references:
 *   PHPOLYGON_UPDATE_SNAPSHOTS=1 vendor/bin/phpunit
 */
/**
 * @phpstan-require-extends \PHPUnit\Framework\TestCase
 */
trait VisualTestCase
{
    /**
     * Assert that the rendered frame matches the reference screenshot.
     *
     * @param GdRenderer2D $renderer The renderer with the current frame
     * @param string $name Screenshot name (e.g. 'main-menu', 'hud-layout')
     * @param float $threshold Per-pixel color threshold in YIQ space (0.0–1.0)
     * @param int|null $maxDiffPixels Absolute pixel count tolerance
     * @param float|null $maxDiffPixelRatio Ratio tolerance (0.0–1.0)
     * @param array<array{x: int, y: int, w: int, h: int}> $mask Rectangles to mask (filled with magenta)
     */
    /**
     * Whether to include a platform suffix in snapshot filenames.
     * Override in your test class if you use fonts or other platform-dependent features.
     *
     * @return bool true → "name-gd-darwin.png", false → "name.png"
     */
    protected function usePlatformSuffix(): bool
    {
        return false;
    }

    protected function assertScreenshot(
        GdRenderer2D $renderer,
        string $name,
        float $threshold = 0.1,
        ?int $maxDiffPixels = null,
        ?float $maxDiffPixelRatio = null,
        array $mask = [],
    ): void {
        $snapshotDir = $this->resolveSnapshotDir();
        $suffix = $this->usePlatformSuffix() ? '-' . $this->detectPlatformSuffix() : '';
        $filename = "{$name}{$suffix}.png";

        $expectedPath = $snapshotDir . '/' . $filename;
        $actualPath = $snapshotDir . '/' . "{$name}{$suffix}.actual.png";
        $diffPath = $snapshotDir . '/' . "{$name}{$suffix}.diff.png";

        // Apply masks before saving
        if (count($mask) > 0) {
            $this->applyMasks($renderer, $mask);
        }

        // Save actual screenshot
        @mkdir($snapshotDir, 0755, true);
        $renderer->savePng($actualPath);

        $updateMode = $this->isUpdateSnapshotsMode();

        // First run or update mode: save as reference
        if (!file_exists($expectedPath) || $updateMode) {
            copy($actualPath, $expectedPath);
            @unlink($actualPath);
            @unlink($diffPath);

            if (!file_exists($expectedPath)) {
                $this->fail("Failed to save reference screenshot: {$expectedPath}");
            }

            // First run passes — Playwright behavior
            $this->addToAssertionCount(1);
            return;
        }

        // Compare
        $result = ScreenshotComparer::compare($expectedPath, $actualPath, $diffPath, $threshold);

        if ($result->passes($maxDiffPixels, $maxDiffPixelRatio)) {
            // Clean up actual/diff on success
            @unlink($actualPath);
            @unlink($diffPath);
            $this->addToAssertionCount(1);
            return;
        }

        // Build failure message
        $msg = "Visual regression detected for '{$name}'.\n";
        $msg .= $result->summary() . "\n\n";
        $msg .= "  Expected: {$expectedPath}\n";
        $msg .= "  Actual:   {$actualPath}\n";
        if ($result->diffPath !== null) {
            $msg .= "  Diff:     {$result->diffPath}\n";
        }
        $msg .= "\nTo update snapshots: PHPOLYGON_UPDATE_SNAPSHOTS=1 vendor/bin/phpunit";

        $this->fail($msg);
    }

    /**
     * Resolve the snapshot directory for the current test.
     * Convention: TestFile.php → TestFile.php-snapshots/
     */
    private function resolveSnapshotDir(): string
    {
        $reflector = new \ReflectionClass($this);
        $testFile = $reflector->getFileName();

        if ($testFile === false) {
            throw new \RuntimeException('Cannot determine test file path');
        }

        return $testFile . '-snapshots';
    }

    /**
     * Detect platform suffix for snapshot naming.
     */
    private function detectPlatformSuffix(): string
    {
        $os = match (PHP_OS_FAMILY) {
            'Darwin' => 'darwin',
            'Windows' => 'win32',
            default => 'linux',
        };

        return "gd-{$os}";
    }

    /**
     * Check if we're in update-snapshots mode.
     */
    private function isUpdateSnapshotsMode(): bool
    {
        return (bool) (getenv('PHPOLYGON_UPDATE_SNAPSHOTS') ?: false);
    }

    /**
     * Apply mask rectangles to the renderer image (filled with magenta).
     * Masked areas are ignored during comparison because both reference
     * and actual will have the same magenta fill.
     *
     * @param array<array{x: int, y: int, w: int, h: int}> $masks
     */
    private function applyMasks(GdRenderer2D $renderer, array $masks): void
    {
        $image = $renderer->getImage();
        $magenta = imagecolorallocate($image, 255, 0, 255);
        if ($magenta === false) {
            return;
        }

        foreach ($masks as $mask) {
            imagefilledrectangle(
                $image,
                $mask['x'],
                $mask['y'],
                $mask['x'] + $mask['w'] - 1,
                $mask['y'] + $mask['h'] - 1,
                $magenta,
            );
        }
    }

    /**
     * Create a headless engine with GD renderer for visual testing.
     *
     * @return array{0: \PHPolygon\Engine, 1: GdRenderer2D}
     */
    protected function createVisualTestEngine(int $width = 800, int $height = 600): array
    {
        $engine = new \PHPolygon\Engine(new \PHPolygon\EngineConfig(
            width: $width,
            height: $height,
            headless: true,
        ));

        $renderer = new GdRenderer2D($width, $height);
        $engine->renderer2D = $renderer;

        return [$engine, $renderer];
    }

    /**
     * Load a scene into a headless engine, tick once, render, and return
     * the engine + renderer ready for assertScreenshot().
     *
     * Usage:
     *   [$engine, $renderer] = $this->renderScene(MainMenu::class, 'main-menu');
     *   $this->assertScreenshot($renderer, 'main-menu');
     *
     * @param class-string<\PHPolygon\Scene\Scene> $sceneClass
     * @param string $sceneName Registry name for the scene
     * @param int $ticks Number of update ticks before rendering (default 1)
     * @return array{0: \PHPolygon\Engine, 1: GdRenderer2D}
     */
    protected function renderScene(
        string $sceneClass,
        string $sceneName,
        int $width = 800,
        int $height = 600,
        int $ticks = 1,
        float $dt = 1.0 / 60.0,
    ): array {
        [$engine, $renderer] = $this->createVisualTestEngine($width, $height);

        // Set up the Renderer2DSystem so sprites get drawn through GdRenderer
        $renderSystem = new \PHPolygon\System\Renderer2DSystem(
            $renderer,
            $engine->camera2D,
            $engine->textures,
        );
        $engine->world->addSystem($renderSystem);

        // Register and load the scene
        $engine->scenes->register($sceneName, $sceneClass);
        $engine->scenes->loadScene($sceneName);

        // Run update ticks (physics, logic, etc.)
        for ($i = 0; $i < $ticks; $i++) {
            $engine->world->update($dt);
        }

        // Render one frame
        $renderer->beginFrame();
        $engine->world->render();
        $renderer->endFrame();

        return [$engine, $renderer];
    }
}
