<?php

declare(strict_types=1);

namespace PHPolygon\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Category;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Range;
use PHPolygon\ECS\Attribute\Serializable;

/**
 * Isometric/top-down camera that orbits a target point using
 * spherical coordinates and an orthographic projection.
 *
 * Attach to the same entity as a Transform3D. The system computes
 * the camera position from angle/pitch/distance and writes the
 * SetCamera command each frame.
 */
#[Serializable]
#[Category('Rendering')]
class IsometricCamera extends AbstractComponent
{
    /** Orthographic half-height in world units. Smaller = more zoomed in. */
    #[Property(editorHint: 'slider')]
    #[Range(min: 1, max: 200)]
    public float $zoom;

    /** Y-axis rotation in degrees (orbit angle around target). */
    #[Property(editorHint: 'slider')]
    #[Range(min: 0, max: 360)]
    public float $angle;

    /** Pitch angle in degrees (elevation). 90 = top-down, 30 = classic isometric. */
    #[Property(editorHint: 'slider')]
    #[Range(min: 5, max: 90)]
    public float $pitch;

    /** Distance from the target point along the spherical direction. */
    #[Property(editorHint: 'slider')]
    #[Range(min: 1, max: 500)]
    public float $distance;

    /** Near clipping plane. */
    #[Property]
    public float $near;

    /** Far clipping plane. */
    #[Property]
    public float $far;

    /** Whether this camera is active. Only one camera renders per frame. */
    #[Property]
    public bool $active;

    /** Smoothing factor for follow (0 = instant, 1 = very slow). */
    #[Property(editorHint: 'slider')]
    #[Range(min: 0, max: 0.99)]
    public float $smoothing;

    public function __construct(
        float $zoom = 20.0,
        float $angle = 45.0,
        float $pitch = 35.264,
        float $distance = 50.0,
        float $near = 0.1,
        float $far = 500.0,
        bool $active = true,
        float $smoothing = 0.0,
    ) {
        $this->zoom = $zoom;
        $this->angle = $angle;
        $this->pitch = $pitch;
        $this->distance = $distance;
        $this->near = $near;
        $this->far = $far;
        $this->active = $active;
        $this->smoothing = $smoothing;
    }
}
