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
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Impostor\ImpostorBaker;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * Impostor atlas (shader plan 9.1): a pillar 1 m wide, 0.4 m deep and 2 m high baked
 * from 8 sides looks like the pillar itself from afar - same silhouette, same colour -
 * seen from the front and turned a quarter (the narrow side, another view).
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioImpostorTest extends TestCase
{
    private const W = 160;
    private const H = 120;
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
        // Origin at the pillar's foot.
        $box = BoxMesh::generate(1.0, 2.0, 0.4);
        $v = $box->vertices;
        for ($i = 1, $n = count($v); $i < $n; $i += 3) {
            $v[$i] += 1.0;
        }
        MeshRegistry::register('pillar', new \PHPolygon\Geometry\MeshData($v, $box->normals, $box->uvs, $box->indices));
        MaterialRegistry::register('pillar', new Material(albedo: new Color(0.7, 0.15, 0.1), roughness: 1.0, useEnvironmentMap: false));
        MaterialRegistry::register('impostor', new Material(albedo: new Color(1.0, 1.0, 1.0), roughness: 1.0, useEnvironmentMap: false, alphaCutoff: 0.5));
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

    public function testTheImpostorLooksLikeThePillarFromTheFront(): void
    {
        $this->compare(0.0, 0.9);
    }

    public function testTheImpostorShowsTheNarrowSideWhenTurned(): void
    {
        // The narrow side: the real pillar's near edge stands 0.5 m in front of the card
        // and its long sides show a sliver in perspective - a pixel or two wider.
        $this->compare(M_PI / 2, 0.75);
    }

    private function compare(float $yaw, float $minIou): void
    {
        self::assertNotNull($this->ctx);
        $renderer = new VioRenderer3D($this->ctx, self::W, self::H);
        $renderer->applySettings(new GraphicsSettings(shadowQuality: ShadowQuality::Off, antiAliasing: AntiAliasing::Off, bloom: false, hdr: false));
        $set = (new ImpostorBaker($this->ctx))->bake($renderer, 'pillar', 'pillar', 'pillar_imp', 8, 64);
        self::assertCount(8, $set->textureIds);

        // From afar, where impostors stand in: the flat card and the 0.4 m deep pillar
        // then cover nearly the same pixels.
        $camera = new Vec3(0.0, 1.0, 14.0);
        $real = $this->scene($camera, new DrawMesh('pillar', 'pillar', Mat4::rotationY($yaw)));
        $fake = $this->scene($camera, $set->command('impostor', $camera, [[0.0, 0.0, 0.0, $yaw, 1.0]]));
        $a = $renderer->renderToImage($real, self::W, self::H, new Color(0.0, 0.0, 0.0));
        $b = $renderer->renderToImage($fake, self::W, self::H, new Color(0.0, 0.0, 0.0));

        $inA = 0;
        $inB = 0;
        $both = 0;
        $diff = [0.0, 0.0, 0.0];
        for ($p = 0, $n = self::W * self::H; $p < $n; $p++) {
            $o = $p * 4;
            $ca = ord($a[$o]) + ord($a[$o + 1]) + ord($a[$o + 2]) > 30;
            $cb = ord($b[$o]) + ord($b[$o + 1]) + ord($b[$o + 2]) > 30;
            $inA += $ca ? 1 : 0;
            $inB += $cb ? 1 : 0;
            if ($ca && $cb) {
                $both++;
                for ($c = 0; $c < 3; $c++) {
                    $diff[$c] += abs(ord($a[$o + $c]) - ord($b[$o + $c]));
                }
            }
        }
        $iou = $both / max(1, $inA + $inB - $both);
        fprintf(STDERR, "[impostor yaw %.2f] pillar %d px, impostor %d px, IoU %.3f, mean colour diff %.1f/%.1f/%.1f\n",
            $yaw, $inA, $inB, $iou, $diff[0] / max(1, $both), $diff[1] / max(1, $both), $diff[2] / max(1, $both));
        self::assertGreaterThan(150, $inA, 'the pillar is in the picture');
        self::assertGreaterThan($minIou, $iou, 'same silhouette');
        for ($c = 0; $c < 3; $c++) {
            self::assertLessThan(14.0, $diff[$c] / max(1, $both), "channel {$c}: same colour");
        }
    }

    private function scene(Vec3 $camera, object $draw): RenderCommandList
    {
        $list = new RenderCommandList();
        $list->add(new SetCamera(Mat4::lookAt($camera, new Vec3(0.0, 1.0, 0.0), new Vec3(0.0, 1.0, 0.0)), Mat4::perspective(deg2rad(18.0), self::W / self::H, 0.1, 100.0)));
        $list->add(new SetAmbientLight(new Color(1.0, 1.0, 1.0), 0.8));
        $list->add($draw);
        return $list;
    }
}
