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
use PHPolygon\Rendering\Command\AddPointLight;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ScreenSpaceAO;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioRenderer3D;

/** Hair: the two-lobe shine answers point lights too, and a hashed cut-out dithers its edge. */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioHairShadingTest extends TestCase
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
        MeshRegistry::register('ball', SphereMesh::generate(1.0, 48, 64));
        MeshRegistry::register('card', BoxMesh::generate(3.0, 3.0, 0.02));
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

    private function frame(VioRenderer3D $renderer, string $mesh, string $material, bool $light): string
    {
        $l = new RenderCommandList();
        $l->add(new SetCamera(Mat4::lookAt(new Vec3(0.0, 0.0, 5.0), new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 1.0, 0.0)), Mat4::perspective(deg2rad(30.0), 1.0, 0.1, 50.0)));
        $l->add(new SetAmbientLight(new Color(1.0, 1.0, 1.0), 0.05));
        if ($light) {
            $l->add(new AddPointLight(new Vec3(1.5, 2.0, 4.0), new Color(1.0, 1.0, 1.0), 2.0, 20.0));
        }
        $l->add(new DrawMesh($mesh, $material, Mat4::identity()));

        return $renderer->renderToImage($l, self::SIZE, self::SIZE, new Color(0.0, 0.0, 0.0));
    }

    public function testPointLightsGetTheHairShine(): void
    {
        self::assertNotNull($this->ctx);
        MaterialRegistry::register('plain', new Material(albedo: new Color(0.25, 0.14, 0.07), roughness: 0.8, useEnvironmentMap: false));
        MaterialRegistry::register('shine', new Material(albedo: new Color(0.25, 0.14, 0.07), roughness: 0.8, useEnvironmentMap: false, hairShine: 1.0));
        $renderer = new VioRenderer3D($this->ctx, self::SIZE, self::SIZE);
        $renderer->applySettings(new GraphicsSettings(shadowQuality: ShadowQuality::Off, antiAliasing: AntiAliasing::Off, ambientOcclusion: ScreenSpaceAO::Off, bloom: false));
        $peak = static function (string $img): int {
            $m = 0;
            for ($i = 0; $i < strlen($img); $i += 4) {
                $m = max($m, ord($img[$i]));
            }

            return $m;
        };
        $plain = $peak($this->frame($renderer, 'ball', 'plain', true));
        $shine = $peak($this->frame($renderer, 'ball', 'shine', true));
        fprintf(STDERR, "[hair] peak with a point light: plain %d, hair shine %d\n", $plain, $shine);
        self::assertGreaterThan($plain + 20, $shine, 'the hair lobes answer the point light');
    }

    public function testHashedCutOutDithersTheEdge(): void
    {
        self::assertNotNull($this->ctx);
        $renderer = new VioRenderer3D($this->ctx, self::SIZE, self::SIZE);
        $renderer->applySettings(new GraphicsSettings(shadowQuality: ShadowQuality::Off, antiAliasing: AntiAliasing::Off, ambientOcclusion: ScreenSpaceAO::Off, bloom: false));
        // A texture whose alpha is 0.6 everywhere: a cutoff of 0.5 keeps it all, a hashed one drops about a third.
        $tex = vio_texture($this->ctx, ['data' => str_repeat("\xFF\xFF\xFF\x99", 4), 'width' => 2, 'height' => 2, 'filter' => VIO_FILTER_NEAREST]);
        self::assertNotFalse($tex);
        $renderer->registerGpuTexture('cut', $tex);
        $base = new Material(albedo: new Color(0.8, 0.8, 0.8), roughness: 1.0, useEnvironmentMap: false, albedoTexture: 'cut', alphaCutoff: 0.5);
        MaterialRegistry::register('plain', $base);
        MaterialRegistry::register('hashed', $base->withAlphaHash());
        $lit = static function (string $img): int {
            $n = 0;
            for ($i = 0; $i < strlen($img); $i += 4) {
                if (ord($img[$i]) > 10) {
                    $n++;
                }
            }

            return $n;
        };
        $plain = $lit($this->frame($renderer, 'card', 'plain', true));
        $hashed = $lit($this->frame($renderer, 'card', 'hashed', true));
        fprintf(STDERR, "[hair] lit pixels: plain %d, hashed %d\n", $plain, $hashed);
        self::assertGreaterThan(1000, $plain);
        self::assertLessThan($plain * 0.9, $hashed, 'the hashed threshold drops part of the pixels');
        self::assertGreaterThan($plain * 0.4, $hashed, 'and keeps the rest');
    }
}
