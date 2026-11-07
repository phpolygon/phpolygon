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
use PHPolygon\Rendering\Command\SetShaderParameter;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\NormalPattern;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ScreenSpaceAO;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * Surface techniques of shader plan 5.1 on the GPU: a soot decal darkens the floor
 * inside its box and leaves it alone outside; the cobblestone pattern gives a lit
 * floor visible relief (much more contrast than the flat floor).
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioSurfaceDecalTest extends TestCase
{
    private const SIZE = 64;

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
        MeshRegistry::register('floor', BoxMesh::generate(10.0, 0.1, 10.0));
        MaterialRegistry::register('plain', new Material(albedo: new Color(0.8, 0.8, 0.8), roughness: 0.2, useEnvironmentMap: false));
        MaterialRegistry::register('cobble', new Material(albedo: new Color(0.8, 0.8, 0.8), roughness: 0.2, useEnvironmentMap: false, normalPattern: NormalPattern::COBBLESTONE, normalScale: 1.0, normalIntensity: 1.0));
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

    public function testASootDecalDarkensOnlyInsideItsBox(): void
    {
        $decal = [
            new SetShaderParameter('u_decal_count', 1),
            new SetShaderParameter('u_decals[0]', [0.0, 0.0, 0.0, 0.0]),
            new SetShaderParameter('u_decals[1]', [1.5, 0.5, 1.5, 1.0]),
            new SetShaderParameter('u_decals[2]', [0.1, 0.1, 0.1, 1.0]),
        ];
        $with = $this->render('plain', $decal);
        $without = $this->render('plain', [new SetShaderParameter('u_decal_count', 0)]);

        $centreWith = $this->red($with, 32, 32);
        $centreWithout = $this->red($without, 32, 32);
        self::assertLessThan($centreWithout * 0.85, $centreWith, "soot darkens the middle ({$centreWith} vs {$centreWithout})");
        self::assertEqualsWithDelta($this->red($without, 4, 4), $this->red($with, 4, 4), 3, 'outside the box nothing changes');
    }

    public function testCobblestonesShowRelief(): void
    {
        $flat = $this->render('plain', [new SetShaderParameter('u_decal_count', 0)]);
        $cobble = $this->render('cobble', [new SetShaderParameter('u_decal_count', 0)]);

        self::assertGreaterThan(self::spread($flat) + 20, self::spread($cobble), 'stones and joints differ in brightness');
    }

    private static function spread(string $image): int
    {
        $min = 255;
        $max = 0;
        for ($i = 0; $i < strlen($image); $i += 4) {
            $v = ord($image[$i]);
            $min = min($min, $v);
            $max = max($max, $v);
        }

        return $max - $min;
    }

    private function red(string $image, int $x, int $y): int
    {
        return ord($image[($y * self::SIZE + $x) * 4]);
    }

    /** @param list<object> $extra */
    private function render(string $material, array $extra): string
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
        $list->add(new SetAmbientLight(new Color(1.0, 1.0, 1.0), 0.15));
        $list->add(new SetDirectionalLight(new Vec3(-0.6, -0.5, -0.3), new Color(1.0, 1.0, 1.0), 0.35));
        foreach ($extra as $cmd) {
            $list->add($cmd);
        }
        $list->add(new DrawMesh('floor', $material, Mat4::translation(0.0, -0.05, 0.0)));

        return $renderer->renderToImage($list, self::SIZE, self::SIZE);
    }
}
