<?php

declare(strict_types=1);

namespace PHPolygon\Branding;

use PHPolygon\Rendering\Renderer2DInterface;

/**
 * Optional studio-branding splash that plays before the engine's own splash.
 *
 * Provide an implementation via `EngineConfig::$studioSplash` to show a
 * studio logo animation ahead of the "Developed with PHPolygon" splash.
 * The engine drives the frame lifecycle (begin/clear/swap/poll) and the
 * skip-input handling; implementations only draw their animation each
 * frame and tell the engine how long it should run.
 *
 * Skipped in headless mode and when `EngineConfig::$skipSplash` is true.
 *
 * @see \PHPolygon\Engine::showStudioSplash()
 */
interface StudioSplashInterface
{
    /**
     * Total duration of the splash in seconds, including any fade-in and
     * fade-out the implementation runs inside `render()`. The engine ends
     * the splash unconditionally once this many seconds have elapsed.
     */
    public function getDuration(): float;

    /**
     * Render one frame of the splash. The engine has already cleared the
     * back buffer to black before this call; the implementation is free
     * to paint over the entire viewport.
     *
     * Called inside an active `beginFrame()` / `endFrame()` pair on the
     * engine's primary `Renderer2D`. Do not call `beginFrame()` /
     * `endFrame()` / `swapBuffers()` from inside this method.
     *
     * @param Renderer2DInterface $renderer Engine's primary 2D renderer.
     * @param float               $elapsed  Seconds since the splash started
     *                                      (clamped to `[0, getDuration()]`).
     */
    public function render(Renderer2DInterface $renderer, float $elapsed): void;

    /**
     * Whether the user is allowed to skip via input at the current point in
     * the animation. Returning `false` during the first frames prevents an
     * accidental keystroke from killing a short intro before the player has
     * had a chance to see it. Once `true`, the engine ends the splash on
     * the next ESC / Enter / Space / left-mouse-click.
     */
    public function isSkippable(float $elapsed): bool;
}
