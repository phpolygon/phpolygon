<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

/**
 * Per-backend rendering conventions.
 *
 * Different GPU APIs disagree on three coordinate conventions that leak up into
 * engine and shader code:
 *
 *   1. Clip-space depth range — OpenGL maps NDC z to [-1, 1]; D3D11/D3D12, Metal
 *      and Vulkan use [0, 1]. The engine's {@see \PHPolygon\Math\Mat4} projection
 *      builders emit the OpenGL convention. When vio transpiles GLSL → HLSL it
 *      applies SPIRV-Cross' FIXUP_DEPTH_CONVENTION, so the *rasterised* depth is
 *      remapped for us on D3D — but any depth math the engine does in PHP (e.g.
 *      a shadow-compare reference) must know the target range itself.
 *   2. Framebuffer/render-target Y origin — OpenGL is bottom-left (y-up); D3D and
 *      Vulkan are top-left (y-down). Sampling a render target authored with the
 *      GL convention on a y-down backend needs a clip-Y flip.
 *   3. Shader source ingestion — only the OpenGL backend takes raw GLSL; every
 *      other vio backend must transpile (GLSL → SPIR-V → HLSL/MSL).
 *
 * Historically these were handled with scattered `vio_backend_name($ctx) === 'opengl'`
 * checks and an inline `$flipY = ($backend === 'd3d11' || 'd3d12')` in the CSM
 * path. This value object centralises that knowledge so a single place owns each
 * convention. Obtain one from the engine via {@see \PHPolygon\Engine::backendConventions()},
 * or directly with {@see self::forBackend()}.
 *
 * Backend names match {@see vio_backend_name()}:
 *   'opengl', 'd3d11', 'd3d12', 'metal', 'vulkan' (and 'unknown' when vio is
 *   unavailable — treated as OpenGL-like, the safe headless default).
 */
final class BackendConventions
{
    /** @var array<string, self> */
    private static array $cache = [];

    private function __construct(
        public readonly string $backend,
    ) {
    }

    public static function forBackend(string $backend): self
    {
        $backend = strtolower($backend);

        return self::$cache[$backend] ??= new self($backend);
    }

    public function name(): string
    {
        return $this->backend;
    }

    public function isOpenGL(): bool
    {
        return $this->backend === 'opengl' || $this->backend === 'unknown';
    }

    public function isDirect3D(): bool
    {
        return $this->backend === 'd3d11' || $this->backend === 'd3d12';
    }

    /**
     * True when clip-space depth lands in [0, 1] (D3D/Metal/Vulkan), false for
     * OpenGL's [-1, 1]. Use when the engine computes depth values in PHP that
     * must agree with what the GPU stores.
     */
    public function depthZeroToOne(): bool
    {
        return !$this->isOpenGL();
    }

    /**
     * True when the backend's render-target Y origin is top-left (D3D/Vulkan),
     * so a render target authored with the GL (bottom-left) convention must have
     * its sample/clip Y flipped. False for OpenGL.
     *
     * NOTE: only the D3D backends are currently confirmed to need the manual
     * flip in engine code — vio handles Vulkan's clip-Y in its own 2D path and
     * Metal has not been exercised here. Extend this when those paths are tested
     * on hardware so the change is empirical, not a guess.
     */
    public function flipRenderTargetClipY(): bool
    {
        return $this->isDirect3D();
    }

    /**
     * The vio shader-source format constant for this backend. OpenGL ingests raw
     * GLSL ({@see VIO_SHADER_GLSL_RAW}); every other backend must transpile
     * ({@see VIO_SHADER_GLSL}). Centralises the former scattered
     * `vio_backend_name($ctx) === 'opengl'` checks.
     */
    public function shaderSourceFormat(): int
    {
        return $this->isOpenGL() ? VIO_SHADER_GLSL_RAW : VIO_SHADER_GLSL;
    }
}
