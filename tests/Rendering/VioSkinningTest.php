<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use PHPolygon\Animation\Pose;
use PHPolygon\Animation\PoseRig;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Geometry\PlaneMesh;
use PHPolygon\Geometry\SkinnedMeshData;
use PHPolygon\Geometry\SkinnedMeshRegistry;
use PHPolygon\Geometry\SphereMesh;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\DrawSkinnedMesh;
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetDirectionalLight;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\ProcModeShaderRegistry;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ScreenSpaceAO;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * GPU skinning (DrawSkinnedMesh): a sphere bent by a two-joint rig is drawn posed on the
 * GPU and, as the reference, posed on the CPU (SkinnedMeshData::skin) and drawn as a
 * plain mesh; the images must agree, also in the shadow it casts. Hardware GPU run:
 * PHPOLYGON_TEST_VIO_BACKEND=d3d11|d3d12 PHPOLYGON_TEST_VIO_HARDWARE=1, one window at a time.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioSkinningTest extends TestCase
{
    private const SIZE = 96;

    private ?\VioContext $ctx = null;

    protected function setUp(): void
    {
        $ctx = VioTestContext::create(self::SIZE, self::SIZE, ['shader_model' => 6, 'shader_cache' => sys_get_temp_dir() . '/phpolygon-test-shader-cache']);
        if ($ctx === null) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        $this->ctx = $ctx;
        MeshRegistry::clear();
        MaterialRegistry::clear();
        SkinnedMeshRegistry::clear();
        ProcModeShaderRegistry::clear();
    }

    protected function tearDown(): void
    {
        MaterialRegistry::clear();
        MeshRegistry::clear();
        SkinnedMeshRegistry::clear();
        ProcModeShaderRegistry::clear();
        if ($this->ctx !== null) {
            vio_destroy($this->ctx);
            $this->ctx = null;
        }
    }

    private function rig(): PoseRig
    {
        return (new PoseRig())
            ->joint('root', null, new Vec3(0.0, 0.0, 0.0))
            ->joint('arm', 'root', new Vec3(0.0, 0.0, 0.0));
    }

    private function skinned(): SkinnedMeshData
    {
        $mesh = SphereMesh::generate(0.5, 16, 24);
        $infl = [];
        for ($i = 0; $i < $mesh->vertexCount(); $i++) {
            $w = max(0.0, min(1.0, ($mesh->vertices[$i * 3 + 1] + 0.2) / 0.4));
            $infl[] = $w <= 0.0 ? ['root' => 1.0] : ($w >= 1.0 ? ['arm' => 1.0] : ['root' => 1.0 - $w, 'arm' => $w]);
        }

        return SkinnedMeshData::fromInfluences($mesh, $infl, $this->rig());
    }

    private function renderer(ShadowQuality $shadows): VioRenderer3D
    {
        $r = new VioRenderer3D($this->ctx, self::SIZE, self::SIZE);
        $r->applySettings(new GraphicsSettings(shadowQuality: $shadows, ambientOcclusion: ScreenSpaceAO::Off, antiAliasing: AntiAliasing::Off, bloom: false));

        return $r;
    }

    /** @param list<object> $draws */
    private function image(VioRenderer3D $r, array $draws, bool $sun): string
    {
        $list = new RenderCommandList();
        $list->add(new SetCamera(
            Mat4::lookAt(new Vec3(0.0, 2.0, 6.0), new Vec3(0.0, -0.3, 0.0), new Vec3(0.0, 1.0, 0.0)),
            Mat4::orthographic(-2.0, 2.0, -2.0, 2.0, 1.0, 20.0),
        ));
        $list->add(new SetAmbientLight(new Color(1.0, 1.0, 1.0), $sun ? 0.3 : 1.0));
        if ($sun) {
            $list->add(new SetDirectionalLight(new Vec3(0.3, -1.0, -0.2), new Color(1.0, 1.0, 1.0), 2.0));
        }
        foreach ($draws as $d) {
            $list->add($d);
        }

        return $r->renderToImage($list, self::SIZE, self::SIZE, new Color(0.0, 0.0, 0.0));
    }

    private static function meanAbsDiff(string $a, string $b): float
    {
        $sum = 0;
        $n = strlen($a);
        for ($i = 0; $i < $n; $i++) {
            if ($i % 4 !== 3) {
                $sum += abs(ord($a[$i]) - ord($b[$i]));
            }
        }

        return $sum / max(1, $n * 3 / 4);
    }

    private static function lit(string $img): int
    {
        $c = 0;
        for ($i = 0; $i < strlen($img); $i += 4) {
            if (ord($img[$i]) > 40) {
                $c++;
            }
        }

        return $c;
    }

    public function testGpuSkinningMatchesTheCpuReference(): void
    {
        self::assertNotNull($this->ctx);
        $renderer = $this->renderer(ShadowQuality::Off);
        if (!$renderer->skinningAvailable()) {
            $this->markTestSkipped('no vertex storage buffers on this backend');
        }
        $skin = $this->skinned();
        SkinnedMeshRegistry::register('body', $skin);
        MaterialRegistry::register('m', new Material(albedo: new Color(0.8, 0.5, 0.3), roughness: 1.0, useEnvironmentMap: false));
        $pose = Pose::degrees(['arm' => [0, 0, 80]]);
        $palette = $skin->palette($this->rig(), $pose->raw());
        MeshRegistry::register('cpu', $skin->skin($palette));

        $cpu = $this->image($renderer, [new DrawMesh('cpu', 'm', Mat4::identity())], false);
        $gpu = $this->image($renderer, [DrawSkinnedMesh::single('body', 'm', Mat4::identity(), $palette)], false);

        self::assertGreaterThan(300, self::lit($cpu), 'the reference draws something');
        self::assertGreaterThan(300, self::lit($gpu), 'the skinned draw shows up');
        // The bend moved geometry: the bind pose differs from the bent one.
        $bind = $this->image($renderer, [DrawSkinnedMesh::single('body', 'm', Mat4::identity(), $skin->palette($this->rig(), (new Pose())->raw()))], false);
        self::assertGreaterThan(1.0, self::meanAbsDiff($bind, $gpu), 'the pose changes the image');
        self::assertLessThan(1.5, self::meanAbsDiff($cpu, $gpu), 'GPU and CPU skinning agree');
    }

    public function testSkinnedCasterThrowsTheSameShadowAsTheCpuMesh(): void
    {
        self::assertNotNull($this->ctx);
        $renderer = $this->renderer(ShadowQuality::Medium);
        if (!$renderer->skinningAvailable()) {
            $this->markTestSkipped('no vertex storage buffers on this backend');
        }
        $skin = $this->skinned();
        SkinnedMeshRegistry::register('body', $skin);
        MeshRegistry::register('ground', BoxMesh::generate(8.0, 0.1, 8.0));
        MaterialRegistry::register('m', new Material(albedo: new Color(0.8, 0.5, 0.3), roughness: 1.0, useEnvironmentMap: false));
        MaterialRegistry::register('g', new Material(albedo: new Color(0.9, 0.9, 0.9), roughness: 1.0, useEnvironmentMap: false));
        $palette = $skin->palette($this->rig(), Pose::degrees(['arm' => [0, 0, 80]])->raw());
        MeshRegistry::register('cpu', $skin->skin($palette));
        $ground = new DrawMesh('ground', 'g', Mat4::translation(0.0, -1.5, 0.0));
        $up = Mat4::translation(0.0, 0.0, 0.0);

        $cpu = $this->image($renderer, [$ground, new DrawMesh('cpu', 'm', $up)], true);
        $gpu = $this->image($renderer, [$ground, DrawSkinnedMesh::single('body', 'm', $up, $palette)], true);
        $noCast = $this->image($renderer, [$ground, new DrawSkinnedMesh('body', 'm', [$up], [$palette], [], false)], true);

        self::assertLessThan(2.5, self::meanAbsDiff($cpu, $gpu), 'body and shadow match the CPU reference');
        self::assertGreaterThan(0.2, self::meanAbsDiff($gpu, $noCast), 'the skinned caster darkens the ground');
    }

    public function testInstancesOutsideTheViewAreCulled(): void
    {
        self::assertNotNull($this->ctx);
        $renderer = $this->renderer(ShadowQuality::Off);
        if (!$renderer->skinningAvailable()) {
            $this->markTestSkipped('no vertex storage buffers on this backend');
        }
        $skin = $this->skinned();
        SkinnedMeshRegistry::register('body', $skin);
        MaterialRegistry::register('m', new Material(albedo: new Color(0.8, 0.5, 0.3), roughness: 1.0, useEnvironmentMap: false));
        $pal = $skin->palette($this->rig(), (new Pose())->raw());
        $here = Mat4::translation(0.0, 0.0, 0.0);
        $gone = Mat4::translation(60.0, 0.0, 0.0);
        $one = $this->image($renderer, [new DrawSkinnedMesh('body', 'm', [$here], [$pal])], false);
        self::assertSame(0, $renderer->skinInstancesCulled());
        $both = $this->image($renderer, [new DrawSkinnedMesh('body', 'm', [$gone, $here, $gone], [$pal], [0, 0, 0])], false);
        self::assertSame(2, $renderer->skinInstancesCulled(), 'the two far instances leave the camera pass');
        self::assertLessThan(0.2, self::meanAbsDiff($one, $both), 'the visible instance still draws');
        $all = $this->image($renderer, [new DrawSkinnedMesh('body', 'm', [$gone], [$pal])], false);
        self::assertSame(1, $renderer->skinInstancesCulled());
        self::assertSame(0, self::lit($all));
    }

    public function testCutOutCasterLetsLightThroughItsTransparentHalf(): void
    {
        self::assertNotNull($this->ctx);
        $renderer = $this->renderer(ShadowQuality::Medium);
        if (!$renderer->skinningAvailable()) {
            $this->markTestSkipped('no vertex storage buffers on this backend');
        }
        $card = PlaneMesh::generate(2.0, 2.0);
        $rig = (new PoseRig())->joint('root', null, new Vec3(0.0, 0.0, 0.0));
        $infl = array_fill(0, $card->vertexCount(), ['root' => 1.0]);
        SkinnedMeshRegistry::register('card', SkinnedMeshData::fromInfluences($card, $infl, $rig));
        MeshRegistry::register('ground', BoxMesh::generate(10.0, 0.1, 10.0));
        // One texel transparent, one opaque: half the card is cut out.
        $tex = vio_texture($this->ctx, ['data' => "\xFF\xFF\xFF\x00\xFF\xFF\xFF\xFF", 'width' => 2, 'height' => 1, 'filter' => VIO_FILTER_NEAREST]);
        self::assertNotFalse($tex);
        $renderer->registerGpuTexture('half', $tex);
        MaterialRegistry::register('cut', new Material(albedo: new Color(0.2, 0.8, 0.2), roughness: 1.0, useEnvironmentMap: false, albedoTexture: 'half', alphaCutoff: 0.5, twoSided: true));
        MaterialRegistry::register('solid', new Material(albedo: new Color(0.2, 0.8, 0.2), roughness: 1.0, useEnvironmentMap: false, twoSided: true));
        MaterialRegistry::register('g', new Material(albedo: new Color(0.9, 0.9, 0.9), roughness: 1.0, useEnvironmentMap: false));
        $pal = SkinnedMeshData::packPalette([1.0, 0.0, 0.0, 0.0, 0.0, 1.0, 0.0, 0.0, 0.0, 0.0, 1.0, 0.0, 0.0, 0.0, 0.0, 1.0]);
        $card1 = Mat4::translation(0.0, 2.0, 0.0);
        $ground = new DrawMesh('ground', 'g', Mat4::translation(0.0, -0.05, 0.0));
        $shot = function (string $material) use ($renderer, $ground, $card1, $pal): string {
            $list = new RenderCommandList();
            // Top-down; the sun leans toward +X, so the card's shadow lands 2 m to its side, out from under the card.
            $list->add(new SetCamera(Mat4::lookAt(new Vec3(0.0, 10.0, 0.001), new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 0.0, -1.0)), Mat4::orthographic(-4.0, 4.0, -4.0, 4.0, 1.0, 30.0)));
            $list->add(new SetAmbientLight(new Color(1.0, 1.0, 1.0), 0.2));
            $list->add(new SetDirectionalLight((new Vec3(1.0, -1.0, 0.0))->normalize(), new Color(1.0, 1.0, 1.0), 2.5));
            $list->add($ground);
            $list->add(DrawSkinnedMesh::single('card', $material, $card1, $pal));

            return $renderer->renderToImage($list, self::SIZE, self::SIZE, new Color(0.0, 0.0, 0.0));
        };
        $at = static fn (string $img, float $x): int => ord($img[(48 * self::SIZE + (int) round(($x + 4.0) / 8.0 * self::SIZE)) * 4]);
        $cut = $shot('cut');
        $solid = $shot('solid');
        // The shadow of the 2 m card spans x 1..3 on the ground; sample both halves of it.
        self::assertLessThan(15, abs($at($solid, 1.5) - $at($solid, 2.5)), 'a solid card shadows both halves alike');
        self::assertGreaterThan(30, abs($at($cut, 1.5) - $at($cut, 2.5)), 'the cut-out half lets the sun through');
    }

    public function testInstancesShareAndSelectPalettes(): void
    {
        self::assertNotNull($this->ctx);
        $renderer = $this->renderer(ShadowQuality::Off);
        if (!$renderer->skinningAvailable()) {
            $this->markTestSkipped('no vertex storage buffers on this backend');
        }
        $skin = $this->skinned();
        SkinnedMeshRegistry::register('body', $skin);
        MaterialRegistry::register('m', new Material(albedo: new Color(0.8, 0.5, 0.3), roughness: 1.0, useEnvironmentMap: false));
        $straight = $skin->palette($this->rig(), (new Pose())->raw());
        $bent = $skin->palette($this->rig(), Pose::degrees(['arm' => [0, 0, 80]])->raw());
        $left = Mat4::translation(-1.0, 0.0, 0.0);
        $right = Mat4::translation(1.0, 0.0, 0.0);
        // Two instances, two palettes - and the same crowd as two single draws.
        $one = $this->image($renderer, [new DrawSkinnedMesh('body', 'm', [$left, $right], [$straight, $bent], [0, 1])], false);
        $two = $this->image($renderer, [
            DrawSkinnedMesh::single('body', 'm', $left, $straight),
            DrawSkinnedMesh::single('body', 'm', $right, $bent),
        ], false);
        self::assertLessThan(0.3, self::meanAbsDiff($one, $two));
        // Swapping the palette indices changes the picture.
        $swapped = $this->image($renderer, [new DrawSkinnedMesh('body', 'm', [$left, $right], [$straight, $bent], [1, 0])], false);
        self::assertGreaterThan(1.0, self::meanAbsDiff($one, $swapped));
    }
}
