<?php

declare(strict_types=1);

namespace PHPolygon\Audio;

use InvalidArgumentException;
use PHPolygon\Audio\Spatial\Attenuation;
use PHPolygon\Audio\Spatial\AttenuationCurve;
use PHPolygon\Audio\Spatial\AudioListener;
use PHPolygon\Audio\Spatial\SoundCone;
use PHPolygon\Audio\Spatial\SpatialSource;
use PHPolygon\Math\Vec3;

class AudioManager
{
    /** Default speed of sound (metres per second – scale it when a world unit is not a metre). */
    public const float SPEED_OF_SOUND = 343.3;

    /**
     * A derived velocity at or above this fraction of the speed of sound is a
     * teleport, not motion: it counts as standing still.
     */
    public const float TELEPORT_SPEED_FRACTION = 0.5;

    /**
     * Position updates further apart than this (seconds) are not continuous
     * motion: no velocity is derived from them.
     */
    public const float MAX_VELOCITY_GAP = 0.25;

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
     * 3D voices: world position, roll-off, the distance gain last computed
     * (gain) and the factor the voice volume carries for it (level: distance ×
     * cone on the fallback path, only the out-of-range cut on the native one),
     * velocity (auto = derived from position changes, movedAt = manager clock
     * of the last derived step, moved = position set since the last update()),
     * Doppler factor, facing and cone.
     *
     * @var array<int, array{position: Vec3, attenuation: Attenuation, gain: float, level: float,
     *     velocity: Vec3, auto: bool, movedAt: float, moved: bool, doppler: float,
     *     direction: ?Vec3, cone: ?SoundCone}>
     */
    private array $spatialPlaybacks = [];

    /** @var array<int, float> playbackId => pitch */
    private array $playbackPitches = [];

    /** @var array<int, float> playbackId => pan */
    private array $playbackPans = [];

    private AudioListener $listener;
    private Attenuation $defaultAttenuation;

    /** True when the backend does the 3D work itself ({@see NativeSpatialAudioBackendInterface}). */
    private bool $native;

    private bool $dopplerEnabled = true;
    private float $speedOfSound = self::SPEED_OF_SOUND;

    /** Seconds advanced through update() – the time base for derived velocities. */
    private float $clock = 0.0;

    /** Listener velocity is derived (the listener came without one). */
    private bool $listenerAuto = true;
    private bool $listenerMoved = false;
    private float $listenerMovedAt = -INF;

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
        $this->listener = new AudioListener(velocity: new Vec3());
        $this->defaultAttenuation = new Attenuation();
        $this->native = $this->backend instanceof NativeSpatialAudioBackendInterface
            && $this->backend->hasNativeSpatial();

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
     * True when the backend places 3D voices itself (php-vio ≥ 2.36): native
     * listener, attenuation, Doppler, cones, live pitch/pan. False = the
     * fallback path – distance and cone gain computed here, listener-space
     * directions, pitch/pan fixed at the start, no Doppler.
     */
    public function hasNativeSpatial(): bool
    {
        return $this->native;
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
     * backends play the clip without them. Velocity, Doppler and the cone's
     * native shaping need a native spatial backend
     * ({@see hasNativeSpatial()}); on the fallback path the cone is applied
     * through the volume and there is no Doppler. A voice started on a paused
     * channel starts paused.
     */
    public function playWith(string $clipId, AudioChannel $channel, PlaybackOptions $options): int
    {
        $spatial = null;
        $direction = null;
        if ($options->position !== null) {
            $spatial = [
                'position' => self::copy($options->position),
                'attenuation' => $options->attenuation ?? $this->defaultAttenuation,
                'gain' => 1.0,
                'level' => 1.0,
                'velocity' => $options->velocity !== null ? self::copy($options->velocity) : new Vec3(),
                'auto' => $options->velocity === null,
                'movedAt' => $this->clock,
                'moved' => false,
                'doppler' => $options->doppler,
                'direction' => $options->direction !== null ? self::copy($options->direction) : null,
                'cone' => $options->cone,
            ];
            $direction = $this->measure($spatial);
        }

        $nativePause = $this->backend instanceof SpatialAudioBackendInterface;
        $startPaused = $this->isChannelPausedFor($channel);
        // A native pause is set right after the start: start silent so
        // nothing leaks, the level is restored once the voice is held.
        $volume = $startPaused && $nativePause
            ? 0.0
            : $this->calculateEffectiveVolume($channel, $options->volume) * ($spatial['level'] ?? 1.0);
        $plain = $spatial === null && $options->pitch === 1.0 && $options->pan === 0.0;

        if ($spatial !== null && $this->native && $this->backend instanceof NativeSpatialAudioBackendInterface) {
            $playbackId = $this->backend->playSource($clipId, $volume, $options->loop, $options->pitch, $this->sourceOf($spatial));
        } elseif ($this->backend instanceof SpatialAudioBackendInterface && !$plain) {
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
        $this->playbackPitches[$playbackId] = $options->pitch;
        $this->playbackPans[$playbackId] = $spatial === null ? max(-1.0, min(1.0, $options->pan)) : 0.0;
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
     *
     * A listener without a velocity gets one derived from the position change
     * since the previous call (over the time {@see update()} advanced; a jump
     * of half the speed of sound or more counts as a teleport); it falls back
     * to zero once the listener stops being moved. On a native backend the
     * native listener follows (position, facing, up, velocity).
     */
    public function setListener(AudioListener $listener): void
    {
        if ($listener->velocity === null) {
            $velocity = $this->listener->velocity ?? new Vec3();
            if (!$this->listenerAuto) {
                $velocity = new Vec3();
                $this->listenerMovedAt = $this->clock;
            }
            $elapsed = $this->clock - $this->listenerMovedAt;
            if ($elapsed > 0.0) {
                $velocity = $this->derivedVelocity($this->listener->position, $listener->position, $elapsed);
                $this->listenerMovedAt = $this->clock;
            }
            $this->listenerAuto = true;
            $this->listenerMoved = true;
            $listener = $listener->withVelocity($velocity);
        } else {
            $this->listenerAuto = false;
        }
        $this->listener = $listener;
        if ($this->native && $this->backend instanceof NativeSpatialAudioBackendInterface) {
            $this->backend->setListenerPose($listener);
        }
        foreach ($this->spatialPlaybacks as $playbackId => $_) {
            $this->updateSpatial($playbackId, false);
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
     *
     * $velocity (units/s) sets the Doppler velocity explicitly and keeps it
     * until the next explicit one; without it the velocity is derived from
     * the position change since the previous call (once per {@see update()}
     * tick; it falls back to zero when the source is not moved for a tick,
     * after a gap over MAX_VELOCITY_GAP, or on a teleport).
     */
    public function setPlaybackPosition(int $playbackId, Vec3 $position, ?Vec3 $velocity = null): void
    {
        if (!isset($this->playbackChannels[$playbackId])) {
            return;
        }
        $copy = self::copy($position);
        $s = $this->spatialPlaybacks[$playbackId] ?? null;
        if ($s === null) {
            $s = [
                'position' => $copy,
                'attenuation' => $this->defaultAttenuation,
                'gain' => 1.0,
                'level' => 1.0,
                'velocity' => new Vec3(),
                'auto' => true,
                'movedAt' => $this->clock,
                'moved' => false,
                'doppler' => 1.0,
                'direction' => null,
                'cone' => null,
            ];
        }
        if ($velocity !== null) {
            $s['velocity'] = self::copy($velocity);
            $s['auto'] = false;
        } elseif ($s['auto']) {
            $elapsed = $this->clock - $s['movedAt'];
            if ($elapsed > 0.0) {
                $s['velocity'] = $this->derivedVelocity($s['position'], $copy, $elapsed);
                $s['movedAt'] = $this->clock;
            }
            $s['moved'] = true;
        }
        $s['position'] = $copy;
        $this->spatialPlaybacks[$playbackId] = $s;
        $this->updateSpatial($playbackId, true);
    }

    /**
     * Doppler velocity of a 3D voice in units/s, explicitly; null hands it back
     * to the derivation from position changes (starting from rest).
     */
    public function setPlaybackVelocity(int $playbackId, ?Vec3 $velocity): void
    {
        if (!isset($this->spatialPlaybacks[$playbackId])) {
            return;
        }
        $this->spatialPlaybacks[$playbackId]['velocity'] = $velocity !== null ? self::copy($velocity) : new Vec3();
        $this->spatialPlaybacks[$playbackId]['auto'] = $velocity === null;
        $this->spatialPlaybacks[$playbackId]['movedAt'] = $this->clock;
        $this->updateSpatial($playbackId, true);
    }

    /** Current Doppler velocity of a 3D voice (null for 2D voices). */
    public function getPlaybackVelocity(int $playbackId): ?Vec3
    {
        $v = $this->spatialPlaybacks[$playbackId]['velocity'] ?? null;
        return $v !== null ? self::copy($v) : null;
    }

    /** Doppler factor of one 3D voice (0 = off, 1 = physical). */
    public function setPlaybackDoppler(int $playbackId, float $factor): void
    {
        if (!isset($this->spatialPlaybacks[$playbackId]) || !is_finite($factor) || $factor < 0.0) {
            return;
        }
        $this->spatialPlaybacks[$playbackId]['doppler'] = $factor;
        $this->updateSpatial($playbackId, true);
    }

    /**
     * Facing of a directional 3D voice and its cone (null cone = keep the
     * current one). Without a cone the facing has no audible effect.
     */
    public function setPlaybackDirection(int $playbackId, ?Vec3 $direction, ?SoundCone $cone = null): void
    {
        if (!isset($this->spatialPlaybacks[$playbackId])) {
            return;
        }
        $this->spatialPlaybacks[$playbackId]['direction'] = $direction !== null ? self::copy($direction) : null;
        if ($cone !== null) {
            $this->spatialPlaybacks[$playbackId]['cone'] = $cone;
        }
        $this->updateSpatial($playbackId, true);
    }

    /** Replace (or with null remove) the cone of a 3D voice. */
    public function setPlaybackCone(int $playbackId, ?SoundCone $cone): void
    {
        if (!isset($this->spatialPlaybacks[$playbackId])) {
            return;
        }
        $this->spatialPlaybacks[$playbackId]['cone'] = $cone;
        $this->updateSpatial($playbackId, true);
    }

    /**
     * Playback rate of a running voice (> 0; other values are ignored). Takes
     * effect at once on a native spatial backend; on the fallback path the
     * voice keeps its start pitch (the value is still remembered).
     */
    public function setPlaybackPitch(int $playbackId, float $pitch): void
    {
        if (!isset($this->playbackChannels[$playbackId]) || !is_finite($pitch) || $pitch <= 0.0) {
            return;
        }
        $this->playbackPitches[$playbackId] = $pitch;
        if ($this->native && $this->backend instanceof NativeSpatialAudioBackendInterface) {
            $this->backend->setPitch($playbackId, $pitch);
        }
    }

    public function getPlaybackPitch(int $playbackId): ?float
    {
        return $this->playbackPitches[$playbackId] ?? null;
    }

    /**
     * Stereo pan (−1…+1) of a running 2D voice; 3D voices pan by direction and
     * ignore it. Live on a native spatial backend, start-only otherwise.
     */
    public function setPlaybackPan(int $playbackId, float $pan): void
    {
        if (!isset($this->playbackChannels[$playbackId]) || isset($this->spatialPlaybacks[$playbackId]) || is_nan($pan)) {
            return;
        }
        $pan = max(-1.0, min(1.0, $pan));
        $this->playbackPans[$playbackId] = $pan;
        if ($this->native && $this->backend instanceof NativeSpatialAudioBackendInterface) {
            $this->backend->setPan($playbackId, $pan);
        }
    }

    public function getPlaybackPan(int $playbackId): ?float
    {
        return $this->playbackPans[$playbackId] ?? null;
    }

    /**
     * Doppler on or off for every voice (each keeps its own factor). Only a
     * native spatial backend shifts pitch by motion.
     */
    public function setDopplerEnabled(bool $enabled): void
    {
        if ($this->dopplerEnabled === $enabled) {
            return;
        }
        $this->dopplerEnabled = $enabled;
        foreach ($this->spatialPlaybacks as $playbackId => $_) {
            $this->updateSpatial($playbackId, true);
        }
    }

    public function isDopplerEnabled(): bool
    {
        return $this->dopplerEnabled;
    }

    /**
     * Speed of sound in world units per second (default {@see SPEED_OF_SOUND},
     * metres). Sets the Doppler strength and the teleport threshold of
     * derived velocities.
     */
    public function setSpeedOfSound(float $speed): void
    {
        if (!is_finite($speed) || $speed <= 0.0) {
            throw new InvalidArgumentException("AudioManager: speed of sound must be > 0, got {$speed}");
        }
        $this->speedOfSound = $speed;
        if ($this->native && $this->backend instanceof NativeSpatialAudioBackendInterface) {
            $this->backend->setSpeedOfSound($speed);
        }
    }

    public function getSpeedOfSound(): float
    {
        return $this->speedOfSound;
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
        $this->updateSpatial($playbackId, true);
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
        $this->playbackPitches = [];
        $this->playbackPans = [];
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
        $this->clock += max(0.0, $dt);
        $this->settleVelocities();
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
        $level = $this->spatialPlaybacks[$playbackId]['level'] ?? 1.0;
        $this->backend->setVolume($playbackId, $this->calculateEffectiveVolume($channel, $voice) * $level);
    }

    /**
     * Re-measure a 3D voice after the listener ($sourceChanged = false) or the
     * source itself moved or changed, and push it to the backend.
     */
    private function updateSpatial(int $playbackId, bool $sourceChanged): void
    {
        $spatial = $this->spatialPlaybacks[$playbackId] ?? null;
        if ($spatial === null) {
            return;
        }
        $level = $spatial['level'];
        $direction = $this->measure($spatial);
        $this->spatialPlaybacks[$playbackId] = $spatial;
        if ($this->native && $this->backend instanceof NativeSpatialAudioBackendInterface) {
            if ($sourceChanged) {
                $this->backend->updateSource($playbackId, $this->sourceOf($spatial));
            } elseif ($level === $spatial['level']) {
                return; // the backend follows the listener on its own
            }
        } elseif ($this->backend instanceof SpatialAudioBackendInterface) {
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
            $this->playbackPitches[$playbackId],
            $this->playbackPans[$playbackId],
            $this->pausedPlaybacks[$playbackId],
            $this->heldPlaybacks[$playbackId],
        );
    }

    private static function copy(Vec3 $v): Vec3
    {
        return new Vec3($v->x, $v->y, $v->z);
    }

    /**
     * Velocity of a move from $from to $to over $elapsed seconds: zero for a
     * gap (not continuous motion) or a teleport.
     */
    private function derivedVelocity(Vec3 $from, Vec3 $to, float $elapsed): Vec3
    {
        if ($elapsed <= 0.0 || $elapsed > self::MAX_VELOCITY_GAP) {
            return new Vec3();
        }
        $v = $to->sub($from)->div($elapsed);
        if ($v->length() >= self::TELEPORT_SPEED_FRACTION * $this->speedOfSound) {
            return new Vec3();
        }
        return $v;
    }

    /**
     * Once per tick: derived velocities of anything that was not moved since
     * the previous tick fall back to zero (a source that stopped would
     * otherwise keep its last Doppler shift).
     */
    private function settleVelocities(): void
    {
        foreach ($this->spatialPlaybacks as $playbackId => $s) {
            if (!$s['auto']) {
                continue;
            }
            if ($s['moved']) {
                $this->spatialPlaybacks[$playbackId]['moved'] = false;
                continue;
            }
            if ($s['velocity']->lengthSquared() > 0.0) {
                $this->spatialPlaybacks[$playbackId]['velocity'] = new Vec3();
                $this->spatialPlaybacks[$playbackId]['movedAt'] = $this->clock;
                $this->updateSpatial($playbackId, true);
            }
        }
        if ($this->listenerAuto) {
            if ($this->listenerMoved) {
                $this->listenerMoved = false;
            } elseif (($this->listener->velocity?->lengthSquared() ?? 0.0) > 0.0) {
                $this->listenerMovedAt = $this->clock;
                $this->listener = $this->listener->withVelocity(new Vec3());
                if ($this->native && $this->backend instanceof NativeSpatialAudioBackendInterface) {
                    $this->backend->setListenerPose($this->listener);
                }
            }
        }
    }

    /**
     * Recompute a 3D voice's distance gain and volume level; returns its
     * listener-space unit direction ((0, 0, 0) when on top of the listener).
     *
     * @param array{position: Vec3, attenuation: Attenuation, gain: float, level: float,
     *     velocity: Vec3, auto: bool, movedAt: float, moved: bool, doppler: float,
     *     direction: ?Vec3, cone: ?SoundCone} $s
     * @param-out array{position: Vec3, attenuation: Attenuation, gain: float, level: float,
     *     velocity: Vec3, auto: bool, movedAt: float, moved: bool, doppler: float,
     *     direction: ?Vec3, cone: ?SoundCone} $s
     * @return array{float, float, float}
     */
    private function measure(array &$s): array
    {
        $local = $this->listener->toLocal($s['position']);
        $distance = $local->length();
        $attenuation = $s['attenuation'];
        if ($this->native) {
            // The backend attenuates and cones; only silence what is out of range.
            $s['gain'] = $attenuation->nativeGain($distance);
            $s['level'] = $attenuation->curve === AttenuationCurve::None || $distance < $attenuation->maxDistance ? 1.0 : 0.0;
        } else {
            $s['gain'] = $attenuation->gain($distance);
            $cone = $s['cone'] !== null && $s['direction'] !== null
                ? $s['cone']->gain($s['direction'], $this->listener->position->sub($s['position']))
                : 1.0;
            $s['level'] = $s['gain'] * $cone;
        }
        if ($distance <= 1e-6) {
            return [0.0, 0.0, 0.0];
        }
        return [$local->x / $distance, $local->y / $distance, $local->z / $distance];
    }

    /**
     * @param array{position: Vec3, attenuation: Attenuation, gain: float, level: float,
     *     velocity: Vec3, auto: bool, movedAt: float, moved: bool, doppler: float,
     *     direction: ?Vec3, cone: ?SoundCone} $s
     */
    private function sourceOf(array $s): SpatialSource
    {
        return new SpatialSource(
            $s['position'],
            $s['velocity'],
            $s['attenuation'],
            $this->dopplerEnabled ? $s['doppler'] : 0.0,
            $s['direction'],
            $s['cone'],
        );
    }
}
