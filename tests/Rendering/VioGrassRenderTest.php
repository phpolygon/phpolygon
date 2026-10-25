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
use PHPolygon\Rendering\Command\DrawMeshInstanced;
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetDirectionalLight;
use PHPolygon\Rendering\Command\SetGrass;
use PHPolygon\Rendering\Command\SetWind;
use PHPolygon\Rendering\CubemapRegistry;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ScreenSpaceAO;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioRenderer3D;
use PHPolygon\Vegetation\GrassBladeShape;
use PHPolygon\Vegetation\GrassTuftMesh;

/**
 * Grass blades on the GPU: the tuft draws, coloured root to tip; tufts beyond
 * the fade range vanish; the hash in the instance matrix never moves a vertex;
 * the wind bends the tips (and stands still with foliageMotion 0); a bender
 * pushes the blades away.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioGrassRenderTest extends TestCase
{
    private const int W = 96;
    private const int H = 96;

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
        // A dense, straight, upright tuft (no lean) so the shifts are clean.
        MeshRegistry::register('tuft', GrassTuftMesh::generate(new GrassBladeShape(segments: 4, blades: 24, tuftRadius: 0.1, heightMin: 0.45, heightMax: 0.5, width: 0.06, curvature: 0.0, lean: 0.0), 3));
        MeshRegistry::register('ref_box', BoxMesh::generate(0.6, 0.6, 0.6));
        MaterialRegistry::register('ref_plain', new Material(albedo: new Color(0.6, 0.6, 0.6)));
        MaterialRegistry::register('blades', Material::grass(new Color(0.05, 0.25, 0.05), new Color(0.9, 0.05, 0.05), translucency: 0.0, bend: 0.3, bands: 0, variation: 0.0, rootShade: 0.0));
    }

    protected function tearDown(): void
    {
        if ($this->ctx !== null) {
            vio_destroy($this->ctx);
            $this->ctx = null;
        }
        MeshRegistry::clear();
        MaterialRegistry::clear();
    }

    /** One tuft at (x, 0, z), scale s, hash h. */
    private static function tuft(float $x, float $z, float $s, float $hash = 0.0): DrawMeshInstanced
    {
        return new DrawMeshInstanced(
            meshId: 'tuft',
            materialId: 'blades',
            matrices: [],
            instanceCount: 1,
            packedMatrices: pack('f16', $s, 0.0, 0.0, $hash, 0.0, $s, 0.0, 0.0, 0.0, 0.0, $s, 0.0, $x, 0.0, $z, 1.0),
            castShadows: false,
        );
    }

    /**
     * @param list<object> $draws
     */
    private function render(array $draws, Vec3 $eye, Vec3 $target, ?SetWind $wind = null, ?SetGrass $grass = null, float $time = 0.7): string
    {
        self::assertNotNull($this->ctx);
        $renderer = new VioRenderer3D($this->ctx, self::W, self::H);
        $renderer->applySettings(new GraphicsSettings(shadowQuality: ShadowQuality::Off, antiAliasing: AntiAliasing::Off, ambientOcclusion: ScreenSpaceAO::Off, bloom: false));
        $renderer->setAnimationTime($time);
        $list = new RenderCommandList();
        $list->add(new SetCamera(Mat4::lookAt($eye, $target, new Vec3(0.0, 1.0, 0.0)), Mat4::perspective(deg2rad(50.0), 1.0, 0.05, 500.0)));
        $list->add(new SetAmbientLight(new Color(1.0, 1.0, 1.0), 0.6));
        $list->add(new SetDirectionalLight((new Vec3(-0.3, -1.0, -0.4))->normalize(), new Color(1.0, 1.0, 1.0), 1.0));
        $list->add($wind ?? new SetWind(new Vec3(1.0, 0.0, 0.0), 0.0));
        $list->add($grass ?? new SetGrass(25.0, 60.0, 0.5));
        foreach ($draws as $d) {
            $list->add($d);
        }
        return $renderer->renderToImage($list, self::W, self::H, new Color(0.0, 0.0, 0.0));
    }

    /** @return array{count: int, cx: float, red: int, green: int} pixels that are not the black clear colour */
    private static function coverage(string $rgba, int $y0 = 0, int $y1 = self::H): array
    {
        $count = 0;
        $sx = 0.0;
        $red = 0;
        $green = 0;
        for ($y = $y0; $y < $y1; $y++) {
            for ($x = 0; $x < self::W; $x++) {
                $o = ($y * self::W + $x) * 4;
                $r = ord($rgba[$o]);
                $g = ord($rgba[$o + 1]);
                $b = ord($rgba[$o + 2]);
                if ($r + $g + $b > 24) {
                    $count++;
                    $sx += $x;
                    if ($r > $g * 1.5) {
                        $red++;
                    } elseif ($g > $r * 1.5) {
                        $green++;
                    }
                }
            }
        }
        return ['count' => $count, 'cx' => $count > 0 ? $sx / $count : 0.0, 'red' => $red, 'green' => $green];
    }

    /** @return array{0: Vec3, 1: Vec3} */
    private function side(): array
    {
        return [new Vec3(0.0, 1.1, 4.0), new Vec3(0.0, 1.1, 0.0)];
    }

    /** @return iterable<string, array{0: string}> */
    public static function backends(): iterable
    {
        foreach (['d3d12', 'd3d11', 'vulkan', 'opengl'] as $backend) {
            yield $backend => [$backend];
        }
    }

    /**
     * The grass stages compile and behave on every vio backend: blades drawn,
     * tips coloured, far tufts gone, the wind bends the tips.
     */
    #[DataProvider('backends')]
    public function testEveryBackendDrawsThinsAndBendsTheBlades(string $backend): void
    {
        if ($this->ctx !== null) {
            vio_destroy($this->ctx);
            $this->ctx = null;
        }
        $ctx = @vio_create($backend, ['width' => self::W, 'height' => self::H, 'headless' => true, 'vsync' => false]);
        if ($ctx === false || vio_backend_name($ctx) !== $backend) {
            if ($ctx !== false) {
                vio_destroy($ctx);
            }
            $this->markTestSkipped("{$backend}: no headless context on this machine");
        }
        $this->ctx = $ctx;
        [$eye, $target] = $this->side();
        // Reference: a plain box through the regular pipeline. Some headless
        // contexts (vio OpenGL here) read back an empty image for every draw.
        $box = $this->render([new DrawMesh('ref_box', 'ref_plain', Mat4::translation(0.0, 1.1, 0.0))], $eye, $target);
        if (self::coverage($box)['count'] === 0) {
            $this->markTestSkipped("{$backend}: headless read-back shows no geometry at all");
        }
        $img = $this->render([self::tuft(0.0, 0.0, 4.0)], $eye, $target);
        $top = self::coverage($img, 0, 48);
        self::assertGreaterThan(150, self::coverage($img)['count'], "{$backend}: blades drawn");
        self::assertGreaterThan($top['green'], $top['red'], "{$backend}: tip colour");
        $far = $this->render([self::tuft(0.0, -70.0, 25.0)], new Vec3(0.0, 1.5, 0.0), new Vec3(0.0, 1.5, -70.0));
        self::assertSame(0, self::coverage($far)['count'], "{$backend}: beyond the fade range");
        $still = self::coverage($this->render([self::tuft(0.0, 0.0, 4.0)], $eye, $target, new SetWind(new Vec3(1.0, 0.0, 0.0), 2.0, foliageMotion: 0.0)), 0, 40);
        $windy = self::coverage($this->render([self::tuft(0.0, 0.0, 4.0)], $eye, $target, new SetWind(new Vec3(1.0, 0.0, 0.0), 2.0)), 0, 40);
        self::assertGreaterThan($still['cx'] + 2.0, $windy['cx'], "{$backend}: wind bends the tips");
    }

    public function testTuftIsDrawnAndColouredFromRootToTip(): void
    {
        [$eye, $target] = $this->side();
        $img = $this->render([self::tuft(0.0, 0.0, 4.0)], $eye, $target);
        $top = self::coverage($img, 0, 48);
        $bottom = self::coverage($img, 48, self::H);
        self::assertGreaterThan(150, $top['count'] + $bottom['count'], 'the blades cover the view');
        self::assertGreaterThan($top['green'], $top['red'], 'tips take the tip colour');
        self::assertGreaterThan($bottom['red'], $bottom['green'], 'roots keep the root colour');
    }

    public function testTuftsBeyondTheFadeRangeVanish(): void
    {
        $eye = new Vec3(0.0, 1.5, 0.0);
        $nearImg = $this->render([self::tuft(0.0, -10.0, 4.0)], $eye, new Vec3(0.0, 1.5, -10.0));
        self::assertGreaterThan(30, self::coverage($nearImg)['count'], 'within the full range: drawn');
        $farImg = $this->render([self::tuft(0.0, -70.0, 25.0)], $eye, new Vec3(0.0, 1.5, -70.0));
        self::assertSame(0, self::coverage($farImg)['count'], 'beyond the fade range: gone');
    }

    public function testTheInstanceHashNeverMovesAVertex(): void
    {
        [$eye, $target] = $this->side();
        $a = self::coverage($this->render([self::tuft(0.0, 0.0, 4.0, 0.0)], $eye, $target));
        $b = self::coverage($this->render([self::tuft(0.0, 0.0, 4.0, 0.9)], $eye, $target));
        self::assertSame($a['count'], $b['count']);
        self::assertEqualsWithDelta($a['cx'], $b['cx'], 0.01);
    }

    public function testWindBendsTheTipsAndStandsStillWhenSwitchedOff(): void
    {
        [$eye, $target] = $this->side();
        $draw = [self::tuft(0.0, 0.0, 4.0)];
        $still = self::coverage($this->render($draw, $eye, $target, new SetWind(new Vec3(1.0, 0.0, 0.0), 2.0, foliageMotion: 0.0)), 0, 40);
        $windy = self::coverage($this->render($draw, $eye, $target, new SetWind(new Vec3(1.0, 0.0, 0.0), 2.0)), 0, 40);
        $calm = self::coverage($this->render($draw, $eye, $target, new SetWind(new Vec3(1.0, 0.0, 0.0), 0.0)), 0, 40);
        self::assertGreaterThan($still['cx'] + 2.0, $windy['cx'], sprintf('tips lean with the wind (+X): %.2f -> %.2f px', $still['cx'], $windy['cx']));
        self::assertEqualsWithDelta($calm['cx'], $still['cx'], 0.01, 'foliageMotion 0 stands still');
    }

    public function testBenderPushesTheBladesAway(): void
    {
        [$eye, $target] = $this->side();
        $draw = [self::tuft(0.0, 0.0, 4.0)];
        $free = $this->render($draw, $eye, $target);
        $pushed = $this->render($draw, $eye, $target, grass: new SetGrass(25.0, 60.0, 0.5, [[-0.6, 0.0, 0.0, 1.5]], 1.0));
        self::assertGreaterThan(self::coverage($free)['cx'] + 2.0, self::coverage($pushed)['cx'], 'a bender on the left pushes the blades right');
        self::assertLessThan(self::coverage($free, 0, 40)['count'], self::coverage($pushed, 0, 40)['count'], 'and lays them down');
    }
}
