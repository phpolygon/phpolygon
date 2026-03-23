<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Audio\AudioBackendInterface;
use PHPolygon\Audio\NullAudioBackend;
use PHPolygon\Component\AudioSource;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\Entity;
use PHPolygon\ECS\World;

class AudioSystem extends AbstractSystem
{
    private AudioBackendInterface $backend;

    public function __construct(?AudioBackendInterface $backend = null)
    {
        $this->backend = $backend ?? new NullAudioBackend();
    }

    public function getBackend(): AudioBackendInterface
    {
        return $this->backend;
    }

    public function register(World $world): void
    {
        // Start playOnAwake sources
        foreach ($world->query(AudioSource::class) as $entity) {
            $source = $world->getComponent($entity->id, AudioSource::class);
            if ($source->playOnAwake && !$source->playing && $source->clipId !== '') {
                $this->play($source);
            }
        }
    }

    public function update(World $world, float $dt): void
    {
        foreach ($world->query(AudioSource::class) as $entity) {
            $source = $world->getComponent($entity->id, AudioSource::class);

            // Check if playback has finished
            if ($source->playing && $source->playbackId > 0) {
                if (!$this->backend->isPlaying($source->playbackId)) {
                    $source->playing = false;
                    $source->playbackId = 0;
                }
            }
        }
    }

    public function unregister(World $world): void
    {
        $this->backend->stopAll();
    }

    public function play(AudioSource $source): void
    {
        if ($source->clipId === '') {
            return;
        }
        $source->playbackId = $this->backend->play($source->clipId, $source->volume, $source->loop);
        $source->playing = true;
    }

    public function stop(AudioSource $source): void
    {
        if ($source->playbackId > 0) {
            $this->backend->stop($source->playbackId);
            $source->playing = false;
            $source->playbackId = 0;
        }
    }
}
