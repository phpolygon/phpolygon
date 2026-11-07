<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetSky;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ScreenSpaceAO;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioEnvironmentCubemap;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * Sky cloud cache (shader plan 9.1): clouds looked up from the cube match the clouds
 * raymarched per pixel closely; the cache only trades a little sharpness for time.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioSkyCloudCacheTest extends TestCase
{
    private const SIZE = 64;

    private ?\VioContext $ctx = null;

    protected function setUp(): void
    {
        $ctx = VioTestContext::create(self::SIZE, self::SIZE, ['shader_cache' => sys_get_temp_dir() . '/phpolygon-test-shader-cache']);
        if ($ctx === null) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        if (!VioEnvironmentCubemap::isSupported($ctx)) {
            vio_destroy($ctx);
            $this->markTestSkipped('no cube render targets');
        }
        $this->ctx = $ctx;
    }

    protected function tearDown(): void
    {
        if ($this->ctx !== null) {
            vio_destroy($this->ctx);
            $this->ctx = null;
        }
    }

    public function testCachedCloudsMatchTheLiveOnes(): void
    {
        $live = $this->render(false);
        $cached = $this->render(true);
        $sum = 0;
        for ($i = 0; $i < strlen($live); $i += 4) {
            $sum += abs(ord($live[$i]) - ord($cached[$i])) + abs(ord($live[$i + 1]) - ord($cached[$i + 1])) + abs(ord($live[$i + 2]) - ord($cached[$i + 2]));
        }
        $mean = $sum / (strlen($live) / 4 * 3);
        self::assertLessThan(6.0, $mean, "cached clouds differ by {$mean} on average");
    }

    private function render(bool $cache): string
    {
        self::assertNotNull($this->ctx);
        $renderer = new VioRenderer3D($this->ctx, self::SIZE, self::SIZE);
        $renderer->applySettings(new GraphicsSettings(
            shadowQuality: ShadowQuality::Off,
            ambientOcclusion: ScreenSpaceAO::Off,
            antiAliasing: AntiAliasing::Off,
            bloom: false,
            skyCloudCache: $cache,
        ));
        $list = new RenderCommandList();
        $list->add(new SetCamera(
            Mat4::lookAt(new Vec3(0.0, 2.0, 0.0), new Vec3(0.0, 4.0, -10.0), new Vec3(0.0, 1.0, 0.0)),
            Mat4::perspective(1.2, 1.0, 0.1, 500.0),
        ));
        $list->add(new SetSky(
            sunDirection: new Vec3(0.3, 0.6, -0.5),
            sunColor: new Color(1.0, 0.95, 0.85),
            sunIntensity: 1.0,
            zenithColor: new Color(0.2, 0.4, 0.8),
            horizonColor: new Color(0.7, 0.8, 0.9),
            groundColor: new Color(0.3, 0.3, 0.3),
            cloudCover: 0.6,
        ));

        return $renderer->renderToImage($list, self::SIZE, self::SIZE);
    }
}
