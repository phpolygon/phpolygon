<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Geometry\PlaneMesh;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Math\Vec4;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetDirectionalLight;
use PHPolygon\Rendering\Command\SetWaveAnimation;
use PHPolygon\Rendering\CubemapRegistry;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\ProcModeRegistry;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ScreenSpaceAO;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * Per-pixel motion vectors (PHPOLYGON_MOTION): the MRT scene target gains a
 * RG16F motion attachment (4) and an R8 reactive attachment (5) while a
 * temporal technique runs. The vectors are read back bit-exactly and compared
 * with the analytic reprojection of the surface each pixel shows - camera
 * translation, camera rotation, a moving object and vertex animation - on
 * every backend that runs locally. Motion is in the texture-coordinate space
 * of the scene target (prevUv - uv), so on Y-flipped backends the vertical
 * component points the other way than in NDC.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioRendererMotionVectorTest extends TestCase
{
    private const W = 96;
    private const H = 64;
    /** Acceptance: vector error in render pixels. */
    private const MAX_ERROR_PX = 0.05;

    private ?\VioContext $ctx = null;
    private string $backend = '';

    /** @return iterable<string, array{0: string}> */
    public static function backends(): iterable
    {
        foreach (['d3d12', 'd3d11', 'vulkan', 'opengl'] as $backend) {
            yield $backend => [$backend];
        }
    }

    protected function tearDown(): void
    {
        if ($this->ctx !== null) {
            vio_destroy($this->ctx);
            $this->ctx = null;
        }
        ProcModeRegistry::clear();
    }

    #[DataProvider('backends')]
    public function testCameraTranslationMatchesTheAnalyticReprojection(string $backend): void
    {
        $renderer = $this->temporalRenderer($backend);
        $this->frame($renderer, $this->wallScene(self::eye(0.0, 0.0), new Vec3(0.0, 0.0, 0.0)));
        $this->frame($renderer, $this->wallScene(self::eye(0.35, 0.2), new Vec3(0.35, 0.2, 0.0)));

        $this->assertMotionMatchesWall($renderer, Mat4::identity(), Mat4::identity());
    }

    #[DataProvider('backends')]
    public function testCameraRotationMatchesTheAnalyticReprojection(string $backend): void
    {
        $renderer = $this->temporalRenderer($backend);
        $this->frame($renderer, $this->wallScene(self::eye(0.0, 0.0), new Vec3(0.0, 0.0, 0.0)));
        $this->frame($renderer, $this->wallScene(self::eye(0.0, 0.0), new Vec3(0.6, -0.35, 0.0)));

        $this->assertMotionMatchesWall($renderer, Mat4::identity(), Mat4::identity());
    }

    #[DataProvider('backends')]
    public function testMovingObjectCarriesItsOwnMotion(string $backend): void
    {
        $renderer = $this->temporalRenderer($backend);
        $eye = self::eye(0.0, 0.0);
        $model = Mat4::translation(0.25, -0.15, 0.0);
        $prev = Mat4::translation(0.0, 0.0, 0.0);
        $this->frame($renderer, $this->wallScene($eye, new Vec3(0.0, 0.0, 0.0), $prev));
        $this->frame($renderer, $this->wallScene($eye, new Vec3(0.0, 0.0, 0.0), $model, $prev));

        $this->assertMotionMatchesWall($renderer, $model, $prev);
    }

    #[DataProvider('backends')]
    public function testStaticSceneWithAStillCameraHasNoMotion(string $backend): void
    {
        $renderer = $this->temporalRenderer($backend);
        $scene = $this->groundScene('ground');
        $this->frame($renderer, $scene);
        $this->frame($renderer, $scene);

        [$maxPx] = $this->motionStats($renderer);
        $this->assertSame(0.0, $maxPx, 'jitter must not leak into the vectors');
    }

    #[DataProvider('backends')]
    public function testVertexAnimationMovesWithTheFrameClock(string $backend): void
    {
        $renderer = $this->temporalRenderer($backend);
        ProcModeRegistry::map('wave', 2);
        $scene = $this->groundScene('wave_ground');
        $this->frame($renderer, $scene);
        $this->frame($renderer, $scene);

        [$maxPx, $nonZero] = $this->motionStats($renderer);
        $this->assertGreaterThan(0.01, $maxPx, 'waves move between frames: u_time_prev');
        $this->assertGreaterThan(0.5, $nonZero, 'most of the animated surface carries motion');
    }

    #[DataProvider('backends')]
    public function testCutResetsTheHistoryAndTheCameraMotion(string $backend): void
    {
        $renderer = $this->temporalRenderer($backend);
        $this->frame($renderer, $this->wallScene(self::eye(0.0, 0.0), new Vec3(0.0, 0.0, 0.0)));
        $this->frame($renderer, $this->wallScene(self::eye(2.0, 1.0), new Vec3(2.0, 1.0, 0.0), cut: true));

        $temporal = $renderer->temporalFrame();
        $this->assertNotNull($temporal);
        $this->assertFalse($temporal->historyValid);
        $this->assertSame('cut', $temporal->resetReason);
        [$maxPx] = $this->motionStats($renderer);
        $this->assertSame(0.0, $maxPx, 'a cut has no camera motion');
    }

    #[DataProvider('backends')]
    public function testTemporalOffLeavesTheMrtTargetAtFourAttachments(string $backend): void
    {
        $this->openContext($backend);
        $renderer = new VioRenderer3D($this->vioContext(), self::W, self::H);
        $renderer->applySettings(new GraphicsSettings(
            shadowQuality: ShadowQuality::Off,
            ambientOcclusion: ScreenSpaceAO::Medium,
            bloom: false,
            antiAliasing: AntiAliasing::Fxaa,
        ));
        $scene = $this->groundScene('ground');
        $this->frame($renderer, $scene);

        $this->assertNull($renderer->temporalFrame(), 'no temporal technique selected');
        $this->assertNull($renderer->motionVectorTarget(), 'no motion attachments without a temporal technique');
    }

    // ── helpers ────────────────────────────────────────────────────────

    private function temporalRenderer(string $backend): VioRenderer3D
    {
        $this->openContext($backend);
        $renderer = new VioRenderer3D($this->vioContext(), self::W, self::H);
        $renderer->applySettings(new GraphicsSettings(
            shadowQuality: ShadowQuality::Off,
            ambientOcclusion: ScreenSpaceAO::Off,
            bloom: false,
            antiAliasing: AntiAliasing::Taa,
        ));
        return $renderer;
    }

    private function openContext(string $backend): void
    {
        $ctx = @vio_create($backend, ['width' => self::W, 'height' => self::H, 'headless' => true, 'vsync' => false]);
        if ($ctx === false || vio_backend_name($ctx) !== $backend) {
            if ($ctx !== false) {
                vio_destroy($ctx);
            }
            $this->markTestSkipped("$backend: no headless context on this machine");
        }
        foreach (['VIO_FEATURE_MRT', 'VIO_FEATURE_RENDER_TARGET_DEPTH_SAMPLE'] as $feature) {
            if (!defined($feature) || !vio_supports_feature($ctx, (int) constant($feature))) {
                vio_destroy($ctx);
                $this->markTestSkipped("$backend: $feature missing");
            }
        }
        $this->ctx = $ctx;
        $this->backend = $backend;
        MeshRegistry::clear();
        MaterialRegistry::clear();
        CubemapRegistry::clear();
        MeshRegistry::register('wall', BoxMesh::generate(100.0, 100.0, 0.01));
        MeshRegistry::register('ground', PlaneMesh::generate(60.0, 60.0, 24));
        MaterialRegistry::register('grey', new Material(albedo: new Color(0.6, 0.6, 0.6), roughness: 0.8));
        MaterialRegistry::register('ground', new Material(albedo: new Color(0.4, 0.6, 0.3)));
        MaterialRegistry::register('wave_ground', new Material(albedo: new Color(0.2, 0.4, 0.8)));
    }

    private function vioContext(): \VioContext
    {
        self::assertNotNull($this->ctx);
        return $this->ctx;
    }

    private function frame(VioRenderer3D $renderer, RenderCommandList $list): void
    {
        $ctx = $this->vioContext();
        vio_begin($ctx);
        $renderer->beginFrame();
        $renderer->render($list);
        $renderer->endFrame();
        vio_end($ctx);
    }

    private static function eye(float $x, float $y): Vec3
    {
        return new Vec3($x, $y, 10.0);
    }

    private static function projection(): Mat4
    {
        return Mat4::perspective(deg2rad(50.0), self::W / self::H, 0.1, 100.0);
    }

    private function wallScene(Vec3 $eye, Vec3 $target, ?Mat4 $model = null, ?Mat4 $prevModel = null, bool $cut = false): RenderCommandList
    {
        $list = new RenderCommandList();
        $list->add(new SetCamera(Mat4::lookAt($eye, $target, new Vec3(0, 1, 0)), self::projection(), $cut));
        $list->add(new SetAmbientLight(new Color(0.5, 0.5, 0.5), 0.6));
        $list->add(new SetDirectionalLight(new Vec3(-0.3, -0.5, -1.0), new Color(1, 1, 1), 1.0));
        $list->add(new DrawMesh('wall', 'grey', $model ?? Mat4::identity(), prevModelMatrix: $prevModel));
        return $list;
    }

    private function groundScene(string $material): RenderCommandList
    {
        $list = new RenderCommandList();
        $list->add(new SetCamera(
            Mat4::lookAt(new Vec3(0.0, 4.0, 9.0), new Vec3(0.0, 0.0, 0.0), new Vec3(0, 1, 0)),
            self::projection(),
        ));
        $list->add(new SetAmbientLight(new Color(0.5, 0.5, 0.5), 0.6));
        $list->add(new SetDirectionalLight(new Vec3(-0.3, -1.0, -0.4), new Color(1, 1, 1), 1.0));
        $list->add(new SetWaveAnimation(true, 0.6, 0.8, 0.0));
        $list->add(new DrawMesh('ground', $material, Mat4::identity()));
        return $list;
    }

    /**
     * Every pixel shows the wall's front face (z = 0.005 in object space). The
     * pixel centre, minus this frame's jitter, unprojects onto it; that object
     * point, moved by the previous model matrix and projected with last
     * frame's view-projection, is where the surface was.
     */
    private function assertMotionMatchesWall(VioRenderer3D $renderer, Mat4 $model, Mat4 $prevModel): void
    {
        $temporal = $renderer->temporalFrame();
        $this->assertNotNull($temporal, 'temporal frame recorded');
        $this->assertTrue($temporal->historyValid);
        $motion = $this->readMotion($renderer);

        $vp = $temporal->jitter->unjitteredViewProjection;
        $invVp = $vp->inverse();
        $toPrev = $temporal->prevViewProjection->multiply($prevModel)->multiply($model->inverse());
        $flip = $this->backend === 'opengl' ? 1.0 : -1.0;
        $worst = 0.0;
        $moving = 0;
        for ($row = 2; $row < self::H - 2; $row++) {
            for ($col = 2; $col < self::W - 2; $col++) {
                $ndcX = ($col + 0.5) / self::W * 2.0 - 1.0 - $temporal->jitter->ndcX;
                $ndcY = 1.0 - ($row + 0.5) / self::H * 2.0 - $temporal->jitter->ndcY;
                $near = self::unproject($invVp, $ndcX, $ndcY, -1.0);
                $far = self::unproject($invVp, $ndcX, $ndcY, 1.0);
                $planeZ = $model->transformPoint(new Vec3(0.0, 0.0, 0.005))->z;
                $t = ($planeZ - $near->z) / ($far->z - $near->z);
                $world = new Vec4(
                    $near->x + ($far->x - $near->x) * $t,
                    $near->y + ($far->y - $near->y) * $t,
                    $planeZ,
                    1.0,
                );
                $prevClip = $toPrev->multiplyVec4($world);
                $expectedX = ($prevClip->x / $prevClip->w - $ndcX) * 0.5;
                $expectedY = ($prevClip->y / $prevClip->w - $ndcY) * 0.5 * $flip;
                [$mx, $my] = $motion[$row * self::W + $col];
                $errX = abs($mx - $expectedX) * self::W;
                $errY = abs($my - $expectedY) * self::H;
                $worst = max($worst, $errX, $errY);
                if (abs($expectedX) * self::W > 0.5) {
                    $moving++;
                }
            }
        }
        fprintf(STDERR, "[motion %s] worst vector error %.4f px\n", $this->backend, $worst);
        $this->assertGreaterThan(100, $moving, 'the scene actually moved');
        $this->assertLessThanOrEqual(self::MAX_ERROR_PX, $worst, "worst vector error in px ({$this->backend})");
    }

    private static function unproject(Mat4 $invVp, float $x, float $y, float $z): Vec3
    {
        $p = $invVp->multiplyVec4(new Vec4($x, $y, $z, 1.0));
        return new Vec3($p->x / $p->w, $p->y / $p->w, $p->z / $p->w);
    }

    /** @return array{0: float, 1: float} largest |vector| in px, share of pixels with any motion */
    private function motionStats(VioRenderer3D $renderer): array
    {
        $max = 0.0;
        $nonZero = 0;
        foreach ($this->readMotion($renderer) as [$x, $y]) {
            $len = max(abs($x) * self::W, abs($y) * self::H);
            $max = max($max, $len);
            if ($len > 0.0) {
                $nonZero++;
            }
        }
        return [$max, $nonZero / (self::W * self::H)];
    }

    /** @return list<array{0: float, 1: float}> top-down texels of the RG16F motion attachment */
    private function readMotion(VioRenderer3D $renderer): array
    {
        $target = $renderer->motionVectorTarget();
        $this->assertNotNull($target, 'motion attachment exists while temporal runs');
        $raw = vio_read_render_target($target, -1, VioRenderer3D::MOTION_ATTACHMENT, ['raw' => true]);
        $this->assertIsString($raw);
        $this->assertSame(self::W * self::H * 4, strlen($raw));
        $halves = array_values(unpack('v*', $raw) ?: []);
        $out = [];
        for ($i = 0, $n = count($halves); $i < $n; $i += 2) {
            $out[] = [self::half($halves[$i]), self::half($halves[$i + 1])];
        }
        return $out;
    }

    private static function half(int $h): float
    {
        $sign = ($h & 0x8000) !== 0 ? -1.0 : 1.0;
        $exp = ($h >> 10) & 0x1f;
        $mant = $h & 0x3ff;
        if ($exp === 0) {
            return $sign * $mant * 2.0 ** -24;
        }
        if ($exp === 31) {
            return $mant === 0 ? $sign * INF : NAN;
        }
        return $sign * (1.0 + $mant / 1024.0) * 2.0 ** ($exp - 15);
    }
}
