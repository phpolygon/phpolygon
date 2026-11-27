<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Lighting;

/**
 * Decides per frame whether the mesh program needs the world light grid.
 *
 * Up to eight point lights fit the uniform array, and the grid program reads exactly
 * those (the grid is off, `u_light_grid.w = 0`). A program that declares the grid's
 * storage buffers pays for them anyway: on Direct3D a fragment shader that touches a UAV
 * loses the early depth test. So a frame that fits the uniforms draws with the
 * grid-less twin of the program, and only a frame with more lights takes the grid.
 *
 * The switch to the grid is immediate (a ninth light must not vanish for a few
 * frames); the switch back waits until the lights have been few for `$holdFrames`
 * frames in a row, so a count hovering around the limit does not alternate programs.
 */
final class LightGridGate
{
    /** Point lights the uniform array holds. */
    public const int UNIFORM_LIGHTS = 8;

    /** Default quiet frames before the grid is left again. */
    public const int HOLD_FRAMES = 60;

    private bool $grid = false;
    private int $quiet = 0;

    public function __construct(private readonly int $holdFrames = self::HOLD_FRAMES)
    {
    }

    /** Whether this frame draws with the grid program. */
    public function needsGrid(int $pointLights): bool
    {
        if ($pointLights > self::UNIFORM_LIGHTS) {
            $this->grid = true;
            $this->quiet = 0;
            return true;
        }
        if (!$this->grid) {
            return false;
        }
        if (++$this->quiet >= $this->holdFrames) {
            $this->grid = false;
            $this->quiet = 0;
        }
        return $this->grid;
    }
}
