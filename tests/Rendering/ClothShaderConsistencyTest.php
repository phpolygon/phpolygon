<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPolygon\Rendering\VioRenderer3D;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Locks the procedural-cloth sway formula across the three shader sources
 * so the GLSL (OpenGL), MSL (Metal), and embedded Vio GLSL backends can't
 * silently drift apart. Each "magic constant" is verified to appear in
 * every shader source - the literals are deliberately not extracted into
 * a shared header because each backend lives in its own language.
 *
 * If you intentionally re-tune the cloth feel, update the constant here
 * AND the three shader sources in lock-step.
 */
final class ClothShaderConsistencyTest extends TestCase
{
    private const GLSL_PATH  = __DIR__ . '/../../resources/shaders/source/mesh3d.vert.glsl';
    private const METAL_PATH = __DIR__ . '/../../resources/shaders/source/mesh3d.metal';

    /**
     * @return array<string, string>
     */
    private function shaderSources(): array
    {
        $glsl  = (string) file_get_contents(self::GLSL_PATH);
        $metal = (string) file_get_contents(self::METAL_PATH);

        $vio = (new ReflectionClass(VioRenderer3D::class))
            ->getReflectionConstant('DEFAULT_VERT')?->getValue();
        self::assertIsString($vio, 'VioRenderer3D::DEFAULT_VERT must exist as string constant');

        return [
            'glsl'  => $glsl,
            'metal' => $metal,
            'vio'   => $vio,
        ];
    }

    public function testAllBackendsClampAabbHeightToTinyEpsilon(): void
    {
        foreach ($this->shaderSources() as $name => $src) {
            $this->assertStringContainsString(
                '1e-4',
                $src,
                "{$name}: AABB height clamp epsilon (1e-4) missing - cloth sway will divide by zero on flat meshes"
            );
        }
    }

    public function testAllBackendsApplyTheSameTwoComponentWave(): void
    {
        // sin(t + pos.x * 2.0) * 0.7  +  cos(t * 1.3 + pos.z * 1.5) * 0.3
        // Match each multiplier independently so language-specific
        // expression layouts (`pos.x * 2.0` vs `pos.x*2.0`) don't trip
        // the test.
        $expectedFragments = [
            'sin component scale 0.7'      => '* 0.7',
            'sin component pos.x * 2.0'    => 'pos.x * 2.0',
            'cos component scale 0.3'      => '* 0.3',
            'cos component pos.z * 1.5'    => 'pos.z * 1.5',
            'cos component time scale 1.3' => '* 1.3',
        ];
        foreach ($this->shaderSources() as $name => $src) {
            foreach ($expectedFragments as $label => $needle) {
                $this->assertStringContainsString(
                    $needle,
                    $src,
                    "{$name}: cloth wave formula missing fragment '{$label}' ({$needle})"
                );
            }
        }
    }

    public function testAllBackendsDefaultWindDirectionToPositiveZ(): void
    {
        // Default when wind direction is zero-vector: vec3/float3 (0.0, 0.0, 1.0)
        foreach ($this->shaderSources() as $name => $src) {
            $matches =
                str_contains($src, 'vec3(0.0, 0.0, 1.0)')
                || str_contains($src, 'float3(0.0, 0.0, 1.0)');
            $this->assertTrue(
                $matches,
                "{$name}: cloth wind-direction zero-vector fallback (vec3/float3(0,0,1)) missing"
            );
        }
    }

    public function testAllBackendsThresholdWindDirectionAtSameEpsilon(): void
    {
        // The wind-direction normalisation skips when length() < 1e-4.
        foreach ($this->shaderSources() as $name => $src) {
            $this->assertMatchesRegularExpression(
                '/length\(\s*(?:wd|u_wind_direction|float3\(light\.wind_direction\))\s*\)\s*>\s*1e-4/',
                $src,
                "{$name}: wind direction zero-length threshold (length(...) > 1e-4) missing"
            );
        }
    }

    public function testAllBackendsDampVerticalSwayAtFifteenPercent(): void
    {
        // sway.y *= 0.15
        foreach ($this->shaderSources() as $name => $src) {
            $this->assertStringContainsString(
                'sway.y *= 0.15',
                $src,
                "{$name}: vertical-sway dampening (sway.y *= 0.15) missing"
            );
        }
    }

    public function testAllBackendsClampYNormToZeroOne(): void
    {
        // anchor weight derives from clamp((pos.y - aabbMin.y) / aabbHeight, 0.0, 1.0)
        foreach ($this->shaderSources() as $name => $src) {
            $this->assertMatchesRegularExpression(
                '/clamp\(\s*\(pos\.y - .*?\)\s*\/\s*aabbHeight\s*,\s*0\.0\s*,\s*1\.0\s*\)/',
                $src,
                "{$name}: yNorm clamp((pos.y - aabbMin.y) / aabbHeight, 0, 1) missing"
            );
        }
    }

    public function testAllBackendsBranchOnAnchorTopFlag(): void
    {
        // anchorWeight = anchorTop == 1 ? yNorm : (1.0 - yNorm)
        foreach ($this->shaderSources() as $name => $src) {
            $this->assertMatchesRegularExpression(
                '/anchor_top == 1 \? yNorm : \(1\.0 - yNorm\)/',
                $src,
                "{$name}: anchor-top branch (anchor_top == 1 ? yNorm : 1 - yNorm) missing"
            );
        }
    }
}
