<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\TestCase;

/**
 * Foliage wind and two-sided lighting live in several shader sources (vio
 * mesh3d + G-buffer, OpenGL fallback) that must not drift apart: a G-buffer
 * that does not sway like the forward pass halos the leaves in SSAO, and a
 * fallback that bends differently changes the look per backend. Like
 * ClothShaderConsistencyTest, the constants are checked in every source.
 */
final class FoliageShaderConsistencyTest extends TestCase
{
    private const string DIR = __DIR__ . '/../../resources/shaders/source/';

    /** @return array<string, string> */
    private static function vertexSources(): array
    {
        return [
            'vio mesh3d.vert' => (string) file_get_contents(self::DIR . 'vio/mesh3d.vert.glsl'),
            'vio gbuffer.vert' => (string) file_get_contents(self::DIR . 'vio/gbuffer.vert.glsl'),
            'gl mesh3d.vert' => (string) file_get_contents(self::DIR . 'mesh3d.vert.glsl'),
        ];
    }

    public function testEverySourceBendsAndFluttersTheSameWay(): void
    {
        $fragments = [
            'uniform toggle' => 'uniform int   u_foliage_wind;',
            'flutter uniform' => 'uniform float u_wind_flutter;',
            'tuft uniform' => 'uniform int   u_foliage_tufts;',
            'tuft phase' => 'phase += a_uv.x * 6.2831;',
            'tuft weight' => 'r = 0.45 + 0.55 * clamp(a_uv.y, 0.0, 1.0);',
            'motion scale' => 'uniform float u_foliage_motion;',
            'fade range' => 'uniform vec2  u_foliage_fade;',
            'bend with height squared' => '0.0009 * h * h * amp',
            'phase from instance origin' => 'dot(origin.xz, vec2(0.173, 0.291))',
            'phase hash' => 'vec2(12.9898, 78.233)',
            'lean' => '0.65 + 0.35 * sin(time * 0.9 + phase)',
            'oscillation' => 'sin(time * 1.7 + phase * 1.3) * 0.35 + sin(time * 2.9 + phase * 0.7) * 0.15',
            'flutter x' => 'sin(time * 4.1 + dot(pos, vec3(1.7, 0.9, 1.3)) + phase)',
            'flutter y' => 'sin(time * 5.3 + dot(pos, vec3(0.8, 1.9, 1.1)) + phase) * 0.6',
            'flutter z' => 'cos(time * 3.7 + dot(pos, vec3(1.2, 0.6, 1.9)) + phase)',
            'distance fade' => 'smoothstep(u_foliage_fade.x, u_foliage_fade.y, length(origin - camPos))',
            'back to object space' => '(transpose(mat3(model)) * d) / s2',
            'applied when on' => 'if (u_foliage_wind == 1) {',
        ];
        foreach (self::vertexSources() as $name => $src) {
            foreach ($fragments as $label => $needle) {
                self::assertStringContainsString($needle, $src, "{$name}: {$label}");
            }
        }
    }

    public function testTwoSidedMirrorsTheNormalInForwardAndGbuffer(): void
    {
        $frag = (string) file_get_contents(self::DIR . 'vio/mesh3d.frag.glsl');
        $gbuf = (string) file_get_contents(self::DIR . 'vio/gbuffer.frag.glsl');
        self::assertStringContainsString('uniform int   u_two_sided;', $frag);
        self::assertStringContainsString('uniform int   u_two_sided;', $gbuf);
        self::assertStringContainsString('N = normalize(N - 2.0 * dot(N, tsNg) * tsNg);', $frag);
        self::assertStringContainsString('n = normalize(n - 2.0 * dot(n, ng) * ng);', $gbuf);
        // The mirror runs before the MRT G-buffer value is taken.
        self::assertLessThan(strpos($frag, 'o_gbuffer = gbufferValue(N);'), strpos($frag, 'if (u_two_sided == 1)'));
    }

    public function testRenderersUploadTheNewUniforms(): void
    {
        $vio = (string) file_get_contents(__DIR__ . '/../../src/Rendering/VioRenderer3D.php');
        foreach (["'u_two_sided'", "'u_foliage_wind'", "'u_wind_flutter'", "'u_foliage_tufts'", "'u_foliage_motion'", "'u_foliage_fade'"] as $u) {
            self::assertGreaterThanOrEqual(2, substr_count($vio, $u), "VioRenderer3D sets {$u} for forward and G-buffer");
        }
        $gl = (string) file_get_contents(__DIR__ . '/../../src/Rendering/OpenGLRenderer3D.php');
        foreach (["'u_foliage_wind'", "'u_wind_flutter'", "'u_foliage_tufts'", "'u_foliage_motion'", "'u_foliage_fade'"] as $u) {
            self::assertStringContainsString($u, $gl);
        }
    }
}
