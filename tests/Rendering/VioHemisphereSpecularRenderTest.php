<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Geometry\MetricMesh;
use PHPolygon\Geometry\RoundMesh;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetDirectionalLight;
use PHPolygon\Rendering\Command\SetSky;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ScreenSpaceAO;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * Hemisphere specular fallback (VioRenderer3D::setHemisphereSpecular): without an
 * environment probe a rough metal has no diffuse term and nothing to reflect, so it
 * renders black in an ambient-lit studio. Opted in, it reflects the ambient light.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioHemisphereSpecularRenderTest extends TestCase
{
    private const SIZE = 64;

    private ?\VioContext $ctx = null;

    protected function setUp(): void
    {
        $ctx = VioTestContext::create(self::SIZE, self::SIZE, ['shader_cache' => sys_get_temp_dir() . '/phpolygon-test-shader-cache']);
        if ($ctx === null) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        if (!vio_supports_feature($ctx, VIO_FEATURE_3D_PIPELINE)) {
            vio_destroy($ctx);
            $this->markTestSkipped('backend has no 3D pipeline');
        }
        $this->ctx = $ctx;
        MeshRegistry::clear();
        MaterialRegistry::clear();
        MeshRegistry::register('ball', RoundMesh::sphere(1.0, 16, 24));
        MeshRegistry::register('roof', MetricMesh::box(8.0, 0.2, 8.0));
        MaterialRegistry::register('metal', new Material(albedo: new Color(0.9, 0.7, 0.4), roughness: 0.8, metallic: 1.0));
    }

    protected function tearDown(): void
    {
        MaterialRegistry::clear();
        MeshRegistry::clear();
        if ($this->ctx !== null) {
            vio_destroy($this->ctx);
            $this->ctx = null;
        }
    }

    public function testRoughMetalIsBlackWithoutTheFallbackAndLitWithIt(): void
    {
        $off = self::meanRed($this->render(0.0));
        $on = self::meanRed($this->render(1.0));

        self::assertLessThan(3.0, $off, "a rough metal in the sun's shadow is black by default ({$off})");
        self::assertGreaterThan(20.0, $on, "the hemisphere fallback lights it ({$on})");
    }

    public function testStrengthScalesTheFallback(): void
    {
        $half = self::meanRed($this->render(0.5));
        $full = self::meanRed($this->render(1.0));

        self::assertGreaterThan($half * 1.4, $full, "full strength is clearly brighter than half ({$half} vs {$full})");
    }

    public function testNegativeStrengthIsTreatedAsOff(): void
    {
        self::assertSame($this->render(0.0), $this->render(-1.0));
    }

    public function testASkyProbeNoLongerBlacksOutInTheSunsShadow(): void
    {
        $off = self::meanRed($this->render(0.0, true));
        $on = self::meanRed($this->render(1.0, true));

        self::assertGreaterThan($off + 10.0, $on, "with a probe bound the reflection no longer follows the sun's shadow ({$off} -> {$on})");
    }

    public function testTogglingOnTheSameRendererRebuildsThePrograms(): void
    {
        $renderer = $this->renderer();
        $first = self::meanRed($this->draw($renderer, false));
        $renderer->setHemisphereSpecular(1.0);
        $second = self::meanRed($this->draw($renderer, false));
        $renderer->setHemisphereSpecular(0.0);
        $third = self::meanRed($this->draw($renderer, false));

        self::assertLessThan(3.0, $first);
        self::assertGreaterThan(20.0, $second, 'switched on between two frames');
        self::assertSame($first, $third, 'switched off again: the old output');
    }

    /** Mean red of the centre 16 x 16 pixels (the sphere). */
    private static function meanRed(string $image): float
    {
        $sum = 0;
        for ($y = 24; $y < 40; $y++) {
            for ($x = 24; $x < 40; $x++) {
                $sum += ord($image[($y * self::SIZE + $x) * 4]);
            }
        }

        return $sum / 256.0;
    }

    private function renderer(): VioRenderer3D
    {
        self::assertNotNull($this->ctx);
        $renderer = new VioRenderer3D($this->ctx, self::SIZE, self::SIZE);
        $renderer->applySettings(new GraphicsSettings(
            shadowQuality: ShadowQuality::Medium,
            ambientOcclusion: ScreenSpaceAO::Off,
            antiAliasing: AntiAliasing::Off,
            bloom: false,
        ));

        return $renderer;
    }

    private function render(float $strength, bool $sky = false): string
    {
        $renderer = $this->renderer();
        $renderer->setHemisphereSpecular($strength);

        return $this->draw($renderer, $sky);
    }

    private function draw(VioRenderer3D $renderer, bool $sky): string
    {
        $list = new RenderCommandList();
        $list->add(new SetCamera(
            Mat4::lookAt(new Vec3(0.0, 0.0, 10.0), new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 1.0, 0.0)),
            Mat4::orthographic(-2.0, 2.0, -2.0, 2.0, 1.0, 20.0),
        ));
        if ($sky) {
            $list->add(new SetSky(
                new Vec3(0.0, 1.0, 0.0), new Color(1.0, 0.95, 0.85), 1.0,
                new Color(0.4, 0.55, 0.8), new Color(0.8, 0.82, 0.85), new Color(0.3, 0.3, 0.3),
            ));
        }
        // A studio with a key light from above and a roof over the ball: the ball lies in the
        // sun's shadow, where the default reflection (scaled by that shadow) and the sun
        // both vanish and a rough metal has no diffuse term.
        $list->add(new SetAmbientLight(new Color(1.0, 1.0, 1.0), 0.6));
        $list->add(new SetDirectionalLight(new Vec3(0.0, -1.0, 0.0), new Color(1.0, 1.0, 1.0), 2.0));
        $list->add(new DrawMesh('roof', 'metal', Mat4::translation(0.0, 3.0, 0.0)));
        $list->add(new DrawMesh('ball', 'metal', Mat4::identity()));

        return $renderer->renderToImage($list, self::SIZE, self::SIZE);
    }
}
