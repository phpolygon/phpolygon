<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Thread\Subsystem;

use PHPUnit\Framework\TestCase;
use PHPolygon\ECS\World;
use PHPolygon\Thread\NullThreadScheduler;
use PHPolygon\Thread\Subsystem\WorkerPoolSubsystem;

class WorkerPoolSubsystemTest extends TestCase
{
    public function testEnqueueAndPrepareInput(): void
    {
        $pool = new WorkerPoolSubsystem();
        $pool->enqueue('job_1', 'city_tick', ['cityId' => 'volkhaven', 'hour' => 12.0]);
        $pool->enqueue('job_2', 'city_tick', ['cityId' => 'ironspire', 'hour' => 12.0]);

        $world = new World();
        $input = $pool->prepareInput($world, 0.016);

        $this->assertCount(2, $input['jobs']);
        $this->assertSame('job_1', $input['jobs'][0]['id']);
        $this->assertSame('volkhaven', $input['jobs'][0]['data']['cityId']);

        // Queue should be flushed
        $input2 = $pool->prepareInput($world, 0.016);
        $this->assertCount(0, $input2['jobs']);
    }

    public function testComputeDefaultProcessor(): void
    {
        $result = WorkerPoolSubsystem::compute([
            'jobs' => [
                ['id' => 'j1', 'type' => 'tick', 'data' => ['value' => 42]],
            ],
        ]);

        $this->assertCount(1, $result['results']);
        $this->assertSame('j1', $result['results'][0]['id']);
        $this->assertTrue($result['results'][0]['result']['processed']);
        $this->assertSame(42, $result['results'][0]['result']['data']['value']);
    }

    public function testComputeWithCustomProcessor(): void
    {
        WorkerPoolSubsystem::setJobProcessor(function (array $data): array {
            return ['doubled' => ((int) ($data['value'] ?? 0)) * 2];
        });

        $result = WorkerPoolSubsystem::compute([
            'jobs' => [
                ['id' => 'j1', 'type' => 'calc', 'data' => ['value' => 21]],
            ],
        ]);

        $this->assertSame(42, $result['results'][0]['result']['doubled']);

        // Reset processor
        WorkerPoolSubsystem::setJobProcessor(function (array $data): array {
            return $data;
        });
    }

    public function testComputeHandlesEmptyJobs(): void
    {
        $result = WorkerPoolSubsystem::compute(['jobs' => []]);
        $this->assertEmpty($result['results']);
    }

    public function testGetResultsExtractsFromDeltas(): void
    {
        $pool = new WorkerPoolSubsystem();
        $deltas = [
            'results' => [
                ['id' => 'j1', 'result' => ['threat' => 3.5]],
                ['id' => 'j2', 'result' => ['threat' => 1.0]],
            ],
        ];

        $results = $pool->getResults($deltas);
        $this->assertCount(2, $results);
        $this->assertSame('j1', $results[0]['id']);
        $this->assertEqualsWithDelta(3.5, $results[0]['result']['threat'], 0.001);
    }

    public function testNullSchedulerRoundTrip(): void
    {
        $world = new World();

        $scheduler = new NullThreadScheduler();
        $scheduler->register('workers', WorkerPoolSubsystem::class);
        $scheduler->boot();

        $scheduler->sendAll($world, 0.016);
        $scheduler->recvAll($world);

        $scheduler->shutdown();
        $this->assertTrue(true);
    }
}
