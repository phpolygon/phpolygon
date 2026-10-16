<?php

declare(strict_types=1);

namespace PHPolygon\Audio;

class AudioManager
{
    private AudioBackendInterface $backend;

    /** @var array<string, float> channel volumes (0.0–1.0) */
    private array $channelVolumes = [];

    /** @var array<string, bool> channel mute state */
    private array $channelMuted = [];

    /** @var array<int, AudioChannel> playbackId => channel */
    private array $playbackChannels = [];

    /**
     * Each playback's own volume (0.0–1.0), before channel and master are
     * applied – kept so a channel or master change can recompute the voice
     * without losing it.
     *
     * @var array<int, float> playbackId => voice volume
     */
    private array $playbackVolumes = [];

    /** Tracked-voice count at which finished voices are swept on play. */
    private int $pruneThreshold = self::PRUNE_MIN_THRESHOLD;

    private const PRUNE_MIN_THRESHOLD = 64;

    /** Ducks at or below this level silence the channel completely. */
    public const float DUCK_FLOOR_DB = -80.0;

    /**
     * Requested attenuation per channel and reason.
     *
     * @var array<string, array<string, float>> channel => [source => dB (<= 0)]
     */
    private array $duckSources = [];

    /** @var array<string, float> channel => current (ramped) duck in dB */
    private array $duckDb = [];

    /** @var array<string, array{from: float, to: float, duration: float, elapsed: float}> */
    private array $duckRamps = [];

    /** @var array<string, AudioClip> id => clip */
    private array $clips = [];

    /** @var int|null Current music playback ID (only one music track at a time) */
    private ?int $currentMusicPlaybackId = null;
    private ?string $currentMusicClipId = null;

    public function __construct(?AudioBackendInterface $backend = null)
    {
        $this->backend = $backend ?? new NullAudioBackend();

        foreach (AudioChannel::cases() as $channel) {
            $this->channelVolumes[$channel->value] = 1.0;
            $this->channelMuted[$channel->value] = false;
        }
    }

    public function getBackend(): AudioBackendInterface
    {
        return $this->backend;
    }

    /**
     * Load an audio clip from a file path.
     */
    public function loadClip(string $id, string $path): AudioClip
    {
        $clip = $this->backend->load($id, $path);
        $this->clips[$id] = $clip;
        return $clip;
    }

    /**
     * Get a loaded clip by ID, or null.
     */
    public function getClip(string $id): ?AudioClip
    {
        return $this->clips[$id] ?? null;
    }

    /**
     * Play a sound effect (fire-and-forget).
     */
    public function playSfx(string $clipId, float $volume = 1.0): int
    {
        return $this->playOnChannel($clipId, AudioChannel::SFX, $volume, false);
    }

    /**
     * Play a UI sound (fire-and-forget).
     */
    public function playUI(string $clipId, float $volume = 1.0): int
    {
        return $this->playOnChannel($clipId, AudioChannel::UI, $volume, false);
    }

    /**
     * Play background music. Stops the current music track if one is playing.
     */
    public function playMusic(string $clipId, float $volume = 1.0, bool $loop = true): int
    {
        $this->stopMusic();

        $playbackId = $this->playOnChannel($clipId, AudioChannel::Music, $volume, $loop);
        $this->currentMusicPlaybackId = $playbackId;
        $this->currentMusicClipId = $clipId;

        return $playbackId;
    }

    /**
     * Stop the current music track.
     */
    public function stopMusic(): void
    {
        if ($this->currentMusicPlaybackId !== null) {
            $this->stop($this->currentMusicPlaybackId);
            $this->currentMusicPlaybackId = null;
            $this->currentMusicClipId = null;
        }
    }

    /**
     * Get the currently playing music clip ID, or null.
     */
    public function getCurrentMusicClipId(): ?string
    {
        return $this->currentMusicClipId;
    }

    /**
     * Play a clip on a specific channel.
     */
    public function playOnChannel(string $clipId, AudioChannel $channel, float $volume = 1.0, bool $loop = false): int
    {
        $effectiveVolume = $this->calculateEffectiveVolume($channel, $volume);
        $playbackId = $this->backend->play($clipId, $effectiveVolume, $loop);
        if ($playbackId === 0) {
            return 0;
        }

        // Amortised sweep: fire-and-forget voices are never stopped
        // explicitly, so drop finished ones once the map has doubled.
        if (count($this->playbackChannels) >= $this->pruneThreshold) {
            $this->pruneFinishedPlaybacks();
            $this->pruneThreshold = max(self::PRUNE_MIN_THRESHOLD, 2 * count($this->playbackChannels));
        }

        $this->playbackChannels[$playbackId] = $channel;
        $this->playbackVolumes[$playbackId] = $volume;

        return $playbackId;
    }

    /**
     * Set volume for a specific active playback (respects channel and master volume).
     * Use this to adjust looping ambient sounds each frame without restarting them.
     */
    public function setPlaybackVolume(int $playbackId, float $volume): void
    {
        $channel = $this->playbackChannels[$playbackId] ?? AudioChannel::SFX;
        if (isset($this->playbackChannels[$playbackId])) {
            $this->playbackVolumes[$playbackId] = $volume;
        }
        $effective = $this->calculateEffectiveVolume($channel, $volume);
        $this->backend->setVolume($playbackId, $effective);
    }

    /**
     * Stop a specific playback.
     */
    public function stop(int $playbackId): void
    {
        $this->backend->stop($playbackId);
        unset($this->playbackChannels[$playbackId], $this->playbackVolumes[$playbackId]);
    }

    /**
     * Stop all audio on a specific channel.
     */
    public function stopChannel(AudioChannel $channel): void
    {
        foreach ($this->playbackChannels as $playbackId => $ch) {
            if ($ch === $channel) {
                $this->backend->stop($playbackId);
                unset($this->playbackChannels[$playbackId], $this->playbackVolumes[$playbackId]);
            }
        }

        if ($channel === AudioChannel::Music) {
            $this->currentMusicPlaybackId = null;
            $this->currentMusicClipId = null;
        }
    }

    /**
     * Stop all audio.
     */
    public function stopAll(): void
    {
        $this->backend->stopAll();
        $this->playbackChannels = [];
        $this->playbackVolumes = [];
        $this->pruneThreshold = self::PRUNE_MIN_THRESHOLD;
        $this->currentMusicPlaybackId = null;
        $this->currentMusicClipId = null;
    }

    /**
     * Set volume for a channel (0.0–1.0).
     */
    public function setChannelVolume(AudioChannel $channel, float $volume): void
    {
        $this->channelVolumes[$channel->value] = max(0.0, min(1.0, $volume));
        $this->updateActivePlaybacks($channel);
    }

    /**
     * Get volume for a channel.
     */
    public function getChannelVolume(AudioChannel $channel): float
    {
        return $this->channelVolumes[$channel->value];
    }

    /**
     * Set the master volume (shortcut for Master channel).
     *
     * Master is folded into every voice's volume here, so it is deliberately
     * not forwarded to the backend's own output gain – that would apply it
     * a second time.
     */
    public function setMasterVolume(float $volume): void
    {
        $this->setChannelVolume(AudioChannel::Master, $volume);
    }

    /**
     * Get the master volume.
     */
    public function getMasterVolume(): float
    {
        return $this->channelVolumes[AudioChannel::Master->value];
    }

    /**
     * Mute a channel.
     */
    public function muteChannel(AudioChannel $channel): void
    {
        $this->channelMuted[$channel->value] = true;
        $this->updateActivePlaybacks($channel);
    }

    /**
     * Unmute a channel.
     */
    public function unmuteChannel(AudioChannel $channel): void
    {
        $this->channelMuted[$channel->value] = false;
        $this->updateActivePlaybacks($channel);
    }

    /**
     * Check if a channel is muted.
     */
    public function isChannelMuted(AudioChannel $channel): bool
    {
        return $this->channelMuted[$channel->value];
    }

    // ---- Ducking ---------------------------------------------------------

    /**
     * Lower a channel by $db for one reason ($source), e.g. music under
     * speech. Several reasons can duck the same channel at once - the
     * strongest (most negative) one wins. Ducking is a separate gain stage:
     * the player's channel volume ({@see getChannelVolume()}) is never
     * touched. Ducking Master lowers every channel.
     *
     * The change ramps linearly in dB over $fadeSeconds (0 = immediately),
     * driven by {@see update()}. Positive values are treated as 0 dB;
     * {@see DUCK_FLOOR_DB} or lower silences the channel.
     */
    public function setChannelDuck(
        AudioChannel $channel,
        float $db,
        float $fadeSeconds = 0.0,
        string $source = 'default',
    ): void {
        if (is_nan($db)) {
            return;
        }
        $this->duckSources[$channel->value][$source] = max(self::DUCK_FLOOR_DB, min(0.0, $db));
        $this->retargetDuck($channel, $fadeSeconds);
    }

    /** Remove one reason; the channel ramps to the strongest remaining duck (or 0 dB). */
    public function releaseChannelDuck(AudioChannel $channel, string $source = 'default', float $fadeSeconds = 0.0): void
    {
        if (!isset($this->duckSources[$channel->value][$source])) {
            return;
        }
        unset($this->duckSources[$channel->value][$source]);
        $this->retargetDuck($channel, $fadeSeconds);
    }

    /** Remove every reason on $channel, or on all channels when null. */
    public function clearChannelDucks(?AudioChannel $channel = null, float $fadeSeconds = 0.0): void
    {
        $channels = $channel !== null ? [$channel] : AudioChannel::cases();
        foreach ($channels as $ch) {
            if (($this->duckSources[$ch->value] ?? []) === [] && !isset($this->duckRamps[$ch->value])
                && ($this->duckDb[$ch->value] ?? 0.0) === 0.0) {
                continue;
            }
            $this->duckSources[$ch->value] = [];
            $this->retargetDuck($ch, $fadeSeconds);
        }
    }

    /** True when $source (or, with null, any reason) ducks the channel. */
    public function hasChannelDuck(AudioChannel $channel, ?string $source = null): bool
    {
        $sources = $this->duckSources[$channel->value] ?? [];
        return $source === null ? $sources !== [] : isset($sources[$source]);
    }

    /** The channel's duck right now, in dB (0 = none), including a running ramp. */
    public function getChannelDuck(AudioChannel $channel): float
    {
        return $this->duckDb[$channel->value] ?? 0.0;
    }

    /** The duck the channel is heading for: the strongest active reason, 0 when none. */
    public function getChannelDuckTarget(AudioChannel $channel): float
    {
        $sources = $this->duckSources[$channel->value] ?? [];
        return $sources === [] ? 0.0 : min($sources);
    }

    /** Linear gain of the channel's current duck (1.0 = none). */
    public function getChannelDuckGain(AudioChannel $channel): float
    {
        return self::dbToGain($this->getChannelDuck($channel));
    }

    /**
     * Advance duck ramps. The engine calls this once per update tick; call it
     * yourself only when driving an AudioManager outside the engine loop.
     */
    public function update(float $dt): void
    {
        if ($this->duckRamps === []) {
            return;
        }
        foreach ($this->duckRamps as $channelValue => $ramp) {
            $ramp['elapsed'] += max(0.0, $dt);
            $t = $ramp['duration'] > 0.0 ? min(1.0, $ramp['elapsed'] / $ramp['duration']) : 1.0;
            $this->duckDb[$channelValue] = $ramp['from'] + ($ramp['to'] - $ramp['from']) * $t;
            if ($t >= 1.0) {
                $this->duckDb[$channelValue] = $ramp['to'];
                unset($this->duckRamps[$channelValue]);
            } else {
                $this->duckRamps[$channelValue] = $ramp;
            }
            $this->updateActivePlaybacks(AudioChannel::from($channelValue));
        }
    }

    private function retargetDuck(AudioChannel $channel, float $fadeSeconds): void
    {
        $current = $this->getChannelDuck($channel);
        $target = $this->getChannelDuckTarget($channel);

        if ($fadeSeconds <= 0.0 || $current === $target) {
            unset($this->duckRamps[$channel->value]);
            if ($current !== $target || !isset($this->duckDb[$channel->value])) {
                $this->duckDb[$channel->value] = $target;
                $this->updateActivePlaybacks($channel);
            }
            return;
        }
        $this->duckRamps[$channel->value] = [
            'from' => $current,
            'to' => $target,
            'duration' => $fadeSeconds,
            'elapsed' => 0.0,
        ];
    }

    private static function dbToGain(float $db): float
    {
        if ($db <= self::DUCK_FLOOR_DB) {
            return 0.0;
        }
        return $db >= 0.0 ? 1.0 : 10.0 ** ($db / 20.0);
    }

    /**
     * Check whether a playback is still playing.
     */
    public function isPlaying(int $playbackId): bool
    {
        return $this->backend->isPlaying($playbackId);
    }

    /**
     * Number of playbacks the manager still tracks for channel/master
     * updates. Finished voices are swept lazily, so this may briefly include
     * voices that have already ended.
     */
    public function getTrackedPlaybackCount(): int
    {
        return count($this->playbackChannels);
    }

    /**
     * Dispose of all resources.
     */
    public function dispose(): void
    {
        $this->stopAll();
        $this->clips = [];
        $this->backend->dispose();
    }

    private function calculateEffectiveVolume(AudioChannel $channel, float $baseVolume): float
    {
        if ($this->channelMuted[$channel->value] || $this->channelMuted[AudioChannel::Master->value]) {
            return 0.0;
        }

        $masterVol = $this->channelVolumes[AudioChannel::Master->value];
        $channelVol = $channel === AudioChannel::Master
            ? 1.0
            : $this->channelVolumes[$channel->value];

        // Ducking is its own gain stage on top of the player's volumes.
        $duck = self::dbToGain($this->duckDb[AudioChannel::Master->value] ?? 0.0);
        if ($channel !== AudioChannel::Master) {
            $duck *= self::dbToGain($this->duckDb[$channel->value] ?? 0.0);
        }

        return $baseVolume * $channelVol * $masterVol * $duck;
    }

    private function updateActivePlaybacks(AudioChannel $channel): void
    {
        foreach ($this->playbackChannels as $playbackId => $ch) {
            if ($ch !== $channel && $channel !== AudioChannel::Master) {
                continue;
            }
            if (!$this->backend->isPlaying($playbackId)) {
                $this->forgetPlayback($playbackId);
                continue;
            }
            $voice = $this->playbackVolumes[$playbackId] ?? 1.0;
            $this->backend->setVolume($playbackId, $this->calculateEffectiveVolume($ch, $voice));
        }
    }

    /**
     * Drop bookkeeping for voices the backend reports as finished.
     */
    private function pruneFinishedPlaybacks(): void
    {
        foreach ($this->playbackChannels as $playbackId => $_) {
            if (!$this->backend->isPlaying($playbackId)) {
                $this->forgetPlayback($playbackId);
            }
        }
    }

    private function forgetPlayback(int $playbackId): void
    {
        unset($this->playbackChannels[$playbackId], $this->playbackVolumes[$playbackId]);
    }
}
