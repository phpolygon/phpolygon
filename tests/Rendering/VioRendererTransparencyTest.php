<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Geometry\PlaneMesh;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\CubemapRegistry;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ScreenSpaceAO;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * The transparent pass tests depth but never writes it. Checked on rendered
 * pixels: a transparent surface submitted after another one still shows
 * behind it, a thin glass box looks the same in both triangle halves of its
 * face, and a pane under a water surface stays visible - on the forward path
 * and on the MRT path.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioRendererTransparencyTest extends TestCase
{
    private const int W = 96;
    private const int H = 96;

    private const array CLEAR = [0.05, 0.05, 0.08];

    private ?\VioContext $ctx = null;

    protected function setUp(): void
    {
        $ctx = @vio_create('auto', ['width' => self::W, 'height' => self::H, 'headless' => true, 'vsync' => false]);
        if ($ctx === false) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        if (!vio_supports_feature($ctx, VIO_FEATURE_3D_PIPELINE)) {
            vio_destroy($ctx);
            $this->markTestSkipped('backend has no 3D pipeline');
        }
        $this->ctx = $ctx;
        MeshRegistry::clear();
        MaterialRegistry::clear();
        CubemapRegistry::clear();
        MeshRegistry::register('box', BoxMesh::generate(1.0, 1.0, 1.0));
        $glass = static fn (float $r, float $g, float $b, float $a): Material =>
            new Material(albedo: new Color($r, $g, $b), alpha: $a, useEnvironmentMap: false);
        MaterialRegistry::register('pane_red', $glass(0.9, 0.05, 0.05, 0.5));
        MaterialRegistry::register('pane_green', $glass(0.05, 0.9, 0.05, 0.5));
        MaterialRegistry::register('glass_clear', $glass(0.1, 0.1, 0.12, 0.3));
        MaterialRegistry::register('surface_green', $glass(0.05, 0.8, 0.3, 0.8));
        MaterialRegistry::register('pane_orange', $glass(0.95, 0.45, 0.05, 0.6));
        MaterialRegistry::register('floor', new Material(albedo: new Color(0.3, 0.3, 0.35), useEnvironmentMap: false));
    }

    protected function tearDown(): void
    {
        putenv('PHPOLYGON_VIO_MRT');
        if ($this->ctx !== null) {
            vio_destroy($this->ctx);
            $this->ctx = null;
        }
    }

    public function testSurfaceSubmittedAfterNearerGlassStillShowsBehindIt(): void
    {
        // Glass first, then the (farther) green surface: the order a centre
        // sort produces for a large surface whose centre is nearer than the pane.
        $glassOnly = $this->render([['glass_clear', self::pane(0.0, 0.0, 1.0)]]);
        $both = $this->render([
            ['glass_clear', self::pane(0.0, 0.0, 1.0)],
            ['surface_green', self::pane(0.0, 0.0, -1.0, 3.0)],
        ]);

        $c = self::px($both, 48, 48);
        $g = self::px($glassOnly, 48, 48);
        self::assertGreaterThan($g[1] + 40, $c[1], 'the green surface behind the glass is visible');
    }

    public function testPanesInWrongOrderBothShow(): void
    {
        // Front red pane first, back green pane second (a sort error): with a
        // depth-writing transparent pass the green pane vanished behind the red.
        $redOnly = $this->render([['pane_red', self::pane(0.0, 0.0, 0.5)]]);
        $both = $this->render([
            ['pane_red', self::pane(0.0, 0.0, 0.5)],
            ['pane_green', self::pane(0.0, 0.0, -0.5)],
        ]);

        $c = self::px($both, 48, 48);
        $r = self::px($redOnly, 48, 48);
        self::assertGreaterThan($r[1] + 25, $c[1], 'the green pane shows through the red one');
        self::assertGreaterThan($c[2] + 10, $c[0], 'the red pane is still on top');
    }

    public function testBothTriangleHalvesOfAThinGlassBoxLookTheSame(): void
    {
        // One thin box (front face, back face) seen slightly from the side and
        // above, so front and back faces overlap with an offset.
        $view = Mat4::lookAt(new Vec3(1.2, 0.9, 5.0), new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 1.0, 0.0));
        $image = $this->render([['pane_orange', self::pane(0.0, 0.0, 0.0, 2.0)]], view: $view);

        $samples = [self::px($image, 40, 40), self::px($image, 56, 56), self::px($image, 40, 56), self::px($image, 56, 40)];
        foreach ($samples as $i => $s) {
            for ($ch = 0; $ch < 3; $ch++) {
                self::assertEqualsWithDelta(
                    $samples[0][$ch],
                    $s[$ch],
                    6,
                    sprintf('sample %d channel %d differs: both triangle halves must layer the same', $i, $ch),
                );
            }
        }
    }

    public function testGlassBoxShowsItsFarFace(): void
    {
        // A 2 m glass cube seen from the front: its far face lies behind the
        // near one and must add a second layer of tint. With a depth-writing
        // transparent pass the far face (emitted after the near face) was
        // rejected and the cube looked like a single pane.
        // Reference: the same two faces as separate quads, far one first. One
        // face is back-facing in the cube; it must also be lit like the quads.
        MeshRegistry::register('quad', PlaneMesh::generate(2.0, 2.0, 1));
        $cube = $this->render([['pane_orange', Mat4::scaling(2.0, 2.0, 2.0)]]);
        $upright = Mat4::rotationX(deg2rad(90.0));
        $twoPanes = $this->render([
            ['pane_orange', Mat4::translation(0.0, 0.0, -1.0)->multiply($upright)],
            ['pane_orange', Mat4::translation(0.0, 0.0, 1.0)->multiply($upright)],
        ], mesh: 'quad');

        $a = self::px($cube, 48, 48);
        $b = self::px($twoPanes, 48, 48);
        for ($ch = 0; $ch < 3; $ch++) {
            self::assertEqualsWithDelta(
                $b[$ch],
                $a[$ch],
                6,
                sprintf('the cube (%d,%d,%d) layers like two sorted panes (%d,%d,%d)', ...$a, ...$b),
            );
        }
    }

    public function testPaneUnderTheWaterSurfaceStaysVisible(): void
    {
        // Looking down at a translucent horizontal surface (drawn first, as the
        // water layer is) with an orange pane standing half below it.
        $view = Mat4::lookAt(new Vec3(0.0, 3.0, 3.0), new Vec3(0.0, -0.3, 0.0), new Vec3(0.0, 1.0, 0.0));
        $surface = Mat4::translation(0.0, 0.0, 0.0)->multiply(Mat4::scaling(8.0, 0.01, 8.0));
        $paneBelow = Mat4::translation(0.0, -0.6, 0.0)->multiply(Mat4::scaling(1.0, 1.0, 0.03));

        $without = $this->render([['surface_green', $surface]], view: $view);
        $with = $this->render([['surface_green', $surface], ['pane_orange', $paneBelow]], view: $view);

        // Centre of the frame: the submerged part of the pane.
        $a = self::px($without, 48, 52);
        $b = self::px($with, 48, 52);
        self::assertGreaterThan($a[0] + 40, $b[0], 'the submerged pane shows (red rises)');
    }

    public function testMrtPathDoesNotWriteTransparentDepthEither(): void
    {
        self::assertNotNull($this->ctx);
        if (!in_array(vio_backend_name($this->ctx), ['d3d11', 'd3d12'], true)
            || !defined('VIO_FEATURE_MRT') || !vio_supports_feature($this->ctx, VIO_FEATURE_MRT)
        ) {
            $this->markTestSkipped('MRT scene path engages on Direct3D only');
        }
        putenv('PHPOLYGON_VIO_MRT=1');
        $glassOnly = $this->render([['glass_clear', self::pane(0.0, 0.0, 1.0)]], ScreenSpaceAO::Medium);
        $both = $this->render([
            ['glass_clear', self::pane(0.0, 0.0, 1.0)],
            ['surface_green', self::pane(0.0, 0.0, -1.0, 3.0)],
        ], ScreenSpaceAO::Medium);

        $c = self::px($both, 48, 48);
        $g = self::px($glassOnly, 48, 48);
        self::assertGreaterThan($g[1] + 40, $c[1], 'MRT: the green surface behind the glass is visible');
    }

    private static function pane(float $x, float $y, float $z, float $size = 1.6): Mat4
    {
        return Mat4::translation($x, $y, $z)->multiply(Mat4::scaling($size, $size, 0.03));
    }

    /** @param list<array{0: string, 1: Mat4}> $draws in submission order */
    private function render(array $draws, ScreenSpaceAO $ao = ScreenSpaceAO::Off, ?Mat4 $view = null, string $mesh = 'box'): string
    {
        self::assertNotNull($this->ctx);
        $renderer = new VioRenderer3D($this->ctx, self::W, self::H);
        $renderer->applySettings(new GraphicsSettings(
            shadowQuality: ShadowQuality::Off,
            antiAliasing: AntiAliasing::Off,
            ambientOcclusion: $ao,
            bloom: false,
        ));

        $list = new RenderCommandList();
        $list->add(new SetCamera(
            $view ?? Mat4::lookAt(new Vec3(0.0, 0.0, 6.0), new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 1.0, 0.0)),
            Mat4::perspective(deg2rad(45.0), self::W / self::H, 0.1, 100.0),
        ));
        $list->add(new SetAmbientLight(new Color(1.0, 1.0, 1.0), 1.0));
        foreach ($draws as [$material, $matrix]) {
            $list->add(new DrawMesh($mesh, $material, $matrix));
        }

        $image = $renderer->renderToImage($list, self::W, self::H, new Color(...self::CLEAR));
        self::assertSame(self::W * self::H * 4, strlen($image));
        return $image;
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function px(string $image, int $x, int $y): array
    {
        $o = ($y * self::W + $x) * 4;
        return [ord($image[$o]), ord($image[$o + 1]), ord($image[$o + 2])];
    }
}
