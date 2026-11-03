<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\TestCase;

/**
 * No vio program declares a uniform in both its vertex and its fragment stage.
 * php-vio's cbuffer backends (D3D11, D3D12, Vulkan, Metal) set such a name in the
 * vertex stage only; the fragment copy silently reads 0 (u_time did, see
 * VioFragmentTimeTest). A stage that needs a value of the other gets its own name.
 */
final class StageUniformNamesTest extends TestCase
{
    private const DIR = __DIR__ . '/../../resources/shaders/source/vio/';

    public function testNoProgramDeclaresAUniformInBothStages(): void
    {
        $pairs = self::programPairs();
        self::assertNotEmpty($pairs);
        self::assertArrayHasKey('mesh3d.vert.glsl + mesh3d.frag.glsl', $pairs, 'the mesh program is among the pairs');
        $shared = [];
        foreach ($pairs as $label => [$vertex, $fragment]) {
            $both = array_values(array_intersect(self::uniforms($vertex), self::uniforms($fragment)));
            if ($both !== []) {
                $shared[] = $label . ': ' . implode(', ', $both);
            }
        }
        self::assertSame([], $shared, 'uniforms declared in both stages');
    }

    /**
     * Vertex + fragment file of every program VioRenderer3D compiles from files.
     *
     * @return array<string, array{string, string}>
     */
    private static function programPairs(): array
    {
        $source = (string) file_get_contents(__DIR__ . '/../../src/Rendering/VioRenderer3D.php');
        preg_match_all("/compileShaderFromFiles\\(\\s*[^,]+,\\s*'([\\w.]+)',\\s*'([\\w.]+)'/", $source, $calls, PREG_SET_ORDER);
        $pairs = [];
        foreach ($calls as [, $vertex, $fragment]) {
            $pairs["{$vertex} + {$fragment}"] = [$vertex, $fragment];
        }
        // The sky layers are compiled in a loop over their names.
        foreach (glob(self::DIR . 'sky_*.frag.glsl') ?: [] as $file) {
            $pairs['atmosphere.vert.glsl + ' . basename($file)] = ['atmosphere.vert.glsl', basename($file)];
        }
        return $pairs;
    }

    /** @return list<string> uniform names declared in a shader file */
    private static function uniforms(string $file): array
    {
        $source = (string) file_get_contents(self::DIR . $file);
        preg_match_all('/^\s*uniform\s+(?:(?:lowp|mediump|highp)\s+)?\w+\s+(\w+)/m', $source, $m);
        return array_values(array_unique($m[1]));
    }
}
