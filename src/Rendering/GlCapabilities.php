<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

/**
 * Detected OpenGL context capabilities for the standalone (php-glfw) backend.
 *
 * The engine no longer hard-requires GL 4.1. The context is created via a
 * version ladder (see {@see \PHPolygon\Runtime\Window}) that tries the newest
 * core profile first and falls back to older ones, down to GL 3.0. This value
 * object captures the version that was actually obtained so the renderer can:
 *
 *   - inject the matching `#version` directive when compiling GLSL
 *     (150 core / 140 / 130 — a single 150-core source is rewritten down),
 *   - decide whether GPU instancing is available as a core feature
 *     (`glVertexAttribDivisor` is core only from GL 3.3) or must degrade to
 *     the CPU fallback path.
 *
 * Pure parsing — {@see parse()} is context-free and unit-testable; only
 * {@see detect()} touches the live GL context.
 */
final class GlCapabilities
{
    private const GL_VERSION = 0x1F02;

    public function __construct(
        public readonly int $major,
        public readonly int $minor,
    ) {}

    /**
     * Parse a `GL_VERSION` string. Handles the common driver spellings:
     *   "4.6.0 NVIDIA 550.90"      → 4.6
     *   "3.1 Mesa 21.2.6"          → 3.1
     *   "4.1 Metal - 89.3"         → 4.1
     *   "OpenGL ES 3.0 Mesa ..."   → 3.0
     *
     * Falls back to 3.0 (the engine floor) when no version token is found,
     * which keeps the most conservative shader/instancing paths.
     */
    public static function parse(string $glVersion): self
    {
        if (preg_match('/(\d+)\.(\d+)/', $glVersion, $m) === 1) {
            return new self((int) $m[1], (int) $m[2]);
        }
        return new self(3, 0);
    }

    /**
     * Detect capabilities from the current GL context. Must be called with a
     * context bound (e.g. after glfwMakeContextCurrent()).
     */
    public static function detect(): self
    {
        $version = glGetString(self::GL_VERSION);
        return self::parse($version);
    }

    /** Combined tier number, e.g. 46, 41, 33, 31, 30. Convenient for comparisons. */
    public function tier(): int
    {
        return $this->major * 10 + $this->minor;
    }

    /**
     * GLSL `#version` directive matching this context.
     *
     * `#version 150 core` requires GL 3.2 (first version with the `core`
     * profile keyword). GL 3.1 → GLSL 140, GL 3.0 → GLSL 130. The engine's
     * standalone shaders are authored at 150 core and guard the few 1.40+
     * builtins (inverse/transpose) behind `#if __VERSION__ >= 140`, so the
     * same source compiles at every rung once the directive is rewritten.
     */
    public function glslVersionDirective(): string
    {
        return match (true) {
            $this->tier() >= 32 => '#version 150 core',
            $this->tier() >= 31 => '#version 140',
            default             => '#version 130',
        };
    }

    /**
     * Whether instanced arrays are a core feature. `glVertexAttribDivisor` +
     * `glDrawArraysInstanced` are core since GL 3.3; below that the renderer
     * degrades DrawMeshInstanced to a per-instance CPU loop.
     */
    public function hasCoreInstancing(): bool
    {
        return $this->tier() >= 33;
    }

    /**
     * Whether the off-screen post-process chain (FXAA/TAA/SSR, render-scale +
     * MSAA resolve) is enabled. Gated at GL 3.3 so the 150-core post-process
     * shaders only ever run on a context that accepts them, and the extra
     * targets are skipped entirely on low-end hardware.
     */
    public function hasPostProcessing(): bool
    {
        return $this->tier() >= 33;
    }
}
