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
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\DrawOutline;
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
 * DrawOutline end to end: a stencil mask of the mesh, then a ring of constant
 * pixel width where the mask is empty. Checked on the rendered image: the ring
 * sits just outside the silhouette, the mesh itself is untouched, and two
 * touching parts outlined together show no ring along their seam.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioRendererOutlineTest extends TestCase
{
    private const int W = 96;
    private const int H = 64;

    private const array CLEAR = [0.05, 0.05, 0.08];
    private const array RING = [0.0, 1.0, 1.0];

    private ?\VioContext $ctx = null;

    protected function setUp(): void
    {
        $ctx = @vio_create('auto', ['width' => self::W, 'height' => self::H, 'headless' => true, 'vsync' => false]);
        if ($ctx === false) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        if (!vio_supports_feature($ctx, VIO_FEATURE_3D_PIPELINE)
            || !defined('VIO_FEATURE_STENCIL') || !vio_supports_feature($ctx, VIO_FEATURE_STENCIL)
        ) {
            vio_destroy($ctx);
            $this->markTestSkipped('backend has no stencil buffer');
        }
        $this->ctx = $ctx;
        MeshRegistry::clear();
        MaterialRegistry::clear();
        CubemapRegistry::clear();
        MeshRegistry::register('box', BoxMesh::generate(1.0, 1.0, 1.0));
        MaterialRegistry::register('orange', new Material(albedo: new Color(0.9, 0.4, 0.15)));
    }

    protected function tearDown(): void
    {
        putenv('PHPOLYGON_VIO_MRT');
        if ($this->ctx !== null) {
            vio_destroy($this->ctx);
            $this->ctx = null;
        }
    }

    public function testRingSitsOutsideTheSilhouetteAndTheMeshIsUntouched(): void
    {
        $box = [Mat4::identity()];
        $plain = $this->render($box, outlined: false);
        $outlined = $this->render($box, outlined: true);

        $y = intdiv(self::H, 2);
        $left = $this->firstNonClearX($plain, $y);
        self::assertNotNull($left, 'the box is in the frame');
        self::assertGreaterThan(4, $left, 'room for the ring left of the box');

        self::assertTrue($this->isClear(self::px($plain, $left - 2, $y)), 'no ring without DrawOutline');
        self::assertTrue($this->isRing(self::px($outlined, $left - 2, $y)), 'ring two pixels left of the silhouette');

        $centre = intdiv(self::W, 2);
        self::assertSame(self::px($plain, $centre, $y), self::px($outlined, $centre, $y), 'the mesh itself keeps its colour');
        self::assertTrue($this->isClear(self::px($outlined, 1, 1)), 'far from the mesh nothing changes');
    }

    public function testTouchingPartsShareOneOutlineWithoutSeam(): void
    {
        $parts = [Mat4::translation(-0.5, 0.0, 0.0), Mat4::translation(0.5, 0.0, 0.0)];
        $outlined = $this->render($parts, outlined: true);

        $y = intdiv(self::H, 2);
        foreach ([-1, 0, 1] as $dx) {
            self::assertFalse(
                $this->isRing(self::px($outlined, intdiv(self::W, 2) + $dx, $y)),
                'no ring where the two outlined parts touch',
            );
        }
        $left = $this->firstNonClearX($outlined, $y);
        self::assertNotNull($left);
        self::assertTrue($this->isRing(self::px($outlined, $left - 2, $y)), 'the merged outline still rings the outer edge');
    }

    public function testMrtScenePathDrawsTheSameRing(): void
    {
        self::assertNotNull($this->ctx);
        if (!in_array(vio_backend_name($this->ctx), ['d3d11', 'd3d12'], true)
            || !defined('VIO_FEATURE_MRT') || !vio_supports_feature($this->ctx, VIO_FEATURE_MRT)
        ) {
            $this->markTestSkipped('MRT scene path engages on Direct3D only');
        }
        $box = [Mat4::identity()];
        $forward = $this->render($box, outlined: true);
        putenv('PHPOLYGON_VIO_MRT=1');
        $mrt = $this->render($box, outlined: true, ambientOcclusion: ScreenSpaceAO::Medium);

        $y = intdiv(self::H, 2);
        $left = $this->firstNonClearX($forward, $y);
        self::assertNotNull($left);
        self::assertTrue($this->isRing(self::px($forward, $left - 2, $y)));
        self::assertTrue($this->isRing(self::px($mrt, $left - 2, $y)), 'the outline pass runs after the MRT composite too');
    }

    /** @param list<Mat4> $parts */
    private function render(array $parts, bool $outlined, ScreenSpaceAO $ambientOcclusion = ScreenSpaceAO::Off): string
    {
        self::assertNotNull($this->ctx);
        $renderer = new VioRenderer3D($this->ctx, self::W, self::H);
        $renderer->applySettings(new GraphicsSettings(
            shadowQuality: ShadowQuality::Off,
            antiAliasing: AntiAliasing::Off,
            ambientOcclusion: $ambientOcclusion,
            bloom: false,
        ));
        self::assertTrue($renderer->outlineSupported());

        $list = new RenderCommandList();
        $list->add(new SetCamera(
            Mat4::lookAt(new Vec3(0.0, 0.0, 6.0), new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 1.0, 0.0)),
            Mat4::perspective(deg2rad(40.0), self::W / self::H, 0.1, 100.0),
        ));
        $list->add(new SetAmbientLight(new Color(1.0, 1.0, 1.0), 1.0));
        foreach ($parts as $matrix) {
            $list->add(new DrawMesh('box', 'orange', $matrix));
        }
        if ($outlined) {
            foreach ($parts as $matrix) {
                $list->add(new DrawOutline('box', $matrix, new Color(...self::RING), 4.0));
            }
        }

        $image = $renderer->renderToImage($list, self::W, self::H, new Color(...self::CLEAR));
        self::assertSame(self::W * self::H * 4, strlen($image));
        return $image;
    }

    private function firstNonClearX(string $image, int $y): ?int
    {
        for ($x = 0; $x < intdiv(self::W, 2); $x++) {
            if (!$this->isClear(self::px($image, $x, $y)) && !$this->isRing(self::px($image, $x, $y))) {
                return $x;
            }
        }
        return null;
    }

    /** @param array{0: int, 1: int, 2: int} $px */
    private function isClear(array $px): bool
    {
        return self::near($px, self::CLEAR, 12);
    }

    /** @param array{0: int, 1: int, 2: int} $px */
    private function isRing(array $px): bool
    {
        return self::near($px, self::RING, 40);
    }

    /**
     * @param array{0: int, 1: int, 2: int} $px
     * @param array{0: float, 1: float, 2: float} $rgb
     */
    private static function near(array $px, array $rgb, int $tolerance): bool
    {
        for ($i = 0; $i < 3; $i++) {
            if (abs($px[$i] - (int) round($rgb[$i] * 255.0)) > $tolerance) {
                return false;
            }
        }
        return true;
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function px(string $rgba, int $x, int $y): array
    {
        $o = ($y * self::W + $x) * 4;
        return [ord($rgba[$o]), ord($rgba[$o + 1]), ord($rgba[$o + 2])];
    }
}
