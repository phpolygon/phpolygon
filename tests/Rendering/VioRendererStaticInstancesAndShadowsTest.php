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
use PHPolygon\Rendering\Command\DrawMeshInstanced;
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetDirectionalLight;
use PHPolygon\Rendering\CubemapRegistry;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ScreenSpaceAO;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * Two renderer details checked on pixels: static instanced batches that share
 * mesh and material keep their own instances (the static instance cache no
 * longer hands the first batch to the second), and DrawMesh::$castShadows
 * keeps a mesh out of the shadow map.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioRendererStaticInstancesAndShadowsTest extends TestCase
{
    private const int W = 96;
    private const int H = 64;

    private const array CLEAR = [0.05, 0.05, 0.08];

    private ?\VioContext $ctx = null;

    protected function setUp(): void
    {
        $ctx = @vio_create('auto', ['width' => self::W, 'height' => self::H, 'headless' => true, 'vsync' => false]);
        if ($ctx === false) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        if (!vio_supports_feature($ctx, VIO_FEATURE_3D_PIPELINE)) {
            vio_destroy($ctx);
            $this->markTestSkipped('backend has no 3D pipeline');
        }
        $this->ctx = $ctx;
        MeshRegistry::clear();
        MaterialRegistry::clear();
        CubemapRegistry::clear();
        MeshRegistry::register('box', BoxMesh::generate(1.0, 1.0, 1.0));
        MaterialRegistry::register('orange', new Material(albedo: new Color(0.9, 0.4, 0.15), useEnvironmentMap: false));
        MaterialRegistry::register('ground', new Material(albedo: new Color(0.7, 0.7, 0.7), useEnvironmentMap: false));
    }

    protected function tearDown(): void
    {
        if ($this->ctx !== null) {
            vio_destroy($this->ctx);
            $this->ctx = null;
        }
    }

    public function testTwoStaticBatchesWithSameMeshAndMaterialKeepTheirOwnInstances(): void
    {
        foreach ([[null, null], ['left', 'right']] as [$keyA, $keyB]) {
            $renderer = $this->renderer(ShadowQuality::Off);
            $left = new DrawMeshInstanced('box', 'orange', [Mat4::translation(-2.0, 0.0, 0.0)], isStatic: true, staticKey: $keyA);
            $right = new DrawMeshInstanced('box', 'orange', [Mat4::translation(2.0, 0.0, 0.0)], isStatic: true, staticKey: $keyB);

            // Frame 1 caches; frame 2 must still draw both batches where they are.
            $this->renderScene($renderer, [$left, $right]);
            $image = $this->renderScene($renderer, [$left, $right]);

            $label = $keyA === null ? 'derived keys' : 'caller keys';
            self::assertFalse(self::isClear(self::px($image, 24, 32)), "$label: left batch drawn");
            self::assertFalse(self::isClear(self::px($image, 72, 32)), "$label: right batch drawn at its own position");
        }
    }

    /**
     * The static cache recognises a re-sent batch by its first/last Mat4
     * objects. A batch that keeps those objects but changes in between (here: a
     * third instance in the middle) or keeps the values in new objects must
     * still draw what it holds now. Shadows on: the shadow and colour passes
     * both read the batch in one frame.
     */
    public function testStaticBatchWithTheSameEndMatricesStillShowsItsMiddle(): void
    {
        $renderer = $this->renderer(ShadowQuality::Medium);
        $left = Mat4::translation(-2.0, 0.0, 0.0);
        $right = Mat4::translation(2.0, 0.0, 0.0);

        $this->renderScene($renderer, [new DrawMeshInstanced('box', 'orange', [$left, $right], isStatic: true, staticKey: 'k')]);
        $image = $this->renderScene($renderer, [new DrawMeshInstanced('box', 'orange', [$left, $right], isStatic: true, staticKey: 'k')]);
        self::assertTrue(self::isClear(self::px($image, 48, 32)), 'two boxes: the middle is empty');

        $three = new DrawMeshInstanced('box', 'orange', [$left, Mat4::translation(0.0, 0.0, 0.0), $right], isStatic: true, staticKey: 'k');
        $image = $this->renderScene($renderer, [$three]);
        self::assertFalse(self::isClear(self::px($image, 48, 32)), 'same end matrices, new middle instance: drawn');

        // Equal values in new Mat4 objects: still the same three boxes.
        $copy = new DrawMeshInstanced('box', 'orange', [
            Mat4::translation(-2.0, 0.0, 0.0), Mat4::translation(0.0, 0.0, 0.0), Mat4::translation(2.0, 0.0, 0.0),
        ], isStatic: true, staticKey: 'k');
        $image = $this->renderScene($renderer, [$copy]);
        self::assertFalse(self::isClear(self::px($image, 24, 32)));
        self::assertFalse(self::isClear(self::px($image, 48, 32)));
        self::assertFalse(self::isClear(self::px($image, 72, 32)));
        self::assertSame(1, $renderer->staticInstanceCacheSize());
    }

    /**
     * A flat static batch is compared whole: re-sending the same array keeps
     * the cache, and a changed middle instance (same count, same first and last
     * matrix) is drawn where it is now.
     */
    public function testFlatStaticBatchIsComparedWhole(): void
    {
        $renderer = $this->renderer(ShadowQuality::Medium);
        $flat = static fn (float $middleY): array => [
            ...Mat4::translation(-2.0, 0.0, 0.0)->toArray(),
            ...Mat4::translation(0.0, $middleY, 0.0)->toArray(),
            ...Mat4::translation(2.0, 0.0, 0.0)->toArray(),
        ];
        $centred = $flat(0.0);
        $image = $this->renderScene($renderer, [DrawMeshInstanced::flat('box', 'orange', $centred, 3, isStatic: true, staticKey: 'f')]);
        self::assertFalse(self::isClear(self::px($image, 48, 32)), 'middle box drawn');
        $image = $this->renderScene($renderer, [DrawMeshInstanced::flat('box', 'orange', $centred, 3, isStatic: true, staticKey: 'f')]);
        self::assertFalse(self::isClear(self::px($image, 48, 32)), 'same array: still drawn');

        $image = $this->renderScene($renderer, [DrawMeshInstanced::flat('box', 'orange', $flat(20.0), 3, isStatic: true, staticKey: 'f')]);
        self::assertTrue(self::isClear(self::px($image, 48, 32)), 'the moved middle box left the centre');
        self::assertFalse(self::isClear(self::px($image, 24, 32)));
        self::assertFalse(self::isClear(self::px($image, 72, 32)));
        self::assertSame(1, $renderer->staticInstanceCacheSize());
    }

    public function testInvalidateStaticInstancesDropsTheCache(): void
    {
        $renderer = $this->renderer(ShadowQuality::Off);
        $batch = new DrawMeshInstanced('box', 'orange', [Mat4::translation(-2.0, 0.0, 0.0)], isStatic: true, staticKey: 'k');
        $this->renderScene($renderer, [$batch]);
        self::assertSame(1, $renderer->staticInstanceCacheSize());
        $renderer->invalidateStaticInstances('k');
        self::assertSame(0, $renderer->staticInstanceCacheSize());
        $this->renderScene($renderer, [$batch]);
        $renderer->invalidateStaticInstances();
        self::assertSame(0, $renderer->staticInstanceCacheSize());
    }

    public function testCastShadowsFalseLeavesTheGroundLit(): void
    {
        $ground = new DrawMesh('box', 'ground', Mat4::translation(0.0, -1.0, 0.0)->multiply(Mat4::scaling(12.0, 0.2, 12.0)));
        $boxAt = Mat4::translation(0.0, 1.2, 0.0);

        $shadowed = $this->renderScene($this->renderer(ShadowQuality::Medium), [
            $ground, new DrawMesh('box', 'orange', $boxAt),
        ], light: true);
        $noCaster = $this->renderScene($this->renderer(ShadowQuality::Medium), [
            $ground, new DrawMesh('box', 'orange', $boxAt, castShadows: false),
        ], light: true);
        $noBox = $this->renderScene($this->renderer(ShadowQuality::Medium), [$ground], light: true);

        // The ground point under the box (its shadow, in front of it in view).
        [$x, $y] = $this->groundProbe();
        $lum = static fn (array $p): int => $p[0] + $p[1] + $p[2];
        self::assertLessThan($lum(self::px($noBox, $x, $y)) - 30, $lum(self::px($shadowed, $x, $y)), 'a caster darkens the ground');
        self::assertEqualsWithDelta($lum(self::px($noBox, $x, $y)), $lum(self::px($noCaster, $x, $y)), 6, 'castShadows: false leaves the ground lit');
    }

    /** @return array{0: int, 1: int} */
    private function groundProbe(): array
    {
        return [48, 34];
    }

    private function renderer(ShadowQuality $shadows): VioRenderer3D
    {
        self::assertNotNull($this->ctx);
        $renderer = new VioRenderer3D($this->ctx, self::W, self::H);
        $renderer->applySettings(new GraphicsSettings(
            shadowQuality: $shadows,
            antiAliasing: AntiAliasing::Off,
            ambientOcclusion: ScreenSpaceAO::Off,
            bloom: false,
        ));
        return $renderer;
    }

    /** @param list<object> $draws */
    private function renderScene(VioRenderer3D $renderer, array $draws, bool $light = false): string
    {
        $list = new RenderCommandList();
        $eye = $light ? new Vec3(0.0, 4.0, 7.0) : new Vec3(0.0, 0.0, 7.0);
        $list->add(new SetCamera(
            Mat4::lookAt($eye, new Vec3(0.0, $light ? -0.5 : 0.0, 0.0), new Vec3(0.0, 1.0, 0.0)),
            Mat4::perspective(deg2rad(50.0), self::W / self::H, 0.1, 100.0),
        ));
        if ($light) {
            $list->add(new SetAmbientLight(new Color(1.0, 1.0, 1.0), 0.2));
            $list->add(new SetDirectionalLight(new Vec3(0.0, -1.0, 0.0), new Color(1.0, 1.0, 1.0), 2.0));
        } else {
            $list->add(new SetAmbientLight(new Color(1.0, 1.0, 1.0), 1.0));
        }
        foreach ($draws as $draw) {
            $list->add($draw);
        }
        $image = $renderer->renderToImage($list, self::W, self::H, new Color(...self::CLEAR));
        self::assertSame(self::W * self::H * 4, strlen($image));
        return $image;
    }

    /** @param array{0: int, 1: int, 2: int} $px */
    private static function isClear(array $px): bool
    {
        foreach ([0, 1, 2] as $i) {
            if (abs($px[$i] - (int) round(self::CLEAR[$i] * 255.0)) > 12) {
                return false;
            }
        }
        return true;
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function px(string $image, int $x, int $y): array
    {
        $o = ($y * self::W + $x) * 4;
        return [ord($image[$o]), ord($image[$o + 1]), ord($image[$o + 2])];
    }
}
