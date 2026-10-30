<?php

declare(strict_types=1);

namespace PHPolygon\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Category;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Range;
use PHPolygon\ECS\Attribute\Serializable;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\TextAlign;

/**
 * Text at a 3D world position, for a screen-space overlay: WorldTextSystem
 * projects the position and emits {@see \PHPolygon\Rendering\Command\DrawWorldText}.
 * It stays flat on the screen, on top of everything, and does not turn with
 * anything in the world. Text that belongs to a sign is a {@see SurfaceText}.
 */
#[Serializable]
#[Category('Rendering')]
class WorldText extends AbstractComponent
{
    #[Property]
    public string $text;

    #[Property(editorHint: 'slider')]
    #[Range(min: 4, max: 128)]
    public float $fontSize;

    #[Property(editorHint: 'color')]
    public Color $color;

    #[Property]
    public string $fontId;

    #[Property]
    public int $textAlign;

    /** Maximum render distance from camera. 0 = unlimited. */
    #[Property]
    public float $maxDistance;

    /** Whether to scale text based on distance (perspective feel). */
    #[Property]
    public bool $scaleWithDistance;

    /** Y offset in screen pixels above the projected position. */
    #[Property]
    public float $screenOffsetY;

    public function __construct(
        string $text = '',
        float $fontSize = 16.0,
        ?Color $color = null,
        string $fontId = '',
        int $textAlign = TextAlign::CENTER | TextAlign::BOTTOM,
        float $maxDistance = 0.0,
        bool $scaleWithDistance = true,
        float $screenOffsetY = 0.0,
    ) {
        $this->text = $text;
        $this->fontSize = $fontSize;
        $this->color = $color ?? Color::white();
        $this->fontId = $fontId;
        $this->textAlign = $textAlign;
        $this->maxDistance = $maxDistance;
        $this->scaleWithDistance = $scaleWithDistance;
        $this->screenOffsetY = $screenOffsetY;
    }
}
