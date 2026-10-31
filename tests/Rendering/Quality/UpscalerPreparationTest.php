<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering\Quality;

use PHPolygon\Rendering\Quality\LazyUpscalerProbe;
use PHPolygon\Rendering\Quality\UpscalerPreparation;
use PHPolygon\Rendering\Quality\UpscalerProbeCache;
use PHPolygon\Rendering\Quality\UpscalerProbeResult;
use PHPUnit\Framework\TestCase;

final class UpscalerPreparationTest extends TestCase
{
    private string $state = 'preparing';
    private bool $returns = true;
    private int $prepared = 0;
    private int $looked = 0;
    private float $now = 100.0;

    private function preparation(): UpscalerPreparation
    {
        return new UpscalerPreparation(
            function (): bool {
                $this->prepared++;
                return $this->returns;
            },
            function (): array {
                $this->looked++;
                return ['state' => $this->state];
            },
            fn(): float => $this->now,
        );
    }

    public function testNothingRunsBeforeTheStart(): void
    {
        $preparation = $this->preparation();

        $this->assertFalse($preparation->started());
        $this->assertSame('idle', $preparation->state());
        $this->assertSame('idle', $preparation->poll());
        $this->assertSame(0, $this->prepared + $this->looked);
    }

    public function testItStartsOnce(): void
    {
        $preparation = $this->preparation();

        $this->assertSame('preparing', $preparation->start());
        $preparation->start();

        $this->assertTrue($preparation->started());
        $this->assertTrue($preparation->isRunning());
        $this->assertSame(1, $this->prepared);
    }

    public function testThePollIsThrottledToTheIntervalNotTheFrame(): void
    {
        $preparation = $this->preparation();
        $preparation->start();
        $looks = $this->looked;

        // 144 fps for two seconds.
        for ($frame = 0; $frame < 288; $frame++) {
            $this->now += 1.0 / 144.0;
            $preparation->poll();
        }

        $this->assertLessThanOrEqual(7, $this->looked - $looks);
        $this->assertGreaterThanOrEqual(6, $this->looked - $looks);
        $this->assertGreaterThanOrEqual(0.25, UpscalerPreparation::POLL_SECONDS);
        $this->assertLessThanOrEqual(0.5, UpscalerPreparation::POLL_SECONDS);
    }

    public function testAFinishedPreparationIsNotLookedAtAgain(): void
    {
        $preparation = $this->preparation();
        $preparation->start();
        $this->state = 'ready';
        $this->now += UpscalerPreparation::POLL_SECONDS;
        $this->assertSame('ready', $preparation->poll());
        $looks = $this->looked;

        $this->now += 10.0;
        $this->assertSame('ready', $preparation->poll());

        $this->assertSame($looks, $this->looked);
        $this->assertFalse($preparation->isRunning());
    }

    public function testAnOldPluginStaysIdle(): void
    {
        $this->state = 'idle';
        $this->returns = false;

        $this->assertSame('idle', $this->preparation()->start());
    }

    public function testARefusalIsFailedAtOnce(): void
    {
        $this->state = 'failed';
        $this->returns = false;

        $this->assertSame('failed', $this->preparation()->start());
    }

    public function testWaitLooksUntilTheWorkEnded(): void
    {
        $preparation = new UpscalerPreparation(
            static fn(): bool => true,
            function (): array {
                return ['state' => ++$this->looked >= 3 ? 'ready' : 'preparing'];
            },
        );
        $preparation->start();

        $this->assertSame('ready', $preparation->wait());
    }

    public function testAnAcceptedAnswerIsStoredAndCountsAsLive(): void
    {
        $file = sys_get_temp_dir() . '/phpolygon-accept-' . uniqid() . '.json';
        $plugin = $file . '.dll';
        file_put_contents($plugin, 'plugin');
        $asked = 0;
        $make = static fn(): LazyUpscalerProbe => new LazyUpscalerProbe(
            'dlss@d3d12',
            static function () use (&$asked): UpscalerProbeResult {
                $asked++;
                return new UpscalerProbeResult(false);
            },
            static fn(): string => 'device',
            new UpscalerProbeCache($file),
        );
        try {
            $probe = $make();
            $probe->accept(UpscalerProbeResult::fromProviderInfo(true, ['plugin' => $plugin, 'library' => $plugin, 'driver' => '1']));

            $this->assertTrue($probe->isSettledForUse());
            $this->assertTrue($make()->settle()->supported, 'from the disk');
            $this->assertSame(0, $asked);
        } finally {
            @unlink($file);
            @unlink($plugin);
        }
    }
}
