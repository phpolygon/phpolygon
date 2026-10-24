<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\MeshData;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Geometry\PlaneMesh;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\DrawMeshInstanced;
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetDirectionalLight;
use PHPolygon\Rendering\Command\SetWind;
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
 * Foliage on the GPU: an opaque two-sided leaf whose authored normal points
 * away from the camera is lit like its front (was: unlit, only sky ambient),
 * on the forward and the MRT path; foliage wind bends a tall mesh at its top
 * and leaves it still with foliageMotion 0; instanced batches with
 * castShadows false stay out of the shadow pass.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioFoliageRenderTest extends TestCase
{
    private const int W = 96;
    private const int H = 96;

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
        // A horizontal leaf whose normal points DOWN (and winding agrees), the
        // classic upside-down frond seen from above.
        MeshRegistry::register('leaf_up', PlaneMesh::generate(2.0, 2.0, 1));
        MeshRegistry::register('leaf_down', PlaneMesh::generate(2.0, 2.0, 1)->mirrored(1));
        MeshRegistry::register('box', BoxMesh::generate(1.0, 1.0, 1.0));
        $green = Color::srgb('#4F9A3A');
        MaterialRegistry::register('one_sided', new Material(albedo: $green, roughness: 0.8, useEnvironmentMap: false));
        MaterialRegistry::register('two_sided', new Material(albedo: $green, roughness: 0.8, useEnvironmentMap: false, twoSided: true));
        MaterialRegistry::register('swaying', new Material(albedo: new Color(0.9, 0.9, 0.9), roughness: 0.9, useEnvironmentMap: false, foliageWind: true));
        MaterialRegistry::register('floor', new Material(albedo: new Color(0.8, 0.8, 0.8), roughness: 0.9, useEnvironmentMap: false));
    }

    protected function tearDown(): void
    {
        putenv('PHPOLYGON_VIO_MRT');
        if ($this->ctx !== null) {
            vio_destroy($this->ctx);
            $this->ctx = null;
        }
    }

    public function testLeafSeenFromItsBackIsLitLikeItsFront(): void
    {
        $this->assertBackIsLit(ScreenSpaceAO::Off);
    }

    public function testTwoSidedOnTheMrtPath(): void
    {
        self::assertNotNull($this->ctx);
        if (!in_array(vio_backend_name($this->ctx), ['d3d11', 'd3d12'], true)
            || !defined('VIO_FEATURE_MRT') || !vio_supports_feature($this->ctx, VIO_FEATURE_MRT)
        ) {
            $this->markTestSkipped('MRT scene path engages on Direct3D only');
        }
        putenv('PHPOLYGON_VIO_MRT=1');
        $this->assertBackIsLit(ScreenSpaceAO::Medium);
    }

    private function assertBackIsLit(ScreenSpaceAO $ao): void
    {
        $view = Mat4::lookAt(new Vec3(0.0, 4.0, 0.01), new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 0.0, -1.0));
        $front = self::px($this->render([new DrawMesh('leaf_up', 'one_sided', Mat4::identity())], $view, $ao), 48, 48);
        $oldBack = self::px($this->render([new DrawMesh('leaf_down', 'one_sided', Mat4::identity())], $view, $ao), 48, 48);
        $newBack = self::px($this->render([new DrawMesh('leaf_down', 'two_sided', Mat4::identity())], $view, $ao), 48, 48);

        self::assertGreaterThan($oldBack[1] + 40, $front[1], sprintf('setup: a one-sided back is dark (front %d, back %d)', $front[1], $oldBack[1]));
        self::assertGreaterThan($oldBack[1] + 40, $newBack[1], 'two-sided: the back is lit');
        self::assertEqualsWithDelta($front[1], $newBack[1], 12, 'two-sided: the back looks like the front');
    }

    public function testFoliageWindBendsTheTopAndStandsStillWhenSwitchedOff(): void
    {
        // A 20 m pole whose foot is at its origin (the plant convention), seen from the front.
        $box = BoxMesh::generate(0.8, 20.0, 0.8);
        $v = $box->vertices;
        for ($i = 1, $n = count($v); $i < $n; $i += 3) {
            $v[$i] += 10.0;
        }
        MeshRegistry::register('pole', new MeshData($v, $box->normals, $box->uvs, $box->indices));
        $pole = Mat4::identity();
        $view = Mat4::lookAt(new Vec3(0.0, 10.0, 30.0), new Vec3(0.0, 10.0, 0.0), new Vec3(0.0, 1.0, 0.0));
        $draw = [new DrawMesh('pole', 'swaying', $pole)];
        $still = $this->render($draw, $view, wind: new SetWind(new Vec3(1.0, 0.0, 0.0), 4.0, foliageMotion: 0.0), time: 0.6);
        $windy = $this->render($draw, $view, wind: new SetWind(new Vec3(1.0, 0.0, 0.0), 4.0), time: 0.6);
        $calm = $this->render($draw, $view, wind: new SetWind(new Vec3(1.0, 0.0, 0.0), 0.0), time: 0.6);

        $topStill = self::centroidX($still, 6, 14);
        $topWindy = self::centroidX($windy, 6, 14);
        $footStill = self::centroidX($still, 80, 88);
        $footWindy = self::centroidX($windy, 80, 88);
        self::assertGreaterThan($topStill + 1.5, $topWindy, sprintf('the top leans with the wind (+X): %.2f -> %.2f px', $topStill, $topWindy));
        self::assertEqualsWithDelta($footStill, $footWindy, 0.5, 'the foot stays put');
        self::assertEqualsWithDelta($topStill, self::centroidX($calm, 6, 14), 0.5, 'no wind, no bend');
    }

    public function testInstancedBatchWithoutShadowsCastsNone(): void
    {
        // A box floating over a floor, lit from straight above: with shadows its
        // shadow darkens the floor below it, without it the floor stays bright.
        $view = Mat4::lookAt(new Vec3(0.0, 6.0, 6.0), new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 1.0, 0.0));
        $floor = new DrawMesh('box', 'floor', Mat4::translation(0.0, -0.05, 0.0)->multiply(Mat4::scaling(12.0, 0.1, 12.0)));
        $box = Mat4::translation(0.0, 1.5, 0.0)->multiply(Mat4::scaling(2.0, 0.4, 2.0));
        $flat = $box->toArray();
        $with = $this->render([$floor, DrawMeshInstanced::flat('box', 'floor', $flat, 1)], $view, shadows: true, sun: new Vec3(0.0, -1.0, 0.8));
        $without = $this->render([$floor, DrawMeshInstanced::flat('box', 'floor', $flat, 1, castShadows: false)], $view, shadows: true, sun: new Vec3(0.0, -1.0, 0.8));
        // The sun leans toward +Z, so the shadow falls 1.2 m toward the camera:
        // sample the floor at z = 1.9, in the shadow and below the box's image.
        [$sx, $sy] = self::project(new Vec3(0.0, 0.0, 1.9), $view);
        $a = self::px($with, $sx, $sy);
        $b = self::px($without, $sx, $sy);
        self::assertGreaterThan($a[1] + 20, $b[1], sprintf('no shadow without castShadows (%d vs %d)', $a[1], $b[1]));
    }

    /**
     * @param list<object> $draws
     */
    private function render(array $draws, Mat4 $view, ScreenSpaceAO $ao = ScreenSpaceAO::Off, ?SetWind $wind = null, float $time = 0.0, bool $shadows = false, ?Vec3 $sun = null): string
    {
        self::assertNotNull($this->ctx);
        $renderer = new VioRenderer3D($this->ctx, self::W, self::H);
        $renderer->applySettings(new GraphicsSettings(
            shadowQuality: $shadows ? ShadowQuality::High : ShadowQuality::Off,
            antiAliasing: AntiAliasing::Off,
            ambientOcclusion: $ao,
            bloom: false,
        ));
        $renderer->setAnimationTime($time);
        $list = new RenderCommandList();
        $list->add(new SetCamera($view, Mat4::perspective(deg2rad(50.0), self::W / self::H, 0.1, 200.0)));
        $list->add(new SetAmbientLight(new Color(0.6, 0.7, 0.9), 0.15));
        $list->add(new SetDirectionalLight(($sun ?? new Vec3(0.1, -1.0, 0.05))->normalize(), new Color(1.0, 1.0, 1.0), 2.0));
        if ($wind !== null) {
            $list->add($wind);
        }
        foreach ($draws as $d) {
            $list->add($d);
        }
        $image = $renderer->renderToImage($list, self::W, self::H, new Color(0.0, 0.0, 0.0));
        self::assertSame(self::W * self::H * 4, strlen($image));
        return $image;
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function px(string $image, int $x, int $y): array
    {
        $o = ($y * self::W + $x) * 4;
        return [ord($image[$o]), ord($image[$o + 1]), ord($image[$o + 2])];
    }

    /** @return array{0: int, 1: int} pixel of a world point */
    private static function project(Vec3 $p, Mat4 $view): array
    {
        $clip = Mat4::perspective(deg2rad(50.0), self::W / self::H, 0.1, 200.0)->multiply($view)
            ->multiplyVec4(new \PHPolygon\Math\Vec4($p->x, $p->y, $p->z, 1.0));
        return [(int) round(($clip->x / $clip->w * 0.5 + 0.5) * self::W), (int) round((0.5 - $clip->y / $clip->w * 0.5) * self::H)];
    }

    /** Mean x of the lit (non-black) pixels in rows [$y0, $y1). */
    private static function centroidX(string $image, int $y0, int $y1): float
    {
        $sum = 0.0;
        $n = 0;
        for ($y = $y0; $y < $y1; $y++) {
            for ($x = 0; $x < self::W; $x++) {
                $p = self::px($image, $x, $y);
                if ($p[0] + $p[1] + $p[2] > 60) {
                    $sum += $x;
                    $n++;
                }
            }
        }
        self::assertGreaterThan(0, $n, "lit pixels in rows {$y0}-{$y1}");
        return $sum / $n;
    }

    public function testMirroredPlaneReallyFacesDown(): void
    {
        $m = MeshRegistry::get('leaf_down');
        self::assertInstanceOf(MeshData::class, $m);
        self::assertLessThan(0.0, $m->normals[1]);
    }
}
