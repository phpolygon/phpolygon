<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Quality;

use PHPolygon\Rendering\GraphicsSettings;

/**
 * A benchmark that drives through a game's own world and tunes the graphics while
 * it runs: the game shows {@see station()}, applies {@see settings()} and feeds every
 * rendered frame's time to {@see frame()}. No rendering happens here, so the
 * control is testable with synthetic frame times.
 *
 * Tour: at each station the camera settles ($settleSeconds after the cut), then
 * $measureFrames frame times are taken. When the frame time at $percentile (0.99 =
 * the "1 % lows") misses the budget 1000 / targetFps, the settings go one step down
 * the {@see AdaptiveTierStack} right away (the player sees it) and the station is
 * measured again; a station the cheapest settings still miss is noted and passed.
 *
 * After the tour: when even the slowest station keeps $headroom (20 %) of the
 * budget free, one step up is tried and checked on the $verifyStations slowest
 * stations; kept if they all hold, taken back otherwise. Up to $maxUpgrades times.
 */
final class TourBenchmark
{
    private const string TOUR = 'tour';
    private const string VERIFY = 'verify';
    private const string DONE = 'done';

    private string $phase = self::TOUR;
    private int $index = 0;
    private float $settleLeft;
    /** @var list<float> */
    private array $samples = [];
    private GraphicsSettings $settings;
    private ?GraphicsSettings $beforeUpgrade = null;
    /** @var array<string, float> */
    private array $stationMs = [];
    /** @var list<int> station indices checked after an upgrade */
    private array $verify = [];
    private int $verifyAt = 0;
    /** @var array<string, float> */
    private array $verifyMs = [];
    private int $upgrades = 0;
    /** @var list<string> */
    private array $changes = [];
    private bool $aborted = false;
    private bool $stationChanged = true;

    /** @param list<TourStation> $stations */
    public function __construct(
        private readonly array $stations,
        private readonly float $targetFps,
        private readonly GraphicsSettings $start,
        private readonly float $percentile = 0.99,
        private readonly float $settleSeconds = 0.5,
        private readonly int $measureFrames = 120,
        private readonly float $headroom = 0.2,
        private readonly int $verifyStations = 3,
        private readonly int $maxUpgrades = 4,
    ) {
        if ($stations === []) {
            throw new \InvalidArgumentException('A benchmark tour needs at least one station');
        }
        if ($targetFps <= 0.0) {
            throw new \InvalidArgumentException('The target FPS must be positive');
        }
        $this->settings = $start;
        $this->settleLeft = $this->settleFor($stations[0]);
    }

    /** Settle time at $station: the default, or longer where its data asks ('settle', s). */
    private function settleFor(?TourStation $station): float
    {
        $extra = $station !== null && is_numeric($station->data['settle'] ?? null) ? (float) $station->data['settle'] : 0.0;

        return max($this->settleSeconds, $extra);
    }

    public function budgetMs(): float
    {
        return 1000.0 / $this->targetFps;
    }

    /** The station to show now; null once the run is over. */
    public function station(): ?TourStation
    {
        return match ($this->phase) {
            self::TOUR => $this->stations[$this->index],
            self::VERIFY => $this->stations[$this->verify[$this->verifyAt]],
            default => null,
        };
    }

    /** Whether station() changed since the last call (the camera has to cut). */
    public function takeStationChange(): bool
    {
        $changed = $this->stationChanged;
        $this->stationChanged = false;

        return $changed;
    }

    /** The settings to render with now. */
    public function settings(): GraphicsSettings
    {
        return $this->settings;
    }

    public function done(): bool
    {
        return $this->phase === self::DONE;
    }

    /** 0..1 for a progress bar. */
    public function progress(): float
    {
        $tourShare = 0.85;
        return match ($this->phase) {
            self::TOUR => $tourShare * $this->index / count($this->stations),
            self::VERIFY => $tourShare + (1.0 - $tourShare) * min(1.0, $this->upgrades / max(1, $this->maxUpgrades)),
            default => 1.0,
        };
    }

    /** The last change the run made (for the overlay), null before the first. */
    public function lastChange(): ?string
    {
        return $this->changes === [] ? null : $this->changes[count($this->changes) - 1];
    }

    /**
     * Feed the time of one rendered frame (ms) and the frame's delta (s). Returns
     * true when settings() changed and must be applied before the next frame.
     */
    public function frame(float $frameMs, float $dt): bool
    {
        if ($this->phase === self::DONE) {
            return false;
        }
        if ($this->settleLeft > 0.0) {
            $this->settleLeft -= $dt;
            return false;
        }
        $this->samples[] = $frameMs;
        if (count($this->samples) < $this->measureFrames) {
            return false;
        }
        $ms = FrameTimes::percentile($this->samples, $this->percentile);
        $this->samples = [];
        $before = $this->station();
        $changed = $this->phase === self::TOUR ? $this->measuredOnTour($ms) : $this->measuredInVerify($ms);
        $now = $this->station();
        // A new station settles as long as its data asks; the same one after a change, the default.
        $this->settleLeft = $now !== null && $now !== $before ? $this->settleFor($now) : $this->settleSeconds;

        return $changed;
    }

    /** Stop now: the result keeps what was measured and says aborted. */
    public function abort(): void
    {
        $this->aborted = true;
        $this->phase = self::DONE;
    }

    public function result(): TourBenchmarkResult
    {
        return new TourBenchmarkResult(
            $this->targetFps,
            $this->percentile,
            $this->start,
            $this->settings,
            $this->stationMs,
            $this->changes,
            $this->aborted,
        );
    }

    private function measuredOnTour(float $ms): bool
    {
        $station = $this->stations[$this->index];
        if ($ms > $this->budgetMs()) {
            $next = AdaptiveTierStack::downgrade($this->settings);
            if ($next !== null) {
                $this->changes[] = self::describe($this->settings, $next);
                $this->settings = $next;
                return true;
            }
        }
        $this->stationMs[$station->label] = $ms;
        $this->index++;
        $this->stationChanged = true;
        if ($this->index >= count($this->stations)) {
            return $this->tryUpgrade();
        }

        return false;
    }

    private function measuredInVerify(float $ms): bool
    {
        $station = $this->stations[$this->verify[$this->verifyAt]];
        if ($ms > $this->budgetMs()) {
            // The step up does not hold: back to the settings that did.
            assert($this->beforeUpgrade !== null);
            $this->changes[] = 'revert: ' . self::describe($this->settings, $this->beforeUpgrade);
            $this->settings = $this->beforeUpgrade;
            $this->phase = self::DONE;
            $this->stationChanged = true;
            return true;
        }
        $this->verifyMs[$station->label] = $ms;
        $this->verifyAt++;
        $this->stationChanged = true;
        if ($this->verifyAt < count($this->verify)) {
            return false;
        }
        $this->stationMs = [...$this->stationMs, ...$this->verifyMs];
        $this->upgrades++;

        return $this->tryUpgrade();
    }

    private function tryUpgrade(): bool
    {
        $worst = $this->stationMs === [] ? INF : max($this->stationMs);
        $next = $this->upgrades < $this->maxUpgrades && $worst <= $this->budgetMs() * (1.0 - $this->headroom)
            ? AdaptiveTierStack::upgrade($this->settings)
            : null;
        if ($next === null) {
            $this->phase = self::DONE;
            return false;
        }
        $this->changes[] = self::describe($this->settings, $next);
        $this->beforeUpgrade = $this->settings;
        $this->settings = $next;
        $byCost = $this->stationMs;
        arsort($byCost);
        $labels = array_slice(array_keys($byCost), 0, max(1, $this->verifyStations));
        $this->verify = [];
        foreach ($this->stations as $i => $s) {
            if (in_array($s->label, $labels, true)) {
                $this->verify[] = $i;
            }
        }
        $this->verifyAt = 0;
        $this->verifyMs = [];
        $this->phase = self::VERIFY;
        $this->stationChanged = true;

        return true;
    }

    /** "shadowQuality high → medium", "effect water_whitecaps off": what changed from $a to $b. */
    public static function describe(GraphicsSettings $a, GraphicsSettings $b): string
    {
        $parts = [];
        $ja = $a->toJson();
        $jb = $b->toJson();
        foreach ($jb as $key => $value) {
            if ($key === 'effects') {
                continue;
            }
            if (($ja[$key] ?? null) !== $value && !is_array($value)) {
                $parts[] = $key . ' ' . self::show($ja[$key] ?? null) . ' → ' . self::show($value);
            }
        }
        foreach (array_unique([...array_keys($a->effects), ...array_keys($b->effects)]) as $effect) {
            $was = $a->effectEnabled((string) $effect);
            $now = $b->effectEnabled((string) $effect);
            if ($was !== $now) {
                $parts[] = 'effect ' . $effect . ($now ? ' on' : ' off');
            }
        }

        return $parts === [] ? 'no change' : implode(', ', $parts);
    }

    private static function show(mixed $v): string
    {
        return match (true) {
            is_bool($v) => $v ? 'on' : 'off',
            is_float($v) => rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.'),
            is_scalar($v) => (string) $v,
            default => '-',
        };
    }
}
