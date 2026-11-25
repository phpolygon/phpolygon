<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use PHPolygon\Geometry\MeshData;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\DrawMeshInstanced;
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\CubemapRegistry;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\TransparentDrawBatcher;
use PHPolygon\Rendering\TransparentDrawSorter;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * A run of identical panes drawn as one instanced draw (TransparentDrawBatcher)
 * must come out as the same pixels as the separate draws: same order, same
 * blend, same lighting of the pane.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
#[Group('gpu')]
final class VioTransparentBatchTest extends TestCase
{
    private const int W = 96;
    private const int H = 96;

    private ?\VioContext $ctx = null;

    protected function setUp(): void
    {
        $ctx = VioTestContext::create(self::W, self::H);
        if ($ctx === null) {
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
        TransparentDrawBatcher::clearCache();
        TransparentDrawSorter::clearCache();
        MeshRegistry::register('quad', new MeshData(
            [-1.0, -1.0, 0.0, 1.0, -1.0, 0.0, 1.0, 1.0, 0.0, -1.0, 1.0, 0.0],
            [0.0, 0.0, 1.0, 0.0, 0.0, 1.0, 0.0, 0.0, 1.0, 0.0, 0.0, 1.0],
            [0.0, 0.0, 1.0, 0.0, 1.0, 1.0, 0.0, 1.0],
            [0, 1, 2, 0, 2, 3],
        ));
        MaterialRegistry::register('pane', new Material(albedo: new Color(0.9, 0.45, 0.05), alpha: 0.4, useEnvironmentMap: false));
    }

    protected function tearDown(): void
    {
        if ($this->ctx !== null) {
            vio_destroy($this->ctx);
            $this->ctx = null;
        }
    }

    /** @param list<object> $draws */
    private function render(array $draws): string
    {
        self::assertNotNull($this->ctx);
        $renderer = new VioRenderer3D($this->ctx, self::W, self::H);
        $renderer->applySettings(new GraphicsSettings(
            shadowQuality: ShadowQuality::Off,
            antiAliasing: AntiAliasing::Off,
            bloom: false,
        ));
        $list = new RenderCommandList();
        $list->add(new SetCamera(
            Mat4::lookAt(new Vec3(0.0, 0.0, 8.0), new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 1.0, 0.0)),
            Mat4::perspective(deg2rad(45.0), self::W / self::H, 0.1, 100.0),
        ));
        $list->add(new SetAmbientLight(new Color(1.0, 1.0, 1.0), 1.0));
        foreach ($draws as $d) {
            $list->add($d);
        }
        $image = $renderer->renderToImage($list, self::W, self::H, new Color(0.05, 0.05, 0.08));
        self::assertSame(self::W * self::H * 4, strlen($image));

        return $image;
    }

    public function testABatchedRunLooksLikeTheSeparateDraws(): void
    {
        // Five overlapping panes, shifted so that every overlap shows, far to near.
        $matrices = [];
        foreach ([-2.0, -1.0, 0.0, 1.0, 2.0] as $i => $z) {
            $matrices[] = Mat4::translation(-0.8 + 0.4 * $i, 0.3 * $i - 0.6, $z)->multiply(Mat4::scaling(1.1, 1.1, 1.0));
        }
        $separate = [];
        foreach ($matrices as $m) {
            $separate[] = new DrawMesh('quad', 'pane', $m);
        }
        $list = new RenderCommandList();
        foreach ($separate as $d) {
            $list->add($d);
        }
        self::assertSame(4, TransparentDrawBatcher::batchList($list));
        $batched = $list->getCommands();
        self::assertCount(1, $batched);
        self::assertInstanceOf(DrawMeshInstanced::class, $batched[0]);

        $a = $this->render($separate);
        $b = $this->render($batched);

        $max = 0;
        $differing = 0;
        for ($i = 0, $n = strlen($a); $i < $n; $i++) {
            $d = abs(ord($a[$i]) - ord($b[$i]));
            $max = max($max, $d);
            $differing += $d > 1 ? 1 : 0;
        }
        self::assertSame(0, $differing, "no byte differs by more than 1 (max {$max})");
        $centre = ((int) (self::H / 2) * self::W + (int) (self::W / 2)) * 4;
        self::assertGreaterThan(ord($a[0]) + 20, ord($a[$centre]), 'the panes are visible at all');
    }
}
