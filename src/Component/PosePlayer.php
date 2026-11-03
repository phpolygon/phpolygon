<?php

declare(strict_types=1);

namespace PHPolygon\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Category;
use PHPolygon\ECS\Attribute\Hidden;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Serializable;

/**
 * Plays {@see \PHPolygon\Animation\PoseClip}s on a figure: put it on the figure's root,
 * name the {@see \PHPolygon\Animation\PoseRig} and bind each joint to the part entity
 * it moves (a child of the root, mesh origin at the joint). Driven by
 * {@see \PHPolygon\System\PosePlayerSystem}, which writes the parts' local position and
 * rotation every frame the pose changes.
 *
 * - {@see play()} switches clips with a crossfade; {@see $speed} scales the clip's time
 *   (a walk cycle at the figure's walking speed).
 * - {@see playIdle()} plays a set of idle variants: after every completed cycle the
 *   next variant is picked from {@see $seed} and the cycle count (the same figure does
 *   the same thing on every run) and faded in over {@see $idleBlend} seconds.
 * - A clip that does not loop holds its last pose; {@see isFinished()} says so.
 *
 * {@see $effect}, {@see $cullRadius} and {@see $maxDistance} work as on
 * {@see SignalTrack}: a register entry for reduced motion, and freezing while the
 * figure is out of view (its parts keep the last pose).
 */
#[Serializable]
#[Category('Animation')]
class PosePlayer extends AbstractComponent
{
    /** Id of the rig in {@see \PHPolygon\Animation\PoseLibrary}. */
    #[Property]
    public string $rig;

    /** Id of the clip playing now ('' = none). */
    #[Property]
    public string $clip = '';

    #[Property]
    public float $speed = 1.0;

    /** @var array<string, int> joint name => part entity id */
    #[Property]
    public array $bindings = [];

    /** @var list<string> idle variants while in idle mode */
    #[Property]
    public array $idleClips = [];

    #[Property]
    public int $seed = 0;

    #[Property]
    public float $idleBlend = 0.4;

    #[Property]
    public ?string $effect = null;

    #[Property]
    public float $cullRadius = 0.0;

    #[Property]
    public float $maxDistance = 0.0;

    /** Seconds into the current clip (unwrapped). */
    #[Hidden]
    public float $time = 0.0;

    /** Clip being faded out ('' = no crossfade running). */
    #[Hidden]
    public string $previousClip = '';

    #[Hidden]
    public float $previousTime = 0.0;

    #[Hidden]
    public float $blendDuration = 0.0;

    #[Hidden]
    public float $blendElapsed = 0.0;

    #[Hidden]
    public bool $finished = false;

    #[Hidden]
    public bool $idle = false;

    #[Hidden]
    public int $idleCycles = 0;

    /** Whether the parts show the current state (false after a change). */
    #[Hidden]
    public bool $posed = false;

    /**
     * @param array<string, int> $bindings
     */
    public function __construct(string $rig = '', array $bindings = [], string $clip = '', int $seed = 0)
    {
        $this->rig = $rig;
        $this->bindings = $bindings;
        $this->clip = $clip;
        $this->seed = $seed;
    }

    public function bind(string $joint, int $entityId): void
    {
        $this->bindings[$joint] = $entityId;
    }

    /**
     * Play $clip, fading over $blend seconds from what played before. The clip already
     * playing keeps running (only $speed changes) unless $restart.
     */
    public function play(string $clip, float $blend = 0.25, ?float $speed = null, bool $restart = false): void
    {
        $this->idle = false;
        if ($speed !== null) {
            $this->speed = $speed;
        }
        if ($clip === $this->clip && !$restart) {
            return;
        }
        $this->start($clip, $blend);
    }

    /**
     * Idle mode: loop through $clips (picked by seed and cycle), fading $blend seconds
     * between them. Keeps the current clip when it is one of them.
     *
     * @param list<string> $clips
     */
    public function playIdle(array $clips, ?float $blend = null): void
    {
        $this->idleClips = $clips;
        if ($blend !== null) {
            $this->idleBlend = $blend;
        }
        $this->speed = 1.0;
        if ($this->idle && in_array($this->clip, $clips, true)) {
            return;
        }
        $this->idle = true;
        if ($clips !== [] && !in_array($this->clip, $clips, true)) {
            $this->start($this->idleVariant(), $this->idleBlend);
        }
    }

    /** The idle variant for the current cycle count. */
    public function idleVariant(): string
    {
        $n = count($this->idleClips);
        if ($n === 0) {
            return $this->clip;
        }
        return $this->idleClips[crc32($this->seed . ':' . $this->idleCycles) % $n];
    }

    public function isFinished(): bool
    {
        return $this->finished;
    }

    public function isBlending(): bool
    {
        return $this->previousClip !== '';
    }

    /** Switch to $clip now, crossfading over $blend seconds. */
    public function start(string $clip, float $blend): void
    {
        if ($blend > 0.0 && $this->clip !== '') {
            $this->previousClip = $this->clip;
            $this->previousTime = $this->time;
            $this->blendDuration = $blend;
            $this->blendElapsed = 0.0;
        } else {
            $this->previousClip = '';
        }
        $this->clip = $clip;
        $this->time = 0.0;
        $this->finished = false;
        $this->posed = false;
    }
}
