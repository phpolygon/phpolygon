<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\TestCase;

/**
 * Structural guard for the standalone OpenGL GLSL shaders. These are compiled
 * directly on the GL context (the php-glfw fallback path), where the renderer
 * rewrites the `#version` directive down to 140/130 for old contexts and binds
 * attribute locations by name. That only works if the sources stay portable:
 *
 *   - authored at `#version 150 core` (the portable default),
 *   - no `layout(location=N)` on attributes (GLSL 3.30+; bound via
 *     glBindAttribLocation instead),
 *   - `inverse()`/`transpose()` (GLSL 1.40+) only behind `#if __VERSION__`.
 *
 * Vio shaders (source/vio/*) and Vulkan shaders (*_vk.*) are excluded — they go
 * through glslang→SPIR-V and keep explicit locations for reflection.
 */
class ShaderPortabilityTest extends TestCase
{
    private const SOURCE_DIR = __DIR__ . '/../../resources/shaders/source';

    /**
     * @return list<string>
     */
    private static function standaloneShaders(): array
    {
        $files = glob(self::SOURCE_DIR . '/*.glsl');
        self::assertIsArray($files);
        // Exclude Vulkan variants (*_vk.*); the vio/ subdir is not matched by the glob.
        return array_values(array_filter($files, static fn (string $f): bool => !str_contains($f, '_vk.')));
    }

    public function testShadersExist(): void
    {
        self::assertNotEmpty(self::standaloneShaders());
    }

    public function testAllStandaloneShadersAre150Core(): void
    {
        foreach (self::standaloneShaders() as $file) {
            $src = (string) file_get_contents($file);
            self::assertStringContainsString(
                '#version 150 core',
                $src,
                basename($file) . ' must be authored at "#version 150 core"',
            );
        }
    }

    public function testNoExplicitAttributeLocations(): void
    {
        foreach (self::standaloneShaders() as $file) {
            $src = (string) file_get_contents($file);
            self::assertDoesNotMatchRegularExpression(
                '/layout\s*\(\s*location/',
                $src,
                basename($file) . ' must not use layout(location=N) — bind by name via glBindAttribLocation',
            );
        }
    }

    public function testInverseAndTransposeAreVersionGuarded(): void
    {
        foreach (self::standaloneShaders() as $file) {
            $src = (string) file_get_contents($file);
            if (!preg_match('/\b(inverse|transpose)\s*\(/', $src)) {
                continue;
            }
            self::assertStringContainsString(
                '__VERSION__',
                $src,
                basename($file) . ' uses inverse()/transpose() (GLSL 1.40+) and must guard it with #if __VERSION__',
            );
        }
    }
}
