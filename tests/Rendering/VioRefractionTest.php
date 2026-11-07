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
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\ProcModeShaderRegistry;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ScreenSpaceAO;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * Refracting and frosted glass (shader plan 5.3 / 8.x catch-up): with more than 16
 * texture units the transparent pass reads a copy of the opaque scene. Frosted glass
 * over a red / blue striped floor blurs the stripes; plain blended glass keeps them.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioRefractionTest extends TestCase
{
    private const SIZE = 64;

    private ?\VioContext $ctx = null;

    protected function setUp(): void
    {
        $ctx = VioTestContext::create(self::SIZE, self::SIZE, ['shader_model' => 6, 'shader_cache' => sys_get_temp_dir() . '/phpolygon-test-shader-cache']);
        if ($ctx === null) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        if (!function_exists('vio_texture_units') || vio_texture_units($ctx)['units'] <= 16) {
            vio_destroy($ctx);
            $this->markTestSkipped('needs more than 16 texture units (php-vio >= 2.42, DXC on D3D12)');
        }
        $this->ctx = $ctx;
        MeshRegistry::clear();
        MaterialRegistry::clear();
        ProcModeShaderRegistry::clear();
        MeshRegistry::register('bar', BoxMesh::generate(0.5, 0.1, 10.0));
        MeshRegistry::register('pane', BoxMesh::generate(5.0, 0.02, 5.0));
        MaterialRegistry::register('red', new Material(albedo: new Color(1.0, 0.0, 0.0), roughness: 1.0, useEnvironmentMap: false));
        MaterialRegistry::register('blue', new Material(albedo: new Color(0.0, 0.0, 1.0), roughness: 1.0, useEnvironmentMap: false));
        MaterialRegistry::register('glass', new Material(albedo: new Color(1.0, 1.0, 1.0), roughness: 0.1, alpha: 0.2, useEnvironmentMap: false));
        MaterialRegistry::register('frost', new Material(albedo: new Color(1.0, 1.0, 1.0), roughness: 0.1, alpha: 0.2, useEnvironmentMap: false, refraction: 0.3, frost: 1.0));
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

    public function testFrostedGlassBlursWhatLiesBehind(): void
    {
        $plain = self::stripeContrast($this->render('glass'));
        $frost = self::stripeContrast($this->render('frost'));

        self::assertGreaterThan(40.0, $plain, 'the stripes show through plain glass');
        self::assertLessThan($plain * 0.6, $frost, "frosted glass blurs the stripes (plain {$plain}, frost {$frost})");
    }

    /** Mean absolute red difference between neighbouring columns in the centre rows. */
    private static function stripeContrast(string $image): float
    {
        $sum = 0.0;
        $n = 0;
        for ($y = 24; $y < 40; $y++) {
            for ($x = 16; $x < 47; $x++) {
                $a = ord($image[($y * self::SIZE + $x) * 4]);
                $b = ord($image[($y * self::SIZE + $x + 1) * 4]);
                $sum += abs($a - $b);
                $n++;
            }
        }

        return $sum / max($n, 1) * 8.0;
    }

    private function render(string $pane): string
    {
        self::assertNotNull($this->ctx);
        $renderer = new VioRenderer3D($this->ctx, self::SIZE, self::SIZE);
        $renderer->applySettings(new GraphicsSettings(
            shadowQuality: ShadowQuality::Off,
            ambientOcclusion: ScreenSpaceAO::Off,
            antiAliasing: AntiAliasing::Off,
            bloom: false,
        ));
        $list = new RenderCommandList();
        $list->add(new SetCamera(
            Mat4::lookAt(new Vec3(0.0, 8.0, 0.0), new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 0.0, -1.0)),
            Mat4::orthographic(-4.0, 4.0, -4.0, 4.0, 1.0, 20.0),
        ));
        $list->add(new SetAmbientLight(new Color(1.0, 1.0, 1.0), 1.0));
        for ($i = -8; $i < 8; $i++) {
            $list->add(new DrawMesh('bar', $i % 2 === 0 ? 'red' : 'blue', Mat4::translation($i * 0.5 + 0.25, -0.05, 0.0)));
        }
        $list->add(new DrawMesh('pane', $pane, Mat4::translation(0.0, 2.0, 0.0)));

        return $renderer->renderToImage($list, self::SIZE, self::SIZE);
    }
}
