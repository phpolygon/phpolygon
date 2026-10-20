<?php

declare(strict_types=1);

namespace PHPolygon\Audio;

use PHPolygon\Audio\Spatial\Attenuation;
use PHPolygon\Audio\Spatial\AudioListener;
use PHPolygon\Math\Vec3;

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

    /**
     * 3D voices: world position, roll-off and the distance gain last computed.
     *
     * @var array<int, array{position: Vec3, attenuation: Attenuation, gain: float}>
     */
    private array $spatialPlaybacks = [];

    private AudioListener $listener;
    private Attenuation $defaultAttenuation;

    /** @var array<int, true> playbacks paused on their own */
    private array $pausedPlaybacks = [];

    /** @var array<string, true> paused channels (Master = every channel) */
    private array $pausedChannels = [];

    /**
     * Voices whose backend pause is in effect (natively, or emulated by muting
     * on a backend without {@see SpatialAudioBackendInterface}).
     *
     * @var array<int, true>
     */
    private array $heldPlaybacks = [];

    /** @var int|null Current music playback ID (only one music track at a time) */
    private ?int $currentMusicPlaybackId = null;
    private ?string $currentMusicClipId = null;

    public function __construct(?AudioBackendInterface $backend = null)
    {
        $this->backend = $backend ?? new NullAudioBackend();
        $this->listener = new AudioListener();
        $this->defaultAttenuation = new Attenuation();

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
        return $this->playWith($clipId, $channel, new PlaybackOptions($volume, $loop));
    }

    /**
     * Play a clip with pitch, pan, a world position and roll-off (see
     * {@see PlaybackOptions}). A positioned voice is attenuated by its
     * distance to the listener and panned by its direction; it follows
     * {@see setListener()} and {@see setPlaybackPosition()}.
     *
     * Pitch and pan need a {@see SpatialAudioBackendInterface} backend; other
     * backends play the clip without them. A voice started on a paused
     * channel starts paused.
     */
    public function playWith(string $clipId, AudioChannel $channel, PlaybackOptions $options): int
    {
        $spatial = null;
        $direction = null;
        if ($options->position !== null) {
            $attenuation = $options->attenuation ?? $this->defaultAttenuation;
            [$gain, $direction] = $this->spatialize($options->position, $attenuation);
            $spatial = [
                'position' => new Vec3($options->position->x, $options->position->y, $options->position->z),
                'attenuation' => $attenuation,
                'gain' => $gain,
            ];
        }

        $nativePause = $this->backend instanceof SpatialAudioBackendInterface;
        $startPaused = $this->isChannelPausedFor($channel);
        // A native pause is set right after the start: start silent so
        // nothing leaks, the level is restored once the voice is held.
        $volume = $startPaused && $nativePause
            ? 0.0
            : $this->calculateEffectiveVolume($channel, $options->volume) * ($spatial['gain'] ?? 1.0);
        $plain = $spatial === null && $options->pitch === 1.0 && $options->pan === 0.0;

        if ($this->backend instanceof SpatialAudioBackendInterface && !$plain) {
            $playbackId = $this->backend->playVoice(
                $clipId,
                $volume,
                $options->loop,
                $options->pitch,
                $spatial === null ? $options->pan : 0.0,
                $direction,
            );
        } else {
            $playbackId = $this->backend->play($clipId, $volume, $options->loop);
        }
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
        $this->playbackVolumes[$playbackId] = $options->volume;
        if ($spatial !== null) {
            $this->spatialPlaybacks[$playbackId] = $spatial;
        }
        if ($startPaused) {
            $this->syncPause($playbackId);
            if ($nativePause) {
                $this->applyVolume($playbackId);
            }
        }

        return $playbackId;
    }

    /**
     * Play a clip as a 3D source at $position.
     */
    public function playAt(
        string $clipId,
        Vec3 $position,
        AudioChannel $channel = AudioChannel::SFX,
        float $volume = 1.0,
        bool $loop = false,
        ?Attenuation $attenuation = null,
        float $pitch = 1.0,
    ): int {
        return $this->playWith($clipId, $channel, PlaybackOptions::at($position, $volume, $loop, $attenuation, $pitch));
    }

    // ---- Listener and 3D sources ----------------------------------------

    /**
     * Move the ears. Every 3D voice is re-attenuated and re-panned at once.
     * {@see AudioListener::fromActiveCamera()} and
     * {@see \PHPolygon\System\AudioListenerSystem} take the pose from the
     * active camera.
     */
    public function setListener(AudioListener $listener): void
    {
        $this->listener = $listener;
        foreach ($this->spatialPlaybacks as $playbackId => $_) {
            $this->updateSpatial($playbackId);
        }
    }

    public function getListener(): AudioListener
    {
        return $this->listener;
    }

    /** Roll-off for 3D voices started without their own. */
    public function setDefaultAttenuation(Attenuation $attenuation): void
    {
        $this->defaultAttenuation = $attenuation;
    }

    public function getDefaultAttenuation(): Attenuation
    {
        return $this->defaultAttenuation;
    }

    /**
     * Move a 3D voice (a moving source) – call it whenever the source moves.
     * A voice started without a position becomes a 3D voice with the
     * default roll-off.
     */
    public function setPlaybackPosition(int $playbackId, Vec3 $position): void
    {
        if (!isset($this->playbackChannels[$playbackId])) {
            return;
        }
        $copy = new Vec3($position->x, $position->y, $position->z);
        if (isset($this->spatialPlaybacks[$playbackId])) {
            $this->spatialPlaybacks[$playbackId]['position'] = $copy;
        } else {
            $this->spatialPlaybacks[$playbackId] = [
                'position' => $copy,
                'attenuation' => $this->defaultAttenuation,
                'gain' => 1.0,
            ];
        }
        $this->updateSpatial($playbackId);
    }

    public function getPlaybackPosition(int $playbackId): ?Vec3
    {
        $p = $this->spatialPlaybacks[$playbackId]['position'] ?? null;
        return $p !== null ? new Vec3($p->x, $p->y, $p->z) : null;
    }

    public function setPlaybackAttenuation(int $playbackId, Attenuation $attenuation): void
    {
        if (!isset($this->spatialPlaybacks[$playbackId])) {
            return;
        }
        $this->spatialPlaybacks[$playbackId]['attenuation'] = $attenuation;
        $this->updateSpatial($playbackId);
    }

    /** Distance gain of a voice right now (1.0 for unpositioned voices). */
    public function getPlaybackDistanceGain(int $playbackId): float
    {
        return $this->spatialPlaybacks[$playbackId]['gain'] ?? 1.0;
    }

    /** True when the voice is a 3D source. */
    public function isSpatial(int $playbackId): bool
    {
        return isset($this->spatialPlaybacks[$playbackId]);
    }

    // ---- Pause -----------------------------------------------------------

    /**
     * Pause one voice. Pauses stack: a voice plays again only when neither it,
     * its channel nor Master is paused.
     */
    public function pause(int $playbackId): void
    {
        if (!isset($this->playbackChannels[$playbackId])) {
            return;
        }
        $this->pausedPlaybacks[$playbackId] = true;
        $this->syncPause($playbackId);
    }

    public function resume(int $playbackId): void
    {
        if (!isset($this->pausedPlaybacks[$playbackId])) {
            return;
        }
        unset($this->pausedPlaybacks[$playbackId]);
        $this->syncPause($playbackId);
    }

    /** True when the voice is held – by itself, its channel or Master. */
    public function isPaused(int $playbackId): bool
    {
        return isset($this->heldPlaybacks[$playbackId]);
    }

    /** Pause a channel; Master pauses every channel. Voices started on it start paused. */
    public function pauseChannel(AudioChannel $channel): void
    {
        $this->pausedChannels[$channel->value] = true;
        $this->syncChannelPause($channel);
    }

    public function resumeChannel(AudioChannel $channel): void
    {
        if (!isset($this->pausedChannels[$channel->value])) {
            return;
        }
        unset($this->pausedChannels[$channel->value]);
        $this->syncChannelPause($channel);
    }

    public function isChannelPaused(AudioChannel $channel): bool
    {
        return isset($this->pausedChannels[$channel->value]);
    }

    /**
     * Pause every channel except $keep (e.g. keep UI for a pause menu).
     */
    public function pauseAll(AudioChannel ...$keep): void
    {
        foreach (AudioChannel::cases() as $channel) {
            if ($channel === AudioChannel::Master || in_array($channel, $keep, true)) {
                continue;
            }
            $this->pausedChannels[$channel->value] = true;
        }
        foreach ($this->playbackChannels as $playbackId => $_) {
            $this->syncPause($playbackId);
        }
    }

    /** Lift every channel pause (Master included). Voices paused on their own stay paused. */
    public function resumeAll(): void
    {
        $this->pausedChannels = [];
        foreach ($this->playbackChannels as $playbackId => $_) {
            $this->syncPause($playbackId);
        }
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
        if (isset($this->playbackChannels[$playbackId])) {
            $this->applyVolume($playbackId);
            return;
        }
        $this->backend->setVolume($playbackId, $this->calculateEffectiveVolume($channel, $volume));
    }

    /**
     * Stop a specific playback.
     */
    public function stop(int $playbackId): void
    {
        $this->backend->stop($playbackId);
        $this->forgetPlayback($playbackId);
    }

    /**
     * Stop all audio on a specific channel.
     */
    public function stopChannel(AudioChannel $channel): void
    {
        foreach ($this->playbackChannels as $playbackId => $ch) {
            if ($ch === $channel) {
                $this->backend->stop($playbackId);
                $this->forgetPlayback($playbackId);
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
        $this->spatialPlaybacks = [];
        $this->pausedPlaybacks = [];
        $this->heldPlaybacks = [];
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
            $this->applyVolume($playbackId);
        }
    }

    /**
     * Push a tracked voice's full volume (voice × channel × master × ducks ×
     * distance) to the backend; 0 while a pause is emulated by muting.
     */
    private function applyVolume(int $playbackId): void
    {
        $channel = $this->playbackChannels[$playbackId] ?? null;
        if ($channel === null) {
            return;
        }
        if (isset($this->heldPlaybacks[$playbackId]) && !$this->backend instanceof SpatialAudioBackendInterface) {
            $this->backend->setVolume($playbackId, 0.0);
            return;
        }
        $voice = $this->playbackVolumes[$playbackId] ?? 1.0;
        $gain = $this->spatialPlaybacks[$playbackId]['gain'] ?? 1.0;
        $this->backend->setVolume($playbackId, $this->calculateEffectiveVolume($channel, $voice) * $gain);
    }

    /**
     * Distance gain and listener-space unit direction of a world position.
     *
     * @return array{float, array{float, float, float}}
     */
    private function spatialize(Vec3 $position, Attenuation $attenuation): array
    {
        $local = $this->listener->toLocal($position);
        $distance = $local->length();
        $gain = $attenuation->gain($distance);
        if ($distance <= 1e-6) {
            return [$gain, [0.0, 0.0, 0.0]];
        }
        return [$gain, [$local->x / $distance, $local->y / $distance, $local->z / $distance]];
    }

    private function updateSpatial(int $playbackId): void
    {
        $spatial = $this->spatialPlaybacks[$playbackId] ?? null;
        if ($spatial === null) {
            return;
        }
        [$gain, $direction] = $this->spatialize($spatial['position'], $spatial['attenuation']);
        $this->spatialPlaybacks[$playbackId]['gain'] = $gain;
        if ($this->backend instanceof SpatialAudioBackendInterface) {
            $this->backend->setDirection($playbackId, $direction[0], $direction[1], $direction[2]);
        }
        $this->applyVolume($playbackId);
    }

    private function isChannelPausedFor(AudioChannel $channel): bool
    {
        return isset($this->pausedChannels[$channel->value]) || isset($this->pausedChannels[AudioChannel::Master->value]);
    }

    private function syncChannelPause(AudioChannel $channel): void
    {
        foreach ($this->playbackChannels as $playbackId => $ch) {
            if ($ch === $channel || $channel === AudioChannel::Master) {
                $this->syncPause($playbackId);
            }
        }
    }

    /** Bring the backend's pause state of a voice in line with its pause reasons. */
    private function syncPause(int $playbackId): void
    {
        $channel = $this->playbackChannels[$playbackId] ?? null;
        if ($channel === null) {
            return;
        }
        $want = isset($this->pausedPlaybacks[$playbackId]) || $this->isChannelPausedFor($channel);
        $held = isset($this->heldPlaybacks[$playbackId]);
        if ($want === $held) {
            return;
        }
        if ($want) {
            $this->heldPlaybacks[$playbackId] = true;
        } else {
            unset($this->heldPlaybacks[$playbackId]);
        }
        if ($this->backend instanceof SpatialAudioBackendInterface) {
            if ($want) {
                $this->backend->pause($playbackId);
            } else {
                $this->backend->resume($playbackId);
            }
            return;
        }
        $this->applyVolume($playbackId);
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
        unset(
            $this->playbackChannels[$playbackId],
            $this->playbackVolumes[$playbackId],
            $this->spatialPlaybacks[$playbackId],
            $this->pausedPlaybacks[$playbackId],
            $this->heldPlaybacks[$playbackId],
        );
    }
}
