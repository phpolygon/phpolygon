<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Text;

/**
 * Finished surface-text textures shipped with a build, by texture id. A game with
 * its own pre-bake (a BC7 encoder, a manifest) implements this; see
 * {@see SurfaceTextDirectoryStore} for the plain default.
 */
interface SurfaceTextStoreInterface
{
    /** File of a finished texture ({@see \PHPolygon\Rendering\TextureManager::load()}), null = rasterize it. */
    public function path(string $textureId): ?string;

    /** Keep a rasterized texture (RGBA8, top row first) for later {@see path()} calls. */
    public function store(string $textureId, string $rgba, int $width, int $height): void;
}
