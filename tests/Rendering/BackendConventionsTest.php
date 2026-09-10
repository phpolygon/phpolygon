<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\TestCase;
use PHPolygon\Rendering\BackendConventions;

/**
 * Per-backend coordinate conventions. Only OpenGL keeps clip depth in [-1, 1]
 * and a bottom-left render-target origin; D3D, Metal and Vulkan store row 0 at
 * the top of NDC, so a GL-authored render target is sampled with a flipped
 * clip-Y there. Vulkan joined that group with php-vio's Vulkan 3D pipeline.
 */
final class BackendConventionsTest extends TestCase
{
    public function testOpenGlKeepsTheGlConventions(): void
    {
        foreach (['opengl', 'unknown'] as $name) {
            $c = BackendConventions::forBackend($name);
            self::assertTrue($c->isOpenGL(), $name);
            self::assertFalse($c->depthZeroToOne(), $name);
            self::assertFalse($c->flipRenderTargetClipY(), $name);
        }
    }

    public function testTopLeftOriginBackendsFlipRenderTargetClipY(): void
    {
        foreach (['d3d11', 'd3d12', 'metal', 'vulkan'] as $name) {
            $c = BackendConventions::forBackend($name);
            self::assertFalse($c->isOpenGL(), $name);
            self::assertTrue($c->depthZeroToOne(), "$name stores depth in [0, 1]");
            self::assertTrue($c->flipRenderTargetClipY(), "$name has row 0 at the NDC top");
        }
        self::assertTrue(BackendConventions::forBackend('Vulkan')->isVulkan(), 'names are case-insensitive');
    }
}
