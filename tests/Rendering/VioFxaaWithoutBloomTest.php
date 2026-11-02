<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\Attributes\DataProvider;
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
use PHPolygon\Rendering\CubemapRegistry;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * FXAA with bloom off shows the lit scene. The FXAA shader declares the bloom
 * sampler after the colour sampler; left unbound it shared the colour slot and on
 * D3D12 the pass read a stale view: a sunlit red box came out near black (WARP,
 * 64x64: 10,10,10 against 230,119,119 without AA).
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioFxaaWithoutBloomTest extends TestCase
{
    private const SIZE = 64;

    /** @return iterable<string, array{bool}> */
    public static function targets(): iterable
    {
        yield 'hdr target' => [true];
        yield 'ldr target' => [false];
    }

    #[DataProvider('targets')]
    public function testFxaaWithoutBloomKeepsTheSunlight(bool $hdr): void
    {
        $reference = $this->centre(AntiAliasing::Off, $hdr);
        self::assertGreaterThan(150, $reference[0], 'the sunlit face is bright without AA');

        $fxaa = $this->centre(AntiAliasing::Fxaa, $hdr);
        for ($c = 0; $c < 3; $c++) {
            self::assertEqualsWithDelta($reference[$c], $fxaa[$c], 4, "channel {$c}: FXAA without bloom shows the same lit face");
        }
    }

    /** @return list<int> centre pixel of the last of three frames */
    private function centre(AntiAliasing $aa, bool $hdr): array
    {
        $ctx = VioTestContext::create(self::SIZE, self::SIZE, ['shader_cache' => sys_get_temp_dir() . '/phpolygon-test-shader-cache']);
        if ($ctx === null) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        MeshRegistry::clear();
        MaterialRegistry::clear();
        CubemapRegistry::clear();
        MeshRegistry::register('box', BoxMesh::generate(1.0, 1.0, 1.0));
        MaterialRegistry::register('red', new Material(albedo: new Color(0.8, 0.1, 0.1), roughness: 0.8));

        try {
            $renderer = new VioRenderer3D($ctx, self::SIZE, self::SIZE);
            $renderer->applySettings(new GraphicsSettings(
                shadowQuality: ShadowQuality::Off,
                antiAliasing: $aa,
                bloom: false,
                hdr: $hdr,
            ));
            $list = new RenderCommandList();
            $list->add(new SetCamera(
                Mat4::lookAt(new Vec3(1.6, 1.4, 2.2), new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 1.0, 0.0)),
                Mat4::perspective(deg2rad(60.0), 1.0, 0.1, 100.0),
            ));
            $list->add(new SetAmbientLight(new Color(1.0, 1.0, 1.0), 0.1));
            $list->add(new SetDirectionalLight(new Vec3(-0.4, -0.8, -0.5), new Color(1.0, 1.0, 1.0), 2.0));
            $list->add(new DrawMesh('box', 'red', Mat4::identity()));
            for ($i = 0; $i < 3; $i++) {
                vio_begin($ctx);
                $renderer->beginFrame();
                $renderer->clear(new Color(0.0, 0.0, 0.0, 1.0));
                $renderer->render($list);
                $renderer->endFrame();
                vio_end($ctx);
            }
            $pixels = vio_read_pixels($ctx);
            self::assertIsString($pixels);
            $half = self::SIZE >> 1;

            return array_values((array) unpack('C4', substr($pixels, ($half * self::SIZE + $half) * 4, 4)));
        } finally {
            unset($renderer);
            MaterialRegistry::clear();
            MeshRegistry::clear();
            vio_destroy($ctx);
        }
    }
}
