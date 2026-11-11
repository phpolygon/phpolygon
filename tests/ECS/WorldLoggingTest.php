<?php

declare(strict_types=1);

namespace PHPolygon\Tests\ECS;

use PHPUnit\Framework\TestCase;
use PHPolygon\ECS\SystemPhase;
use PHPolygon\ECS\World;
use PHPolygon\Log\Log;
use PHPolygon\Tests\Log\RecordingLogWriter;

final class WorldLoggingTest extends TestCase
{
    private RecordingLogWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writer = new RecordingLogWriter();
    }

    protected function tearDown(): void
    {
        Log::reset();
        parent::tearDown();
    }

    public function testHooksOffAddsNoLines(): void
    {
        Log::startWithWriter($this->writer, hooks: false);
        $world = new World();
        $system = new RecordingSystem();

        $world->addSystem($system);
        $world->update(0.016);
        $world->render();
        $world->removeSystem($system);

        $this->assertSame([], $this->writer->writes);
    }

    public function testAddAndRemoveAreLoggedWithClassAndPhase(): void
    {
        Log::startWithWriter($this->writer, hooks: true);
        $world = new World();
        $system = new RecordingSystem();

        $world->addSystem($system, SystemPhase::PostThread);
        $world->removeSystem($system);

        $log = $this->writer->all();
        $this->assertStringContainsString('[system] added', $log);
        $this->assertStringContainsString(RecordingSystem::class, $log);
        $this->assertStringContainsString('PostThread', $log);
        $this->assertStringContainsString('[system] removed', $log);
    }

    public function testFirstRunIsLoggedOnceNotOnEveryFrame(): void
    {
        Log::startWithWriter($this->writer, hooks: true);
        $world = new World();
        $system = new RecordingSystem();
        $world->addSystem($system);

        $world->update(0.016);
        $world->update(0.016);
        $world->update(0.016);

        $this->assertSame(1, substr_count($this->writer->all(), 'first run'));
    }

    public function testFirstRunCoversTheSystemOnceNotUpdateAndRenderSeparately(): void
    {
        Log::startWithWriter($this->writer, hooks: true);
        $world = new World();
        $system = new RecordingSystem();
        $world->addSystem($system);

        $world->update(0.016); // the system's very first call: logged
        $world->render(); // already seen once: not logged again

        $this->assertSame(1, substr_count($this->writer->all(), 'first run'));
        $this->assertStringContainsString('phase=update', $this->writer->all());
    }

    public function testASlowSystemIsReportedWithItsDuration(): void
    {
        Log::startWithWriter($this->writer, hooks: true);
        Log::$slowSystemNs = 1_000_000; // 1 ms, so a 5ms sleep trips it reliably
        $world = new World();
        $system = new RecordingSystem();
        $system->sleepMicroseconds = 5_000;
        $world->addSystem($system);

        $world->update(0.016);

        $log = $this->writer->all();
        $this->assertStringContainsString('[system] WARN slow', $log);
        $this->assertStringContainsString('ms=', $log);
    }

    public function testAFastSystemIsNotReportedAsSlow(): void
    {
        Log::startWithWriter($this->writer, hooks: true);
        $world = new World();
        $world->addSystem(new RecordingSystem());

        $world->update(0.016);

        $this->assertStringNotContainsString('slow', $this->writer->all());
    }

    public function testAnExceptionInASystemIsLoggedThenRethrown(): void
    {
        Log::startWithWriter($this->writer, hooks: true);
        $world = new World();
        $system = new RecordingSystem();
        $system->throwOnNextUpdate = true;
        $world->addSystem($system);

        try {
            $world->update(0.016);
            $this->fail('the exception must propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $log = $this->writer->all();
        $this->assertStringContainsString('[system] ERROR exception', $log);
        $this->assertStringContainsString('boom', $log);
    }

    public function testTraceModeWritesOneFrameTimesLinePerRenderWithUpdateAndRenderMs(): void
    {
        Log::startWithWriter($this->writer, hooks: true, trace: true);
        $world = new World();
        $world->addSystem(new RecordingSystem());

        $world->update(0.016);
        $world->update(0.016); // a second update step before the render, still one line
        $world->render();

        $lines = array_filter(explode("\n", $this->writer->all()), static fn (string $l): bool => str_contains($l, 'frame times'));
        $this->assertCount(1, $lines);
        $this->assertStringContainsString('update_ms', $lines[array_key_first($lines)]);
        $this->assertStringContainsString('render_ms', $lines[array_key_first($lines)]);
    }

    public function testTraceModeOffWritesNoFrameTimesLine(): void
    {
        Log::startWithWriter($this->writer, hooks: true, trace: false);
        $world = new World();
        $world->addSystem(new RecordingSystem());

        $world->update(0.016);
        $world->render();

        $this->assertStringNotContainsString('frame times', $this->writer->all());
    }

    public function testRemovingASystemForgetsItsFirstRunState(): void
    {
        Log::startWithWriter($this->writer, hooks: true);
        $world = new World();
        $system = new RecordingSystem();
        $world->addSystem($system);
        $world->update(0.016); // first run logged

        $world->removeSystem($system);
        $world->addSystem($system); // re-added: a fresh "first run" again

        $this->writer->writes = [];
        $world->update(0.016);

        $this->assertStringContainsString('first run', $this->writer->all());
    }
}
