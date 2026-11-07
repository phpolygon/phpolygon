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
use PHPolygon\Rendering\Command\SetDirectionalLight;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\SplatLayer;
use PHPolygon\Rendering\VioRenderer3D;
use PHPolygon\Rendering\VioTextureManager;

/**
 * Splatted ground (Material::$splatLayers): the splat map's weights land on the world
 * area - green layer where the map says layer 0, grey where it says layer 1 - and with a
 * pattern's height in the blend an even 50/50 map breaks into patches of either layer,
 * where the plain blend is one even mix.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioSplatTest extends TestCase
{
    private const SIZE = 64;

    private ?\VioContext $ctx = null;

    protected function setUp(): void
    {
        MeshRegistry::clear();
        MaterialRegistry::clear();
        MeshRegistry::register('ground', BoxMesh::generate(10.0, 0.1, 10.0));
        $ctx = VioTestContext::create(self::SIZE, self::SIZE, ['shader_cache' => sys_get_temp_dir() . '/phpolygon-test-shader-cache']);
        if ($ctx === null) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        $this->ctx = $ctx;
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

    public function testTheWeightsLandOnTheirArea(): void
    {
        // Map: layer 0 on the -X half, layer 1 on the +X half.
        $img = $this->render(static fn (int $x, int $y): array => $x < 8 ? [255, 0] : [0, 255], 0.0, 6.0);
        [$lr, $lg, $lb] = $this->mean($img, 6, 26);
        [$rr, $rg, $rb] = $this->mean($img, 38, 58);
        fprintf(STDERR, "[splat] left %.0f %.0f %.0f, right %.0f %.0f %.0f\n", $lr, $lg, $lb, $rr, $rg, $rb);
        self::assertGreaterThan($lr + 20.0, $lg, 'the green layer on the -X half');
        self::assertLessThan(12.0, abs($rr - $rg) + abs($rg - $rb), 'the grey layer on the +X half');
    }

    public function testThePatternHeightBreaksAnEvenMixIntoPatches(): void
    {
        $even = static fn (int $x, int $y): array => [128, 128];
        $plain = $this->spread($this->render($even, 0.0, 6.0));
        $height = $this->spread($this->render($even, 1.0, 8.0));
        fprintf(STDERR, "[splat] green-minus-red spread: plain %.1f, height blend %.1f\n", $plain, $height);
        self::assertGreaterThan($plain * 2.0 + 10.0, $height, 'patches of either layer');
    }

    /** @param callable(int, int): array{0: int, 1: int} $weights */
    private function render(callable $weights, float $height, float $sharpness): string
    {
        self::assertNotNull($this->ctx);
        $px = '';
        for ($y = 0; $y < 16; $y++) {
            for ($x = 0; $x < 16; $x++) {
                [$a, $b] = $weights($x, $y);
                $px .= chr($a) . chr($b) . "\x00\x00";
            }
        }
        $textures = new VioTextureManager($this->ctx);
        $textures->loadRgba('splat', 16, 16, $px);
        MaterialRegistry::register('ground', new Material(albedo: new Color(1.0, 1.0, 1.0), roughness: 0.9, useEnvironmentMap: false, albedoTexture: 'splat', normalIntensity: 0.0,
            splatLayers: [
                new SplatLayer(new Color(0.1, 0.6, 0.1), 0.9, null, 1.0, $height),
                new SplatLayer(new Color(0.5, 0.5, 0.5), 0.9, 'cobblestone', 12.0, $height),
            ],
            splatArea: [-5.0, -5.0, 10.0, 10.0], splatSharpness: $sharpness));
        $renderer = new VioRenderer3D($this->ctx, self::SIZE, self::SIZE);
        $renderer->setTextureManager($textures);
        $renderer->applySettings(new GraphicsSettings(shadowQuality: ShadowQuality::Off, antiAliasing: AntiAliasing::Off, bloom: false));
        $list = new RenderCommandList();
        // Straight down, +X to the right of the image.
        $list->add(new SetCamera(Mat4::lookAt(new Vec3(0.0, 8.0, 0.001), new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 0.0, -1.0)), Mat4::perspective(deg2rad(50.0), 1.0, 0.1, 50.0)));
        $list->add(new SetAmbientLight(new Color(1.0, 1.0, 1.0), 0.6));
        $list->add(new SetDirectionalLight(new Vec3(0.0, -1.0, 0.0), new Color(1.0, 1.0, 1.0), 0.8));
        $list->add(new DrawMesh('ground', 'ground', Mat4::identity()));
        return $renderer->renderToImage($list, self::SIZE, self::SIZE, new Color(0.0, 0.0, 0.0));
    }

    /** @return array{0: float, 1: float, 2: float} mean RGB of columns $x0..$x1 over the middle rows */
    private function mean(string $img, int $x0, int $x1): array
    {
        $s = [0, 0, 0];
        $n = 0;
        for ($y = 16; $y < 48; $y++) {
            for ($x = $x0; $x < $x1; $x++) {
                $o = ($y * self::SIZE + $x) * 4;
                $s[0] += ord($img[$o]);
                $s[1] += ord($img[$o + 1]);
                $s[2] += ord($img[$o + 2]);
                $n++;
            }
        }
        return [$s[0] / $n, $s[1] / $n, $s[2] / $n];
    }

    /** Standard deviation of (green - red) over the middle of the image: one even mix is ~0. */
    private function spread(string $img): float
    {
        $v = [];
        for ($y = 12; $y < 52; $y++) {
            for ($x = 12; $x < 52; $x++) {
                $o = ($y * self::SIZE + $x) * 4;
                $v[] = ord($img[$o + 1]) - ord($img[$o]);
            }
        }
        $m = array_sum($v) / count($v);
        $q = 0.0;
        foreach ($v as $x) {
            $q += ($x - $m) ** 2;
        }
        return sqrt($q / count($v));
    }
}
