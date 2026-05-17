<?php

declare(strict_types=1);

namespace PHPolygon\Tests;

use PHPUnit\Framework\TestCase;
use PHPolygon\Engine;
use PHPolygon\EngineConfig;
use PHPolygon\Rendering\NullRenderer2D;
use PHPolygon\Rendering\NullRenderer3D;
use PHPolygon\Rendering\Renderer2DInterface;
use PHPolygon\Rendering\Renderer3DInterface;
use PHPolygon\Runtime\NullWindow;

class HeadlessEngineTest extends TestCase
{
    public function testHeadlessEngineCreatesWithoutGpu(): void
    {
        $engine = new Engine(new EngineConfig(headless: true));

        $this->assertInstanceOf(NullWindow::class, $engine->window);
        $this->assertInstanceOf(NullRenderer2D::class, $engine->renderer2D);
        $this->assertInstanceOf(Renderer2DInterface::class, $engine->renderer2D);
    }

    public function testHeadlessEngineWorldIsAccessible(): void
    {
        $engine = new Engine(new EngineConfig(headless: true));

        $this->assertNotNull($engine->world);
        $this->assertNotNull($engine->events);
        $this->assertNotNull($engine->input);
        $this->assertNotNull($engine->scenes);
        $this->assertNotNull($engine->audio);
        $this->assertNotNull($engine->locale);
        $this->assertNotNull($engine->saves);
    }

    public function testHeadlessRunExecutesInitAndStops(): void
    {
        $engine = new Engine(new EngineConfig(headless: true));

        $initCalled = false;
        $updateCount = 0;

        $engine->onInit(function (Engine $e) use (&$initCalled) {
            $initCalled = true;
        });

        $engine->onUpdate(function (Engine $e, float $dt) use (&$updateCount) {
            $updateCount++;
            if ($updateCount >= 3) {
                $e->stop();
            }
        });

        $engine->run();

        $this->assertTrue($initCalled);
        $this->assertGreaterThanOrEqual(3, $updateCount);
    }

    /**
     * Headless / skipSplash path must drive generator-style onInit callbacks
     * to completion. Regression test for the bug where the headless branch
     * called the generator function and discarded the returned Generator,
     * leaving init code never executed.
     */
    public function testHeadlessRunDrivesGeneratorOnInit(): void
    {
        $engine = new Engine(new EngineConfig(headless: true));

        $chunks = 0;
        $finalReached = false;

        $engine->onInit(function (Engine $e) use (&$chunks, &$finalReached) {
            $chunks++;
            yield;
            $chunks++;
            yield;
            $chunks++;
            $finalReached = true;
        });

        $engine->onUpdate(function (Engine $e) {
            $e->stop();
        });

        $engine->run();

        $this->assertSame(3, $chunks, 'all three chunks between yields must execute');
        $this->assertTrue($finalReached, 'code after the last yield must run');
    }

    public function testHeadlessEngineCanLoadScenes(): void
    {
        $engine = new Engine(new EngineConfig(headless: true));

        // SceneManager should be functional without GPU
        $this->assertNotNull($engine->scenes);
    }

    public function testNullWindowReportsCorrectDimensions(): void
    {
        $window = new NullWindow(800, 600);

        $this->assertSame(800, $window->getWidth());
        $this->assertSame(600, $window->getHeight());
        $this->assertSame(800, $window->getFramebufferWidth());
        $this->assertSame(600, $window->getFramebufferHeight());
        $this->assertSame(1.0, $window->getPixelRatio());
        $this->assertFalse($window->isFullscreen());
        $this->assertFalse($window->shouldClose());
    }

    public function testNullWindowRequestClose(): void
    {
        $window = new NullWindow();

        $this->assertFalse($window->shouldClose());
        $window->requestClose();
        $this->assertTrue($window->shouldClose());
    }

    public function testNullWindowGetHandleThrows(): void
    {
        $window = new NullWindow();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('headless');

        $window->getHandle();
    }

    public function testNullRenderer2DAcceptsAllDrawCalls(): void
    {
        $renderer = new NullRenderer2D(1920, 1080);

        $this->assertSame(1920, $renderer->getWidth());
        $this->assertSame(1080, $renderer->getHeight());

        // These should all execute without error
        $renderer->beginFrame();
        $renderer->clear(new \PHPolygon\Rendering\Color(0, 0, 0));
        $renderer->drawRect(0, 0, 100, 100, new \PHPolygon\Rendering\Color(1, 0, 0));
        $renderer->drawText('test', 0, 0, 16, new \PHPolygon\Rendering\Color(1, 1, 1));
        $renderer->endFrame();

        // No assertions needed beyond "no exceptions thrown"
        $this->assertTrue(true);
    }

    public function testNullRenderer2DSetViewportUpdatesSize(): void
    {
        $renderer = new NullRenderer2D();

        $renderer->setViewport(0, 0, 640, 480);

        $this->assertSame(640, $renderer->getWidth());
        $this->assertSame(480, $renderer->getHeight());
    }

    public function testHeadlessDefaultIsFalse(): void
    {
        $config = new EngineConfig();

        $this->assertFalse($config->headless);
    }

    public function testHeadless3DEngineCreatesNullRenderer3D(): void
    {
        $engine = new Engine(new EngineConfig(headless: true, is3D: true));

        $this->assertInstanceOf(NullRenderer3D::class, $engine->renderer3D);
        $this->assertInstanceOf(Renderer3DInterface::class, $engine->renderer3D);
        $this->assertNotNull($engine->commandList3D);
    }

    public function testNon3DEngineHasNullRenderer3D(): void
    {
        $engine = new Engine(new EngineConfig(headless: true, is3D: false));

        $this->assertNull($engine->renderer3D);
        $this->assertNull($engine->commandList3D);
    }
}
