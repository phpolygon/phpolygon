<?php

declare(strict_types=1);

namespace PHPolygon\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Category;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Range;
use PHPolygon\ECS\Attribute\Serializable;

/**
 * Makes the beam of a {@see SpotLight} on the same entity visible: the light the air
 * scatters toward the viewer inside the cone, added over the scene (a lamp in dusty
 * air, a stage light, a torch at night). It has no colour or brightness of its own -
 * {@see \PHPolygon\System\LightConeSystem} reads the spot light's colour, intensity,
 * angle, penumbra, range and position every frame, so whatever dims or switches the
 * light dims or switches the cone. Without a SpotLight it draws nothing.
 *
 * The beam fades softly where it meets geometry (needs a scene depth the frame can
 * read; without one it is cut hard by the depth test) and when seen along its axis.
 * Effect register entry {@see \PHPolygon\Rendering\LightCones::EFFECT}.
 */
#[Serializable]
#[Category('Lighting')]
class LightCone extends AbstractComponent
{
    /** How much light the air scatters, per metre (brightness of the beam relative to the light). */
    #[Property(editorHint: 'slider')]
    #[Range(min: 0, max: 2)]
    public float $density;

    /** Drawn part of the beam as a fraction of the spot light's range. */
    #[Property(editorHint: 'slider')]
    #[Range(min: 0, max: 1)]
    public float $length;

    /**
     * Soft edge of the visible beam, as a fraction of the cone angle (the spot light's
     * penumbra applies when it is larger). A beam with a hard rim reads as a solid cone.
     */
    #[Property(editorHint: 'slider')]
    #[Range(min: 0, max: 1)]
    public float $edgeSoftness;

    /** 0..1: how much the beam dims when seen along its axis (looking into the lamp or down the beam). */
    #[Property(editorHint: 'slider')]
    #[Range(min: 0, max: 1)]
    public float $axisFade;

    /** Camera distance in metres at which the beam has faded out (it fades over the last quarter). */
    #[Property]
    public float $maxDistance;

    #[Property]
    public bool $enabled;

    public function __construct(
        float $density = 0.06,
        float $length = 1.0,
        float $edgeSoftness = 0.5,
        float $axisFade = 0.6,
        float $maxDistance = 120.0,
        bool $enabled = true,
    ) {
        $this->density = $density;
        $this->length = $length;
        $this->edgeSoftness = $edgeSoftness;
        $this->axisFade = $axisFade;
        $this->maxDistance = $maxDistance;
        $this->enabled = $enabled;
    }
}
