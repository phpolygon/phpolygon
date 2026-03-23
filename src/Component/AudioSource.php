<?php

declare(strict_types=1);

namespace PHPolygon\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Category;
use PHPolygon\ECS\Attribute\Hidden;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Range;
use PHPolygon\ECS\Attribute\Serializable;

#[Serializable]
#[Category('Audio')]
class AudioSource extends AbstractComponent
{
    #[Property]
    public string $clipId;

    #[Property]
    #[Range(min: 0, max: 1)]
    public float $volume;

    #[Property]
    public bool $loop;

    #[Property]
    public bool $playOnAwake;

    #[Hidden]
    public int $playbackId = 0;

    #[Hidden]
    public bool $playing = false;

    public function __construct(
        string $clipId = '',
        float $volume = 1.0,
        bool $loop = false,
        bool $playOnAwake = false,
    ) {
        $this->clipId = $clipId;
        $this->volume = $volume;
        $this->loop = $loop;
        $this->playOnAwake = $playOnAwake;
    }
}
