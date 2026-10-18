<?php

declare(strict_types=1);

namespace PHPolygon\Build;

use Closure;

/**
 * Runs the worker processes of a pre-bake, at most {@see $jobs} at a time.
 *
 * Every worker writes STDOUT + STDERR into its own log file and reads STDIN from the null
 * device: stream_select() on pipes does not work on Windows, a file works everywhere.
 * Progress comes from {@see PrebakeTask::$poll} (polled every {@see $pollInterval} s) or,
 * without one, from finished tasks; it goes to the optional {@see PrebakeProgress}.
 *
 * A worker that fails - could not start, exit code ≠ 0, killed by a signal, or exit 0
 * before its last part - is reported at once on the output (label, name, reason, parts
 * left, the last {@see $logTail} lines of its log), and its log file stays for a look
 * afterwards. A successful worker's log is handed to {@see $onFinish} and deleted.
 *
 * By default the other workers go on; with {@see $failFast} the first failure stops the
 * running ones and starts no more. Either way {@see run()} returns; the caller decides
 * the exit code.
 */
final class PrebakePool
{
    /** @var Closure(string): void */
    private readonly Closure $out;

    /**
     * @param string $label prefix of the messages, e.g. `voice`
     * @param (Closure(string): void)|null $out gets each message line; default STDERR
     * @param (Closure(PrebakeTaskResult): void)|null $onFinish called once per task as it ends
     */
    public function __construct(
        public readonly string $label,
        public readonly int $jobs,
        private readonly ?PrebakeProgress $progress = null,
        ?Closure $out = null,
        public readonly bool $failFast = false,
        public readonly float $pollInterval = 1.0,
        public readonly int $logTail = 40,
        private readonly ?Closure $onFinish = null,
    ) {
        $this->out = $out ?? PrebakeProgress::stderr();
    }

    /**
     * @param list<PrebakeTask> $tasks
     * @return list<PrebakeTaskResult> one per task, in the order they ended
     */
    public function run(array $tasks): array
    {
        $queue = $tasks;
        /** @var array<int, array{process: resource, task: PrebakeTask}> $running */
        $running = [];
        /** @var array<int, int> $polled parts settled per running task (by spl_object_id) */
        $polled = [];
        $settled = 0;
        $results = [];
        $lastPoll = 0.0;
        $stop = false;
        $jobs = max(1, $this->jobs);

        while (($queue !== [] && !$stop) || $running !== []) {
            while (!$stop && $queue !== [] && count($running) < $jobs) {
                $task = array_shift($queue);
                $process = $this->start($task);
                if ($process === null) {
                    $result = new PrebakeTaskResult($task, false, null, null, 0, '');
                    $settled += $task->parts;
                    $results[] = $this->finished($result);
                    $stop = $this->failFast;
                    continue;
                }
                $running[] = ['process' => $process, 'task' => $task];
            }

            foreach ($running as $i => $entry) {
                $status = proc_get_status($entry['process']);
                if ($status['running']) {
                    continue;
                }
                proc_close($entry['process']);
                $task = $entry['task'];
                unset($running[$i], $polled[spl_object_id($task)]);
                $exit = (int) $status['exitcode'];
                $signal = $status['signaled'] ? (int) $status['termsig'] : null;
                $done = $task->poll !== null ? ($this->poll($task) ?? 0) : ($exit === 0 && $signal === null ? $task->parts : 0);
                $result = new PrebakeTaskResult($task, true, $exit, $signal, min($done, $task->parts), self::read($task->log));
                $settled += $task->parts; // failed parts are settled too - as failures
                $results[] = $this->finished($result);
                if ($result->failed() && $this->failFast) {
                    $stop = true;
                }
            }

            if ($stop && $running !== []) {
                foreach ($running as $entry) {
                    proc_terminate($entry['process']);
                    proc_close($entry['process']);
                    $settled += $entry['task']->parts;
                    $results[] = $this->finished(new PrebakeTaskResult(
                        $entry['task'], true, null, null, 0, self::read($entry['task']->log), stopped: true,
                    ));
                }
                $running = [];
                $polled = [];
            }

            $now = microtime(true);
            if ($now - $lastPoll >= $this->pollInterval) {
                $lastPoll = $now;
                foreach ($running as $entry) {
                    $task = $entry['task'];
                    if ($task->poll !== null) {
                        $count = $this->poll($task);
                        if ($count !== null) {
                            $polled[spl_object_id($task)] = min($count, $task->parts);
                        }
                    }
                }
            }
            $this->progress?->update($settled + array_sum($polled));
            if ($running !== [] || ($queue !== [] && !$stop)) {
                usleep(20000);
            }
        }

        return $results;
    }

    /** @return resource|null */
    private function start(PrebakeTask $task)
    {
        $env = $task->env === [] ? null : $task->env + self::environment();
        // One handle for STDOUT and STDERR: two handles on the same file ('w' + 'a') each
        // keep their own position on Windows, and STDERR overwrites what STDOUT wrote.
        $log = @fopen($task->log, 'w');
        if ($log === false) {
            return null;
        }
        $process = @proc_open($task->command, [
            0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
            1 => $log,
            2 => $log,
        ], $pipes, $task->cwd, $env);
        fclose($log); // the worker has its own copy

        return is_resource($process) ? $process : null;
    }

    private function poll(PrebakeTask $task): ?int
    {
        return $task->poll !== null ? ($task->poll)() : null;
    }

    private function finished(PrebakeTaskResult $result): PrebakeTaskResult
    {
        if ($result->failed() && !$result->stopped) {
            $this->report($result);
        } elseif (!$result->failed()) {
            @unlink($result->task->log);
        }
        if ($this->onFinish !== null) {
            ($this->onFinish)($result);
        }

        return $result;
    }

    private function report(PrebakeTaskResult $result): void
    {
        $task = $result->task;
        $lines = $result->log === '' ? [] : explode("\n", $result->log);
        ($this->out)(sprintf(
            'ERROR %s: %s failed (%s)%s%s',
            $this->label,
            $task->name,
            $result->reason(),
            $task->parts > 1 ? sprintf(' – %d of %d not done', $result->missing(), $task->parts) : '',
            !$result->started ? '' : ($lines === [] ? ', log empty' : ", log ({$task->log}):"),
        ));
        if ($lines !== [] && $this->logTail > 0) {
            ($this->out)('  ' . implode("\n  ", array_slice($lines, -$this->logTail)));
        }
    }

    private static function read(string $file): string
    {
        return trim((string) @file_get_contents($file));
    }

    /** @return array<string, string> */
    private static function environment(): array
    {
        $env = [];
        foreach (getenv() as $key => $value) {
            $env[(string) $key] = (string) $value;
        }

        return $env;
    }
}
