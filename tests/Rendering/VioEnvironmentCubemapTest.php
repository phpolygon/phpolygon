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
use PHPolygon\Math\Vec4;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetDirectionalLight;
use PHPolygon\Rendering\Command\SetSky;
use PHPolygon\Rendering\CubemapRegistry;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioEnvironmentCubemap;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * The GPU sky environment cubemap that replaced ext-metal's MetalCubemapTarget:
 * face matrices unproject to the six axis directions, the sky hash tracks the
 * inputs that change the rendered sky, and — on a backend with cube render
 * targets — a SetSky frame produces a bindable cubemap that colours the water
 * reflection.
 */
final class VioEnvironmentCubemapTest extends TestCase
{
    private static function sky(float $sunX = 0.3): SetSky
    {
        return new SetSky(
            new Vec3($sunX, 0.8, 0.5),
            new Color(1.0, 0.95, 0.9), 1.0,
            new Color(0.1, 0.3, 0.8),   // zenith
            new Color(0.7, 0.8, 0.9),   // horizon
            new Color(0.2, 0.15, 0.1),  // ground
        );
    }

    public function testFaceMatricesUnprojectToTheAxisDirections(): void
    {
        $expected = [[1, 0, 0], [-1, 0, 0], [0, 1, 0], [0, -1, 0], [0, 0, 1], [0, 0, -1]];
        $faces = VioEnvironmentCubemap::faceInverseViewProjections();
        self::assertCount(6, $faces);

        foreach ($faces as $i => $invVp) {
            // Same maths as the sky shaders: unproject the NDC centre at z = 1.
            $world = (new Mat4($invVp))->multiplyVec4(new Vec4(0.0, 0.0, 1.0, 1.0));
            $dir = (new Vec3($world->x / $world->w, $world->y / $world->w, $world->z / $world->w))->normalize();
            self::assertEqualsWithDelta($expected[$i][0], $dir->x, 1e-4, "face $i x");
            self::assertEqualsWithDelta($expected[$i][1], $dir->y, 1e-4, "face $i y");
            self::assertEqualsWithDelta($expected[$i][2], $dir->z, 1e-4, "face $i z");
        }
    }

    public function testSkyHashTracksTheInputsThatChangeTheSky(): void
    {
        self::assertSame(VioEnvironmentCubemap::skyHash(self::sky()), VioEnvironmentCubemap::skyHash(self::sky()));
        self::assertNotSame(VioEnvironmentCubemap::skyHash(self::sky(0.3)), VioEnvironmentCubemap::skyHash(self::sky(-0.3)));
        self::assertSame(7.0, VioEnvironmentCubemap::mipMax()); // 128² → levels 0..7
    }

    public function testSkyHashIgnoresDriftTooSmallToSee(): void
    {
        // A running day/night cycle nudges the sun a tiny step every tick; that
        // alone must not re-render six cube faces and a mip chain.
        self::assertSame(VioEnvironmentCubemap::skyHash(self::sky(0.3)), VioEnvironmentCubemap::skyHash(self::sky(0.3 + 1e-5)));
        self::assertNotSame(VioEnvironmentCubemap::skyHash(self::sky(0.3)), VioEnvironmentCubemap::skyHash(self::sky(0.32)));
    }

    public function testUpdatesAreRateLimited(): void
    {
        $interval = VioEnvironmentCubemap::MIN_UPDATE_INTERVAL;
        self::assertTrue(VioEnvironmentCubemap::shouldUpdate('b', '', null, 10.0), 'the first sky renders immediately');
        self::assertFalse(VioEnvironmentCubemap::shouldUpdate('a', 'a', 1.0, 100.0), 'an unchanged sky never re-renders');
        self::assertFalse(VioEnvironmentCubemap::shouldUpdate('b', 'a', 10.0, 10.0 + $interval * 0.5), 'a changed sky waits for the interval');
        self::assertTrue(VioEnvironmentCubemap::shouldUpdate('b', 'a', 10.0, 10.0 + $interval), 'a changed sky renders once the interval passed');
    }

    #[RequiresPhpExtension('vio')]
    #[Group('native-gpu')]
    public function testSkyFrameProducesABindableCubemapOnCubeCapableBackends(): void
    {
        $w = 64;
        $h = 64;
        $ctx = @vio_create('auto', ['width' => $w, 'height' => $h, 'headless' => true, 'vsync' => false]);
        if ($ctx === false) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        if (!VioEnvironmentCubemap::isSupported($ctx)) {
            vio_destroy($ctx);
            $this->markTestSkipped('backend lacks cube render targets');
        }

        MeshRegistry::clear();
        MaterialRegistry::clear();
        CubemapRegistry::clear();   // no baked probe → the GPU sky cube is the env map

        $renderer = new VioRenderer3D($ctx, $w, $h);
        MeshRegistry::register('box', BoxMesh::generate(1.0, 1.0, 1.0));
        MaterialRegistry::register('mat', new Material(albedo: new Color(0.9, 0.4, 0.15)));

        $list = new RenderCommandList();
        $list->add(new SetCamera(
            Mat4::lookAt(new Vec3(2.5, 2.5, 3.5), new Vec3(0, 0, 0), new Vec3(0, 1, 0)),
            Mat4::perspective(deg2rad(55.0), $w / $h, 0.1, 100.0),
        ));
        $list->add(new SetDirectionalLight(new Vec3(-0.4, -1.0, -0.5), new Color(1, 1, 1), 1.2));
        $list->add(self::sky());
        $list->add(new DrawMesh('box', 'mat', Mat4::identity()));

        self::assertNull($renderer->environmentCubemap(), 'no cubemap before the first sky frame');

        $rgba = $renderer->renderToImage($list, $w, $h, new Color(0.1, 0.5, 0.9, 1.0));
        self::assertSame($w * $h * 4, strlen($rgba));
        self::assertInstanceOf(\VioCubemap::class, $renderer->environmentCubemap(), 'sky frame rendered the env cube');

        // Sky pixels are present: a corner is no longer the clear colour but the
        // sky gradient (fullscreen sky pass overwrites the clear).
        $i = (2 * $w + 2) * 4;
        $corner = [ord($rgba[$i]), ord($rgba[$i + 1]), ord($rgba[$i + 2])];
        self::assertFalse(
            abs($corner[0] - 26) <= 4 && abs($corner[1] - 128) <= 4 && abs($corner[2] - 229) <= 4,
            'sky pass must overwrite the clear colour (' . implode(',', $corner) . ')'
        );

        // A second frame with the same sky reuses the cube (same object, no re-render).
        $before = $renderer->environmentCubemap();
        $renderer->renderToImage($list, $w, $h);
        self::assertSame($before, $renderer->environmentCubemap());

        // The cube feeds the IBL term: a polished metal box shades differently
        // with the environment map than with useEnvironmentMap = false.
        MaterialRegistry::register('mirror', new Material(albedo: new Color(0.9, 0.9, 0.9), roughness: 0.05, metallic: 1.0));
        MaterialRegistry::register('mirror_noenv', new Material(albedo: new Color(0.9, 0.9, 0.9), roughness: 0.05, metallic: 1.0, useEnvironmentMap: false));
        $centre = static function (string $rgba) use ($w, $h): array {
            $o = (intdiv($h, 2) * $w + intdiv($w, 2)) * 4;
            return [ord($rgba[$o]), ord($rgba[$o + 1]), ord($rgba[$o + 2])];
        };
        $with = $centre($renderer->renderToImage(self::scene('mirror', $w, $h), $w, $h, new Color(0.1, 0.5, 0.9, 1.0)));
        $without = $centre($renderer->renderToImage(self::scene('mirror_noenv', $w, $h), $w, $h, new Color(0.1, 0.5, 0.9, 1.0)));
        self::assertNotSame($with, $without, 'environment reflection must change the mirror shading');

        vio_destroy($ctx);
    }

    private static function scene(string $material, int $w, int $h): RenderCommandList
    {
        $list = new RenderCommandList();
        $list->add(new SetCamera(
            Mat4::lookAt(new Vec3(2.5, 2.5, 3.5), new Vec3(0, 0, 0), new Vec3(0, 1, 0)),
            Mat4::perspective(deg2rad(55.0), $w / $h, 0.1, 100.0),
        ));
        $list->add(new SetDirectionalLight(new Vec3(-0.4, -1.0, -0.5), new Color(1, 1, 1), 1.2));
        $list->add(self::sky());
        $list->add(new DrawMesh('box', $material, Mat4::identity()));
        return $list;
    }
}
