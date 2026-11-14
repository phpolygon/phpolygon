<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Runtime;

use PHPolygon\Component\NameTag;
use PHPolygon\Component\Transform2D;
use PHPolygon\EditorSyncMode;
use PHPolygon\Engine;
use PHPolygon\EngineConfig;
use PHPolygon\Math\Vec2;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Live sync as an editor uses it: publish the world, take pause/step requests,
 * and — in Reconcile mode — accept an edit while the world stands still.
 *
 * The sync tick is driven directly rather than through a real frame loop; the
 * loop only calls it, and a headless loop would add timing to every assertion.
 */
class EditorSyncTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/phpolygon-sync-' . uniqid() . '.world.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        @unlink($this->path . '.control');
    }

    private function engine(): Engine
    {
        return new Engine(new EngineConfig(headless: true, skipSplash: true));
    }

    /** Drive one throttled sync tick. */
    private function tick(Engine $engine, float $dt = 1.0): void
    {
        $method = new ReflectionMethod($engine, 'tickEditorSync');
        $method->invoke($engine, $dt);
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        clearstatcache(true, $this->path);
        $raw = file_get_contents($this->path);

        return $raw === false ? [] : (json_decode($raw, true) ?? []);
    }

    private function control(array $data): void
    {
        file_put_contents($this->path . '.control', (string) json_encode($data));
    }

    public function test_enabling_sync_publishes_the_world_at_once(): void
    {
        $engine = $this->engine();
        $engine->world->createEntity()->attach(new NameTag('Ground'));

        $engine->enableEditorSync($this->path);

        $this->assertFileExists($this->path);
        $this->assertSame('Ground', $this->snapshot()['entities'][0]['name']);
    }

    public function test_reconcile_publishes_nothing_while_the_world_stands_still(): void
    {
        // Its whole point: a still world writes nothing, so leaving sync on is
        // cheap.
        $engine = $this->engine();
        $engine->enableEditorSync($this->path, 0.05);
        $before = filemtime($this->path);

        sleep(1); // filemtime has one-second resolution
        $this->tick($engine);

        clearstatcache(true, $this->path);
        $this->assertSame($before, filemtime($this->path));
    }

    public function test_reconcile_republishes_once_the_world_advances(): void
    {
        $engine = $this->engine();
        $engine->enableEditorSync($this->path, 0.05);

        $engine->world->createEntity()->attach(new NameTag('Spawned'));
        $this->tick($engine);

        $names = array_column($this->snapshot()['entities'], 'name');
        $this->assertContains('Spawned', $names);
    }

    public function test_stream_publishes_movement_reconcile_would_miss(): void
    {
        // Moving an entity changes component VALUES without changing the
        // world's structure, so a version check publishes nothing and an editor
        // sees a world frozen at the first frame.
        $engine = $this->engine();
        $entity = $engine->world->createEntity();
        $entity->attach(new NameTag('Player'));
        $transform = new Transform2D(new Vec2(0.0, 0.0));
        $entity->attach($transform);

        $engine->enableEditorSync($this->path, 0.05, EditorSyncMode::Stream);
        $transform->position = new Vec2(42.0, 0.0);
        $this->tick($engine);

        $player = $this->snapshot()['entities'][0];
        $position = $player['components'][1]['position'] ?? $player['components'][0]['position'];
        // assertEquals, not assertSame: a whole float round-trips through JSON as an int.
        $this->assertEquals(42.0, $position['x']);
    }

    public function test_the_same_movement_stays_invisible_in_reconcile(): void
    {
        $engine = $this->engine();
        $entity = $engine->world->createEntity();
        $entity->attach(new NameTag('Player'));
        $transform = new Transform2D(new Vec2(0.0, 0.0));
        $entity->attach($transform);

        $engine->enableEditorSync($this->path, 0.05);
        $transform->position = new Vec2(42.0, 0.0);
        $this->tick($engine);

        $player = $this->snapshot()['entities'][0];
        $position = $player['components'][1]['position'] ?? $player['components'][0]['position'];
        $this->assertEquals(0.0, $position['x'], 'reconcile only republishes on structural change');
    }

    public function test_the_mode_is_reported_only_while_sync_runs(): void
    {
        $engine = $this->engine();
        $this->assertNull($engine->editorSyncMode());

        $engine->enableEditorSync($this->path, 0.05, EditorSyncMode::Stream);
        $this->assertSame(EditorSyncMode::Stream, $engine->editorSyncMode());

        $engine->disableEditorSync();
        $this->assertNull($engine->editorSyncMode());
    }

    // --- pause / step -----------------------------------------------------

    public function test_pause_and_resume(): void
    {
        $engine = $this->engine();

        $this->assertFalse($engine->isPaused());
        $engine->pause();
        $this->assertTrue($engine->isPaused());
        $engine->resume();
        $this->assertFalse($engine->isPaused());
    }

    public function test_a_paused_engine_simulates_nothing(): void
    {
        $engine = $this->engine();
        $engine->pause();

        $this->assertFalse($this->shouldSimulate($engine));
        $this->assertFalse($this->shouldSimulate($engine));
    }

    public function test_a_step_advances_exactly_one_frame(): void
    {
        // Stepping is for watching one frame's worth of change; a step that
        // leaked into the next frame would defeat that.
        $engine = $this->engine();
        $engine->pause();
        $engine->step();

        $this->assertTrue($this->shouldSimulate($engine));
        $this->assertFalse($this->shouldSimulate($engine));
    }

    public function test_steps_accumulate(): void
    {
        $engine = $this->engine();
        $engine->pause();
        $engine->step(3);

        $this->assertTrue($this->shouldSimulate($engine));
        $this->assertTrue($this->shouldSimulate($engine));
        $this->assertTrue($this->shouldSimulate($engine));
        $this->assertFalse($this->shouldSimulate($engine));
    }

    public function test_resuming_discards_owed_steps(): void
    {
        $engine = $this->engine();
        $engine->pause();
        $engine->step(5);
        $engine->resume();
        $engine->pause();

        $this->assertFalse($this->shouldSimulate($engine), 'resume clears what was owed');
    }

    public function test_an_editor_can_pause_through_the_control_file(): void
    {
        $engine = $this->engine();
        $engine->enableEditorSync($this->path, 0.05);

        $this->control(['paused' => true]);
        $this->tick($engine);

        $this->assertTrue($engine->isPaused());
    }

    public function test_an_editor_can_step_through_the_control_file(): void
    {
        $engine = $this->engine();
        $engine->enableEditorSync($this->path, 0.05);
        $engine->pause();

        $this->control(['paused' => true, 'step' => 1]);
        $this->tick($engine);

        $this->assertTrue($this->shouldSimulate($engine));
        $this->assertFalse($this->shouldSimulate($engine));
    }

    public function test_a_step_request_is_consumed_not_repeated(): void
    {
        // Left in place, every following tick would step again and the "paused"
        // game would run.
        $engine = $this->engine();
        $engine->enableEditorSync($this->path, 0.05);
        $engine->pause();

        $this->control(['paused' => true, 'step' => 1]);
        $this->tick($engine);
        $this->tick($engine);

        $this->assertSame(0, json_decode((string) file_get_contents($this->path . '.control'), true)['step']);
    }

    public function test_a_half_written_control_file_is_ignored(): void
    {
        $engine = $this->engine();
        $engine->enableEditorSync($this->path, 0.05);
        file_put_contents($this->path . '.control', '{"paused": tr');

        $this->tick($engine);

        $this->assertFalse($engine->isPaused());
    }

    public function test_a_game_without_a_control_file_is_unaffected(): void
    {
        $engine = $this->engine();
        $engine->enableEditorSync($this->path, 0.05);

        $this->tick($engine);

        $this->assertFalse($engine->isPaused());
    }

    private function shouldSimulate(Engine $engine): bool
    {
        return (new ReflectionMethod($engine, 'shouldSimulate'))->invoke($engine);
    }
}
