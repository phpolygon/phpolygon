<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Runtime\Warmup;

use PHPolygon\Engine;
use PHPolygon\EngineConfig;
use PHPolygon\Runtime\Warmup\CallbackWarmupTask;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * The splash drive of the warm-up: a real (offscreen) vio window, the splash shown,
 * the tasks sliced between splash frames. 2D only, so no shader is compiled.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class SplashWarmupTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testSplashDrivesTheTasksOneSliceAtATimeBeforeTheFirstUpdate(): void
    {
        $dir = sys_get_temp_dir() . '/phpolygon-splash-warmup-' . getmypid();
        $engine = new Engine(new EngineConfig(
            width: 320,
            height: 180,
            vsync: false,
            offscreen: true,
            offscreenHardware: getenv('PHPOLYGON_TEST_VIO_HARDWARE') === '1',
            shaderCachePath: '',
            splashDuration: 0.05,
            firstLaunchCalibration: false,
            graphicsSettingsPath: $dir . '/graphics.json',
            warmupFrameBudget: 0.0,
            warmupLabelResolver: static fn(string $key): string => 'label:' . $key,
        ));

        $slices = 0;
        $calls = [];
        $engine->onInit(function (Engine $e) use (&$slices, &$calls): void {
            $e->warmup()->onProgress(function (float $f, ?string $key) use (&$calls): void {
                $calls[] = [$f, $key];
            });
            $e->addWarmupTask(new CallbackWarmupTask('a', function () use (&$slices): \Generator {
                for ($i = 0; $i < 4; $i++) {
                    $slices++;
                    yield ($i + 1) / 4;
                }
            }, 'key.a'));
            $e->addWarmupTask(new CallbackWarmupTask('b', static function (): void {
                throw new \RuntimeException('isolated');
            }, 'key.b'));
        });
        $slicesAtFirstUpdate = null;
        $engine->onUpdate(function (Engine $e) use (&$slices, &$slicesAtFirstUpdate): void {
            $slicesAtFirstUpdate ??= $slices;
            $e->stop();
        });
        $engine->run();

        $this->assertSame(4, $slicesAtFirstUpdate);
        // budget 0 = one unit per step: five slices of 'a' (the last lets the generator end), then 'b'
        $this->assertCount(6, $calls);
        $fractions = array_column($calls, 0);
        $sorted = $fractions;
        sort($sorted);
        $this->assertSame($sorted, $fractions);
        $this->assertSame(1.0, $engine->warmup()->fraction());
        $this->assertSame('failed', $engine->warmup()->results()['b']['status']);
    }
}
