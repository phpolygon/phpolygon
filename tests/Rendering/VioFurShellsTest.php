<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Geometry\SphereMesh;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\DrawMeshInstanced;
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetDirectionalLight;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * Fur shells (shader plan 10.2, Material::$furLength): a furry ball stands out beyond
 * the bare one by about the strand length, with a frayed rim (strands, not a solid
 * shell), single and instanced.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioFurShellsTest extends TestCase
{
    private const W = 160;
    private const H = 120;

    public function testFurGrowsAFrayedRimSingleAndInstanced(): void
    {
        $ctx = VioTestContext::create(self::W, self::H, ['shader_cache' => sys_get_temp_dir() . '/phpolygon-test-shader-cache']);
        if ($ctx === null) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        try {
            MeshRegistry::clear();
            MaterialRegistry::clear();
            MeshRegistry::register('ball', SphereMesh::generate(0.5, 32, 48));
            MaterialRegistry::register('bare', new Material(albedo: new Color(0.6, 0.4, 0.2), roughness: 1.0, useEnvironmentMap: false));
            MaterialRegistry::register('furry', new Material(albedo: new Color(0.6, 0.4, 0.2), roughness: 1.0, useEnvironmentMap: false, furLength: 0.12, furShells: 16, furDensity: 60.0));
            $renderer = new VioRenderer3D($ctx, self::W, self::H);
            $renderer->applySettings(new GraphicsSettings(shadowQuality: ShadowQuality::Off, antiAliasing: AntiAliasing::Off, bloom: false));
            foreach (['single' => false, 'instanced' => true] as $label => $instanced) {
                $draw = fn (string $m) => $instanced ? new DrawMeshInstanced('ball', $m, [Mat4::identity()]) : new DrawMesh('ball', $m, Mat4::identity());
                $bare = $this->mask($renderer->renderToImage($this->scene($draw('bare')), self::W, self::H, new Color(0.0, 0.0, 0.0)));
                $furry = $this->mask($renderer->renderToImage($this->scene($draw('furry')), self::W, self::H, new Color(0.0, 0.0, 0.0)));
                $grown = 0;
                $lost = 0;
                foreach ($bare as $p => $in) {
                    $grown += !$in && $furry[$p] ? 1 : 0;
                    $lost += $in && !$furry[$p] ? 1 : 0;
                }
                $rim = $this->rimGaps($furry);
                fprintf(STDERR, "[fur %s] bare %d px, grown %d px, lost %d px, rim gaps %d\n", $label, array_sum($bare), $grown, $lost, $rim);
                // 0.12 m of strands on a 0.5 m ball: a ring of roughly a fifth of its area,
                // thinned by the gaps between strands.
                self::assertGreaterThan(array_sum($bare) * 0.08, $grown, "{$label}: the fur stands out");
                self::assertLessThan(20, $lost, "{$label}: the ball itself stays covered");
                self::assertGreaterThan(10, $rim, "{$label}: strands, not a solid shell");
            }
        } finally {
            MaterialRegistry::clear();
            MeshRegistry::clear();
            vio_destroy($ctx);
        }
    }

    /** @return array<int, bool> covered pixels */
    private function mask(string $rgba): array
    {
        $m = [];
        for ($p = 0, $n = self::W * self::H; $p < $n; $p++) {
            $m[$p] = ord($rgba[$p * 4]) + ord($rgba[$p * 4 + 1]) + ord($rgba[$p * 4 + 2]) > 20;
        }
        return $m;
    }

    /** Background pixels with covered pixels on both sides along a row: gaps between strands. */
    private function rimGaps(array $mask): int
    {
        $gaps = 0;
        for ($y = 1; $y < self::H - 1; $y++) {
            for ($x = 1; $x < self::W - 1; $x++) {
                $p = $y * self::W + $x;
                if (!$mask[$p] && $mask[$p - 1] && $mask[$p + 1]) {
                    $gaps++;
                }
            }
        }
        return $gaps;
    }

    private function scene(object $draw): RenderCommandList
    {
        $list = new RenderCommandList();
        $list->add(new SetCamera(Mat4::lookAt(new Vec3(0.0, 0.0, 2.6), new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 1.0, 0.0)), Mat4::perspective(deg2rad(40.0), self::W / self::H, 0.1, 20.0)));
        $list->add(new SetAmbientLight(new Color(1.0, 1.0, 1.0), 0.6));
        $list->add(new SetDirectionalLight((new Vec3(-0.4, -0.6, -0.6))->normalize(), new Color(1.0, 1.0, 1.0), 1.2));
        $list->add($draw);
        return $list;
    }
}
