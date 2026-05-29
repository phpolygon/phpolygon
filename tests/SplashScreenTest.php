<?php

declare(strict_types=1);

namespace PHPolygon\Tests;

use PHPUnit\Framework\TestCase;
use PHPolygon\Engine;
use PHPolygon\EngineConfig;
use PHPolygon\Rendering\NullRenderer2D;
use PHPolygon\Rendering\NullRenderer3D;

class SplashScreenTest extends TestCase
{
    public function testSkipSplashDefaultIsFalse(): void
    {
        $config = new EngineConfig();

        $this->assertFalse($config->skipSplash);
    }

    public function testSplashDurationDefault(): void
    {
        $config = new EngineConfig();

        $this->assertSame(2.5, $config->splashDuration);
    }

    public function testSplashDurationCustom(): void
    {
        $config = new EngineConfig(splashDuration: 1.0);

        $this->assertSame(1.0, $config->splashDuration);
    }

    public function testStudioSplashDefaultIsNull(): void
    {
        // Studio splash is opt-in. Games that don't supply one get the
        // engine splash only - same behaviour as before the feature landed.
        $config = new EngineConfig();

        $this->assertNull($config->studioSplash);
    }

    public function testStudioSplashCanBeSet(): void
    {
        $splash = new class implements \PHPolygon\Branding\StudioSplashInterface {
            public function getDuration(): float { return 1.0; }
            public function render(\PHPolygon\Rendering\Renderer2DInterface $r, float $elapsed): void {}
            public function isSkippable(float $elapsed): bool { return $elapsed >= 0.3; }
        };
        $config = new EngineConfig(studioSplash: $splash);

        $this->assertSame($splash, $config->studioSplash);
    }

    public function testHeadlessEngineSkipsSplashAndRuns(): void
    {
        $engine = new Engine(new EngineConfig(headless: true));

        $initCalled = false;
        $engine->onInit(function () use (&$initCalled) {
            $initCalled = true;
        });
        $engine->onUpdate(function (Engine $e) {
            $e->stop();
        });

        $engine->run();

        $this->assertTrue($initCalled);
    }

    public function testSkipSplashEngineRunsNormally(): void
    {
        $engine = new Engine(new EngineConfig(headless: true, skipSplash: true));

        $updateCount = 0;
        $engine->onUpdate(function (Engine $e, float $dt) use (&$updateCount) {
            $updateCount++;
            if ($updateCount >= 2) {
                $e->stop();
            }
        });

        $engine->run();

        $this->assertGreaterThanOrEqual(2, $updateCount);
    }

    public function testRendererInfoEmptyInHeadless(): void
    {
        $engine = new Engine(new EngineConfig(headless: true));

        // Headless uses NullRenderer2D — not a "real" backend, so info is empty
        $this->assertSame('', $engine->buildRendererInfo());
    }

    public function testRendererInfoEmptyForHeadless3D(): void
    {
        $engine = new Engine(new EngineConfig(headless: true, is3D: true));

        // NullRenderer2D + NullRenderer3D — neither is a real backend
        $this->assertSame('', $engine->buildRendererInfo());
    }

    public function testRendererInfoWithNullRenderer3DExcluded(): void
    {
        $engine = new Engine(new EngineConfig(headless: true, is3D: true, renderBackend3D: 'null'));

        // Explicit null backend should not appear in renderer info
        $this->assertSame('', $engine->buildRendererInfo());
    }

    public function testSplashLogoPathExists(): void
    {
        $logoPath = __DIR__ . '/../resources/branding/logo.png';

        $this->assertFileExists($logoPath);
    }

    public function testSplashLogoDimensions(): void
    {
        $logoPath = __DIR__ . '/../resources/branding/logo.png';
        $size = getimagesize($logoPath);

        $this->assertNotFalse($size);
        $this->assertGreaterThan(0, $size[0]); // width
        $this->assertGreaterThan(0, $size[1]); // height
    }
}
