<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering\Quality;

use PHPUnit\Framework\TestCase;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Quality\AdaptiveTierStack;
use PHPolygon\Rendering\Quality\FrameTimes;
use PHPolygon\Rendering\Quality\ScreenSpaceAO;
use PHPolygon\Rendering\Quality\ScreenSpaceReflections;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\Quality\TourBenchmark;
use PHPolygon\Rendering\Quality\TourBenchmarkResult;
use PHPolygon\Rendering\Quality\TourStation;

final class TourBenchmarkTest extends TestCase
{
    protected function setUp(): void
    {
        AdaptiveTierStack::clearSteps();
    }

    /** @return list<TourStation> */
    private static function stations(): array
    {
        return [
            new TourStation('harbour', new Vec3(0, 2, 0), new Vec3(10, 2, 0)),
            new TourStation('city', new Vec3(100, 2, 0), new Vec3(110, 2, 0), ['heavy' => 4.0]),
            new TourStation('room', new Vec3(0, 2, 50), new Vec3(0, 2, 60)),
        ];
    }

    /** A made-up GPU: frame time from the costly settings, plus a station's own weight. */
    private static function cost(GraphicsSettings $s, TourStation $st, float $scale): float
    {
        $heavy = $st->data['heavy'] ?? 0.0;
        $ms = 4.0 + 16.0 * $s->renderScale * $s->renderScale
            + ($s->volumetricFog ? 3.0 : 0.0)
            + ($s->ssr !== ScreenSpaceReflections::Off ? 3.0 : 0.0)
            + ($s->ambientOcclusion !== ScreenSpaceAO::Off ? 2.0 : 0.0)
            + ($s->shadowQuality !== ShadowQuality::Off ? 3.0 : 0.0)
            + (is_float($heavy) ? $heavy : 0.0);

        return $ms * $scale;
    }

    private static function drive(TourBenchmark $b, float $scale, int &$frames = 0): TourBenchmarkResult
    {
        while (!$b->done() && $frames < 200_000) {
            $st = $b->station();
            self::assertNotNull($st);
            // A little jitter: the percentile, not the mean, has to hold.
            $ms = self::cost($b->settings(), $st, $scale) * (($frames % 50) === 0 ? 1.08 : 1.0);
            $b->frame($ms, 1.0 / 60.0);
            $frames++;
        }
        self::assertTrue($b->done(), 'the run ends');

        return $b->result();
    }

    private static function high(): GraphicsSettings
    {
        return (new GraphicsSettings())->with(
            renderScale: 1.0,
            shadowQuality: ShadowQuality::High,
            ambientOcclusion: ScreenSpaceAO::High,
            ssr: ScreenSpaceReflections::High,
            volumetricFog: true,
        );
    }

    public function testASlowCardIsTunedDownUntilEveryStationHoldsTheTarget(): void
    {
        $b = new TourBenchmark(self::stations(), 60.0, self::high(), measureFrames: 60);
        $r = self::drive($b, 1.0);

        self::assertTrue($r->metTarget(), sprintf('slowest station %.2f ms within %.2f ms', $r->worstMs(), 1000.0 / 60.0));
        self::assertNotSame([], $r->changes, 'it changed something');
        self::assertFalse($r->finalSettings->volumetricFog, 'the costliest step went first');
        foreach (self::stations() as $st) {
            self::assertLessThanOrEqual(1000.0 / 60.0, self::cost($r->finalSettings, $st, 1.0) * 1.08 + 1e-9, "{$st->label} holds with the final settings");
        }
    }

    public function testAFastCardKeepsEverythingAndStopsWithoutDowngrades(): void
    {
        $b = new TourBenchmark(self::stations(), 30.0, self::high(), measureFrames: 60);
        $r = self::drive($b, 0.5);

        self::assertTrue($r->metTarget());
        foreach ($r->changes as $change) {
            self::assertStringNotContainsString('volumetricFog on → off', $change, 'nothing given up');
        }
        self::assertTrue($r->finalSettings->volumetricFog);
        self::assertSame(ShadowQuality::High, $r->finalSettings->shadowQuality);
        self::assertSame(1.0, $r->finalSettings->renderScale);
    }

    public function testHeadroomBuysAStepUpThatIsCheckedOnTheSlowestStations(): void
    {
        $low = self::high()->with(volumetricFog: false, ssr: ScreenSpaceReflections::Off);
        $b = new TourBenchmark(self::stations(), 30.0, $low, measureFrames: 60);
        $r = self::drive($b, 0.5);

        self::assertTrue($r->metTarget());
        self::assertNotSame([], $r->changes, 'headroom bought a step up');
        self::assertNotEquals($low->toJson(), $r->finalSettings->toJson(), 'the final settings are richer: ' . implode(' | ', $r->changes));
    }

    public function testAStepUpThatMissesIsTakenBack(): void
    {
        // Budget 25 ms: the start (20 ms at 0.8) has headroom, a step up costs 3 ms and
        // the heavy station then misses.
        $start = self::high()->with(volumetricFog: false, ssr: ScreenSpaceReflections::Off);
        $b = new TourBenchmark(self::stations(), 40.0, $start, measureFrames: 60, headroom: 0.05, maxUpgrades: 8);
        $r = self::drive($b, 0.8);

        self::assertTrue($r->metTarget(), implode(' | ', $r->changes));
    }

    public function testAnUnreachableTargetEndsAndSaysSo(): void
    {
        $b = new TourBenchmark(self::stations(), 500.0, self::high(), measureFrames: 30);
        $r = self::drive($b, 1.0);

        self::assertFalse($r->metTarget());
        self::assertCount(3, $r->stationMs, 'every station still measured');
    }

    public function testAbortKeepsTheStartAvailableAndEndsAtOnce(): void
    {
        $b = new TourBenchmark(self::stations(), 60.0, self::high(), measureFrames: 30);
        $b->frame(10.0, 1.0);
        $b->abort();

        self::assertTrue($b->done());
        self::assertNull($b->station());
        self::assertTrue($b->result()->aborted);
        self::assertFalse($b->result()->metTarget());
        self::assertEquals(self::high()->toJson(), $b->result()->startSettings->toJson());
    }

    public function testTheCameraCutsAtEveryNewStation(): void
    {
        $b = new TourBenchmark(self::stations(), 30.0, self::high(), settleSeconds: 0.0, measureFrames: 2, maxUpgrades: 0);
        self::assertTrue($b->takeStationChange(), 'first station');
        self::assertFalse($b->takeStationChange());
        $b->frame(1.0, 0.016);
        $b->frame(1.0, 0.016);
        self::assertTrue($b->takeStationChange(), 'second station');
        self::assertSame('city', $b->station()?->label);
    }

    public function testAStationCanAskForALongerSettle(): void
    {
        $stations = [
            new TourStation('a', new Vec3(0, 0, 0), new Vec3(0, 0, -1), ['settle' => 2.0]),
            new TourStation('b', new Vec3(0, 0, 0), new Vec3(0, 0, -1)),
        ];
        $b = new TourBenchmark($stations, 30.0, self::high(), settleSeconds: 0.1, measureFrames: 1, maxUpgrades: 0);
        $frames = 0;
        while ($b->station()?->label === 'a') {
            $b->frame(1.0, 0.1);
            $frames++;
        }
        self::assertSame(21, $frames, '2 s of settling at 0.1 s per frame, then one measured frame');
        $b->frame(1.0, 0.1);
        self::assertSame('b', $b->station()?->label, 'the next station settles 0.1 s only');
        $b->frame(1.0, 0.1);
        self::assertTrue($b->done());
    }

    public function testDescribeNamesWhatChanged(): void
    {
        $a = self::high();
        self::assertSame('shadowQuality high → medium', TourBenchmark::describe($a, $a->with(shadowQuality: ShadowQuality::Medium)));
        self::assertSame('effect water_foam off', TourBenchmark::describe($a, $a->with(effects: ['water_foam' => false])));
        self::assertSame('no change', TourBenchmark::describe($a, $a));
    }

    public function testPercentile(): void
    {
        $samples = array_merge(array_fill(0, 99, 10.0), [40.0]);
        self::assertSame(40.0, FrameTimes::percentile($samples, 0.99));
        self::assertSame(10.0, FrameTimes::percentile($samples, 0.95));
        self::assertSame(0.0, FrameTimes::percentile([], 0.99));
    }

    public function testNoStationsIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TourBenchmark([], 60.0, new GraphicsSettings());
    }
}
