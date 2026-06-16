<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPolygon\Rendering\BackendConventions;
use PHPUnit\Framework\TestCase;

/**
 * Compiles the vio mesh shader on a headless context so sampler/register
 * regressions (e.g. D3D12 "X4610: SRV binding ranges overlap") are caught here
 * instead of crashing the game at startup. Skips where vio isn't loaded (CI
 * Linux) — it runs on the Windows/D3D12 dev + CI machines that have the backend.
 */
final class MeshShaderCompileTest extends TestCase
{
    public function testVioMeshShaderCompiles(): void
    {
        if (!extension_loaded('vio') || !function_exists('vio_create')) {
            $this->markTestSkipped('vio extension not loaded');
        }

        $ctx = vio_create('auto', ['width' => 16, 'height' => 16, 'headless' => true, 'visible' => false]);
        if ($ctx === false) {
            $this->markTestSkipped('vio_create failed (no GPU/headless context)');
        }

        try {
            $dir = dirname(__DIR__, 2) . '/resources/shaders/source/vio/';
            $vert = file_get_contents($dir . 'mesh3d.vert.glsl');
            $frag = file_get_contents($dir . 'mesh3d.frag.glsl');
            $this->assertIsString($vert);
            $this->assertIsString($frag);

            $format = BackendConventions::forBackend(vio_backend_name($ctx))->shaderSourceFormat();
            $shader = vio_shader($ctx, ['vertex' => $vert, 'fragment' => $frag, 'format' => $format]);

            $this->assertNotFalse(
                $shader,
                'vio mesh3d shader must compile — a false result means a backend compile error '
                . '(e.g. overlapping sampler registers).',
            );
        } finally {
            vio_destroy($ctx);
        }
    }
}
