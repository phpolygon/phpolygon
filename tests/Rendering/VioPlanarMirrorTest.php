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
use PHPolygon\Math\Vec4;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetDirectionalLight;
use PHPolygon\Rendering\Command\SetPlanarMirror;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\ProcModeRegistry;
use PHPolygon\Rendering\ProcModeShaderRegistry;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ScreenSpaceAO;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\Reflection\PlanarMirrors;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\RenderLayers;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * A planar mirror on the GPU, an asymmetric scene so that every flip shows: a mirror in the
 * wall z = -3 facing +z, a tall red box at the left in front of it, a tall blue box BEHIND
 * the camera at the right (only the mirror sees it). The expected pixel of each object's
 * image comes from projecting its mirror image through the real camera, so a wrong
 * orientation, a wrong winding or a missing object fails.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioPlanarMirrorTest extends TestCase
{
    private const W = 128;
    private const H = 96;

    private ?\VioContext $ctx = null;

    protected function setUp(): void
    {
        $ctx = VioTestContext::create(self::W, self::H, ['shader_cache' => sys_get_temp_dir() . '/phpolygon-test-shader-cache']);
        if ($ctx === null) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        $this->ctx = $ctx;
        $this->clearState();
    }

    protected function tearDown(): void
    {
        putenv('PHPOLYGON_VIO_MRT');
        putenv('PHPOLYGON_SSAO');
        $this->clearState();
        if ($this->ctx !== null) {
            vio_destroy($this->ctx);
            $this->ctx = null;
        }
    }

    private function clearState(): void
    {
        MeshRegistry::clear();
        MaterialRegistry::clear();
        ProcModeRegistry::clear();
        ProcModeShaderRegistry::clear();
        PlanarMirrors::clear();
    }

    private function registerScene(bool $mirrors): void
    {
        $this->clearState();
        if ($mirrors) {
            PlanarMirrors::register();
            PlanarMirrors::registerMode(3);
        }
        MeshRegistry::register('box', BoxMesh::generate(1.0, 1.0, 1.0));
        $flat = static fn(float $r, float $g, float $b): Material => new Material(albedo: new Color($r, $g, $b), roughness: 0.9, useEnvironmentMap: false);
        MaterialRegistry::register('floor', $flat(0.5, 0.5, 0.5));
        MaterialRegistry::register('wall', $flat(0.6, 0.6, 0.6));
        MaterialRegistry::register('red', $flat(0.9, 0.05, 0.05));
        MaterialRegistry::register('blue', $flat(0.05, 0.1, 0.95));
        MaterialRegistry::register('skin', $flat(0.9, 0.7, 0.1));
        MaterialRegistry::register('mirror', new Material(albedo: new Color(1.0, 1.0, 1.0), roughness: 0.05, useEnvironmentMap: false));
        ProcModeRegistry::map('mirror', 3);
    }

    private function renderer(): VioRenderer3D
    {
        self::assertNotNull($this->ctx);
        $renderer = new VioRenderer3D($this->ctx, self::W, self::H);
        $renderer->applySettings(new GraphicsSettings(
            shadowQuality: ShadowQuality::Off,
            ambientOcclusion: ScreenSpaceAO::Off,
            antiAliasing: AntiAliasing::Off,
            bloom: false,
        ));

        return $renderer;
    }

    private static function view(Vec3 $eye): Mat4
    {
        return Mat4::lookAt($eye, new Vec3(0.0, 1.2, -3.0), new Vec3(0.0, 1.0, 0.0));
    }

    private static function projection(): Mat4
    {
        return Mat4::perspective(deg2rad(60.0), self::W / self::H, 0.1, 60.0);
    }

    private static function mirrorCommand(float $maxDistance = 20.0, int $updateEvery = 1, int $mask = RenderLayers::MIRROR_DEFAULT): SetPlanarMirror
    {
        return new SetPlanarMirror(new Vec3(0.0, 1.5, -2.98), new Vec3(0.0, 0.0, 1.0), new Vec3(1.0, 0.0, 0.0), 2.4, 1.4, 0.75, $maxDistance, $updateEvery, $mask, 0.0, 'wall');
    }

    private static function scene(Vec3 $eye, ?SetPlanarMirror $mirror, bool $body = false): RenderCommandList
    {
        $l = new RenderCommandList();
        $l->add(new SetCamera(self::view($eye), self::projection()));
        $l->add(new SetAmbientLight(new Color(1.0, 1.0, 1.0), 0.6));
        $l->add(new SetDirectionalLight(new Vec3(-0.3, -1.0, -0.5), new Color(1.0, 1.0, 1.0), 1.0));
        $l->add(new DrawMesh('box', 'floor', Mat4::translation(0.0, -0.05, 2.0)->multiply(Mat4::scaling(12.0, 0.1, 16.0))));
        $l->add(new DrawMesh('box', 'wall', Mat4::translation(0.0, 1.5, -3.1)->multiply(Mat4::scaling(12.0, 3.0, 0.2))));
        // The panel's front face at z = -2.98, on the mirror-surface layer.
        $l->add(new DrawMesh('box', 'mirror', Mat4::translation(0.0, 1.5, -2.99)->multiply(Mat4::scaling(4.8, 2.8, 0.02)), layers: RenderLayers::MIRROR_SURFACE));
        // In front of the mirror at the left; behind the camera at the right.
        $l->add(new DrawMesh('box', 'red', Mat4::translation(-1.2, 0.8, 0.0)->multiply(Mat4::scaling(0.8, 1.6, 0.8))));
        $l->add(new DrawMesh('box', 'blue', Mat4::translation(1.4, 1.0, 7.0)->multiply(Mat4::scaling(1.0, 2.0, 1.0))));
        if ($body) {
            // A yellow body at the centre between the camera and the mirror, on the BODY layer,
            // hidden from the camera the way Renderer3DSystem draws a hidden-from-main body.
            $l->add(new DrawMesh('box', 'skin', Mat4::translation(0.2, 0.8, 1.2)->multiply(Mat4::scaling(0.5, 1.6, 0.5)), castShadows: false, shadowOnly: true, layers: RenderLayers::BODY));
        }
        if ($mirror !== null) {
            $l->add($mirror);
        }

        return $l;
    }

    /** Pixel (col, row) of a world point through the real camera. */
    private static function project(Vec3 $eye, Vec3 $p): array
    {
        $c = self::projection()->multiply(self::view($eye))->multiplyVec4(new Vec4($p->x, $p->y, $p->z, 1.0));

        return [(int) floor(($c->x / $c->w * 0.5 + 0.5) * self::W), (int) floor((1.0 - ($c->y / $c->w * 0.5 + 0.5)) * self::H)];
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function px(string $rgba, int $col, int $row): array
    {
        $o = ($row * self::W + $col) * 4;

        return [ord($rgba[$o]), ord($rgba[$o + 1]), ord($rgba[$o + 2])];
    }

    private static function isRed(array $c): bool
    {
        return $c[0] > 120 && $c[0] - $c[1] > 80 && $c[0] - $c[2] > 80;
    }

    private static function isBlue(array $c): bool
    {
        return $c[2] > 120 && $c[2] - $c[0] > 80 && $c[2] - $c[1] > 60;
    }

    private static function isYellow(array $c): bool
    {
        return $c[0] > 120 && $c[1] > 90 && $c[0] - $c[2] > 70 && abs($c[0] - $c[1]) < 70;
    }

    /** @return array<string, array{0: string}> */
    public static function paths(): array
    {
        return ['forward' => ['0'], 'mrt' => ['1']];
    }

    private function usePath(string $mrt): void
    {
        if ($mrt === '1') {
            if (!defined('VIO_FEATURE_MRT') || !vio_supports_feature($this->ctx, VIO_FEATURE_MRT) || vio_backend_name($this->ctx) === 'opengl') {
                $this->markTestSkipped('backend has no MRT scene path');
            }
            putenv('PHPOLYGON_SSAO=1');
        }
        putenv('PHPOLYGON_VIO_MRT=' . $mrt);
    }

    #[DataProvider('paths')]
    public function testTheMirrorShowsTheSceneUprightWithWhatLiesBehindTheCamera(string $mrt): void
    {
        $this->usePath($mrt);
        $this->registerScene(true);
        $eye = new Vec3(0.3, 1.6, 4.0);
        $renderer = $this->renderer();
        $image = $renderer->renderToImage(self::scene($eye, self::mirrorCommand(), true), self::W, self::H, new Color(0.0, 0.0, 0.0));

        self::assertTrue($renderer->planarMirrorBound());
        self::assertSame(1, $renderer->planarMirrorDraws());

        // The red box at x = -1.2, z = 0 appears at x = -1.2, z = -6 in the wall: its middle height, then its base.
        [$c, $r] = self::project($eye, new Vec3(-1.2, 0.8, -5.96));
        self::assertTrue(self::isRed(self::px($image, $c, $r)), 'the red box in the mirror, upright and at the left: ' . json_encode(self::px($image, $c, $r)));
        [$c, $r] = self::project($eye, new Vec3(-1.2, 2.0, -5.96));
        self::assertFalse(self::isRed(self::px($image, $c, $r)), 'above its top the mirror shows the wall');
        // The blue box behind the camera at x = 1.4, z = 7 appears at z = -13, at the right.
        [$c, $r] = self::project($eye, new Vec3(1.4, 1.0, -12.96));
        self::assertTrue(self::isBlue(self::px($image, $c, $r)), 'the blue box behind the camera is in the mirror: ' . json_encode(self::px($image, $c, $r)));
        // The yellow body in the mirror (it is hidden from the camera itself).
        [$c, $r] = self::project($eye, new Vec3(0.2, 0.8, -7.16));
        self::assertTrue(self::isYellow(self::px($image, $c, $r)), 'the hidden body is in the mirror: ' . json_encode(self::px($image, $c, $r)));
        // The body is not drawn in the camera's own view.
        [$c, $r] = self::project($eye, new Vec3(0.2, 0.8, 1.2));
        self::assertFalse(self::isYellow(self::px($image, $c, $r)), 'hidden from the main camera');
    }

    public function testTheBodyLayerOutOfTheMaskLeavesTheBodyOutOfTheMirror(): void
    {
        $this->registerScene(true);
        $eye = new Vec3(0.3, 1.6, 4.0);
        $renderer = $this->renderer();
        $mask = RenderLayers::MIRROR_DEFAULT & ~RenderLayers::BODY;
        $image = $renderer->renderToImage(self::scene($eye, self::mirrorCommand(20.0, 1, $mask), true), self::W, self::H, new Color(0.0, 0.0, 0.0));
        [$c, $r] = self::project($eye, new Vec3(0.2, 0.8, -7.16));
        self::assertFalse(self::isYellow(self::px($image, $c, $r)), 'the body layer is outside the mask');
        [$c, $r] = self::project($eye, new Vec3(-1.2, 0.8, -5.96));
        self::assertTrue(self::isRed(self::px($image, $c, $r)), 'the rest is there');
    }

    public function testNoCommandNoPassAndTheFrameIsThatOfAnEngineWithoutMirrors(): void
    {
        $eye = new Vec3(0.3, 1.6, 4.0);
        $this->registerScene(false);
        $plain = $this->renderer();
        $without = $plain->renderToImage(self::scene($eye, null), self::W, self::H, new Color(0.0, 0.0, 0.0));

        // Registered, no command: the mirror panel is the only thing that may differ, so leave it out of the compare.
        $this->registerScene(true);
        $registered = $this->renderer();
        $with = $registered->renderToImage(self::scene($eye, null), self::W, self::H, new Color(0.0, 0.0, 0.0));
        self::assertSame(0, $registered->planarMirrorDraws());
        self::assertFalse($registered->planarMirrorBound());

        // Away from the panel (the lower rows show floor and the boxes) the two frames agree to the byte.
        $differing = 0;
        for ($row = 70; $row < self::H; $row++) {
            for ($col = 0; $col < self::W; $col++) {
                if (self::px($without, $col, $row) !== self::px($with, $col, $row)) {
                    $differing++;
                }
            }
        }
        self::assertSame(0, $differing, 'registered mirrors without a command change no pixel outside the panel');
    }

    public function testTheMirrorIsSkippedFromBehindAndBeyondItsDistance(): void
    {
        $this->registerScene(true);
        $eye = new Vec3(0.3, 1.6, 4.0);
        $renderer = $this->renderer();

        $renderer->renderToImage(self::scene($eye, self::mirrorCommand(3.0)), self::W, self::H, new Color(0.0, 0.0, 0.0));
        self::assertSame(0, $renderer->planarMirrorDraws(), 'beyond maxDistance: no pass');
        self::assertFalse($renderer->planarMirrorBound());

        $renderer->renderToImage(self::scene(new Vec3(0.3, 1.6, -9.0), self::mirrorCommand()), self::W, self::H, new Color(0.0, 0.0, 0.0));
        self::assertSame(0, $renderer->planarMirrorDraws(), 'from behind the mirror: no pass');

        $renderer->renderToImage(self::scene($eye, self::mirrorCommand()), self::W, self::H, new Color(0.0, 0.0, 0.0));
        self::assertSame(1, $renderer->planarMirrorDraws());
    }

    public function testUpdateEveryKeepsTheImageAndStaysAlignedWhileTheCameraMoves(): void
    {
        $this->registerScene(true);
        $renderer = $this->renderer();
        $eyes = [new Vec3(0.3, 1.6, 4.0), new Vec3(0.2, 1.6, 4.0), new Vec3(0.1, 1.6, 4.0), new Vec3(0.0, 1.6, 4.0)];
        $images = [];
        foreach ($eyes as $eye) {
            $images[] = $renderer->renderToImage(self::scene($eye, self::mirrorCommand(20.0, 3)), self::W, self::H, new Color(0.0, 0.0, 0.0));
        }
        // Frames 1-3 share one image (drawn at frame 1), frame 4 draws a fresh one.
        self::assertSame(2, $renderer->planarMirrorDraws(), 'four frames, updateEvery 3: two images');
        // The kept image is projected with the camera it was drawn with: the red box stays where it is mirrored.
        foreach ([1, 2] as $i) {
            [$c, $r] = self::project($eyes[$i], new Vec3(-1.2, 0.8, -5.96));
            self::assertTrue(self::isRed(self::px($images[$i], $c, $r)), "frame {$i} with a kept image: the red box is at its projected place");
        }
    }

    public function testBlurSpreadsTheEdgeOfTheImage(): void
    {
        $this->registerScene(true);
        $eye = new Vec3(0.3, 1.6, 4.0);
        $renderer = $this->renderer();
        $sharp = $renderer->renderToImage(self::scene($eye, self::mirrorCommand()), self::W, self::H, new Color(0.0, 0.0, 0.0));
        $cmd = new SetPlanarMirror(new Vec3(0.0, 1.5, -2.98), new Vec3(0.0, 0.0, 1.0), new Vec3(1.0, 0.0, 0.0), 2.4, 1.4, 0.75, 20.0, 1, RenderLayers::MIRROR_DEFAULT, 6.0, 'wall');
        $blurred = $renderer->renderToImage(self::scene($eye, $cmd), self::W, self::H, new Color(0.0, 0.0, 0.0));
        $edgeSharp = 0;
        $edgeBlurred = 0;
        [$c, $r] = self::project($eye, new Vec3(-1.2, 0.8, -5.96));
        for ($dc = -12; $dc <= 12; $dc++) {
            $a = self::px($sharp, $c + $dc, $r);
            $b = self::px($blurred, $c + $dc, $r);
            $edgeSharp = max($edgeSharp, abs($a[0] - self::px($sharp, $c + $dc - 1, $r)[0]));
            $edgeBlurred = max($edgeBlurred, abs($b[0] - self::px($blurred, $c + $dc - 1, $r)[0]));
        }
        self::assertGreaterThan($edgeBlurred, $edgeSharp, 'the steepest red step of the blurred image is gentler');
    }
}


