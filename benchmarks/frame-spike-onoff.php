<?php

declare(strict_types=1);

/**
 * On/off cost of the frame spike recorder through the real headless
 * Engine::run() loop, alternating both modes in one process. Also times one
 * spike line into a real log file.
 *
 * Usage: php benchmarks/frame-spike-onoff.php [rounds] [frames per round]
 */

require __DIR__ . '/../vendor/autoload.php';

use PHPolygon\Engine;
use PHPolygon\EngineConfig;
use PHPolygon\Runtime\FrameSpikeRecorder;

$rounds = (int) ($argv[1] ?? 10);
$frames = (int) ($argv[2] ?? 100_000);

/** @return float ns per frame */
function runEngine(FrameSpikeRecorder|false $spikes, int $frames): float
{
    $engine = new Engine(new EngineConfig(headless: true, frameSpikes: $spikes));
    $count = 0;
    $start = 0;
    $end = 0;
    $engine->onRender(static function (Engine $e) use (&$count, &$start, &$end, $frames): void {
        if ($count === 0) {
            $start = hrtime(true);
        }
        if (++$count === $frames) {
            $end = hrtime(true);
            $e->stop();
        }
    });
    $engine->run();
    return ($end - $start) / ($frames - 1);
}

runEngine(false, 2000);

$off = [];
$on = [];
for ($i = 0; $i < $rounds; $i++) {
    $off[] = runEngine(false, $frames);
    $on[] = runEngine(new FrameSpikeRecorder(static function (string $line): void {}), $frames);
}
sort($off);
sort($on);
$median = static fn(array $v): float => $v[intdiv(count($v), 2)];
$diffs = array_map(static fn(float $a, float $b): float => $a - $b, $on, $off);

printf("opcache=%s jit=%s\n", ini_get('opcache.enable_cli') ?: '0', ini_get('opcache.jit') ?: '-');
printf("off median %.0f ns/frame (min %.0f, max %.0f)\n", $median($off), $off[0], end($off));
printf("on  median %.0f ns/frame (min %.0f, max %.0f)\n", $median($on), $on[0], end($on));
printf("delta median %.0f ns/frame = %.4f ms\n", $median($on) - $median($off), ($median($on) - $median($off)) / 1e6);

$path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'frame-spike-onoff.log';
$sink = static function (string $line) use ($path): void {
    @file_put_contents($path, '[' . date('Y-m-d H:i:s') . '] ' . $line . "\n", FILE_APPEND);
};
$recorder = new FrameSpikeRecorder($sink, minLineIntervalSeconds: 0.0, maxLines: PHP_INT_MAX);
$now = (int) hrtime(true);
for ($i = 0; $i < 300; $i++) {
    $recorder->frame($now);
    $now += 16_000_000;
}
$lines = [];
for ($i = 0; $i < 200; $i++) {
    $now += 60_000_000;
    $t = hrtime(true);
    $recorder->frame($now);
    $lines[] = hrtime(true) - $t;
    $recorder->frame($now += 16_000_000);
}
sort($lines);
printf("spike frame with line to %s: median %.3f ms, max %.3f ms\n", $path, $lines[100] / 1e6, end($lines) / 1e6);
@unlink($path);
