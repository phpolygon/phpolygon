<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\TestCase;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * Which backends run the real SSAO chain, and where the pass filters the G-buffer in
 * the shader. Vulkan and Metal run SSAO since the shader filter removed their streaks
 * and speckle (VioSsaoFlatSurfaceTest); Direct3D keeps the sampler.
 */
final class VioSsaoBackendsTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('PHPOLYGON_SSAO');
        putenv('PHPOLYGON_SSAO_FILTER');
    }

    public function testSsaoRunsOnEveryTranspilingBackend(): void
    {
        self::assertTrue(VioRenderer3D::ssaoRunsOn('d3d12'));
        self::assertTrue(VioRenderer3D::ssaoRunsOn('d3d11'));
        self::assertTrue(VioRenderer3D::ssaoRunsOn('vulkan'));
        self::assertTrue(VioRenderer3D::ssaoRunsOn('metal'));
        self::assertFalse(VioRenderer3D::ssaoRunsOn('opengl'), 'OpenGL has no G-buffer passes');
    }

    public function testTheOverrideKeepsOpenGLOut(): void
    {
        putenv('PHPOLYGON_SSAO=1');
        self::assertTrue(VioRenderer3D::ssaoRunsOn('vulkan'));
        self::assertTrue(VioRenderer3D::ssaoRunsOn('metal'));
        self::assertFalse(VioRenderer3D::ssaoRunsOn('opengl'));
    }

    public function testTheShaderFiltersTheGbufferOnEverySsaoBackend(): void
    {
        self::assertTrue(VioRenderer3D::ssaoFiltersInShader('vulkan'));
        self::assertTrue(VioRenderer3D::ssaoFiltersInShader('metal'));
        self::assertTrue(VioRenderer3D::ssaoFiltersInShader('d3d12'), 'hardware bilinear filtering misplaces far flat ground on D3D12 too');
        self::assertTrue(VioRenderer3D::ssaoFiltersInShader('d3d11'));
        self::assertFalse(VioRenderer3D::ssaoFiltersInShader('opengl'));
    }

    public function testTheFilterOverrideWinsEverywhere(): void
    {
        putenv('PHPOLYGON_SSAO_FILTER=1');
        self::assertTrue(VioRenderer3D::ssaoFiltersInShader('d3d12'));
        putenv('PHPOLYGON_SSAO_FILTER=0');
        self::assertFalse(VioRenderer3D::ssaoFiltersInShader('metal'));
    }
}
