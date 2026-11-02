<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\TestCase;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * Which backends run the real SSAO chain. Vulkan and Metal keep the curvature AO
 * surrogate until their SSAO matches D3D12 (streaks on Metal, speckles on Vulkan);
 * enabling one is a line in VioRenderer3D::SSAO_BACKENDS and a line here.
 */
final class VioSsaoBackendsTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('PHPOLYGON_SSAO');
    }

    public function testSsaoRunsOnDirect3DOnly(): void
    {
        self::assertTrue(VioRenderer3D::ssaoRunsOn('d3d12'));
        self::assertTrue(VioRenderer3D::ssaoRunsOn('d3d11'));
        self::assertFalse(VioRenderer3D::ssaoRunsOn('vulkan'));
        self::assertFalse(VioRenderer3D::ssaoRunsOn('metal'));
        self::assertFalse(VioRenderer3D::ssaoRunsOn('opengl'));
    }

    public function testTheOverrideRunsItOnEveryTranspilingBackend(): void
    {
        putenv('PHPOLYGON_SSAO=1');
        self::assertTrue(VioRenderer3D::ssaoRunsOn('vulkan'));
        self::assertTrue(VioRenderer3D::ssaoRunsOn('metal'));
        self::assertFalse(VioRenderer3D::ssaoRunsOn('opengl'), 'OpenGL has no G-buffer passes');
    }
}
