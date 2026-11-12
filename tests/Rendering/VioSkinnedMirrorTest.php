<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use PHPolygon\Animation\PoseRig;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Geometry\SkinnedMeshData;
use PHPolygon\Geometry\SkinnedMeshRegistry;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Math\Vec4;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\DrawSkinnedMesh;
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetDirectionalLight;
use PHPolygon\Rendering\Command\SetPlanarMirror;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\ProcModeRegistry;
use PHPolygon\Rendering\ProcModeShaderRegistry;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ScreenSpaceAO;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\Reflection\PlanarMirrors;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\RenderLayers;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * A skinned player body (DrawSkinnedMesh, hidden from the main camera, on the BODY layer) in a planar
 * mirror and as a shadow caster: not seen by the camera, seen in the mirror, its sun shadow on the floor.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioSkinnedMirrorTest extends TestCase
{
    private const W = 128;
    private const H = 96;

    private ?\VioContext $ctx = null;

    protected function setUp(): void
    {
        $ctx = VioTestContext::create(self::W, self::H, ['shader_model' => 6, 'shader_cache' => sys_get_temp_dir() . '/phpolygon-test-shader-cache']);
        if ($ctx === null) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        $this->ctx = $ctx;
        $this->clear();
    }

    protected function tearDown(): void
    {
        putenv('PHPOLYGON_VIO_MRT');
        putenv('PHPOLYGON_SSAO');
        $this->clear();
        if ($this->ctx !== null) {
            vio_destroy($this->ctx);
            $this->ctx = null;
        }
    }

    private function clear(): void
    {
        MeshRegistry::clear();
        MaterialRegistry::clear();
        SkinnedMeshRegistry::clear();
        ProcModeRegistry::clear();
        ProcModeShaderRegistry::clear();
        PlanarMirrors::clear();
    }

    private function register(): void
    {
        $this->clear();
        PlanarMirrors::register();
        PlanarMirrors::registerMode(3);
        MeshRegistry::register('box', BoxMesh::generate(1.0, 1.0, 1.0));
        $box = BoxMesh::generate(1.0, 1.0, 1.0);
        $rig = (new PoseRig())->joint('root', null, new Vec3(0.0, 0.0, 0.0));
        SkinnedMeshRegistry::register('body', SkinnedMeshData::fromInfluences($box, array_fill(0, $box->vertexCount(), ['root' => 1.0]), $rig));
        $flat = static fn (float $r, float $g, float $b): Material => new Material(albedo: new Color($r, $g, $b), roughness: 0.9, useEnvironmentMap: false);
        MaterialRegistry::register('floor', $flat(0.5, 0.5, 0.5));
        MaterialRegistry::register('wall', $flat(0.6, 0.6, 0.6));
        MaterialRegistry::register('skin', $flat(0.9, 0.7, 0.1));
        MaterialRegistry::register('mirror', new Material(albedo: new Color(1.0, 1.0, 1.0), roughness: 0.05, useEnvironmentMap: false));
        ProcModeRegistry::map('mirror', 3);
    }

    private function renderer(ShadowQuality $shadows): VioRenderer3D
    {
        self::assertNotNull($this->ctx);
        $r = new VioRenderer3D($this->ctx, self::W, self::H);
        $r->applySettings(new GraphicsSettings(shadowQuality: $shadows, shadowDistance: 30.0, ambientOcclusion: ScreenSpaceAO::Off, antiAliasing: AntiAliasing::Off, bloom: false));

        return $r;
    }

    private static function view(Vec3 $eye): Mat4
    {
        return Mat4::lookAt($eye, new Vec3(0.0, 1.2, -3.0), new Vec3(0.0, 1.0, 0.0));
    }

    private static function projection(): Mat4
    {
        return Mat4::perspective(deg2rad(60.0), self::W / self::H, 0.1, 60.0);
    }

    private static function palette(): string
    {
        return SkinnedMeshData::packPalette([1.0, 0.0, 0.0, 0.0, 0.0, 1.0, 0.0, 0.0, 0.0, 0.0, 1.0, 0.0, 0.0, 0.0, 0.0, 1.0]);
    }

    /** @param ?bool $shadowOnly null = no body */
    private static function scene(Vec3 $eye, bool $mirror, ?bool $shadowOnly, int $mask = RenderLayers::MIRROR_DEFAULT, bool $sun = false): RenderCommandList
    {
        $l = new RenderCommandList();
        $l->add(new SetCamera(self::view($eye), self::projection()));
        $l->add(new SetAmbientLight(new Color(1.0, 1.0, 1.0), $sun ? 0.3 : 0.6));
        $l->add(new SetDirectionalLight(new Vec3(-0.3, -1.0, -0.5), new Color(1.0, 1.0, 1.0), $sun ? 1.5 : 1.0));
        $l->add(new DrawMesh('box', 'floor', Mat4::translation(0.0, -0.05, 2.0)->multiply(Mat4::scaling(12.0, 0.1, 16.0)), castShadows: false));
        $l->add(new DrawMesh('box', 'wall', Mat4::translation(0.0, 1.5, -3.1)->multiply(Mat4::scaling(12.0, 3.0, 0.2))));
        $l->add(new DrawMesh('box', 'mirror', Mat4::translation(0.0, 1.5, -2.99)->multiply(Mat4::scaling(4.8, 2.8, 0.02)), layers: RenderLayers::MIRROR_SURFACE));
        if ($shadowOnly !== null) {
            $l->add(new DrawSkinnedMesh('body', 'skin', [Mat4::translation(0.2, 0.8, 1.2)->multiply(Mat4::scaling(0.5, 1.6, 0.5))], [self::palette()], [], true, [], $shadowOnly, RenderLayers::BODY));
        }
        if ($mirror) {
            $l->add(new SetPlanarMirror(new Vec3(0.0, 1.5, -2.98), new Vec3(0.0, 0.0, 1.0), new Vec3(1.0, 0.0, 0.0), 2.4, 1.4, 0.75, 20.0, 1, $mask, 0.0, 'wall'));
        }

        return $l;
    }

    /** @return array{0: int, 1: int} */
    private static function project(Vec3 $eye, Vec3 $p): array
    {
        $c = self::projection()->multiply(self::view($eye))->multiplyVec4(new Vec4($p->x, $p->y, $p->z, 1.0));

        return [(int) floor(($c->x / $c->w * 0.5 + 0.5) * self::W), (int) floor((1.0 - ($c->y / $c->w * 0.5 + 0.5)) * self::H)];
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function px(string $rgba, int $col, int $row): array
    {
        $o = ($row * self::W + $col) * 4;

        return [ord($rgba[$o]), ord($rgba[$o + 1]), ord($rgba[$o + 2])];
    }

    private static function isYellow(array $c): bool
    {
        return $c[0] > 120 && $c[1] > 90 && $c[0] - $c[2] > 70 && abs($c[0] - $c[1]) < 70;
    }

    /** @return array<string, array{0: string}> */
    public static function paths(): array
    {
        return ['forward' => ['0'], 'mrt' => ['1']];
    }

    #[DataProvider('paths')]
    public function testAHiddenSkinnedBodyIsInTheMirrorAndNotInTheView(string $mrt): void
    {
        if ($mrt === '1') {
            if (!defined('VIO_FEATURE_MRT') || !vio_supports_feature($this->ctx, VIO_FEATURE_MRT)) {
                $this->markTestSkipped('backend has no MRT scene path');
            }
            putenv('PHPOLYGON_SSAO=1');
        }
        putenv('PHPOLYGON_VIO_MRT=' . $mrt);
        $this->register();
        $eye = new Vec3(0.3, 1.6, 4.0);
        $renderer = $this->renderer(ShadowQuality::Off);
        if (!$renderer->skinningAvailable()) {
            $this->markTestSkipped('no vertex storage buffers on this backend');
        }
        $image = $renderer->renderToImage(self::scene($eye, true, true), self::W, self::H, new Color(0.0, 0.0, 0.0));

        self::assertTrue($renderer->planarMirrorBound());
        [$c, $r] = self::project($eye, new Vec3(0.2, 0.8, -7.16));
        self::assertTrue(self::isYellow(self::px($image, $c, $r)), 'the hidden skinned body is in the mirror: ' . json_encode(self::px($image, $c, $r)));
        [$c, $r] = self::project($eye, new Vec3(0.2, 0.8, 1.2));
        self::assertFalse(self::isYellow(self::px($image, $c, $r)), 'hidden from the main camera');

        // Control: the same body, not hidden, is seen by the camera.
        $seen = $renderer->renderToImage(self::scene($eye, true, false), self::W, self::H, new Color(0.0, 0.0, 0.0));
        self::assertTrue(self::isYellow(self::px($seen, $c, $r)), 'the visible control body is yellow in the view');

        // A mask without the body layer leaves it out of the mirror.
        $mask = RenderLayers::MIRROR_DEFAULT & ~RenderLayers::BODY;
        $noBody = $renderer->renderToImage(self::scene($eye, true, true, $mask), self::W, self::H, new Color(0.0, 0.0, 0.0));
        [$c, $r] = self::project($eye, new Vec3(0.2, 0.8, -7.16));
        self::assertFalse(self::isYellow(self::px($noBody, $c, $r)), 'the body layer is outside the mask');
    }

    public function testAHiddenSkinnedBodyCastsItsShadow(): void
    {
        $this->register();
        $eye = new Vec3(4.0, 5.0, 5.0);
        $renderer = $this->renderer(ShadowQuality::Medium);
        if (!$renderer->skinningAvailable()) {
            $this->markTestSkipped('no vertex storage buffers on this backend');
        }
        $view = Mat4::lookAt($eye, new Vec3(0.0, 0.0, 0.6), new Vec3(0.0, 1.0, 0.0));
        $frame = function (?bool $shadowOnly) use ($renderer, $view): string {
            $l = self::scene($eye = new Vec3(4.0, 5.0, 5.0), false, $shadowOnly, RenderLayers::MIRROR_DEFAULT, true);
            $cmds = array_values(array_filter($l->getCommands(), static fn (object $c): bool => !($c instanceof SetCamera)));
            $l->replaceCommands([new SetCamera($view, self::projection()), ...$cmds]);

            return $renderer->renderToImage($l, self::W, self::H, new Color(0.0, 0.0, 0.0));
        };
        $without = $frame(null);
        $hidden = $frame(true);
        $at = static function (string $rgba, Vec3 $p) use ($view): int {
            $c = self::projection()->multiply($view)->multiplyVec4(new Vec4($p->x, $p->y, $p->z, 1.0));
            $col = (int) floor(($c->x / $c->w * 0.5 + 0.5) * self::W);
            $row = (int) floor((1.0 - ($c->y / $c->w * 0.5 + 0.5)) * self::H);

            return ord($rgba[($row * self::W + $col) * 4]);
        };
        // The shadow at body height 0.8 lands at x = 0.2 - 0.3 * 0.8, z = 1.2 - 0.5 * 0.8 on the floor.
        $spot = new Vec3(-0.04, 0.0, 0.8);
        self::assertLessThan($at($without, $spot) - 25, $at($hidden, $spot), 'the floor lies in the shadow of the hidden skinned body');
        $bodySpot = new Vec3(0.2, 1.0, 1.2);
        self::assertSame($at($without, $bodySpot), $at($hidden, $bodySpot), 'the hidden body does not appear');
    }
}
