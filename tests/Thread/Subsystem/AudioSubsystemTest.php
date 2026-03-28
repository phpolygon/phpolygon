<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Thread\Subsystem;

use PHPUnit\Framework\TestCase;
use PHPolygon\ECS\World;
use PHPolygon\Thread\NullThreadScheduler;
use PHPolygon\Thread\Subsystem\AudioCommandBuffer;
use PHPolygon\Thread\Subsystem\AudioSubsystem;

class AudioSubsystemTest extends TestCase
{
    public function testCommandBufferCollectsAndFlushes(): void
    {
        $buffer = new AudioCommandBuffer();
        $this->assertTrue($buffer->isEmpty());

        $buffer->play('explosion', 0.8, false);
        $buffer->stop(42);
        $buffer->setMasterVolume(0.5);

        $this->assertFalse($buffer->isEmpty());

        $commands = $buffer->flush();
        $this->assertCount(3, $commands);
        $this->assertSame('play', $commands[0]['type']);
        $this->assertSame('explosion', $commands[0]['clipId']);
        $this->assertSame('stop', $commands[1]['type']);
        $this->assertSame(42, $commands[1]['playbackId']);
        $this->assertSame('setMasterVolume', $commands[2]['type']);

        // Buffer should be empty after flush
        $this->assertTrue($buffer->isEmpty());
        $this->assertCount(0, $buffer->flush());
    }

    public function testComputeProcessesPlayCommands(): void
    {
        $result = AudioSubsystem::compute([
            'commands' => [
                ['type' => 'play', 'clipId' => 'bgm', 'volume' => 1.0, 'loop' => true],
                ['type' => 'play', 'clipId' => 'sfx_hit', 'volume' => 0.5, 'loop' => false],
            ],
            'backendClass' => \PHPolygon\Audio\NullAudioBackend::class,
        ]);

        $this->assertArrayHasKey('playbackIds', $result);
        $this->assertCount(2, $result['playbackIds']);
        $this->assertEmpty($result['finished']);
    }

    public function testComputeProcessesStopCommands(): void
    {
        $result = AudioSubsystem::compute([
            'commands' => [
                ['type' => 'stop', 'playbackId' => 123],
            ],
            'backendClass' => \PHPolygon\Audio\NullAudioBackend::class,
        ]);

        $this->assertContains(123, $result['finished']);
    }

    public function testComputeHandlesEmptyCommands(): void
    {
        $result = AudioSubsystem::compute([
            'commands' => [],
            'backendClass' => \PHPolygon\Audio\NullAudioBackend::class,
        ]);

        $this->assertEmpty($result['finished']);
        $this->assertEmpty($result['playbackIds']);
    }

    public function testNullSchedulerRoundTrip(): void
    {
        $world = new World();

        $scheduler = new NullThreadScheduler();
        $scheduler->register('audio', AudioSubsystem::class);
        $scheduler->boot();

        $scheduler->sendAll($world, 0.016);
        $scheduler->recvAll($world);

        $scheduler->shutdown();
        $this->assertTrue(true); // No exceptions
    }

    public function testPrepareInputExtractsCommandBuffer(): void
    {
        $world = new World();
        $subsystem = new AudioSubsystem();

        $subsystem->getCommandBuffer()->play('test_clip', 0.7, true);

        $input = $subsystem->prepareInput($world, 0.016);

        $this->assertCount(1, $input['commands']);
        $this->assertSame('play', $input['commands'][0]['type']);
        $this->assertSame('test_clip', $input['commands'][0]['clipId']);

        // Buffer should be flushed
        $this->assertTrue($subsystem->getCommandBuffer()->isEmpty());
    }
}
