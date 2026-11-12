<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use PHPolygon\Animation\BakedClipSet;
use PHPolygon\Animation\Pose;
use PHPolygon\Animation\PoseClip;
use PHPolygon\Animation\PoseRig;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Geometry\SkinnedMeshData;
use PHPolygon\Geometry\SphereMesh;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\DrawMesh;
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
 * Baked crowds: two instances of one mesh and one material, one playing a straight clip and the
 * other a bent one (clip row and phase from the instance params), shaded with the baked normals;
 * the image matches the CPU-posed meshes. Runs wherever the context can read a vertex animation
 * texture (more than 17 texture units, or sampler-less fetch units above them) and skips otherwise.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioVatCrowdTest extends TestCase
{
    private const SIZE = 96;

    private ?\VioContext $ctx = null;

    protected function setUp(): void
    {
        $ctx = VioTestContext::create(self::SIZE, self::SIZE, ['shader_model' => 6, 'shader_cache' => sys_get_temp_dir() . '/phpolygon-test-shader-cache']);
        if ($ctx === null || !function_exists('vio_texture_units')) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        $tu = vio_texture_units($ctx);
        if (($tu['units'] ?? 0) <= 17 && (($tu['fetch_units'] ?? 0) <= 17 || getenv('PHPOLYGON_VAT_FETCH') !== '1')) {
            vio_destroy($ctx);
            $this->markTestSkipped('needs more than 17 texture units, or sampler-less fetch units with PHPOLYGON_VAT_FETCH=1');
        }
        $this->ctx = $ctx;
        MeshRegistry::clear();
        MaterialRegistry::clear();
        ProcModeShaderRegistry::clear();
        ProcModeShaderRegistry::enableInstanceParams();
    }

    protected function tearDown(): void
    {
        MaterialRegistry::clear();
        MeshRegistry::clear();
        ProcModeShaderRegistry::clear();
        if ($this->ctx !== null) {
            vio_destroy($this->ctx);
            $this->ctx = null;
        }
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

    public function testInstancesPlayTheirOwnClipWithBakedNormals(): void
    {
        self::assertNotNull($this->ctx);
        $rig = (new PoseRig())->joint('root', null, new Vec3(0, 0, 0))->joint('arm', 'root', new Vec3(0, 0, 0));
        $mesh = SphereMesh::generate(0.5, 16, 24);
        $infl = [];
        for ($i = 0; $i < $mesh->vertexCount(); $i++) {
            $w = max(0.0, min(1.0, ($mesh->vertices[$i * 3 + 1] + 0.2) / 0.4));
            $infl[] = $w <= 0.0 ? ['root' => 1.0] : ($w >= 1.0 ? ['arm' => 1.0] : ['root' => 1.0 - $w, 'arm' => $w]);
        }
        $skin = SkinnedMeshData::fromInfluences($mesh, $infl, $rig);
        $straight = new Pose();
        $bent = Pose::degrees(['arm' => [0, 0, 80]]);
        $set = BakedClipSet::bake($skin, $rig, [
            'straight' => PoseClip::keyframes([[0.0, $straight], [0.5, $straight]]),
            'bent' => PoseClip::keyframes([[0.0, $bent], [0.5, $bent]]),
        ], 4.0);
        self::assertSame(['straight', 'bent'], $set->clipNames());
        self::assertSame([2.0, 2.0, 0.0], $set->params('bent', 0.0), 'bent starts after the two frames of straight');

        $img = $set->image;
        $tex = vio_texture($this->ctx, ['data' => $img['rgba'], 'width' => $img['width'], 'height' => $img['height'], 'filter' => VIO_FILTER_NEAREST]);
        self::assertNotFalse($tex);
        MeshRegistry::register('crowd', $skin->mesh);
        MaterialRegistry::register('vat', new Material(albedo: new Color(0.8, 0.5, 0.3), roughness: 1.0, useEnvironmentMap: false, vatTexture: 'crowd_vat', vatInfo: $set->vatInfo()));
        MaterialRegistry::register('plain', new Material(albedo: new Color(0.8, 0.5, 0.3), roughness: 1.0, useEnvironmentMap: false));
        MeshRegistry::register('cpu_straight', $skin->skin($skin->palette($rig, $straight->raw())));
        MeshRegistry::register('cpu_bent', $skin->skin($skin->palette($rig, $bent->raw())));

        $renderer = new VioRenderer3D($this->ctx, self::SIZE, self::SIZE);
        $renderer->registerGpuTexture('crowd_vat', $tex);
        $renderer->applySettings(new GraphicsSettings(shadowQuality: ShadowQuality::Off, ambientOcclusion: ScreenSpaceAO::Off, antiAliasing: AntiAliasing::Off, bloom: false));
        $left = Mat4::translation(-1.0, 0.0, 0.0);
        $right = Mat4::translation(1.0, 0.0, 0.0);
        $frame = function (array $draws) use ($renderer): string {
            $list = new RenderCommandList();
            $list->add(new SetCamera(Mat4::lookAt(new Vec3(0.0, 2.0, 6.0), new Vec3(0.0, -0.3, 0.0), new Vec3(0.0, 1.0, 0.0)), Mat4::orthographic(-2.0, 2.0, -2.0, 2.0, 1.0, 20.0)));
            $list->add(new SetAmbientLight(new Color(1.0, 1.0, 1.0), 0.2));
            $list->add(new SetDirectionalLight(new Vec3(0.5, -0.6, -0.6), new Color(1.0, 1.0, 1.0), 2.0));
            foreach ($draws as $d) {
                $list->add($d);
            }

            return $renderer->renderToImage($list, self::SIZE, self::SIZE, new Color(0.0, 0.0, 0.0));
        };
        $vat = $frame([
            new DrawMesh('crowd', 'vat', $left, params: $set->params('straight', 0.0)),
            new DrawMesh('crowd', 'vat', $right, params: $set->params('bent', 0.3)),
        ]);
        $cpu = $frame([new DrawMesh('cpu_straight', 'plain', $left), new DrawMesh('cpu_bent', 'plain', $right)]);
        $swapped = $frame([
            new DrawMesh('crowd', 'vat', $left, params: $set->params('bent', 0.0)),
            new DrawMesh('crowd', 'vat', $right, params: $set->params('straight', 0.0)),
        ]);

        self::assertLessThan(2.0, self::meanAbsDiff($vat, $cpu), 'baked positions and normals match the CPU-posed meshes');
        self::assertGreaterThan(1.0, self::meanAbsDiff($vat, $swapped), 'the clip comes from the instance params');
    }
}
