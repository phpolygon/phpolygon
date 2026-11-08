<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Geometry\SphereMesh;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\DrawMeshInstanced;
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * Breathing (Material::$breathing): a sphere whose band round the equator breathes gets
 * wider and narrower there over time while its top stays put. Instance colour variation
 * (Material::$instanceTint): six identical crates differ in colour with it, not without.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioBreathingTintTest extends TestCase
{
    private const SIZE = 96;

    private ?\VioContext $ctx = null;

    protected function setUp(): void
    {
        $ctx = VioTestContext::create(self::SIZE, self::SIZE, ['shader_cache' => sys_get_temp_dir() . '/phpolygon-test-shader-cache']);
        if ($ctx === null) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        $this->ctx = $ctx;
        MeshRegistry::clear();
        MaterialRegistry::clear();
        MeshRegistry::register('ball', SphereMesh::generate(0.5, 32, 48));
        MeshRegistry::register('crate', BoxMesh::generate(0.8, 0.8, 0.8));
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

    public function testTheBandBreathesAndTheTopStays(): void
    {
        self::assertNotNull($this->ctx);
        MaterialRegistry::register('chest', new Material(albedo: new Color(0.8, 0.8, 0.8), roughness: 1.0, useEnvironmentMap: false, breathing: [0.06, 0.25, -0.3, 0.3]));
        $renderer = new VioRenderer3D($this->ctx, self::SIZE, self::SIZE);
        $renderer->applySettings(new GraphicsSettings(shadowQuality: ShadowQuality::Off, antiAliasing: AntiAliasing::Off, bloom: false));
        $widths = [];
        $tops = [];
        for ($t = 0; $t < 8; $t++) {
            $renderer->setAnimationTime($t * 0.5);
            $list = new RenderCommandList();
            $list->add(new SetCamera(Mat4::lookAt(new Vec3(0.0, 0.0, 2.2), new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 1.0, 0.0)), Mat4::orthographic(-0.75, 0.75, -0.75, 0.75, 0.1, 10.0)));
            $list->add(new SetAmbientLight(new Color(1.0, 1.0, 1.0), 1.0));
            $list->add(new DrawMesh('ball', 'chest', Mat4::identity()));
            $img = $renderer->renderToImage($list, self::SIZE, self::SIZE, new Color(0.0, 0.0, 0.0));
            $widths[] = $this->covered($img, intdiv(self::SIZE, 2), true);
            $tops[] = $this->covered($img, intdiv(self::SIZE, 2), false);
        }
        fprintf(STDERR, "[breathing] equator width %d..%d px, height %d..%d px\n", min($widths), max($widths), min($tops), max($tops));
        self::assertGreaterThanOrEqual(3, max($widths) - min($widths), 'the band swells and settles');
        self::assertLessThanOrEqual(1, max($tops) - min($tops), 'the poles stay put');
    }

    public function testCratesVaryInColourWithTheTint(): void
    {
        self::assertNotNull($this->ctx);
        $spread = [];
        foreach ([0.0, 1.0] as $tint) {
            MaterialRegistry::register('crate', new Material(albedo: new Color(0.6, 0.45, 0.3), roughness: 1.0, useEnvironmentMap: false, instanceTint: $tint));
            $renderer = new VioRenderer3D($this->ctx, self::SIZE, self::SIZE);
            $renderer->applySettings(new GraphicsSettings(shadowQuality: ShadowQuality::Off, antiAliasing: AntiAliasing::Off, bloom: false));
            $flat = [];
            for ($i = 0; $i < 6; $i++) {
                array_push($flat, ...Mat4::translation(-2.5 + $i, 0.0, 0.0)->toArray());
            }
            $list = new RenderCommandList();
            $list->add(new SetCamera(Mat4::lookAt(new Vec3(0.0, 0.0, 6.0), new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 1.0, 0.0)), Mat4::orthographic(-3.0, 3.0, -3.0, 3.0, 0.1, 20.0)));
            $list->add(new SetAmbientLight(new Color(1.0, 1.0, 1.0), 1.0));
            $list->add(new DrawMeshInstanced(meshId: 'crate', materialId: 'crate', matrices: [], instanceCount: 6, flatMatrices: $flat));
            $img = $renderer->renderToImage($list, self::SIZE, self::SIZE, new Color(0.0, 0.0, 0.0));
            $means = [];
            for ($i = 0; $i < 6; $i++) {
                $x = (int) round((($i - 2.5) / 6.0 + 0.5) * self::SIZE);
                $o = (intdiv(self::SIZE, 2) * self::SIZE + $x) * 4;
                $means[] = ord($img[$o]) + ord($img[$o + 1]) + ord($img[$o + 2]);
            }
            $spread[] = max($means) - min($means);
        }
        fprintf(STDERR, "[instance tint] colour spread over six crates: without %d, with %d\n", $spread[0], $spread[1]);
        // Without the tint the crates differ only by where they stand in the ambient light.
        self::assertLessThanOrEqual(6, $spread[0], 'nearly identical without the tint');
        self::assertGreaterThan($spread[0] + 12, $spread[1], 'each crate its own shade');
    }

    /** Covered pixels along the middle row ($horizontal) or the middle column. */
    private function covered(string $img, int $line, bool $horizontal): int
    {
        $n = 0;
        for ($k = 0; $k < self::SIZE; $k++) {
            [$x, $y] = $horizontal ? [$k, $line] : [$line, $k];
            $o = ($y * self::SIZE + $x) * 4;
            $n += ord($img[$o]) + ord($img[$o + 1]) + ord($img[$o + 2]) > 30 ? 1 : 0;
        }
        return $n;
    }
}
