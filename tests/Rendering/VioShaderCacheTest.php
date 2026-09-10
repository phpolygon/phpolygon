<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use PHPolygon\EngineConfig;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * The renderer's built-in shaders go through php-vio's on-disk shader cache
 * (vio_create 'shader_cache', wired from EngineConfig::$shaderCachePath by
 * VioWindow): the first renderer stores the compiled stages, a second renderer
 * in the same directory compiles nothing and loads them — the startup win the
 * cache exists for.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioShaderCacheTest extends TestCase
{
    public function testDefaultCachePathLivesNextToTheSaves(): void
    {
        $config = new EngineConfig();
        self::assertSame('saves/shader-cache', $config->shaderCachePath);
        self::assertSame('', (new EngineConfig(shaderCachePath: ''))->shaderCachePath, 'empty path disables the cache');
    }

    public function testSecondRendererWarmsItsShadersFromTheCache(): void
    {
        if (!function_exists('vio_shader_cache_stats')) {
            $this->markTestSkipped('php-vio without the shader cache (< 2.13)');
        }
        $dir = sys_get_temp_dir() . '/phpolygon-shader-cache-' . getmypid();
        @mkdir($dir);
        $opts = ['width' => 32, 'height' => 32, 'headless' => true, 'vsync' => false, 'shader_cache' => $dir];

        $ctx = @vio_create('auto', $opts);
        if ($ctx === false) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        $backend = vio_backend_name($ctx);
        if (!vio_supports_feature($ctx, VIO_FEATURE_3D_PIPELINE)) {
            vio_destroy($ctx);
            $this->markTestSkipped('backend has no 3D pipeline');
        }

        $before = vio_shader_cache_stats();
        self::assertSame($dir, $before['dir']);
        $renderer = new VioRenderer3D($ctx, 32, 32);
        $renderer->warmShaders();
        $afterFirst = vio_shader_cache_stats();
        vio_destroy($ctx);

        if ($backend === 'opengl' && $afterFirst['stores'] === $before['stores']) {
            $this->cleanup($dir);
            $this->markTestSkipped('OpenGL below 4.1 has no program binaries');
        }
        self::assertGreaterThan($before['stores'], $afterFirst['stores'], 'the first renderer stores its compiled shaders');
        self::assertNotEmpty(glob($dir . '/*'), 'cache files exist');

        $ctx = @vio_create('auto', $opts);
        self::assertNotFalse($ctx);
        $renderer = new VioRenderer3D($ctx, 32, 32);
        $renderer->warmShaders();
        $afterSecond = vio_shader_cache_stats();
        vio_destroy($ctx);

        self::assertGreaterThan($afterFirst['hits'], $afterSecond['hits'], 'the second renderer loads from the cache');
        self::assertSame($afterFirst['stores'], $afterSecond['stores'], 'nothing new to store the second time');

        $this->cleanup($dir);
    }

    private function cleanup(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    }
}
