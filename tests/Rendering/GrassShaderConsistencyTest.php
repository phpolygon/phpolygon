<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\TestCase;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * The grass blade stage lives in every mesh vertex source (vio mesh3d + G-buffer,
 * OpenGL fallback) and its colour in both mesh fragment sources. A G-buffer that
 * bends differently halos the blades in SSAO, a fallback that thins differently
 * changes the look per backend - so the function bodies must be identical.
 */
final class GrassShaderConsistencyTest extends TestCase
{
    private const string DIR = __DIR__ . '/../../resources/shaders/source/';

    /** @return array<string, string> */
    private static function vertexSources(): array
    {
        return [
            'vio mesh3d.vert' => self::read('vio/mesh3d.vert.glsl'),
            'vio gbuffer.vert' => self::read('vio/gbuffer.vert.glsl'),
            'gl mesh3d.vert' => self::read('mesh3d.vert.glsl'),
        ];
    }

    private static function read(string $file): string
    {
        return str_replace("\r\n", "\n", (string) file_get_contents(self::DIR . $file));
    }

    private static function block(string $src, string $start, string $end): string
    {
        $i = strpos($src, $start);
        self::assertNotFalse($i, "missing: {$start}");
        $j = strpos($src, $end, $i);
        self::assertNotFalse($j);
        return substr($src, $i, $j - $i + strlen($end));
    }

    public function testEveryVertexSourceShapesTheBladesIdentically(): void
    {
        $bodies = [];
        foreach (self::vertexSources() as $name => $src) {
            foreach ([
                'uniform int   u_grass;',
                'uniform float u_grass_bend;',
                'uniform vec4  u_grass_range;',
                'uniform vec4  u_grass_push[4];',
                // the hash is read from m[0].w and cleared before the matrix is used
                "    float grassHash = 0.0;\n    if (u_grass == 1) {\n        grassHash = model[0].w;\n        model[0].w = 0.0;\n    }",
                'grassBlade(pos, a_uv, model, grassHash,',
            ] as $needle) {
                self::assertStringContainsString($needle, $src, "{$name}: {$needle}");
            }
            $bodies[$name] = self::block($src, 'vec3 grassBlade(', "\n}\n");
            // grass replaces the foliage sway, never both
            self::assertStringContainsString('} else if (u_foliage_wind == 1) {', $src, $name);
        }
        $first = array_shift($bodies);
        foreach ($bodies as $name => $body) {
            self::assertSame($first, $body, "{$name}: grassBlade() drifted from vio mesh3d.vert");
        }
        foreach ([
            'thin by hash against the falling density' => 'clamp(((1.0 - fadeT) * 1.25 - hash) * 4.0, 0.0, 1.0)',
            'shorter toward the fade range' => 'mix(1.0, u_grass_range.z, fadeT)',
            'reduced motion' => 'u_wind_intensity * u_foliage_motion',
            'back to object space' => '(transpose(mat3(model)) * d) / s2',
        ] as $label => $needle) {
            self::assertStringContainsString($needle, $first, $label);
        }
    }

    public function testTheMotionVectorReplayClearsTheHashToo(): void
    {
        $src = self::read('vio/mesh3d.vert.glsl');
        self::assertStringContainsString("    if (u_grass == 1) {\n        prevModel[0].w = 0.0;\n    }", $src);
        self::assertStringContainsString('animatePosition(a_position, prevModel, u_time_prev, grassHash)', $src);
    }

    public function testStorageInstancingStillBuildsFromTheVertexSource(): void
    {
        $out = VioRenderer3D::storageInstancingVertexSource(self::read('vio/mesh3d.vert.glsl'));
        self::assertStringContainsString('instanceModels[gl_InstanceIndex]', $out);
        self::assertStringContainsString('vec3 grassBlade(', $out);
    }

    public function testBothFragmentSourcesColourTheBladesIdentically(): void
    {
        $vio = self::read('vio/mesh3d.frag.glsl');
        $gl = self::read('mesh3d.frag.glsl');
        $blocks = [];
        foreach (['vio' => $vio, 'gl' => $gl] as $name => $src) {
            self::assertStringContainsString('uniform int   u_grass_shade;', $src, $name);
            self::assertStringContainsString('uniform vec3  u_grass_tip;', $src, $name);
            self::assertStringContainsString('uniform vec4  u_grass_look;', $src, $name);
            self::assertStringContainsString('uniform vec2  u_grass_far_fade;', $src, $name);
            self::assertStringNotContainsString('uniform int   u_grass;', $src, "{$name}: the fragment flag has a stage-local name");
            $blocks[$name] = self::block($src, '    if (u_grass_shade == 1) {', "        albedo = gc;\n    }");
        }
        self::assertSame($blocks['vio'], $blocks['gl']);
        // translucency toward the tips (vio)
        self::assertStringContainsString('sssStrength *= mix(0.25, 1.0, clamp(v_uv.y, 0.0, 1.0));', $vio);
    }

    public function testRenderersUploadTheGrassUniforms(): void
    {
        $vio = (string) file_get_contents(__DIR__ . '/../../src/Rendering/VioRenderer3D.php');
        $gl = (string) file_get_contents(__DIR__ . '/../../src/Rendering/OpenGLRenderer3D.php');
        foreach (["'u_grass'", "'u_grass_shade'", "'u_grass_far_fade'", "'u_grass_bend'", "'u_grass_tip'", "'u_grass_look'", "'u_grass_range'", 'u_grass_push['] as $u) {
            self::assertStringContainsString($u, $vio, "VioRenderer3D sets {$u}");
            self::assertStringContainsString($u, $gl, "OpenGLRenderer3D sets {$u}");
        }
        // forward pass and G-buffer
        self::assertGreaterThanOrEqual(2, substr_count($vio, "'u_grass'"));
        self::assertSame(2, substr_count($vio, '$this->uploadGrassFrameUniforms();'));
    }
}
