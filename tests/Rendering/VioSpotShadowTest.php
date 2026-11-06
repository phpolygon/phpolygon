<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\AddSpotLight;
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
 * The spot shadow on the GPU: a beam from (3, 6, 0) aimed down-left past a small slab
 * at (0, 3, 0) lights a white floor seen straight from above. The slab's shadow lands
 * around x = -3 (beside the slab, so the camera sees it). With castsShadow the floor
 * there must come out much darker than with the same beam unshadowed; a lit spot of
 * the beam outside the shadow stays the same in both.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioSpotShadowTest extends TestCase
{
    private const SIZE = 32;

    private ?\VioContext $ctx = null;

    protected function setUp(): void
    {
        $ctx = VioTestContext::create(self::SIZE, self::SIZE, ['shader_cache' => sys_get_temp_dir() . '/phpolygon-test-shader-cache']);
        if ($ctx === null) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        $this->ctx = $ctx;
        MeshRegistry::clear();
        MaterialRegistry::clear();
        MeshRegistry::register('floor', BoxMesh::generate(20.0, 0.1, 20.0));
        MeshRegistry::register('slab', BoxMesh::generate(1.5, 0.3, 1.5));
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

    public function testTheSlabShadowsTheBeam(): void
    {
        [$shadowedLit, $shadowedOpen] = $this->render(true);
        [$plainLit, $plainOpen] = $this->render(false);

        self::assertGreaterThan(40, $plainLit, "the beam lights the floor without a shadow ({$plainLit})");
        self::assertLessThan($plainLit * 0.5, $shadowedLit, "the slab shadows the floor ({$shadowedLit} vs {$plainLit})");
        self::assertEqualsWithDelta($plainOpen, $shadowedOpen, 12, "outside the shadow the beam is unchanged ({$shadowedOpen} vs {$plainOpen})");
    }

    /**
     * Red channel at the shadow (x = -3, z = 0) and at a lit floor spot of the beam
     * (x = -1, z = 2.5).
     *
     * @return array{0: int, 1: int}
     */
    private function render(bool $castsShadow): array
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
            Mat4::lookAt(new Vec3(0.0, 10.0, 0.0), new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 0.0, -1.0)),
            Mat4::orthographic(-5.0, 5.0, -5.0, 5.0, 1.0, 50.0),
        ));
        $list->add(new SetAmbientLight(new Color(0.0, 0.0, 0.0), 0.0));
        $list->add(new AddSpotLight(
            new Vec3(3.0, 6.0, 0.0),
            (new Vec3(-1.0, -1.0, 0.0))->normalize(),
            new Color(1.0, 1.0, 1.0),
            3.0,
            20.0,
            0.8,
            0.2,
            $castsShadow,
        ));
        $list->add(new DrawMesh('floor', 'white', Mat4::translation(0.0, -0.05, 0.0)));
        $list->add(new DrawMesh('slab', 'white', Mat4::translation(0.0, 3.0, 0.0)));
        $image = $renderer->renderToImage($list, self::SIZE, self::SIZE);

        $at = function (float $x, float $z) use ($image): int {
            $col = (int) (($x + 5.0) / 10.0 * self::SIZE);
            $row = (int) (($z + 5.0) / 10.0 * self::SIZE);

            return ord($image[($row * self::SIZE + $col) * 4]);
        };

        return [$at(-3.0, 0.0), $at(-1.0, 2.5)];
    }
}
