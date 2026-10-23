<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Audio\AudioManager;
use PHPolygon\Audio\Spatial\AudioListener;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;

/**
 * Puts the audio listener on the active 3D camera every update tick, so 3D
 * voices pan and fade with the view. Opt-in: a game adds it when it plays
 * positioned sounds; without an active camera the listener stays put.
 * The camera's velocity (Doppler) is derived by AudioManager from the pose
 * change between ticks; on php-vio ≥ 2.36 the native listener follows.
 */
class AudioListenerSystem extends AbstractSystem
{
    public function __construct(private readonly AudioManager $audio) {}

    public function update(World $world, float $dt): void
    {
        $listener = AudioListener::fromActiveCamera($world);
        if ($listener !== null) {
            $this->audio->setListener($listener);
        }
    }
}
