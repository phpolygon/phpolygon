<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\AddPointLight;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ScreenSpaceAO;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * The point shadow cube on the GPU: a point light, a small slab between it and a white
 * floor seen straight from above. The slab's shadow is read through the cube's -Y face
 * (light high above) and through its -X face (light low at the side, the floor point
 * far along -X) – a face drawn with the wrong orientation puts the shadow elsewhere.
 * With castsShadow the floor there must come out much darker than unshadowed; a lit
 * floor spot outside the shadow stays the same.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioPointShadowTest extends TestCase
{
    private const SIZE = 64;
    private const HALF = 10.0;

    private ?\VioContext $ctx = null;

    protected function setUp(): void
    {
        $ctx = VioTestContext::create(self::SIZE, self::SIZE, ['shader_cache' => sys_get_temp_dir() . '/phpolygon-test-shader-cache']);
        if ($ctx === null) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        if (!vio_supports_feature($ctx, \VIO_FEATURE_RENDER_TARGET_LAYERED) || !vio_supports_feature($ctx, \VIO_FEATURE_RENDER_TARGET_CUBE)) {
            vio_destroy($ctx);
            $this->markTestSkipped('no depth cube render targets');
        }
        $this->ctx = $ctx;
        MeshRegistry::clear();
        MaterialRegistry::clear();
        MeshRegistry::register('floor', BoxMesh::generate(40.0, 0.1, 40.0));
        MeshRegistry::register('slab', BoxMesh::generate(1.0, 0.2, 1.0));
        MaterialRegistry::register('white', new Material(albedo: new Color(1.0, 1.0, 1.0), roughness: 1.0, useEnvironmentMap: false));
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

    /** @return array<string, array{0: Vec3, 1: Vec3, 2: float, 3: float, 4: float, 5: float}> */
    public static function faces(): array
    {
        // light, slab centre, shadow x, lit x (both at z = 0), intensity, radius
        return [
            'down face' => [new Vec3(1.0, 4.0, 0.0), new Vec3(0.0, 2.5, 0.0), -1.67, 3.0, 4.0, 12.0],
            'side face' => [new Vec3(4.0, 2.0, 0.0), new Vec3(1.0, 1.5, 0.0), -8.0, -4.0, 60.0, 20.0],
        ];
    }

    #[DataProvider('faces')]
    public function testTheSlabShadowsTheLight(Vec3 $light, Vec3 $slab, float $shadowX, float $litX, float $intensity, float $radius): void
    {
        [$shadowed, $shadowedLit] = $this->render(true, $light, $slab, $shadowX, $litX, $intensity, $radius);
        [$plain, $plainLit] = $this->render(false, $light, $slab, $shadowX, $litX, $intensity, $radius);

        self::assertGreaterThan(30, $plain, "the light reaches the floor without a shadow ({$plain})");
        self::assertLessThan($plain * 0.5, $shadowed, "the slab shadows the floor ({$shadowed} vs {$plain})");
        self::assertEqualsWithDelta($plainLit, $shadowedLit, 12, "outside the shadow the light is unchanged ({$shadowedLit} vs {$plainLit})");
    }

    /**
     * A glowing shell around the light (lamp glass, a flame) is the light source
     * itself: it must not close the cube, the floor stays lit.
     */
    public function testAGlowingShellAroundTheLightCastsNoShadow(): void
    {
        MeshRegistry::register('shell', BoxMesh::generate(0.6, 0.6, 0.6));
        MaterialRegistry::register('glow', new Material(albedo: new Color(1.0, 0.6, 0.2), emission: new Color(1.0, 0.6, 0.2)));
        $light = new Vec3(1.0, 4.0, 0.0);
        $extra = [new DrawMesh('shell', 'glow', Mat4::translation($light->x, $light->y, $light->z))];
        [$shadowed] = $this->render(true, $light, new Vec3(30.0, 0.0, 30.0), 3.0, 3.0, 4.0, 12.0, $extra);
        [$plain] = $this->render(false, $light, new Vec3(30.0, 0.0, 30.0), 3.0, 3.0, 4.0, 12.0, $extra);

        self::assertGreaterThan(30, $plain, "the light reaches the floor ({$plain})");
        self::assertEqualsWithDelta($plain, $shadowed, 12, "the glowing shell casts no shadow ({$shadowed} vs {$plain})");
    }

    /**
     * @param list<DrawMesh> $extra
     * @return array{0: int, 1: int} red at the shadow and at the lit spot
     */
    private function render(bool $castsShadow, Vec3 $light, Vec3 $slab, float $shadowX, float $litX, float $intensity, float $radius, array $extra = []): array
    {
        self::assertNotNull($this->ctx);
        $renderer = new VioRenderer3D($this->ctx, self::SIZE, self::SIZE);
        $renderer->applySettings(new GraphicsSettings(
            shadowQuality: ShadowQuality::High,
            ambientOcclusion: ScreenSpaceAO::Off,
            antiAliasing: AntiAliasing::Off,
            bloom: false,
        ));
        $list = new RenderCommandList();
        $list->add(new SetCamera(
            Mat4::lookAt(new Vec3(0.0, 20.0, 0.0), new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 0.0, -1.0)),
            Mat4::orthographic(-self::HALF, self::HALF, -self::HALF, self::HALF, 1.0, 50.0),
        ));
        $list->add(new SetAmbientLight(new Color(0.0, 0.0, 0.0), 0.0));
        $list->add(new AddPointLight($light, new Color(1.0, 1.0, 1.0), $intensity, $radius, $castsShadow));
        $list->add(new DrawMesh('floor', 'white', Mat4::translation(0.0, -0.05, 0.0)));
        $list->add(new DrawMesh('slab', 'white', Mat4::translation($slab->x, $slab->y, $slab->z)));
        foreach ($extra as $draw) {
            $list->add($draw);
        }
        $image = $renderer->renderToImage($list, self::SIZE, self::SIZE);

        $at = function (float $x) use ($image): int {
            $col = (int) (($x + self::HALF) / (2.0 * self::HALF) * self::SIZE);
            $row = self::SIZE / 2;

            return ord($image[($row * self::SIZE + $col) * 4]);
        };

        return [$at($shadowX), $at($litX)];
    }
}
