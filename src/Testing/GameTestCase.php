<?php

declare(strict_types=1);

namespace PHPolygon\Testing;

use PHPolygon\Engine;
use PHPolygon\EngineConfig;
use PHPolygon\Rendering\Command\AddPointLight;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\DrawMeshInstanced;
use PHPolygon\Rendering\NullRenderer3D;
use PHPolygon\Rendering\RenderCommandList;
use PHPUnit\Framework\TestCase;

/**
 * Base test case for games built on PHPolygon.
 *
 * Ships in the engine (PSR-4 `PHPolygon\` → src/), so a consuming game can:
 *
 *   final class WorldTest extends GameTestCase {
 *       protected function registerScenes(Engine $e): void {
 *           $e->scenes->register('overworld', OverworldScene::class);
 *       }
 *       public function testOverworldSpawnsPlayer(): void {
 *           $this->loadScene('overworld');
 *           $this->assertEntityExists('overworld', 'Player');
 *           $this->tick();
 *           $this->assertDrawsMesh('player_body');
 *       }
 *   }
 *
 * Runs entirely headless (Null* backends) — no GPU, window or display server —
 * so game logic, ECS wiring, scene construction and the emitted 3D render
 * command list can be asserted in CI. For 2D pixel snapshots, use the
 * {@see VisualTestCase} trait (composed in below): build a {@see GdRenderer2D},
 * draw, and call `assertScreenshot()`.
 *
 * Lifecycle: a fresh headless {@see Engine} is created in setUp() from
 * {@see engineConfig()}; override that for 2D-only games or custom sizes, and
 * override {@see registerScenes()} to register the game's scenes.
 */
abstract class GameTestCase extends TestCase
{
    use VisualTestCase;

    protected Engine $engine;

    /**
     * Engine configuration for the test. Override for a 2D-only game
     * (`is3D: false`) or to change the virtual framebuffer size. Always
     * headless — the base class asserts that.
     */
    protected function engineConfig(): EngineConfig
    {
        return new EngineConfig(headless: true, is3D: true);
    }

    /**
     * Register the game's scenes on the engine. Override in the game's test.
     * Called once from setUp() after the engine is constructed.
     */
    protected function registerScenes(Engine $engine): void
    {
    }

    protected function setUp(): void
    {
        parent::setUp();

        $config = $this->engineConfig();
        if (!$config->headless) {
            $this->fail('GameTestCase requires a headless EngineConfig (headless: true)');
        }

        $this->engine = new Engine($config);
        $this->registerScenes($this->engine);
    }

    // ── Scene + world lifecycle ────────────────────────────────────────────

    /** Load a registered scene (single mode). */
    protected function loadScene(string $name): void
    {
        $this->engine->scenes->loadScene($name);
    }

    /**
     * Advance the simulation by $times update ticks of $dt seconds each.
     * Runs the ECS update phase only (no render).
     */
    protected function tick(float $dt = 1.0 / 60.0, int $times = 1): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->engine->world->update($dt);
        }
    }

    /**
     * Run one render pass and return the 3D command list the renderer received.
     * Requires the game to have wired a system that submits to the 3D renderer
     * (e.g. Renderer3DSystem). Returns an empty list if nothing was submitted.
     */
    protected function renderCommands(): RenderCommandList
    {
        $this->engine->world->render();

        $renderer = $this->engine->renderer3D;
        if ($renderer instanceof NullRenderer3D) {
            return $renderer->getLastCommandList() ?? new RenderCommandList();
        }
        return new RenderCommandList();
    }

    // ── Entity assertions ──────────────────────────────────────────────────

    /** Assert a named entity declared by the scene was materialised. */
    protected function assertEntityExists(string $sceneName, string $entityName): void
    {
        $entities = $this->engine->scenes->getSceneEntities($sceneName);
        self::assertNotNull($entities, "Scene '{$sceneName}' has no entity map (loaded?)");
        self::assertArrayHasKey(
            $entityName,
            $entities,
            "Entity '{$entityName}' not found in scene '{$sceneName}'",
        );
    }

    /** Assert the total number of live entities in the world. */
    protected function assertEntityCount(int $expected): void
    {
        self::assertSame($expected, $this->engine->world->entityCount());
    }

    protected function assertSceneLoaded(string $name): void
    {
        self::assertTrue(
            $this->engine->scenes->isLoaded($name),
            "Scene '{$name}' is not loaded",
        );
    }

    // ── Render-command assertions (headless 3D) ────────────────────────────

    /**
     * Assert at least one mesh (plain or instanced) with the given id is drawn
     * in the current frame's command list.
     */
    protected function assertDrawsMesh(string $meshId, ?RenderCommandList $commands = null): void
    {
        $commands ??= $this->renderCommands();
        foreach ($commands->getCommands() as $cmd) {
            if ($cmd instanceof DrawMesh && $cmd->meshId === $meshId) {
                self::addToAssertionCount(1);
                return;
            }
            if ($cmd instanceof DrawMeshInstanced && $cmd->meshId === $meshId) {
                self::addToAssertionCount(1);
                return;
            }
        }
        self::fail("No DrawMesh/DrawMeshInstanced for mesh '{$meshId}' in the command list");
    }

    /** Assert the number of DrawMesh commands (plain, non-instanced) this frame. */
    protected function assertMeshDrawCount(int $expected, ?RenderCommandList $commands = null): void
    {
        $commands ??= $this->renderCommands();
        self::assertCount($expected, $commands->ofType(DrawMesh::class));
    }

    /** Assert the number of point lights emitted this frame. */
    protected function assertPointLightCount(int $expected, ?RenderCommandList $commands = null): void
    {
        $commands ??= $this->renderCommands();
        self::assertCount($expected, $commands->ofType(AddPointLight::class));
    }
}
