<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

interface Renderer3DInterface extends RenderContextInterface
{
    public function render(RenderCommandList $commandList): void;

    /**
     * Apply a GraphicsSettings snapshot to this renderer.
     *
     * Backends should react to anything they can hot-swap (shadow-map size,
     * shader override, view-distance clamp, anisotropy). Settings that
     * require an FBO rebuild (render scale, MSAA) may be deferred or stored
     * for the next frame - the contract only requires that the renderer
     * does not crash and behaves reasonably for any valid settings input.
     */
    public function applySettings(GraphicsSettings $settings): void;
}
