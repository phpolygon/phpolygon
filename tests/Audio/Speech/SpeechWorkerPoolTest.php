<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Audio\Speech;

use PHPolygon\Audio\Speech\SpeechJob;
use PHPolygon\Audio\Speech\SpeechWorkerPool;
use PHPUnit\Framework\TestCase;

final class SpeechWorkerPoolTest extends TestCase
{
    private const string FAKE = __DIR__ . '/fake-worker/chatterbox_worker.py';

    private string $dir;

    /** @var list<string> */
    private array $log = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/phpolygon-pool-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->log = [];
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    public function testWorkersRunInParallel(): void
    {
        $jobs = $this->jobs(8);
        $start = microtime(true);
        [$results, $counts] = $this->runPool(['a.250', 'b.250'], $jobs);
        $elapsed = microtime(true) - $start;

        self::assertCount(8, $results);
        self::assertSame([], array_filter($results));
        self::assertSame(8, array_sum($counts));
        self::assertGreaterThanOrEqual(3, min($counts));
        // One worker alone needs 8 x 250 ms.
        self::assertLessThan(1.7, $elapsed);
    }

    public function testEachJobIsReportedWhenItIsDone(): void
    {
        $times = [];
        $start = microtime(true);
        $pool = new SpeechWorkerPool([['command' => $this->fake('a.200')]], $this->dir, $this->collect(...));
        $pool->run($this->jobs(3), self::encode(...), static function (SpeechJob $job, ?string $error) use (&$times, $start): void {
            $times[] = microtime(true) - $start;
        });

        self::assertCount(3, $times);
        self::assertGreaterThan(0.15, $times[1] - $times[0]);
        self::assertGreaterThan(0.15, $times[2] - $times[1]);
    }

    public function testJobsOfOneReferenceStayOnOneWorker(): void
    {
        $jobs = [];
        for ($i = 0; $i < 8; $i++) {
            $jobs[] = $this->job("j{$i}", $i % 2 === 0 ? 'ref-a.wav' : 'ref-b.wav');
        }
        $this->runPool(['a.30', 'b.30'], $jobs);

        $workerOf = [];
        foreach ($jobs as $job) {
            [$worker] = explode('|', (string) file_get_contents($job->wavPath));
            $workerOf[(string) $job->reference][$worker] = true;
        }
        self::assertCount(1, $workerOf['ref-a.wav']);
        self::assertCount(1, $workerOf['ref-b.wav']);
        self::assertNotSame(array_keys($workerOf['ref-a.wav']), array_keys($workerOf['ref-b.wav']));
    }

    public function testASlowWorkerIsNotLeftWithTheTail(): void
    {
        $start = microtime(true);
        [$results, $counts] = $this->runPool(['fast.20', 'slow.600'], $this->jobs(16));
        $elapsed = microtime(true) - $start;

        self::assertCount(16, $results);
        self::assertSame([], array_filter($results));
        self::assertLessThanOrEqual(2, $counts[1]);
        self::assertLessThan(1.6, $elapsed);
    }

    public function testADeadWorkerFailsItsLineAndTheOthersGoOn(): void
    {
        [$results, $counts] = $this->runPool(['a.30.2', 'b.30'], $this->jobs(6));

        self::assertCount(6, $results);
        $errors = array_filter($results);
        self::assertCount(1, $errors);
        self::assertStringContainsString('speech worker w1 exited with code 7', (string) array_values($errors)[0]);
        self::assertSame(1, $counts[0]);
        self::assertSame(4, $counts[1]);
        self::assertContains('[w1] fake a dies', $this->log);
    }

    public function testARequeueWorkerHandsItsLineToTheOthers(): void
    {
        $pool = new SpeechWorkerPool([
            ['command' => $this->fake('remote.30.2'), 'label' => 'me@mac', 'requeue' => true],
            ['command' => $this->fake('local.30')],
        ], $this->dir, $this->collect(...));
        $results = [];
        $pool->run($this->jobs(6), self::encode(...), static function (SpeechJob $job, ?string $error) use (&$results): void {
            $results[$job->id] = $error;
        });

        self::assertCount(6, $results);
        self::assertSame([], array_filter($results));
        self::assertContains('[w1 me@mac] fake remote dies', $this->log);
        self::assertNotEmpty(array_filter($this->log, static fn (string $l): bool => str_contains($l, 'w1 me@mac ended (exit code 7)')));
    }

    public function testWorkerOutputIsPrefixedPerWorker(): void
    {
        $this->runPool(['a.0', 'b.0'], $this->jobs(2));

        self::assertContains('[w1] fake a ready', $this->log);
        self::assertContains('[w2] fake b ready', $this->log);
        self::assertContains('[w1] loading model', $this->log);
    }

    public function testNoLineFromAnyWorkerThrows(): void
    {
        $this->expectExceptionMessage('exited before finishing a line');
        $this->runPool(['a.0.0.2', 'b.0.0.3'], $this->jobs(2));
    }

    public function testJobsLeftWhenEveryWorkerIsGoneFail(): void
    {
        [$results] = $this->runPool(['a.0.3'], $this->jobs(4));

        self::assertCount(4, $results);
        self::assertNull($results['j0']);
        self::assertNull($results['j1']);
        self::assertStringContainsString('exited with code 7', (string) $results['j2']);
        self::assertSame('no speech worker left to speak this line', $results['j3']);
    }

    /**
     * @param list<string> $behaviours
     * @param list<SpeechJob> $jobs
     * @return array{array<string, ?string>, array<int, int>}
     */
    private function runPool(array $behaviours, array $jobs): array
    {
        $pool = new SpeechWorkerPool(
            array_map(fn (string $b): array => ['command' => $this->fake($b)], $behaviours),
            $this->dir,
            $this->collect(...),
        );
        $results = [];
        $counts = $pool->run($jobs, self::encode(...), static function (SpeechJob $job, ?string $error) use (&$results): void {
            self::assertArrayNotHasKey($job->id, $results, 'reported twice');
            $results[$job->id] = $error;
        });
        return [$results, $counts];
    }

    /** @return list<string> */
    private function fake(string $behaviour): array
    {
        return [PHP_BINARY, self::FAKE, '--fake=' . $behaviour];
    }

    private function collect(string $line): void
    {
        $this->log[] = $line;
    }

    private static function encode(SpeechJob $job, int $worker): string
    {
        return json_encode(['id' => $job->id, 'text' => $job->text, 'reference' => $job->reference, 'out' => $job->wavPath], JSON_THROW_ON_ERROR);
    }

    /** @return list<SpeechJob> */
    private function jobs(int $count): array
    {
        $jobs = [];
        for ($i = 0; $i < $count; $i++) {
            $jobs[] = $this->job("j{$i}", null);
        }
        return $jobs;
    }

    private function job(string $id, ?string $reference): SpeechJob
    {
        return new SpeechJob($id, "line {$id}", 'en', $reference, "{$this->dir}/{$id}.wav", 0, 0.5, 0.5);
    }
}
