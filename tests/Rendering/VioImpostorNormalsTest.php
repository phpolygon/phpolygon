<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\MeshData;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetDirectionalLight;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Impostor\ImpostorBaker;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\ProcModeShaderRegistry;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * Impostor normals: a pillar turned 45 degrees shows two faces; lit from the left, the left
 * one is bright and the right one dark. The impostor with baked normals turns its halves
 * the same way, the plain card shows both halves alike - and with the light from the right
 * the normals swap the halves.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioImpostorNormalsTest extends TestCase
{
    private const W = 96;
    private const H = 96;

    private ?\VioContext $ctx = null;

    protected function setUp(): void
    {
        $ctx = VioTestContext::create(self::W, self::H, ['shader_cache' => sys_get_temp_dir() . '/phpolygon-test-shader-cache']);
        if ($ctx === null) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        if (!ProcModeShaderRegistry::normalMapAvailable()) {
            vio_destroy($ctx);
            $this->markTestSkipped('no normal maps with this php-vio');
        }
        $this->ctx = $ctx;
        MeshRegistry::clear();
        MaterialRegistry::clear();
        // A 1 x 2 x 1 m pillar standing on its origin, turned 45 degrees.
        $box = BoxMesh::generate(1.0, 2.0, 1.0)->transformed(Mat4::translation(0.0, 1.0, 0.0)->multiply(Mat4::rotationY(M_PI / 4.0)));
        MeshRegistry::register('pillar', new MeshData($box->vertices, $box->normals, $box->uvs, $box->indices));
        MaterialRegistry::register('pillar', new Material(albedo: new Color(0.6, 0.6, 0.6), roughness: 1.0, useEnvironmentMap: false));
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

    public function testBakedNormalsTurnTheImpostorWithTheLight(): void
    {
        self::assertNotNull($this->ctx);
        $renderer = new VioRenderer3D($this->ctx, self::W, self::H);
        $renderer->applySettings(new GraphicsSettings(shadowQuality: ShadowQuality::Off, antiAliasing: AntiAliasing::Off, bloom: false, hdr: false));
        $set = (new ImpostorBaker($this->ctx))->bake($renderer, 'pillar', 'pillar', 'pillar_imp', 8, 64, normals: true);
        self::assertCount(8, $set->normalTextureIds);
        $camera = new Vec3(0.0, 1.0, 8.0);
        $instances = [[0.0, 0.0, 0.0, 0.0, 1.0]];
        foreach ([1.0, -1.0] as $side) {
            // $side 1: light travelling to +X, i.e. coming from the left.
            $light = new Vec3($side, -0.3, -0.2);
            $real = $this->contrast($renderer->renderToImage($this->scene($camera, $light, [new \PHPolygon\Rendering\Command\DrawMesh('pillar', 'pillar', Mat4::identity())]), self::W, self::H, new Color(0.0, 0.0, 0.0)));
            $mapped = $this->contrast($renderer->renderToImage($this->scene($camera, $light, $set->commands('impostor', $camera, $instances)), self::W, self::H, new Color(0.0, 0.0, 0.0)));
            $plain = $this->contrast($renderer->renderToImage($this->scene($camera, $light, [$set->command('impostor', $camera, $instances)]), self::W, self::H, new Color(0.0, 0.0, 0.0)));
            fprintf(STDERR, "[impostor normals] light %s: left-minus-right pillar %.1f, impostor with normals %.1f, plain card %.1f\n", $side > 0 ? 'from the left' : 'from the right', $real, $mapped, $plain);
            self::assertGreaterThan(20.0, abs($real), 'the pillar shows a lit and a dark face');
            self::assertSame($real > 0, $mapped > 0, 'the impostor turns the same half to the light');
            self::assertGreaterThan(abs($real) * 0.4, abs($mapped), 'and nearly as strongly');
            self::assertLessThan(abs($real) * 0.25, abs($plain), 'the plain card stays even');
        }
    }

    /** @param list<object> $draws */
    private function scene(Vec3 $camera, Vec3 $light, array $draws): RenderCommandList
    {
        $list = new RenderCommandList();
        $list->add(new SetCamera(Mat4::lookAt($camera, new Vec3(0.0, 1.0, 0.0), new Vec3(0.0, 1.0, 0.0)), Mat4::perspective(deg2rad(25.0), 1.0, 0.1, 50.0)));
        $list->add(new SetAmbientLight(new Color(1.0, 1.0, 1.0), 0.1));
        $list->add(new SetDirectionalLight($light, new Color(1.0, 1.0, 1.0), 1.2));
        foreach ($draws as $d) {
            $list->add($d);
        }
        return $list;
    }

    /** Mean green of the covered pixels left of the image centre minus right of it. */
    private function contrast(string $img): float
    {
        $s = [0.0, 0.0];
        $n = [0, 0];
        for ($y = 0; $y < self::H; $y++) {
            for ($x = 0; $x < self::W; $x++) {
                $o = ($y * self::W + $x) * 4;
                $g = ord($img[$o + 1]);
                if (ord($img[$o]) + $g + ord($img[$o + 2]) < 20) {
                    continue;
                }
                $side = $x < self::W / 2 ? 0 : 1;
                $s[$side] += $g;
                $n[$side]++;
            }
        }
        return $s[0] / max(1, $n[0]) - $s[1] / max(1, $n[1]);
    }
}
