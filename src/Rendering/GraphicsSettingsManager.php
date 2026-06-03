<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

use PHPolygon\Engine;
use PHPolygon\Event\EventDispatcher;
use PHPolygon\Event\GraphicsSettingsChanged;
use PHPolygon\Event\TargetFpsChanged;
use PHPolygon\Rendering\Quality\BenchmarkResult;
use PHPolygon\Rendering\Quality\GraphicsAutoTuner;
use PHPolygon\Rendering\Quality\QualityMode;
use PHPolygon\Runtime\ThermalProfile;
use PHPolygon\Scene\Scene;

/**
 * Manages the player's graphics settings: persistence, hardware fingerprinting,
 * and propagation to all renderer backends.
 *
 * Lifecycle:
 *   1. Engine constructs this with default settings + persistence path
 *   2. Engine calls applyToRenderer() once the 3D renderer has been created
 *   3. UI / game code uses update(callable) or set*() helpers - every change
 *      emits GraphicsSettingsChanged and is persisted to disk
 *
 * Backwards compatibility:
 *   Games that never call this manager simply use the default GraphicsSettings,
 *   which mirror the engine's pre-existing rendering behaviour. No graphics.json
 *   is written until update() is called or recalibrate() succeeds.
 */
class GraphicsSettingsManager
{
    private GraphicsSettings $settings;
    private string $path;
    private string $hardwareFingerprint;
    private bool $recommendRecalibration = false;
    private ?Engine $engine = null;
    private ?EventDispatcher $events = null;
    /**
     * Persisted marker that ThermalMonitor's initial hardware ceiling has
     * already been applied to this save file. Stored under the top-level
     * "thermalHint" key in graphics.json so it survives settings rewrites.
     *
     * Once set, applyInitialCeiling() is a no-op - the user is free to raise
     * targetFps in the options panel without the engine clobbering it on
     * the next boot.
     */
    private ?string $thermalHint = null;

    public function __construct(
        string $path = 'saves/graphics.json',
        ?GraphicsSettings $defaults = null,
    ) {
        $this->path = $path;
        $this->settings = $defaults ?? new GraphicsSettings();
        $this->hardwareFingerprint = self::computeHardwareFingerprint();
        $this->load();
    }

    public function bindEngine(Engine $engine): void
    {
        $this->engine = $engine;
        $this->events = $engine->events;
    }

    public function settings(): GraphicsSettings
    {
        return $this->settings;
    }

    public function mode(): QualityMode
    {
        return $this->settings->mode;
    }

    public function setMode(QualityMode $mode): void
    {
        $this->update(fn(GraphicsSettings $s): GraphicsSettings => $s->with(mode: $mode));
    }

    public function targetFps(): float
    {
        return $this->settings->targetFps;
    }

    public function setTargetFps(float $fps): void
    {
        $this->update(fn(GraphicsSettings $s): GraphicsSettings => $s->with(targetFps: max(15.0, $fps)));
    }

    /**
     * Non-persisting targetFps change for runtime-driven adjustments such
     * as the ThermalMonitor's pressure reactions. Applies the value to the
     * active renderer and emits both GraphicsSettingsChanged and
     * TargetFpsChanged, but never touches graphics.json - we don't want a
     * temporary throttle reaction to become the player's permanent setting.
     *
     * No-op when the new value matches the current targetFps. Source/reason
     * are forwarded to the TargetFpsChanged event for telemetry.
     */
    public function setRuntimeTargetFps(float $fps, string $source, string $reason): void
    {
        $fps = max(15.0, $fps);
        $previous = $this->settings;
        if (abs($previous->targetFps - $fps) < 0.5) {
            return;
        }
        $next = $previous->with(targetFps: $fps);
        $this->settings = $next;
        $this->applyToRenderer();
        $this->events?->dispatch(new GraphicsSettingsChanged(previous: $previous, current: $next));
        $this->events?->dispatch(new TargetFpsChanged(
            previous: $previous->targetFps,
            current: $next->targetFps,
            source: $source,
            reason: $reason,
        ));
    }

    /**
     * One-shot ceiling applied at first launch on known throttle-prone
     * hardware (e.g. 2018/2019 15" MBP i9). Lowers targetFps to $maxFps if
     * the current value exceeds it and persists a thermalHint marker so
     * subsequent boots leave the player's setting alone.
     *
     * No-op when:
     *   - $maxFps is null (hardware has no recommended ceiling)
     *   - thermalHint is already set in graphics.json
     *   - current targetFps is already at or below $maxFps
     */
    public function applyInitialCeiling(?float $maxFps, ThermalProfile $profile): void
    {
        if ($maxFps === null) {
            return;
        }
        if ($this->thermalHint !== null) {
            return;
        }
        $hint = $profile->value;
        if ($this->settings->targetFps <= $maxFps + 0.5) {
            // No change needed, but still record the hint so we don't keep
            // re-evaluating on every boot.
            $this->thermalHint = $hint;
            $this->save();
            return;
        }
        $this->update(fn(GraphicsSettings $s): GraphicsSettings => $s->with(targetFps: $maxFps));
        $this->thermalHint = $hint;
        $this->save();
    }

    public function thermalHint(): ?string
    {
        return $this->thermalHint;
    }

    /**
     * Apply an immutable update via a callable that receives the current
     * settings and must return a new GraphicsSettings instance. Persists,
     * applies to the active renderer, and emits the change event.
     *
     * @param callable(GraphicsSettings): GraphicsSettings $mutator
     */
    public function update(callable $mutator): void
    {
        $previous = $this->settings;
        $next = $mutator($previous);
        if (self::settingsEqual($previous, $next)) {
            return;
        }
        $this->settings = $next;
        $this->applyToRenderer();
        $this->save();
        $this->events?->dispatch(new GraphicsSettingsChanged(previous: $previous, current: $next));
    }

    /**
     * Apply the current settings to the engine's active 3D renderer.
     * Safe to call on a 2D-only engine - it is a no-op when renderer3D is null.
     */
    public function applyToRenderer(): void
    {
        $r3d = $this->engine?->renderer3D;
        if ($r3d !== null) {
            $r3d->applySettings($this->settings);
        }
        $this->engine?->shaders->reset();
        $shaderId = $this->settings->shaderQuality->shaderId();
        if ($shaderId !== null) {
            $this->engine?->shaders->use($shaderId);
        }
    }

    public function isRecalibrationRecommended(): bool
    {
        return $this->recommendRecalibration;
    }

    public function clearRecalibrationRecommendation(): void
    {
        $this->recommendRecalibration = false;
    }

    public function hardwareFingerprint(): string
    {
        return $this->hardwareFingerprint;
    }

    /**
     * Run the auto-tuner against the engine's bound benchmark scene (or a
     * caller-supplied custom one). Persists the resulting settings.
     */
    public function recalibrate(?Scene $custom = null): BenchmarkResult
    {
        if ($this->engine === null) {
            throw new \RuntimeException('GraphicsSettingsManager::recalibrate requires bindEngine() first');
        }
        $tuner = new GraphicsAutoTuner($this->engine, $this);
        $result = $tuner->calibrate($this->settings->targetFps, $custom);
        $this->update(fn(): GraphicsSettings => $result->finalSettings);
        return $result;
    }

    /**
     * Load persisted settings from disk if the file exists. If the saved
     * hardware fingerprint differs from the current one, the recalibration
     * recommendation flag is set so the UI can prompt the player.
     */
    private function load(): void
    {
        if (!file_exists($this->path)) {
            return;
        }
        $contents = @file_get_contents($this->path);
        if ($contents === false || $contents === '') {
            return;
        }
        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            return;
        }
        $settingsData = is_array($decoded['settings'] ?? null) ? $decoded['settings'] : $decoded;
        /** @var array<string, mixed> $settingsData */
        $this->settings = GraphicsSettings::fromJson($settingsData);

        $savedFingerprint = is_string($decoded['hardwareFingerprint'] ?? null) ? $decoded['hardwareFingerprint'] : '';
        if ($savedFingerprint !== '' && $savedFingerprint !== $this->hardwareFingerprint) {
            $this->recommendRecalibration = true;
        }

        $hint = $decoded['thermalHint'] ?? null;
        if (is_string($hint) && $hint !== '') {
            $this->thermalHint = $hint;
        }
    }

    public function save(): void
    {
        $payload = [
            'version' => 1,
            'hardwareFingerprint' => $this->hardwareFingerprint,
            'settings' => $this->settings->toJson(),
        ];
        if ($this->thermalHint !== null) {
            $payload['thermalHint'] = $this->thermalHint;
        }
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents($this->path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * Build a stable identifier for the host machine so that swapping GPU
     * drivers, OS or hardware can flag the saved settings as stale.
     *
     * Notes:
     *   - We do NOT call glGetString() here. Calling GL functions before a
     *     context is current is a segfault on some drivers, and
     *     GraphicsSettingsManager is constructed before any window has been
     *     initialised. The renderer can call updateHardwareFingerprintFromGl()
     *     later to incorporate the GPU vendor/renderer string.
     *   - We do NOT call vio_backend_name() either; same lifetime concern.
     *
     * Sources used unconditionally:
     *   - vio extension presence (function_exists, no calls)
     *   - PHP_INT_SIZE
     *   - php_uname() s / m
     */
    private static function computeHardwareFingerprint(): string
    {
        $parts = [];

        if (function_exists('vio_create')) {
            $parts[] = 'vio=loaded';
        }

        $parts[] = 'php_int_size=' . PHP_INT_SIZE;
        $parts[] = 'os=' . php_uname('s');
        $parts[] = 'arch=' . php_uname('m');

        return hash('sha256', implode('|', $parts));
    }

    /**
     * Refine the fingerprint with the GPU vendor/renderer strings that are
     * only available after a GL context has been made current. Callers
     * (typically the engine, after Renderer3D is built) can invoke this to
     * upgrade the fingerprint - it never weakens it.
     */
    public function updateHardwareFingerprintFromGl(string $vendor, string $renderer): void
    {
        if ($vendor === '' && $renderer === '') {
            return;
        }
        $base = $this->hardwareFingerprint;
        $this->hardwareFingerprint = hash('sha256', $base . '|gl=' . $vendor . '|' . $renderer);
    }

    private static function settingsEqual(GraphicsSettings $a, GraphicsSettings $b): bool
    {
        return $a->toJson() === $b->toJson();
    }
}
