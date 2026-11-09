<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use PHPolygon\Component\LightCone;
use PHPolygon\Component\SpotLight;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\World;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Math\Vec4;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\AddSpotLight;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\LightCones;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ScreenSpaceAO;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioRenderer3D;
use PHPolygon\System\LightConeSystem;

/**
 * Visible spot-light beams (LightCone + LightConeSystem + DrawLightCone) on the GPU:
 * a lamp hangs over a floor and shines down, the camera looks at it from above and in
 * front. The beam lights the air, fades into the floor where it meets it (a hard cut
 * only on the depth-tested path), follows the light's intensity, and leaves the frame
 * byte for byte as it was while the light is off or the effect is switched off.
 * (VioLightConeTest is the air-wide scattering of HeightFog::$lightHalo.)
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioLightConeMeshTest extends TestCase
{
    private const W = 160;
    private const H = 120;
    private const APEX_Y = 3.2;
    private const ANGLE = 0.4;

    private ?\VioContext $ctx = null;
    private ?VioRenderer3D $renderer = null;

    protected function setUp(): void
    {
        MeshRegistry::clear();
        MaterialRegistry::clear();
        MeshRegistry::register('floor', BoxMesh::generate(40.0, 0.2, 40.0));
        MaterialRegistry::register('floor', new Material(albedo: new Color(0.25, 0.25, 0.25), roughness: 0.9, useEnvironmentMap: false));
        $this->ctx = VioTestContext::create(self::W, self::H, ['shader_cache' => sys_get_temp_dir() . '/phpolygon-test-shader-cache']);
        if ($this->ctx === null) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        $this->renderer = new VioRenderer3D($this->ctx, self::W, self::H);
        $this->renderer->applySettings(self::settings());
    }

    protected function tearDown(): void
    {
        putenv('PHPOLYGON_LIGHT_CONE_DEPTH');
        if ($this->ctx !== null) {
            vio_destroy($this->ctx);
        }
        $this->ctx = null;
        $this->renderer = null;
        MaterialRegistry::clear();
        MeshRegistry::clear();
    }

    public function testTheBeamLightsTheAirAndFadesIntoTheFloor(): void
    {
        $off = $this->shot(3.0, false);
        $on = $this->shot(3.0, true);
        $stats = $this->renderer()->lightConeStats();
        self::assertSame(1, $stats['drawn']);
        if (!$stats['sceneDepth']) {
            $this->markTestSkipped('the frame has no readable scene depth: the beam is cut by the depth test');
        }

        [$mx, $my] = $this->project(new Vec3(0.0, 1.8, 0.0));
        $mid = $this->added($on, $off, $mx, $my, 2);
        // Where the beam's near rim meets the floor, on the column through the axis.
        [$cx, $cy] = $this->project(new Vec3(0.0, 0.0, self::APEX_Y * tan(self::ANGLE)));
        $contact = $this->added($on, $off, $cx, $cy - 1, 0);
        $inFront = $this->added($on, $off, $cx, $cy + 3, 1);
        // Far from the beam: nothing added.
        $aside = $this->added($on, $off, 8, 8, 4);
        fprintf(STDERR, "[light cone] mid-air +%.1f, at the floor contact +%.1f, floor in front +%.1f, aside +%.1f\n", $mid, $contact, $inFront, $aside);

        self::assertGreaterThan(15.0, $mid, 'the beam is visible in the air');
        self::assertLessThan($mid * 0.3, $contact, 'it fades out where it meets the floor');
        self::assertLessThan(1.5, $inFront, 'the floor in front of the beam hides it');
        self::assertLessThan(0.5, $aside, 'nothing is added outside the beam');
    }

    public function testWithoutTheSceneDepthTheFloorCutsTheBeamHard(): void
    {
        $off = $this->shot(3.0, false);
        $soft = $this->shot(3.0, true);
        if (!$this->renderer()->lightConeStats()['sceneDepth']) {
            $this->markTestSkipped('the frame has no readable scene depth: nothing to compare against');
        }
        putenv('PHPOLYGON_LIGHT_CONE_DEPTH=0');
        $hard = $this->shot(3.0, true);
        self::assertFalse($this->renderer()->lightConeStats()['sceneDepth']);

        [$mx, $my] = $this->project(new Vec3(0.0, 1.8, 0.0));
        [$cx, $cy] = $this->project(new Vec3(0.0, 0.0, self::APEX_Y * tan(self::ANGLE)));
        $hardMid = $this->added($hard, $off, $mx, $my, 2);
        $hardContact = $this->added($hard, $off, $cx, $cy - 1, 0);
        $softContact = $this->added($soft, $off, $cx, $cy - 1, 0);
        fprintf(STDERR, "[light cone] depth-tested: mid-air +%.1f, at the floor contact +%.1f (soft path +%.1f)\n", $hardMid, $hardContact, $softContact);

        self::assertGreaterThan(15.0, $hardMid, 'the beam stays visible without the scene depth');
        self::assertGreaterThan($softContact + 5.0, $hardContact, 'without the depth the beam does not fade at the floor');
    }

    public function testTheBeamFollowsItsLightAndIsGoneWhenTheLightIsOff(): void
    {
        [$mx, $my] = $this->project(new Vec3(0.0, 1.8, 0.0));
        $full = $this->added($this->shot(3.0, true), $this->shot(3.0, false), $mx, $my, 2);
        $dim = $this->added($this->shot(1.0, true), $this->shot(1.0, false), $mx, $my, 2);
        fprintf(STDERR, "[light cone] intensity 3: +%.1f, intensity 1: +%.1f\n", $full, $dim);
        self::assertGreaterThan(2.0, $dim);
        self::assertLessThan($full * 0.8, $dim, 'a dimmer light gives a dimmer beam');

        // Light off: the system emits no beam, the frame is the one without a cone.
        $withCone = $this->shot(0.0, true);
        self::assertSame(0, $this->renderer()->lightConeStats()['drawn']);
        self::assertSame($this->shot(0.0, false), $withCone);
    }

    public function testTheEffectSwitchLeavesTheFrameAsWithoutIt(): void
    {
        $off = $this->shot(3.0, false);
        $this->renderer()->applySettings(self::settings()->with(effects: [LightCones::EFFECT => false]));
        $switched = $this->shot(3.0, true);
        self::assertSame(0, $this->renderer()->lightConeStats()['drawn']);
        self::assertSame($off, $switched);
    }

    public function testTheMrtPathAddsTheSameBeam(): void
    {
        [$mx, $my] = $this->project(new Vec3(0.0, 1.8, 0.0));
        [$cx, $cy] = $this->project(new Vec3(0.0, 0.0, self::APEX_Y * tan(self::ANGLE)));
        $result = [];
        foreach (['0', '1'] as $mrt) {
            putenv("PHPOLYGON_VIO_MRT={$mrt}");
            try {
                self::assertNotNull($this->ctx);
                // SSAO needs the G-buffer: with PHPOLYGON_VIO_MRT=1 the frame takes the MRT path.
                $this->renderer = new VioRenderer3D($this->ctx, self::W, self::H);
                $this->renderer->applySettings(self::settings()->with(ambientOcclusion: ScreenSpaceAO::Medium));
                $off = $this->shot(3.0, false);
                $on = $this->shot(3.0, true);
                $result[$mrt] = [$this->renderer->mrtActive(), $this->added($on, $off, $mx, $my, 2), $this->added($on, $off, $cx, $cy - 1, 0)];
            } finally {
                putenv('PHPOLYGON_VIO_MRT');
            }
        }
        fprintf(STDERR, "[light cone] forward mid-air +%.1f contact +%.1f, MRT mid-air +%.1f contact +%.1f\n", $result['0'][1], $result['0'][2], $result['1'][1], $result['1'][2]);
        self::assertFalse($result['0'][0]);
        if (!$result['1'][0]) {
            $this->markTestSkipped('this backend has no MRT path');
        }
        self::assertGreaterThan(15.0, $result['1'][1], 'the beam shows on the MRT path');
        // The forward frame here has an LDR target: the beam is added after the tonemap,
        // a little brighter over the dark floor than the linear add the composite tonemaps.
        self::assertEqualsWithDelta($result['1'][1], $result['0'][1], $result['1'][1] * 0.2, 'forward and MRT add about the same light');
        self::assertLessThan($result['0'][1] * 0.1, $result['0'][2], 'the forward beam fades at the floor too');
        self::assertLessThan($result['1'][1] * 0.3, $result['1'][2], 'and fade at the floor alike');
    }

    public function testTheBeamShowsFromInsideIt(): void
    {
        // Camera inside the cone, below the lamp, looking up the beam at an angle.
        $eye = new Vec3(0.2, 1.0, 0.1);
        $target = new Vec3(0.0, 3.0, -2.5);
        $off = $this->shot(3.0, false, $eye, $target);
        $on = $this->shot(3.0, true, $eye, $target);
        if (!$this->renderer()->lightConeStats()['sceneDepth']) {
            $this->markTestSkipped('the frame has no readable scene depth');
        }
        $centre = $this->added($on, $off, intdiv(self::W, 2), intdiv(self::H, 2), 6);
        fprintf(STDERR, "[light cone] from inside the beam: +%.1f\n", $centre);
        self::assertGreaterThan(5.0, $centre);
    }

    private static function settings(): GraphicsSettings
    {
        return new GraphicsSettings(shadowQuality: ShadowQuality::Off, antiAliasing: AntiAliasing::Off, bloom: false);
    }

    /** One frame (the second: the first compiles and fills the depth copy) as RGBA bytes. */
    private function shot(float $intensity, bool $cone, ?Vec3 $eye = null, ?Vec3 $target = null): string
    {
        $eye ??= new Vec3(0.0, 4.5, 7.0);
        $target ??= new Vec3(0.0, 0.8, 0.0);
        $this->renderer()->renderToImage($this->scene($intensity, $cone, $eye, $target), self::W, self::H, new Color(0.0, 0.0, 0.0));
        return $this->renderer()->renderToImage($this->scene($intensity, $cone, $eye, $target), self::W, self::H, new Color(0.0, 0.0, 0.0));
    }

    private function scene(float $intensity, bool $cone, Vec3 $eye, Vec3 $target): RenderCommandList
    {
        $camera = new SetCamera(Mat4::lookAt($eye, $target, new Vec3(0.0, 1.0, 0.0)), $this->projection());
        $world = new World();
        $spot = new SpotLight(new Vec3(0.0, -1.0, 0.0), new Color(1.0, 0.85, 0.6), $intensity, 6.0, self::ANGLE, 0.2);
        $entity = $world->createEntity()
            ->attach(new Transform3D(position: new Vec3(0.0, self::APEX_Y, 0.0)))
            ->attach($spot);
        if ($cone) {
            $entity->attach(new LightCone(density: 0.25, edgeSoftness: 0.3));
        }
        $list = new RenderCommandList();
        $list->add($camera);
        $list->add(new SetAmbientLight(new Color(0.4, 0.45, 0.6), 0.05));
        if ($intensity > 0.0) {
            $list->add(new AddSpotLight(new Vec3(0.0, self::APEX_Y, 0.0), $spot->direction, $spot->color, $intensity, $spot->range, $spot->angle, $spot->penumbra));
        }
        $list->add(new DrawMesh('floor', 'floor', Mat4::translation(0.0, -0.1, 0.0)));
        foreach (LightConeSystem::cones($world, $camera) as $command) {
            $list->add($command);
        }
        return $list;
    }

    private function projection(): Mat4
    {
        return Mat4::perspective(deg2rad(60.0), self::W / self::H, 0.1, 100.0);
    }

    /**
     * Pixel of a world point in the default camera's image (row 0 at the top).
     *
     * @return array{0: int, 1: int}
     */
    private function project(Vec3 $p): array
    {
        $view = Mat4::lookAt(new Vec3(0.0, 4.5, 7.0), new Vec3(0.0, 0.8, 0.0), new Vec3(0.0, 1.0, 0.0));
        $clip = $this->projection()->multiply($view)->multiplyVec4(new Vec4($p->x, $p->y, $p->z, 1.0));
        $x = ($clip->x / $clip->w * 0.5 + 0.5) * self::W;
        $y = (0.5 - $clip->y / $clip->w * 0.5) * self::H;
        return [(int) floor($x), (int) floor($y)];
    }

    /** Mean RGB of $on minus that of $off over the square of half size $r around ($x, $y). */
    private function added(string $on, string $off, int $x, int $y, int $r): float
    {
        return $this->mean($on, $x, $y, $r) - $this->mean($off, $x, $y, $r);
    }

    private function mean(string $img, int $x, int $y, int $r): float
    {
        $s = 0;
        $n = 0;
        for ($yy = max(0, $y - $r); $yy <= min(self::H - 1, $y + $r); $yy++) {
            for ($xx = max(0, $x - $r); $xx <= min(self::W - 1, $x + $r); $xx++) {
                $o = ($yy * self::W + $xx) * 4;
                $s += ord($img[$o]) + ord($img[$o + 1]) + ord($img[$o + 2]);
                $n += 3;
            }
        }
        return $s / max(1, $n);
    }

    private function renderer(): VioRenderer3D
    {
        self::assertNotNull($this->renderer);
        return $this->renderer;
    }
}
