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
use PHPolygon\Math\Vec4;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetDirectionalLight;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ScreenSpaceAO;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\RenderLayers;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * A body hidden from the main camera (a draw with shadowOnly, what Renderer3DSystem emits for
 * MeshRenderer::$hiddenFromMainCamera) is not seen, but its sun shadow lies on the floor.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioHiddenBodyShadowTest extends TestCase
{
    private const W = 128;
    private const H = 96;

    private ?\VioContext $ctx = null;

    protected function setUp(): void
    {
        $ctx = VioTestContext::create(self::W, self::H, ['shader_cache' => sys_get_temp_dir() . '/phpolygon-test-shader-cache']);
        if ($ctx === null) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        $this->ctx = $ctx;
        MeshRegistry::clear();
        MaterialRegistry::clear();
        MeshRegistry::register('box', BoxMesh::generate(1.0, 1.0, 1.0));
        MaterialRegistry::register('floor', new Material(albedo: new Color(0.7, 0.7, 0.7), roughness: 0.9, useEnvironmentMap: false));
        MaterialRegistry::register('body', new Material(albedo: new Color(0.9, 0.7, 0.1), roughness: 0.9, useEnvironmentMap: false));
    }

    protected function tearDown(): void
    {
        MeshRegistry::clear();
        MaterialRegistry::clear();
        if ($this->ctx !== null) {
            vio_destroy($this->ctx);
            $this->ctx = null;
        }
    }

    private function frame(?bool $shadowOnly): string
    {
        self::assertNotNull($this->ctx);
        $renderer = new VioRenderer3D($this->ctx, self::W, self::H);
        $renderer->applySettings(new GraphicsSettings(
            shadowQuality: ShadowQuality::Medium,
            shadowDistance: 30.0,
            ambientOcclusion: ScreenSpaceAO::Off,
            antiAliasing: AntiAliasing::Off,
            bloom: false,
        ));
        $l = new RenderCommandList();
        $l->add(new SetCamera(self::view(), self::projection()));
        $l->add(new SetAmbientLight(new Color(1.0, 1.0, 1.0), 0.3));
        $l->add(new SetDirectionalLight(new Vec3(-0.3, -1.0, -0.5), new Color(1.0, 1.0, 1.0), 1.5));
        $l->add(new DrawMesh('box', 'floor', Mat4::translation(0.0, -0.05, 0.0)->multiply(Mat4::scaling(20.0, 0.1, 20.0)), castShadows: false));
        if ($shadowOnly !== null) {
            $l->add(new DrawMesh('box', 'body', Mat4::translation(0.2, 0.8, 1.2)->multiply(Mat4::scaling(0.5, 1.6, 0.5)), shadowOnly: $shadowOnly, layers: RenderLayers::BODY));
        }

        return $renderer->renderToImage($l, self::W, self::H, new Color(0.0, 0.0, 0.0));
    }

    private static function view(): Mat4
    {
        return Mat4::lookAt(new Vec3(4.0, 5.0, 5.0), new Vec3(0.0, 0.0, 0.6), new Vec3(0.0, 1.0, 0.0));
    }

    private static function projection(): Mat4
    {
        return Mat4::perspective(deg2rad(50.0), self::W / self::H, 0.1, 60.0);
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function at(string $rgba, Vec3 $p): array
    {
        $c = self::projection()->multiply(self::view())->multiplyVec4(new Vec4($p->x, $p->y, $p->z, 1.0));
        $col = (int) floor(($c->x / $c->w * 0.5 + 0.5) * self::W);
        $row = (int) floor((1.0 - ($c->y / $c->w * 0.5 + 0.5)) * self::H);
        $o = ($row * self::W + $col) * 4;

        return [ord($rgba[$o]), ord($rgba[$o + 1]), ord($rgba[$o + 2])];
    }

    public function testAHiddenBodyCastsItsShadowAndIsNotSeen(): void
    {
        $without = $this->frame(null);
        $hidden = $this->frame(true);
        $visible = $this->frame(false);

        // The shadow at body height 0.8 lands at x = 0.2 - 0.3 * 0.8, z = 1.2 - 0.5 * 0.8 on the floor.
        $spot = new Vec3(-0.04, 0.0, 0.8);
        $lit = self::at($without, $spot);
        $shadowed = self::at($hidden, $spot);
        self::assertLessThan($lit[0] - 25, $shadowed[0], sprintf('floor in the shadow of the hidden body: %d lit, %d shadowed', $lit[0], $shadowed[0]));

        // Not seen: the pixel where the body stands in the camera's view is the floor's.
        $bodySpot = new Vec3(0.2, 1.0, 1.2);
        self::assertSame(self::at($without, $bodySpot), self::at($hidden, $bodySpot), 'the hidden body does not appear');
        // The same draw without shadowOnly shows it (control).
        $seen = self::at($visible, $bodySpot);
        self::assertGreaterThan($seen[2] + 60, $seen[0], 'the control body is yellow');
    }
}
