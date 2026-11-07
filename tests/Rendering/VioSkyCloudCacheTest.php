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
 * Temporal (4.3): after the first fill at most one face per frame, the clouds drift
 * smoothly instead of jumping at each refresh, and a lightning flash still shows.
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

    public function testTheCacheRendersOneFacePerFrameAndTheCloudsDriftSmoothly(): void
    {
        self::assertNotNull($this->ctx);
        $renderer = new VioRenderer3D($this->ctx, self::SIZE, self::SIZE);
        $renderer->applySettings($this->settings(true));
        $faces = [];
        $steps = [];
        $prev = null;
        for ($f = 0; $f < 120; $f++) {
            $time = $f / 60.0;
            $renderer->setAnimationTime($time);
            $img = $renderer->renderToImage($this->scene(cloudTime: $time, windSpeed: 400.0), self::SIZE, self::SIZE);
            $faces[] = $renderer->skyCloudFacesRenderedLastFrame();
            if ($prev !== null && $f > 1) {
                $sum = 0;
                for ($i = 0, $n = strlen($img); $i < $n; $i += 4) {
                    $sum += abs(ord($img[$i]) - ord($prev[$i])) + abs(ord($img[$i + 1]) - ord($prev[$i + 1])) + abs(ord($img[$i + 2]) - ord($prev[$i + 2]));
                }
                $steps[] = $sum / (strlen($img) / 4 * 3);
            }
            $prev = $img;
        }
        $mean = array_sum($steps) / count($steps);
        $max = max($steps);
        fprintf(STDERR, "[cloud cache] faces first %d, later max %d, total %d; step mean %.3f max %.3f\n", $faces[0], max(array_slice($faces, 1)), array_sum($faces), $mean, $max);
        self::assertSame(6, $faces[0], 'the first snapshot is filled at once');
        self::assertLessThanOrEqual(1, max(array_slice($faces, 1)), 'never more than one face per frame afterwards');
        self::assertGreaterThanOrEqual(18, array_sum(array_slice($faces, 1)), 'a new snapshot every half second');
        self::assertGreaterThan(0.02, $mean, 'the clouds move');
        // A refresh without blending shows as a lone step between still frames; blended,
        // every visible step has a neighbour of similar size.
        for ($i = 1, $n = count($steps) - 1; $i < $n; $i++) {
            if ($steps[$i] > 0.05) {
                self::assertGreaterThan(0.5 * $steps[$i], max($steps[$i - 1], $steps[$i + 1]), "lone jump at step {$i}");
            }
        }
    }

    public function testALightningFlashShowsWithTheCache(): void
    {
        $live = $this->render(false, 1.0);
        $cached = $this->render(true, 1.0);
        $sum = 0;
        for ($i = 0; $i < strlen($live); $i += 4) {
            $sum += abs(ord($live[$i]) - ord($cached[$i])) + abs(ord($live[$i + 1]) - ord($cached[$i + 1])) + abs(ord($live[$i + 2]) - ord($cached[$i + 2]));
        }
        self::assertLessThan(0.5, $sum / (strlen($live) / 4 * 3), 'flash frames draw the clouds live');
    }

    private function settings(bool $cache): GraphicsSettings
    {
        return new GraphicsSettings(
            shadowQuality: ShadowQuality::Off,
            ambientOcclusion: ScreenSpaceAO::Off,
            antiAliasing: AntiAliasing::Off,
            bloom: false,
            skyCloudCache: $cache,
        );
    }

    private function scene(float $lightning = 0.0, float $cloudTime = 0.0, float $windSpeed = 0.0): RenderCommandList
    {
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
            time: $cloudTime,
            cloudWindSpeed: $windSpeed,
            lightning: $lightning,
        ));
        return $list;
    }

    private function render(bool $cache, float $lightning = 0.0): string
    {
        self::assertNotNull($this->ctx);
        $renderer = new VioRenderer3D($this->ctx, self::SIZE, self::SIZE);
        $renderer->applySettings($this->settings($cache));
        return $renderer->renderToImage($this->scene($lightning), self::SIZE, self::SIZE);
    }
}
