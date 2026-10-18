<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Build;

use PHPolygon\Build\FileTree;
use PHPolygon\Build\PrebakePool;
use PHPolygon\Build\PrebakeProgress;
use PHPolygon\Build\PrebakeTask;
use PHPolygon\Build\PrebakeTaskResult;
use PHPUnit\Framework\TestCase;

/**
 * The worker pool of a pre-bake with real PHP processes: logs in files (no pipes),
 * progress from result files, dead workers with exit code and log tail.
 */
final class PrebakePoolTest extends TestCase
{
    private string $dir;
    /** @var list<string> */
    private array $lines = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/phpolygon-pool-' . getmypid() . '-' . bin2hex(random_bytes(3));
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        FileTree::remove($this->dir);
    }

    /** @param (\Closure(PrebakeTaskResult): void)|null $onFinish */
    private function pool(int $jobs = 2, bool $failFast = false, ?PrebakeProgress $progress = null, ?\Closure $onFinish = null): PrebakePool
    {
        return new PrebakePool('bake', $jobs, $progress, function (string $line): void {
            $this->lines[] = $line;
        }, failFast: $failFast, pollInterval: 0.05, onFinish: $onFinish);
    }

    private function php(string $name, string $code, int $parts = 1, ?\Closure $poll = null, array $env = []): PrebakeTask
    {
        return new PrebakeTask($name, [PHP_BINARY, '-n', '-r', $code], "{$this->dir}/$name.log", $env, $parts, $poll);
    }

    public function testSuccessfulWorkersHandTheirLogOverAndLeaveNoFile(): void
    {
        $logs = [];
        $results = $this->pool(onFinish: function (PrebakeTaskResult $r) use (&$logs): void {
            $logs[$r->task->name] = $r->log;
        })->run([
            $this->php('a', 'echo "out a"; fwrite(STDERR, " err a");'),
            $this->php('b', 'echo "out b";'),
            $this->php('c', 'echo "out c";'),
        ]);

        self::assertCount(3, $results);
        foreach ($results as $result) {
            self::assertFalse($result->failed(), $result->reason());
            self::assertSame(0, $result->exitCode);
            self::assertSame(1, $result->done);
            self::assertFileDoesNotExist($result->task->log);
        }
        ksort($logs);
        self::assertSame(['a' => 'out a err a', 'b' => 'out b', 'c' => 'out c'], $logs);
        self::assertSame([], $this->lines);
    }

    public function testAFailedWorkerIsReportedWithItsLogAndTheOthersGoOn(): void
    {
        $results = $this->pool(jobs: 1)->run([
            $this->php('bad', 'echo "line 1\nline 2"; exit(3);'),
            $this->php('good', 'echo "fine";'),
        ]);

        $byName = [];
        foreach ($results as $r) {
            $byName[$r->task->name] = $r;
        }
        self::assertTrue($byName['bad']->failed());
        self::assertSame(3, $byName['bad']->exitCode);
        self::assertSame('exit code 3', $byName['bad']->reason());
        self::assertFileExists($byName['bad']->task->log, 'the log of a failed worker stays');
        self::assertFalse($byName['good']->failed());

        self::assertCount(2, $this->lines);
        self::assertSame('ERROR bake: bad failed (exit code 3), log (' . $byName['bad']->task->log . '):', $this->lines[0]);
        self::assertSame("  line 1\n  line 2", $this->lines[1]);
    }

    public function testFailFastStopsTheRunningWorkersAndStartsNoMore(): void
    {
        $results = $this->pool(jobs: 2, failFast: true)->run([
            $this->php('slow', 'sleep(30);'),
            $this->php('bad', 'exit(1);'),
            $this->php('never', 'echo "x";'),
        ]);

        $names = array_map(static fn (PrebakeTaskResult $r): string => $r->task->name, $results);
        self::assertSame(['bad', 'slow'], $names, 'the third task never started');
        self::assertTrue($results[1]->stopped);
        self::assertSame('stopped', $results[1]->reason());
        self::assertCount(1, $this->lines, 'only the real failure is reported');
    }

    public function testProgressComesFromTheWorkersResultFiles(): void
    {
        $result = "{$this->dir}/result.json";
        $poll = static function () use ($result): ?int {
            $data = json_decode((string) @file_get_contents($result), true);

            return is_array($data) ? count($data) : null;
        };
        $now = 0.0;
        $progressLines = [];
        $progress = new PrebakeProgress('bake', 4, function (string $l) use (&$progressLines): void {
            $progressLines[] = $l;
        }, function () use (&$now): float {
            return $now += 1.0; // every clock read is a second later: every due step prints
        }, minInterval: 0.0, stepPercent: 25, heartbeat: 1e9);

        $code = sprintf('$f = %s; $d = []; for ($i = 0; $i < 3; $i++) { $d[] = $i; file_put_contents($f, json_encode($d)); usleep(300000); }', var_export($result, true));
        $results = $this->pool(progress: $progress)->run([$this->php('worker', $code, parts: 4, poll: $poll)]);

        // exit 0 after 3 of 4 parts: the worker ended early
        self::assertTrue($results[0]->failed());
        self::assertSame(3, $results[0]->done);
        self::assertSame(1, $results[0]->missing());
        self::assertSame('ended after 3 of 4', $results[0]->reason());
        self::assertStringStartsWith('ERROR bake: worker failed (ended after 3 of 4) – 1 of 4 not done, log empty', $this->lines[0]);

        self::assertNotEmpty($progressLines, 'progress lines while the worker ran');
        foreach ($progressLines as $line) {
            self::assertMatchesRegularExpression('#^bake: [1-3]/4 \(\d+ %\)#', $line);
        }
        self::assertSame(4, $progress->done(), 'a failed task settles its parts');
    }

    public function testTheTaskEnvironmentIsSetOverTheParents(): void
    {
        $logs = [];
        $this->pool(onFinish: function (PrebakeTaskResult $r) use (&$logs): void {
            $logs[] = $r->log;
        })->run([$this->php('env', 'echo getenv("PREBAKE_POOL_TEST"), "|", getenv("PATH") !== false ? "path" : "none";', env: ['PREBAKE_POOL_TEST' => 'hello'])]);

        self::assertSame(['hello|path'], $logs);
    }

    public function testAWorkerThatCannotStartIsAFailure(): void
    {
        $task = new PrebakeTask('ghost', [$this->dir . '/no-such-program'], "{$this->dir}/ghost.log");
        $results = $this->pool()->run([$task]);

        self::assertTrue($results[0]->failed());
        self::assertCount(1, $this->lines);
        self::assertStringStartsWith('ERROR bake: ghost failed (', $this->lines[0]);
    }
}
