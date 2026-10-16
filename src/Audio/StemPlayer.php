<?php

declare(strict_types=1);

namespace PHPolygon\Audio;

use PHPolygon\Audio\Dsp\GainFollower;

/**
 * Plays a layered piece live: pre-rendered stems that come and go against
 * marks the game reaches at run time (a cutscene's beats, a fight's phases).
 *
 * The arrangement lives in a {@see StemSchedule} the game fills from outside
 * (start, fade-out, crossfade, cut); every {@see update()} the player starts
 * the clips whose time has come on its channel, sets their volume to the
 * schedule's gain × the duck, and stops the ones that are over. Stems are
 * ids with a file path – the player loads each once ({@see preload()}) and
 * treats a missing file or a failed play as a silent stem.
 *
 * Volumes go through {@see AudioManager::setPlaybackVolume()}, so the
 * player's channel and Master volume, mute and the manager's channel ducks
 * ({@see AudioManager::setChannelDuck()}) still apply on top. The player's own
 * duck ({@see duck()}, a {@see GainFollower} on the player's clock) is for
 * ducking that must run where the manager's ramps do not, or per stem; do not
 * duck the same reason in both places.
 *
 * At the end {@see release()} hands the stems still sounding to a new owner
 * ({@see StemHandoff}), which can {@see adopt()} them into another player
 * that fades them out on its own clock.
 */
final class StemPlayer
{
    /** Longest step the player takes in one update (s) – a stall does not skip a fade. */
    public const float MAX_STEP = 0.25;

    private StemSchedule $schedule;

    /** @var array<string, ?string> stem => file path (null: silent stem) */
    private array $paths = [];

    /** @var array<string, string> stem => clip id */
    private array $clipIds = [];

    /** @var array<string, true> clips handed to the audio manager */
    private array $loaded = [];

    /** @var array<string, true> stems that failed (missing file, failed play) */
    private array $failed = [];

    /** @var array<string, int> stem => playback */
    private array $playing = [];

    /** @var array<string, float> stem => volume last handed to the playback */
    private array $volume = [];

    /** @var array<string, true> stems that have played out */
    private array $over = [];

    /** @var array<string, GainFollower> per-stem duck overrides */
    private array $stemDucks = [];

    /** @var array<string, float> stem => duck target (dB) for its own follower */
    private array $stemDuckTargets = [];

    /** @var list<array{handoff: StemHandoff, elapsed: float}> adopted playbacks fading out */
    private array $adopted = [];

    private float $duckTarget = 0.0;
    private ?float $now = null;
    private bool $released = false;

    private readonly GainFollower $duck;

    /** @var (\Closure(string): void)|null */
    private readonly ?\Closure $onProblem;

    /**
     * @param GainFollower|null $duck the follower for {@see duck()}; null: an instant one
     * @param (\Closure(string): void)|null $onProblem told once per stem about a missing or failing clip
     */
    public function __construct(
        private readonly AudioManager $audio,
        public readonly AudioChannel $channel = AudioChannel::Music,
        ?GainFollower $duck = null,
        ?\Closure $onProblem = null,
    ) {
        $this->schedule = new StemSchedule();
        $this->duck = $duck ?? new GainFollower(0.0, 0.0);
        $this->onProblem = $onProblem;
    }

    /**
     * Register a stem: its id, the file to play (null: none – the stem stays
     * silent) and the clip id it is loaded under (default `stem:<id>`).
     */
    public function addStem(string $id, ?string $path, ?string $clipId = null): void
    {
        $this->paths[$id] = $path;
        $this->clipIds[$id] = $clipId ?? 'stem:' . $id;
    }

    /** Load every registered stem now, so no start waits for a decode. */
    public function preload(): void
    {
        foreach (array_keys($this->paths) as $stem) {
            $this->load((string) $stem);
        }
    }

    /** The arrangement the player follows; fill it as marks are reached. */
    public function schedule(): StemSchedule
    {
        return $this->schedule;
    }

    /**
     * Duck every stem (or only $stem) towards $db along the duck follower.
     * 0 dB ends the duck. A per-stem duck starts from the shared gain.
     */
    public function duck(float $db, ?string $stem = null): void
    {
        if ($stem === null) {
            $this->duckTarget = $db;
            return;
        }
        if (!isset($this->stemDucks[$stem])) {
            $this->stemDucks[$stem] = $this->duck->fresh($this->duck->db());
        }
        $this->stemDuckTargets[$stem] = $db;
    }

    /** Current duck gain of $stem (or the shared one). */
    public function duckGain(?string $stem = null): float
    {
        return ($stem !== null ? ($this->stemDucks[$stem] ?? null) : null)?->gain() ?? $this->duck->gain();
    }

    /**
     * One frame at player time $now (s, monotonic; the clock the schedule
     * uses). Steps the ducks, starts / updates / ends stems and advances
     * adopted fade-outs.
     */
    public function update(float $now): void
    {
        $dt = $this->now === null ? 0.0 : max(0.0, min(self::MAX_STEP, $now - $this->now));
        $this->now = $now;

        $this->duck->step($dt, $this->duckTarget);
        foreach ($this->stemDucks as $stem => $follower) {
            $follower->step($dt, $this->stemDuckTargets[$stem] ?? 0.0);
        }

        if (!$this->released) {
            foreach ($this->schedule->stems() as $stem) {
                $this->updateStem($stem, $now);
            }
        }
        $this->updateAdopted($dt);
    }

    /**
     * Give up the stems still sounding: each comes back with its playback,
     * current volume and the fade the new owner should use. Silent ones are
     * stopped. The player drives no stem afterwards (until {@see reset()}).
     *
     * @return list<StemHandoff>
     */
    public function release(float $fade): array
    {
        $out = [];
        foreach ($this->playing as $stem => $playback) {
            $volume = $this->volume[$stem] ?? 0.0;
            if ($volume > 0.0 && $this->audio->isPlaying($playback)) {
                $out[] = new StemHandoff((string) $stem, $playback, $volume, max(0.0, $fade), $this->channel);
            } else {
                $this->audio->stop($playback);
            }
        }
        $this->playing = [];
        $this->volume = [];
        $this->released = true;
        return $out;
    }

    /**
     * Take over a playback another player released and fade it out over its
     * {@see StemHandoff::$fade} on this player's clock (0: stop now).
     */
    public function adopt(StemHandoff $handoff): void
    {
        if ($handoff->fade <= 0.0) {
            $this->audio->stop($handoff->playback);
            return;
        }
        $this->adopted[] = ['handoff' => $handoff, 'elapsed' => 0.0];
    }

    /** Stop everything at once, adopted playbacks included. */
    public function stopNow(): void
    {
        foreach ($this->playing as $playback) {
            $this->audio->stop($playback);
        }
        foreach ($this->adopted as $a) {
            $this->audio->stop($a['handoff']->playback);
        }
        $this->playing = [];
        $this->volume = [];
        $this->adopted = [];
        $this->released = true;
    }

    /** A new run: stops everything, clears the schedule and the ducks; loaded clips stay. */
    public function reset(): void
    {
        $this->stopNow();
        $this->schedule->clear();
        $this->over = [];
        $this->stemDucks = [];
        $this->stemDuckTargets = [];
        $this->duckTarget = 0.0;
        $this->duck->reset();
        $this->now = null;
        $this->released = false;
    }

    /** Is any stem (or adopted playback) audible? */
    public function isPlaying(): bool
    {
        foreach ($this->playing as $stem => $playback) {
            if (($this->volume[$stem] ?? 0.0) > 0.0 && $this->audio->isPlaying($playback)) {
                return true;
            }
        }
        foreach ($this->adopted as $a) {
            if ($this->audio->isPlaying($a['handoff']->playback)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Volume last handed to each playing stem.
     *
     * @return array<string, float>
     */
    public function volumes(): array
    {
        return $this->volume;
    }

    /** The playback of a sounding stem, null when it is not playing. */
    public function playback(string $stem): ?int
    {
        return $this->playing[$stem] ?? null;
    }

    private function updateStem(string $stem, float $now): void
    {
        if (isset($this->over[$stem]) || isset($this->failed[$stem])) {
            return;
        }
        if ($this->schedule->isOver($stem, $now)) {
            $this->end($stem);
            return;
        }
        $gain = $this->schedule->gainAt($stem, $now) * $this->duckGain($stem);
        if (!isset($this->playing[$stem])) {
            $start = $this->schedule->startOf($stem);
            if ($start === null || $now < $start) {
                return;
            }
            $this->play($stem, $gain);
            return;
        }
        $this->audio->setPlaybackVolume($this->playing[$stem], $gain);
        $this->volume[$stem] = $gain;
    }

    private function updateAdopted(float $dt): void
    {
        $still = [];
        foreach ($this->adopted as $a) {
            $a['elapsed'] += $dt;
            $h = $a['handoff'];
            $left = 1.0 - $a['elapsed'] / $h->fade;
            if ($left <= 0.0 || !$this->audio->isPlaying($h->playback)) {
                $this->audio->stop($h->playback);
                continue;
            }
            $this->audio->setPlaybackVolume($h->playback, $h->volume * $left);
            $still[] = $a;
        }
        $this->adopted = $still;
    }

    private function play(string $stem, float $gain): void
    {
        $clip = $this->load($stem);
        if ($clip === null) {
            return;
        }
        try {
            $playback = $this->audio->playOnChannel($clip, $this->channel, $gain, false);
        } catch (\Throwable $e) {
            $this->problem($stem, "cannot play stem '$stem': " . $e->getMessage());
            return;
        }
        if ($playback === 0) {
            $this->problem($stem, "cannot play stem '$stem'");
            return;
        }
        $this->playing[$stem] = $playback;
        $this->volume[$stem] = $gain;
    }

    private function end(string $stem): void
    {
        $this->over[$stem] = true;
        if (isset($this->playing[$stem])) {
            $this->audio->stop($this->playing[$stem]);
            unset($this->playing[$stem], $this->volume[$stem]);
        }
    }

    /** The clip id of a stem, loaded once; null when it cannot be played. */
    private function load(string $stem): ?string
    {
        if (isset($this->failed[$stem])) {
            return null;
        }
        if (!array_key_exists($stem, $this->paths)) {
            $this->problem($stem, "unknown stem '$stem'");
            return null;
        }
        $clip = $this->clipIds[$stem];
        if (isset($this->loaded[$clip])) {
            return $clip;
        }
        $path = $this->paths[$stem];
        if ($path === null) {
            $this->problem($stem, "no file for stem '$stem'");
            return null;
        }
        try {
            $this->audio->loadClip($clip, $path);
        } catch (\Throwable $e) {
            $this->problem($stem, "cannot load stem '$stem' ($path): " . $e->getMessage());
            return null;
        }
        $this->loaded[$clip] = true;
        return $clip;
    }

    private function problem(string $stem, string $message): void
    {
        $this->failed[$stem] = true;
        if ($this->onProblem !== null) {
            ($this->onProblem)($message);
        }
    }
}
