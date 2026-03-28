<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Thread\Subsystem;

use PHPUnit\Framework\TestCase;
use PHPolygon\ECS\World;
use PHPolygon\Thread\NullThreadScheduler;
use PHPolygon\Thread\Subsystem\AIProcessorConfig;
use PHPolygon\Thread\Subsystem\AISubsystem;
use PHPolygon\Thread\Subsystem\CombinedAIAudioSubsystem;

class AISubsystemTest extends TestCase
{
    public function testProcessorConfigRateConversion(): void
    {
        $config = new AIProcessorConfig(
            perceptionRate: 60.0,
            pathfindingRate: 10.0,
            thinkRate: 20.0,
        );

        // 10 Hz = 100ms = 100,000,000 ns
        $this->assertSame(100_000_000, $config->pathfindingIntervalNs());
        // 20 Hz = 50ms = 50,000,000 ns
        $this->assertSame(50_000_000, $config->thinkIntervalNs());
    }

    public function testComputeReturnsAgentStates(): void
    {
        $result = AISubsystem::compute([
            'dt' => 0.016,
            'agents' => [
                ['id' => 1, 'x' => 10.0, 'y' => 20.0, 'state' => 'patrol'],
                ['id' => 2, 'x' => 50.0, 'y' => 30.0, 'state' => 'idle'],
            ],
            'worldSnapshot' => [],
            'navMesh' => [],
        ]);

        $this->assertArrayHasKey('agents', $result);
        $this->assertCount(2, $result['agents']);
    }

    public function testComputeHandlesEmptyAgents(): void
    {
        $result = AISubsystem::compute([
            'dt' => 0.016,
            'agents' => [],
            'worldSnapshot' => [],
            'navMesh' => [],
        ]);

        $this->assertEmpty($result['agents']);
    }

    public function testNullSchedulerRoundTrip(): void
    {
        $world = new World();

        $scheduler = new NullThreadScheduler();
        $scheduler->register('ai', AISubsystem::class);
        $scheduler->boot();

        $inputs = $scheduler->sendAll($world, 0.016);
        $this->assertArrayHasKey('ai', $inputs);

        $scheduler->recvAll($world);
        $scheduler->shutdown();
    }

    public function testCombinedAIAudioProcessesBothSubsystems(): void
    {
        $result = CombinedAIAudioSubsystem::compute([
            'audio' => [
                'commands' => [
                    ['type' => 'play', 'clipId' => 'sfx', 'volume' => 1.0, 'loop' => false],
                ],
                'backendClass' => \PHPolygon\Audio\NullAudioBackend::class,
            ],
            'ai' => [
                'dt' => 0.016,
                'agents' => [
                    ['id' => 1, 'x' => 0.0, 'y' => 0.0, 'state' => 'chase'],
                ],
                'worldSnapshot' => [],
                'navMesh' => [],
            ],
        ]);

        $this->assertArrayHasKey('audio', $result);
        $this->assertArrayHasKey('ai', $result);
        $this->assertCount(1, $result['audio']['playbackIds']);
        $this->assertCount(1, $result['ai']['agents']);
    }

    public function testCombinedSubsystemNullSchedulerRoundTrip(): void
    {
        $world = new World();

        $scheduler = new NullThreadScheduler();
        $scheduler->register('ai_audio', CombinedAIAudioSubsystem::class);
        $scheduler->boot();

        $scheduler->sendAll($world, 0.016);
        $scheduler->recvAll($world);

        $scheduler->shutdown();
        $this->assertTrue(true);
    }
}
