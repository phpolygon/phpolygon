<?php

declare(strict_types=1);

namespace PHPolygon\Log;

use PHPolygon\Thread\ParallelCapability;

/**
 * Writes the log on a thread of its own, so the frame pays for a channel
 * send (about a microsecond) instead of a file write.
 *
 * One `parallel\Runtime` without a bootstrap file: the thread runs plain PHP
 * and never loads a project class, so it starts the same from a source tree
 * and from a packed game. Text travels over the named, unbounded channel
 * {@see CHANNEL}; nothing but strings crosses it. The thread appends, rotates
 * by size ({@see LogFile} describes the scheme) and has every string on disk
 * before it takes the next one.
 *
 * What the calling thread can rely on:
 * - No method throws. Whatever goes wrong inside the thread comes back as
 *   the thread's return value, never as an exception.
 * - write() returns false once the thread is gone. {@see unwritten()} then
 *   holds what was handed over but did not reach the file, for the writer
 *   that takes over.
 * - close() waits for the thread at most {@see $timeoutSeconds}. A thread
 *   stuck in a blocking write cannot be interrupted (neither close nor kill
 *   of a runtime return before the call does), so it is left alone then and
 *   only the end of the process waits for it.
 *
 * Channels are closed by name at the end, as in {@see \PHPolygon\Thread\ThreadScheduler::shutdown()}:
 * a channel left in parallel's global table can keep a Windows process from exiting.
 */
final class ThreadedLogWriter implements LogWriterInterface
{
    public const string CHANNEL = 'phpolygon_log';

    /** Control messages; a log line never starts with a NUL byte. */
    private const string END = "\0end";
    private const string FLUSH = "\0flush";
    private const string FAULT = "\0fault";

    /** Strings kept to tell what a dead thread left unwritten. */
    private const int KEPT = 256;

    private static int $instances = 0;

    private bool $alive = true;
    private bool $closed = false;
    private string $failure = '';
    private int $sent = 0;
    /** @var array<int, string> the last strings handed over, by their running number */
    private array $kept = [];
    private string $unwritten = '';
    private float $startMs = 0.0;

    private function __construct(
        private readonly \parallel\Runtime $runtime,
        private readonly \parallel\Future $future,
        private readonly \parallel\Channel $channel,
        private readonly \parallel\Channel $ack,
        private readonly string $channelName,
        private readonly float $timeoutSeconds,
    ) {}

    /**
     * Start the thread, or return null where there is none to start: a PHP
     * without ext-parallel, or a runtime that would not come up.
     */
    public static function start(
        string $path,
        int $maxBytes = LogFile::DEFAULT_MAX_BYTES,
        int $keep = LogFile::DEFAULT_KEEP,
        float $timeoutSeconds = 2.0,
    ): ?self {
        if (!ParallelCapability::isAvailable()) {
            return null;
        }

        $started = hrtime(true);
        $channel = null;
        $ack = null;
        $name = '';
        try {
            // One name per writer: a second engine in the same process, or a
            // test, must not open the channel of the first.
            for ($attempt = 0; $attempt < 8 && $channel === null; $attempt++) {
                $number = ++self::$instances;
                $name = $number === 1 ? self::CHANNEL : self::CHANNEL . '_' . $number;
                try {
                    $channel = \parallel\Channel::make($name, \parallel\Channel::Infinite);
                } catch (\parallel\Channel\Error\Existence) {
                    $channel = null;
                }
            }
            if ($channel === null) {
                return null;
            }
            $ack = \parallel\Channel::make($name . '_ack', \parallel\Channel::Infinite);

            $runtime = new \parallel\Runtime();
            $future = $runtime->run(self::threadMain(), [$name, $path, $maxBytes, $keep]);
            if ($future === null) {
                throw new \RuntimeException('the log thread returned no future');
            }
        } catch (\Throwable) {
            self::closeChannel($channel);
            self::closeChannel($ack);

            return null;
        }

        $writer = new self($runtime, $future, $channel, $ack, $name, $timeoutSeconds);
        $writer->startMs = (hrtime(true) - $started) / 1_000_000.0;

        return $writer;
    }

    public function write(string $text): bool
    {
        if (!$this->alive) {
            return false;
        }
        if ($text === '') {
            return true;
        }
        try {
            // The thread only ends early when it failed: cannot open the
            // file, the disk is gone. Checking costs some 30 ns.
            if ($this->future->done()) {
                $this->died();

                return false;
            }
            $this->channel->send($text);
        } catch (\Throwable $e) {
            $this->died($e->getMessage());

            return false;
        }

        $this->kept[$this->sent] = $text;
        unset($this->kept[$this->sent - self::KEPT]);
        $this->sent++;

        return true;
    }

    public function flush(): void
    {
        if (!$this->alive) {
            return;
        }
        try {
            $this->channel->send(self::FLUSH);
            $events = new \parallel\Events();
            $events->addChannel($this->ack);
            $events->setBlocking(true);
            $events->setTimeout((int) ($this->timeoutSeconds * 1_000_000));
            $events->poll();
        } catch (\parallel\Events\Error\Timeout) {
            // Busy or stuck: write() notices a dead thread, nothing to do here.
        } catch (\Throwable $e) {
            $this->died($e->getMessage());
        }
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;

        $finished = false;
        try {
            if ($this->alive) {
                $this->channel->send(self::END);
            }
            $deadline = hrtime(true) + (int) ($this->timeoutSeconds * 1_000_000_000);
            while (!($finished = $this->future->done()) && hrtime(true) < $deadline) {
                usleep(200);
            }
            if ($finished) {
                if ($this->alive) {
                    $this->readResult();
                }
                $this->runtime->close();
            } elseif ($this->alive) {
                $this->alive = false;
                $this->failure = 'the log thread did not finish within ' . $this->timeoutSeconds . ' s';
                $this->unwritten = implode('', $this->kept);
            }
        } catch (\Throwable $e) {
            if ($this->alive) {
                $this->died($e->getMessage());
            }
        }
        $this->alive = false;

        self::closeChannel($this->channel);
        self::closeChannel($this->ack);
    }

    public function isAlive(): bool
    {
        return $this->alive;
    }

    /** Why the thread is gone; '' while it runs or after a clean end. */
    public function failure(): string
    {
        return $this->failure;
    }

    /**
     * Text handed to a thread that died before writing it. When the thread
     * could not say how far it got, this is everything still remembered (the
     * last {@see KEPT} strings) and parts of it may be in the file already.
     */
    public function unwritten(): string
    {
        return $this->unwritten;
    }

    /** Milliseconds start() took: channels, runtime, scheduling the task. */
    public function startMilliseconds(): float
    {
        return $this->startMs;
    }

    public function channelName(): string
    {
        return $this->channelName;
    }

    /**
     * Make the thread fail the way an unwritable disk would - for the tests
     * of the fallback.
     *
     * @internal
     */
    public function injectFault(): void
    {
        try {
            $this->channel->send(self::FAULT);
        } catch (\Throwable) {
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    private function died(string $reason = ''): void
    {
        $this->alive = false;
        $this->failure = $reason;
        $this->readResult();
        if ($this->failure === '') {
            $this->failure = 'the log thread ended';
        }
    }

    /** Read "ok:<written>" or "error:<written>:<message>" from a finished thread. */
    private function readResult(): void
    {
        $written = -1;
        try {
            if ($this->future->done()) {
                $result = $this->future->value();
                if (is_string($result)) {
                    $parts = explode(':', $result, 3);
                    $written = (int) ($parts[1] ?? -1);
                    if ($parts[0] !== 'ok') {
                        $this->alive = false;
                        $this->failure = $parts[2] ?? 'the log thread failed';
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->alive = false;
            if ($this->failure === '') {
                $this->failure = $e->getMessage();
            }
        }

        $missing = '';
        foreach ($this->kept as $number => $text) {
            if ($written < 0 || $number >= $written) {
                $missing .= $text;
            }
        }
        $this->unwritten = $missing;
    }

    private static function closeChannel(?\parallel\Channel $channel): void
    {
        try {
            $channel?->close();
        } catch (\Throwable) {
            // Closed already.
        }
    }

    /**
     * The thread: receive, append, rotate, until the end marker.
     *
     * Runs without an autoloader, so it uses nothing but PHP itself and
     * repeats {@see LogFile::append()} and {@see LogFile::rotate()} inline.
     * Returns "ok:<strings written>", or "error:<strings written>:<message>"
     * when it had to give up.
     */
    private static function threadMain(): \Closure
    {
        return static function (string $name, string $path, int $maxBytes, int $keep): string {
            $written = 0;
            $handle = null;
            try {
                $channel = \parallel\Channel::open($name);
                $ack = \parallel\Channel::open($name . '_ack');
                $size = 0;

                $dot = strrpos($path, '.');
                $slash = max((int) strrpos($path, '/'), (int) strrpos($path, '\\'));
                $head = $dot !== false && $dot > $slash ? substr($path, 0, $dot) : $path;
                $tail = $dot !== false && $dot > $slash ? substr($path, $dot) : '';

                while (true) {
                    $text = $channel->recv();
                    if (!is_string($text) || $text === "\0end") {
                        break;
                    }
                    if ($text === "\0flush") {
                        $ack->send(true);
                        continue;
                    }
                    if ($text === "\0fault") {
                        throw new \RuntimeException('injected fault');
                    }

                    // Open, and when the text does not fit any more: close,
                    // rotate, open again (second pass writes whatever came of it).
                    // Two passes at most - the second always breaks - so this
                    // is a plain loop rather than a bounded for: the pass count
                    // never reaches the point where a comparison would decide.
                    $pass = 0;
                    while (true) {
                        if ($handle === null) {
                            $dir = dirname($path);
                            if (!is_dir($dir)) {
                                @mkdir($dir, 0755, true);
                            }
                            $opened = @fopen($path, 'ab');
                            if ($opened === false) {
                                throw new \RuntimeException('cannot open ' . $path);
                            }
                            $handle = $opened;
                            $stat = fstat($handle);
                            $size = $stat !== false ? $stat['size'] : 0;
                        }
                        if ($pass === 1 || $size === 0 || $size + strlen($text) <= $maxBytes) {
                            break;
                        }
                        fclose($handle);
                        $handle = null;
                        $last = max(1, $keep - 1);
                        @unlink($head . '.' . $last . $tail);
                        for ($i = $last - 1; $i >= 1; $i--) {
                            if (is_file($head . '.' . $i . $tail)) {
                                @rename($head . '.' . $i . $tail, $head . '.' . ($i + 1) . $tail);
                            }
                        }
                        if ($keep <= 1) {
                            @unlink($path);
                        } else {
                            @rename($path, $head . '.1' . $tail);
                        }
                        clearstatcache(true, $path);
                        $pass++;
                    }

                    $bytes = @fwrite($handle, $text);
                    if ($bytes === false) {
                        throw new \RuntimeException('cannot write ' . $path);
                    }
                    @fflush($handle);
                    $size += $bytes;
                    $written++;
                }

                if ($handle !== null) {
                    fclose($handle);
                }

                return 'ok:' . $written;
            } catch (\Throwable $e) {
                if (is_resource($handle)) {
                    @fclose($handle);
                }

                return 'error:' . $written . ':' . $e->getMessage();
            }
        };
    }
}
