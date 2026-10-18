<?php

declare(strict_types=1);

namespace PHPolygon\Audio\Speech;

/**
 * Runs synthesis jobs on several worker processes at once and hands out the jobs
 * one at a time from a shared queue, so a slow worker (CPU next to GPUs) never
 * holds a block of lines the others could have spoken.
 *
 * Worker protocol: a job is one JSON line on the worker's stdin; the worker answers
 * every job with one JSON line `{"id": …, "ok": true}` or `{"id": …, "error": "…"}`
 * on stdout and ends at EOF. A result may carry the WAV inline (`"wav"`: base64),
 * which is written to the job's `wavPath` before it is reported (remote workers).
 * Anything else on stdout, and all of stderr, goes to the log, prefixed with the
 * worker's label (`[w2]`, `[w3 user@mac]`) when there is more than one worker.
 *
 * Scheduling:
 * - a worker gets its next job when it reported the last one (one job in flight);
 * - it keeps its reference voice when the queue has more lines of it (switching
 *   costs a prepare_conditionals); otherwise it takes the reference with the most
 *   queued lines that no other worker is on, else the largest one;
 * - near the end a worker is left idle when the others, at their measured pace,
 *   would finish the queue before it finished one more line.
 *
 * A local worker that dies reports its job in flight as failed (it may be the line
 * that killed it); the others go on. A worker marked `requeue` (remote: the network
 * or SSH may drop) puts its job back into the queue for the others instead, once per
 * job, while another worker can still take it (or it reconnects). Jobs left when
 * every worker is gone are reported as failed. When no worker reported anything and
 * one exited with an error, nothing is reported and the run throws.
 *
 * Reconnect: a requeue worker that ends while lines are still queued is started
 * again (a new SSH connection, the model loads anew) after a backoff
 * ({@see self::BACKOFF}), up to `$remoteRetries` times in a row without a finished
 * line; a finished line resets the count. Its job in flight goes back to the queue
 * every time it reconnects (the reconnect count bounds a line that kills it). The
 * other workers go on during the backoff. Exit code 127 (no speech environment on the host) is not retried.
 * Exit code 75 ({@see self::EXIT_OUT_OF_MEMORY}: device memory ran out, or the worker
 * recycles itself before it does) restarts any worker, local ones too, the same way –
 * its line goes back to the queue instead of failing, and the worker does not go on
 * failing every line it takes. The
 * `$onRestart` callback of {@see run()} lets the caller forget what the old process
 * had (e.g. reference WAVs it was sent).
 */
final class SpeechWorkerPool
{
    /** How often one job is put back after a requeue worker dropped it. */
    private const int MAX_REQUEUES = 1;

    /** @var list<float> seconds before reconnect attempt 1, 2, …; the last value repeats */
    public const array BACKOFF = [5.0, 15.0, 45.0, 120.0];

    /** Exit code of a remote command that is not there (no speech environment). */
    private const int EXIT_NOT_FOUND = 127;

    /**
     * Exit code of a worker that ran out of device memory (or recycles itself before it
     * does): its line goes back to the queue and it starts anew, local workers too.
     */
    public const int EXIT_OUT_OF_MEMORY = 75;

    /** @var \Closure(string): void */
    private readonly \Closure $log;

    /** @var array<int, array{process: resource, stdin: resource|null, stdout: resource, stderr: resource|null, out: string, err: string, job: ?SpeechJob, sent: float, reference: string|null|false, seconds: float, done: int, open: bool, label: string, requeue: bool, retries: int, retryAt: ?float, restarted: bool}> */
    private array $workers = [];

    /** @var list<SpeechJob> */
    private array $queue = [];

    /** @var array<string, int> */
    private array $requeued = [];

    /**
     * @param list<array{command: list<string>, label?: string, requeue?: bool}> $specs one per worker process
     * @param string $cwd working directory of the workers
     * @param (callable(string): void)|null $log receives worker output lines; default STDERR
     * @param int $remoteRetries reconnects of a requeue worker in a row without a finished line; 0 = none
     * @param list<float> $backoff seconds before reconnect attempt 1, 2, …; the last value repeats
     */
    public function __construct(
        private readonly array $specs,
        private readonly string $cwd,
        ?callable $log = null,
        private readonly int $remoteRetries = SpeechConfig::REMOTE_RETRIES,
        private readonly array $backoff = self::BACKOFF,
    ) {
        if ($specs === []) {
            throw new \InvalidArgumentException('a speech worker pool needs at least one worker');
        }
        if ($remoteRetries < 0) {
            throw new \InvalidArgumentException('speech remoteRetries must not be negative');
        }
        if ($remoteRetries > 0 && $backoff === []) {
            throw new \InvalidArgumentException('speech reconnects need a backoff');
        }
        $this->log = $log !== null ? \Closure::fromCallable($log) : static function (string $line): void {
            fwrite(STDERR, $line . "\n");
        };
    }

    /**
     * @param list<SpeechJob> $jobs
     * @param callable(SpeechJob, int): string $encode the job as worker N's JSON line (no newline)
     * @param callable(SpeechJob, ?string): void $onResult called as soon as a job is done
     * @param (callable(int): void)|null $onRestart called with the worker index when a
     *                                   worker was started anew, before its first job
     * @return array<int, int> jobs reported per worker index
     */
    public function run(array $jobs, callable $encode, callable $onResult, ?callable $onRestart = null): array
    {
        if ($jobs === []) {
            return [];
        }
        $this->queue = $jobs;
        $this->requeued = [];
        $this->workers = [];
        $byId = [];
        foreach ($jobs as $job) {
            $byId[$job->id] = $job;
        }
        $prefixed = count($this->specs) > 1;

        foreach ($this->specs as $i => $spec) {
            $pipes = $this->start($spec['command']);
            if ($pipes === null) {
                foreach ($this->workers as $worker) {
                    $this->stop($worker);
                }
                throw new \RuntimeException('cannot start the speech worker: ' . implode(' ', $spec['command']));
            }
            $label = 'w' . ($i + 1) . (isset($spec['label']) && $spec['label'] !== '' ? ' ' . $spec['label'] : '');
            $this->workers[$i] = [
                ...$pipes,
                'out' => '', 'err' => '', 'job' => null, 'sent' => 0.0,
                // false = no job yet; null = the built-in voice
                'reference' => false, 'seconds' => 0.0, 'done' => 0, 'open' => true,
                'label' => $label, 'requeue' => $spec['requeue'] ?? false,
                'retries' => 0, 'retryAt' => null, 'restarted' => false,
            ];
        }

        $reported = 0;
        $exits = [];
        /** @var list<array{SpeechJob, string}> $unreported */
        $unreported = [];
        $this->dispatch($encode);

        while (true) {
            if ($this->reconnect($onRestart)) {
                $this->dispatch($encode);
            }
            $read = [];
            foreach ($this->workers as $worker) {
                if ($worker['open']) {
                    $read[] = $worker['stdout'];
                }
                if ($worker['stderr'] !== null) {
                    $read[] = $worker['stderr'];
                }
            }
            $retryAt = $this->nextRetry();
            if ($read === []) {
                if ($retryAt === null) {
                    break;
                }
                // Only reconnects left: wait for the next one.
                usleep((int) max(0.0, ($retryAt - microtime(true)) * 1e6));
                continue;
            }
            $wait = $retryAt !== null ? min(1.0, max(0.0, $retryAt - microtime(true))) : 1.0;
            $write = null;
            $except = null;
            if (@stream_select($read, $write, $except, (int) $wait, (int) (fmod($wait, 1.0) * 1e6)) === false) {
                throw new \RuntimeException('waiting for the speech workers failed');
            }
            foreach ($read as $stream) {
                foreach ($this->workers as $i => &$worker) {
                    $tag = $prefixed ? '[' . $worker['label'] . '] ' : '';
                    if ($stream === $worker['stderr']) {
                        $chunk = (string) fread($stream, 65536);
                        $worker['err'] .= $chunk;
                        $eof = $chunk === '' && feof($stream);
                        $this->flushLines($worker['err'], $tag, $eof);
                        if ($eof) {
                            fclose($stream);
                            $worker['stderr'] = null;
                        }
                        continue;
                    }
                    if ($stream !== $worker['stdout'] || !$worker['open']) {
                        continue;
                    }
                    $chunk = (string) fread($stream, 1 << 20);
                    $worker['out'] .= $chunk;
                    while (($nl = strpos($worker['out'], "\n")) !== false) {
                        $line = substr($worker['out'], 0, $nl);
                        $worker['out'] = substr($worker['out'], $nl + 1);
                        $result = json_decode($line, true);
                        $id = is_array($result) ? ($result['id'] ?? null) : null;
                        if (!is_array($result) || !is_string($id) || !isset($byId[$id])) {
                            if (trim($line) !== '') {
                                ($this->log)($tag . rtrim($line, "\r")); // stray library output
                            }
                            continue;
                        }
                        $reported++;
                        $worker['done']++;
                        if ($worker['job'] !== null && $worker['job']->id === $id) {
                            $took = microtime(true) - $worker['sent'];
                            $worker['seconds'] = $worker['done'] === 1 ? $took : 0.7 * $worker['seconds'] + 0.3 * $took;
                            $worker['job'] = null;
                        }
                        $error = isset($result['error']) && is_string($result['error']) ? $result['error'] : null;
                        if ($error === null && isset($result['wav'])) {
                            $error = self::writeInline($byId[$id], $result['wav']);
                        }
                        if ($error !== null && $worker['requeue']) {
                            $error = "{$worker['label']}: {$error}";
                        }
                        if ($worker['restarted']) {
                            $worker['restarted'] = false;
                            ($this->log)("speech: worker {$worker['label']} reconnected");
                        }
                        if ($error === null) {
                            $worker['retries'] = 0;
                        }
                        $onResult($byId[$id], $error);
                    }
                    if ($chunk === '' && feof($stream)) {
                        $exit = $this->reap($i, $tag);
                        $exits[$i] = $exit;
                        $job = $worker['job'];
                        $worker['job'] = null;
                        $worker['restarted'] = false;
                        // Out of device memory: a fresh process frees it, so it restarts
                        // like a dropped remote worker, whether local or remote.
                        $restarts = $worker['requeue'] || $exit === self::EXIT_OUT_OF_MEMORY;
                        $canRetry = $restarts && $exit !== self::EXIT_NOT_FOUND && $worker['retries'] < $this->remoteRetries;
                        if ($job !== null) {
                            // A reconnecting worker always gets its line back; handing it to
                            // the others (it may be the line that kills workers) happens once.
                            // Only a hand-over counts against MAX_REQUEUES: a worker that
                            // reconnected several times and then gives up still passes its
                            // line on to the others once.
                            if ($canRetry) {
                                array_unshift($this->queue, $job);
                                ($this->log)("speech: worker {$worker['label']} ended (exit code {$exit}); its line goes back to the queue");
                            } elseif ($worker['requeue'] && ($this->requeued[$job->id] ?? 0) < self::MAX_REQUEUES && $this->anyoneCanTake()) {
                                $this->requeued[$job->id] = ($this->requeued[$job->id] ?? 0) + 1;
                                array_unshift($this->queue, $job);
                                ($this->log)("speech: worker {$worker['label']} ended (exit code {$exit}); its line goes back to the queue");
                            } else {
                                $unreported[] = [$job, "the speech worker {$worker['label']} exited with code {$exit} before reporting this line"];
                            }
                        } elseif ($exit !== 0) {
                            ($this->log)("speech: worker {$worker['label']} ended with exit code {$exit}");
                        }
                        if ($restarts && $this->queue !== []) {
                            $this->lost($worker, $exit, $canRetry);
                        }
                    }
                }
                unset($worker);
            }
            $this->dispatch($encode);
        }

        if ($reported === 0 && array_filter($exits, static fn (int $code): bool => $code !== 0) !== []) {
            $codes = [];
            foreach ($exits as $i => $code) {
                $codes[] = $this->workers[$i]['label'] . "={$code}";
            }
            throw new \RuntimeException('the speech worker(s) exited before finishing a line (exit codes ' . implode(', ', $codes) . ')');
        }
        foreach ($this->queue as $job) {
            $unreported[] = [$job, 'no speech worker left to speak this line'];
        }
        $this->queue = [];
        foreach ($unreported as [$job, $error]) {
            $onResult($job, $error);
        }

        return array_map(static fn (array $worker): int => $worker['done'], $this->workers);
    }

    /**
     * Start one worker process.
     *
     * @param list<string> $command
     * @return array{process: resource, stdin: resource, stdout: resource, stderr: resource}|null
     */
    private function start(array $command): ?array
    {
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->cwd);
        if (!is_resource($process)) {
            return null;
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        return ['process' => $process, 'stdin' => $pipes[0], 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
    }

    /**
     * A requeue worker ended while lines are queued: schedule its reconnect, or give up.
     *
     * @param array{label: string, retries: int, retryAt: ?float} $worker
     */
    private function lost(array &$worker, int $exit, bool $canRetry): void
    {
        if ($canRetry) {
            $worker['retries']++;
            $delay = $this->backoff[min($worker['retries'], count($this->backoff)) - 1];
            $worker['retryAt'] = microtime(true) + $delay;
            ($this->log)(sprintf(
                'speech: worker %s lost (exit %d) – reconnecting in %s s (attempt %d/%d)',
                $worker['label'],
                $exit,
                rtrim(rtrim(number_format($delay, 2, '.', ''), '0'), '.'),
                $worker['retries'],
                $this->remoteRetries,
            ));
        } elseif ($this->remoteRetries > 0 && $exit !== self::EXIT_NOT_FOUND) {
            ($this->log)("speech: worker {$worker['label']} lost (exit {$exit}) – gave up after {$worker['retries']} attempts");
        }
    }

    /**
     * Start the workers whose reconnect is due; drop pending reconnects once the
     * queue is empty. True when a worker was started.
     *
     * @param (callable(int): void)|null $onRestart
     */
    private function reconnect(?callable $onRestart): bool
    {
        $started = false;
        foreach (array_keys($this->workers) as $i) {
            $worker = &$this->workers[$i];
            if ($worker['retryAt'] === null) {
                unset($worker);
                continue;
            }
            if ($this->queue === []) {
                $worker['retryAt'] = null;
                unset($worker);
                continue;
            }
            if (microtime(true) < $worker['retryAt']) {
                unset($worker);
                continue;
            }
            $worker['retryAt'] = null;
            $pipes = $this->start($this->specs[$i]['command']);
            if ($pipes === null) {
                $this->lost($worker, -1, $worker['retries'] < $this->remoteRetries);
                unset($worker);
                continue;
            }
            $worker = [
                ...$worker,
                ...$pipes,
                'out' => '', 'err' => '', 'job' => null, 'open' => true,
                // a new process: no reference voice prepared yet
                'reference' => false, 'restarted' => true,
            ];
            unset($worker);
            $started = true;
            if ($onRestart !== null) {
                $onRestart($i);
            }
        }
        return $started;
    }

    /** The earliest pending reconnect, or null. */
    private function nextRetry(): ?float
    {
        $next = null;
        foreach ($this->workers as $worker) {
            if ($worker['retryAt'] !== null && ($next === null || $worker['retryAt'] < $next)) {
                $next = $worker['retryAt'];
            }
        }
        return $next;
    }

    /** Close a worker whose stdout ended, pass on its last stderr and return its exit code. */
    private function reap(int $i, string $tag): int
    {
        $worker = &$this->workers[$i];
        $worker['open'] = false;
        fclose($worker['stdout']);
        if ($worker['stdin'] !== null) {
            @fclose($worker['stdin']);
            $worker['stdin'] = null;
        }
        if ($worker['stderr'] !== null) {
            stream_set_blocking($worker['stderr'], true);
            $worker['err'] .= (string) stream_get_contents($worker['stderr']);
            $this->flushLines($worker['err'], $tag, true);
            fclose($worker['stderr']);
            $worker['stderr'] = null;
        }
        return proc_close($worker['process']);
    }

    /** True while a running (or reconnecting) worker can still be handed a job. */
    private function anyoneCanTake(): bool
    {
        foreach ($this->workers as $worker) {
            if (($worker['open'] && $worker['stdin'] !== null) || $worker['retryAt'] !== null) {
                return true;
            }
        }
        return false;
    }

    /**
     * Hand every idle worker its next job; close the stdin of idle workers when
     * nothing is left for them (and no job can come back from a requeue worker).
     *
     * @param callable(SpeechJob, int): string $encode
     */
    private function dispatch(callable $encode): void
    {
        $mayReturn = false;
        foreach ($this->workers as $worker) {
            $mayReturn = $mayReturn || ($worker['open'] && $worker['requeue'] && $worker['job'] !== null);
        }
        // Fastest first: when only a few lines are left they go to the quick workers.
        $order = array_keys($this->workers);
        usort($order, function (int $a, int $b): int {
            $sa = $this->workers[$a]['done'] > 0 ? $this->workers[$a]['seconds'] : 0.0;
            $sb = $this->workers[$b]['done'] > 0 ? $this->workers[$b]['seconds'] : 0.0;
            return $sa <=> $sb ?: $a <=> $b;
        });
        foreach ($order as $i) {
            $worker = &$this->workers[$i];
            if (!$worker['open'] || $worker['stdin'] === null || $worker['job'] !== null) {
                unset($worker);
                continue;
            }
            if ($this->queue === []) {
                if (!$mayReturn) {
                    @fclose($worker['stdin']);
                    $worker['stdin'] = null;
                }
                unset($worker);
                continue;
            }
            if ($this->othersFinishFirst($i, count($this->queue))) {
                unset($worker);
                continue;
            }
            $index = $this->pick($i);
            $job = $this->queue[$index];
            array_splice($this->queue, $index, 1);
            $worker['job'] = $job;
            $worker['sent'] = microtime(true);
            $worker['reference'] = $job->reference;
            if (@fwrite($worker['stdin'], $encode($job, $i) . "\n") === false) {
                // A dead worker; its EOF reports or requeues the job.
                @fclose($worker['stdin']);
                $worker['stdin'] = null;
            } else {
                fflush($worker['stdin']);
            }
            unset($worker);
        }
    }

    /**
     * True when the other running workers, at their measured pace, would speak the
     * $queued lines before this worker finished one of them.
     */
    private function othersFinishFirst(int $self, int $queued): bool
    {
        $mine = $this->workers[$self];
        if ($mine['done'] === 0) {
            return false;
        }
        $rate = 0.0;
        $busy = 0;
        foreach ($this->workers as $i => $worker) {
            if ($i === $self || !$worker['open'] || $worker['stdin'] === null || $worker['done'] === 0 || $worker['seconds'] <= 0.0) {
                continue;
            }
            $rate += 1.0 / $worker['seconds'];
            $busy += $worker['job'] !== null ? 1 : 0;
        }
        if ($rate <= 0.0) {
            return false;
        }
        return $mine['seconds'] > ($queued + $busy) / $rate;
    }

    /** Index of the next queued job for worker $self. */
    private function pick(int $self): int
    {
        $current = $this->workers[$self]['reference'];
        $counts = [];
        $first = [];
        foreach ($this->queue as $index => $job) {
            if ($current !== false && $job->reference === $current) {
                return $index;
            }
            $key = self::referenceKey($job->reference);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
            $first[$key] ??= $index;
        }
        $taken = [];
        foreach ($this->workers as $i => $worker) {
            if ($i !== $self && $worker['open'] && $worker['reference'] !== false) {
                $taken[self::referenceKey($worker['reference'])] = true;
            }
        }
        $best = null;
        foreach ($counts as $key => $count) {
            $score = [isset($taken[$key]) ? 0 : 1, $count];
            if ($best === null || $score > $best[0]) {
                $best = [$score, $key];
            }
        }
        return $best !== null ? $first[$best[1]] : 0;
    }

    private static function referenceKey(?string $reference): string
    {
        return $reference === null ? "\0builtin" : 'ref:' . $reference;
    }

    /** Write an inline WAV (base64) to the job's path; the error or null. */
    private static function writeInline(SpeechJob $job, mixed $wav): ?string
    {
        $bytes = is_string($wav) ? base64_decode($wav, true) : false;
        if ($bytes === false || $bytes === '') {
            return 'the worker sent an unreadable WAV';
        }
        $dir = dirname($job->wavPath);
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            return "cannot create {$dir}";
        }
        return @file_put_contents($job->wavPath, $bytes) === strlen($bytes) ? null : "cannot write {$job->wavPath}";
    }

    private function flushLines(string &$buffer, string $tag, bool $all): void
    {
        while (($nl = strpos($buffer, "\n")) !== false) {
            ($this->log)($tag . rtrim(substr($buffer, 0, $nl), "\r"));
            $buffer = substr($buffer, $nl + 1);
        }
        if ($all && $buffer !== '') {
            ($this->log)($tag . rtrim($buffer, "\r"));
            $buffer = '';
        }
    }

    /** @param array{process: resource, stdin: resource|null, stdout: resource, stderr: resource|null} $worker */
    private function stop(array $worker): void
    {
        if ($worker['stdin'] !== null) {
            @fclose($worker['stdin']);
        }
        @fclose($worker['stdout']);
        if ($worker['stderr'] !== null) {
            @fclose($worker['stderr']);
        }
        proc_terminate($worker['process']);
        proc_close($worker['process']);
    }
}
