<?php

declare(strict_types=1);

namespace PHPolygon\Runtime;

use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Quality\PressureSignal;

/**
 * Append-only developer log. Active only when the engine is started with
 * devMode=true (or the --dev CLI flag in a packaged build). Writes to
 * stdout for tail -F use and to the configured log file on disk.
 *
 * Hot-path callers (DevLogger::logFrameTime) gate themselves on a 5 s
 * interval so the file doesn't explode during long dev sessions; less
 * frequent events (state changes, targetFps changes, hardware profile)
 * write unconditionally.
 */
final class DevLogger
{
    public const P95_LOG_INTERVAL_S = 5.0;

    /** @var resource|null */
    private $stream = null;
    private float $lastP95LogAt = 0.0;

    public function __construct(
        private readonly string $path,
        private readonly bool $alsoStdout = true,
    ) {
        $dir = dirname($this->path);
        if ($dir !== '' && !is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $handle = @fopen($this->path, 'a');
        if (is_resource($handle)) {
            $this->stream = $handle;
        }
        $this->write('--- dev session started ---');
    }

    public function logHardwareProfile(HardwareProfile $profile): void
    {
        $this->write('[HW] ' . $profile->describe());
    }

    public function logStateChange(PressureSignal $from, PressureSignal $to, string $source): void
    {
        $this->write(sprintf(
            '[THERMAL] %s -> %s (source=%s)',
            $from->value,
            $to->value,
            $source === '' ? 'aggregate' : $source,
        ));
    }

    public function logTargetFpsChange(float $from, float $to, string $source, string $reason): void
    {
        $this->write(sprintf(
            '[TGT] %.0f -> %.0f fps (source=%s, %s)',
            $from,
            $to,
            $source,
            $reason,
        ));
    }

    public function logFrameTime(float $p95Ms, float $budgetMs, float $currentTargetFps): void
    {
        $now = microtime(true);
        if ($now - $this->lastP95LogAt < self::P95_LOG_INTERVAL_S) {
            return;
        }
        $this->lastP95LogAt = $now;
        $this->write(sprintf(
            '[P95] %.2f ms (budget=%.2f ms, target=%.0f fps)',
            $p95Ms,
            $budgetMs,
            $currentTargetFps,
        ));
    }

    public function logSettings(GraphicsSettings $settings, string $context): void
    {
        $encoded = json_encode($settings->toJson(), JSON_UNESCAPED_SLASHES);
        $this->write('[SETTINGS:' . $context . '] ' . ($encoded !== false ? $encoded : '(encode failed)'));
    }

    public function logMessage(string $message): void
    {
        $this->write('[INFO] ' . $message);
    }

    public function path(): string
    {
        return $this->path;
    }

    public function __destruct()
    {
        if (is_resource($this->stream)) {
            @fclose($this->stream);
            $this->stream = null;
        }
    }

    private function write(string $line): void
    {
        $stamped = '[' . date('H:i:s') . '] ' . $line . PHP_EOL;
        if (is_resource($this->stream)) {
            @fwrite($this->stream, $stamped);
        }
        if ($this->alsoStdout) {
            fwrite(STDOUT, $stamped);
        }
    }
}
