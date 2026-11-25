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
use PHPolygon\Rendering\Command\AddPointLight;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ScreenSpaceAO;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * Several shadowed point lights (GraphicsSettings::$pointShadowLights): two lights far
 * apart, each with a slab between it and the floor; both slabs must shadow the floor
 * through their own cube of the depth array, the light a third caster had no budget for
 * stays unshadowed, and an unchanged cube is not drawn again.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
#[Group('gpu')]
final class VioMultiPointShadowTest extends TestCase
{
    private const SIZE = 96;
    private const HALF = 10.0;

    private ?\VioContext $ctx = null;
    private VioRenderer3D $renderer;

    protected function setUp(): void
    {
        $ctx = VioTestContext::create(self::SIZE, self::SIZE, ['shader_cache' => sys_get_temp_dir() . '/phpolygon-test-shader-cache']);
        if ($ctx === null) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        if (!vio_supports_feature($ctx, \VIO_FEATURE_RENDER_TARGET_LAYERED)) {
            vio_destroy($ctx);
            $this->markTestSkipped('no layered depth render targets');
        }
        $this->ctx = $ctx;
        MeshRegistry::clear();
        MaterialRegistry::clear();
        MeshRegistry::register('floor', BoxMesh::generate(40.0, 0.1, 40.0));
        MeshRegistry::register('slab', BoxMesh::generate(1.0, 0.2, 1.0));
        MaterialRegistry::register('white', new Material(albedo: new Color(1.0, 1.0, 1.0), roughness: 1.0, useEnvironmentMap: false));
        $this->renderer = new VioRenderer3D($ctx, self::SIZE, self::SIZE);
        $this->renderer->applySettings($this->settings(2));
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

    private function settings(int $lights): GraphicsSettings
    {
        return new GraphicsSettings(
            shadowQuality: ShadowQuality::High,
            ambientOcclusion: ScreenSpaceAO::Off,
            antiAliasing: AntiAliasing::Off,
            bloom: false,
            pointShadowLights: $lights,
        );
    }

    /**
     * Light A at (-5, 4, -5) with its slab, light B at (6, 4, 6) with its slab; the floor
     * spot in each slab's shadow and one lit spot beside it.
     *
     * @return array{0: RenderCommandList, 1: DrawMesh, 2: DrawMesh}
     */
    private function scene(bool $castsA, bool $castsB, int $priorityA = 0, int $priorityB = 0): array
    {
        $list = new RenderCommandList();
        $list->add(new SetCamera(
            Mat4::lookAt(new Vec3(0.0, 20.0, 0.0), new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 0.0, -1.0)),
            Mat4::orthographic(-self::HALF, self::HALF, -self::HALF, self::HALF, 1.0, 50.0),
        ));
        $list->add(new SetAmbientLight(new Color(0.0, 0.0, 0.0), 0.0));
        $list->add(new AddPointLight(new Vec3(-5.0, 4.0, -5.0), Color::white(), 4.0, 12.0, $castsA, $priorityA));
        $list->add(new AddPointLight(new Vec3(6.0, 4.0, 6.0), Color::white(), 4.0, 12.0, $castsB, $priorityB));
        $list->add(new DrawMesh('floor', 'white', Mat4::translation(0.0, -0.05, 0.0)));
        $slabA = new DrawMesh('slab', 'white', Mat4::translation(-6.0, 2.5, -5.0));
        $slabB = new DrawMesh('slab', 'white', Mat4::translation(5.0, 2.5, 6.0));
        $list->add($slabA);
        $list->add($slabB);

        return [$list, $slabA, $slabB];
    }

    private function red(string $image, float $x, float $z): int
    {
        $col = (int) (($x + self::HALF) / (2.0 * self::HALF) * self::SIZE);
        $row = (int) (($z + self::HALF) / (2.0 * self::HALF) * self::SIZE);

        return ord($image[($row * self::SIZE + $col) * 4]);
    }

    // shadow spots, lit spots
    private const SHADOW_A = [-7.67, -5.0];
    private const LIT_A = [-2.0, -5.0];
    private const SHADOW_B = [3.33, 6.0];
    private const LIT_B = [8.0, 6.0];

    public function testBothLightsShadowThroughTheirOwnCube(): void
    {
        [$shadowed] = $this->scene(true, true);
        $image = $this->renderer->renderToImage($shadowed, self::SIZE, self::SIZE);
        self::assertSame(2, $this->renderer->pointShadowStats()['lights']);
        [$plainList] = $this->scene(false, false);
        $plain = $this->renderer->renderToImage($plainList, self::SIZE, self::SIZE);

        foreach (['A' => [self::SHADOW_A, self::LIT_A], 'B' => [self::SHADOW_B, self::LIT_B]] as $name => [$shadow, $lit]) {
            $lightAt = $this->red($plain, ...$shadow);
            $shadowAt = $this->red($image, ...$shadow);
            self::assertGreaterThan(30, $lightAt, "light {$name} reaches the floor ({$lightAt})");
            self::assertLessThan($lightAt * 0.5, $shadowAt, "slab {$name} shadows the floor ({$shadowAt} vs {$lightAt})");
            self::assertEqualsWithDelta($this->red($plain, ...$lit), $this->red($image, ...$lit), 12, "outside the shadow of {$name} the light is unchanged");
        }
    }

    public function testTheBudgetLeavesTheLowerPriorityLightUnshadowed(): void
    {
        $this->renderer->applySettings($this->settings(2));
        // three casters would need three cubes: with a budget of 2 only A and B (higher priority) get one
        [$list] = $this->scene(true, true, priorityA: 1, priorityB: 1);
        $list->add(new AddPointLight(new Vec3(0.0, 4.0, 0.0), Color::white(), 4.0, 3.0, true, 0));
        $this->renderer->renderToImage($list, self::SIZE, self::SIZE);
        self::assertSame(2, $this->renderer->pointShadowStats()['lights']);
    }

    /**
     * The graphics-menu path: the budget changes between frames on one renderer (cube
     * program -> array program -> cube program again) without the device or the picture breaking.
     */
    public function testSwitchingTheBudgetBetweenFramesOnOneRenderer(): void
    {
        $renderer = new VioRenderer3D($this->ctx, self::SIZE, self::SIZE);
        $renderer->applySettings($this->settings(1));
        [$plainList] = $this->scene(false, false);
        $plain = $renderer->renderToImage($plainList, self::SIZE, self::SIZE);

        // budget 1: one light casts (the nearest sphere wins, A and B are far apart, priority picks A)
        [$one] = $this->scene(true, true, priorityA: 5);
        $cube = $renderer->renderToImage($one, self::SIZE, self::SIZE);
        self::assertLessThan($this->red($plain, ...self::SHADOW_A) * 0.5, $this->red($cube, ...self::SHADOW_A), 'priority picks A for the single cube');
        self::assertEqualsWithDelta($this->red($plain, ...self::SHADOW_B), $this->red($cube, ...self::SHADOW_B), 12, 'B has no cube');

        // budget 2: both
        $renderer->applySettings($this->settings(2));
        [$two] = $this->scene(true, true);
        $array = $renderer->renderToImage($two, self::SIZE, self::SIZE);
        self::assertSame(2, $renderer->pointShadowStats()['lights']);
        self::assertLessThan($this->red($plain, ...self::SHADOW_A) * 0.5, $this->red($array, ...self::SHADOW_A));
        self::assertLessThan($this->red($plain, ...self::SHADOW_B) * 0.5, $this->red($array, ...self::SHADOW_B));

        // and back to one
        $renderer->applySettings($this->settings(1));
        [$back] = $this->scene(true, true, priorityA: 5);
        $again = $renderer->renderToImage($back, self::SIZE, self::SIZE);
        self::assertSame($cube, $again, 'the single cube renders the same after the round trip');
    }

    public function testAnUnchangedCubeIsNotDrawnAgain(): void
    {
        [$list, $slabA] = $this->scene(true, true);
        $first = $this->renderer->renderToImage($list, self::SIZE, self::SIZE);
        self::assertSame(2, $this->renderer->pointShadowStats()['rendered']);

        $again = $this->renderer->renderToImage($list, self::SIZE, self::SIZE);
        self::assertSame(['lights' => 2, 'rendered' => 0, 'reused' => 2], $this->renderer->pointShadowStats());
        self::assertSame($first, $again, 'a reused cube shades the same image');

        // slab A moves: only its light's cube is drawn again, B's cube is kept
        [$moved] = $this->scene(true, true);
        $commands = $moved->getCommands();
        $moved2 = new RenderCommandList();
        foreach ($commands as $cmd) {
            $moved2->add($cmd instanceof DrawMesh && $cmd->meshId === 'slab' && $cmd->modelMatrix->getTranslation()->x < 0
                ? new DrawMesh('slab', 'white', Mat4::translation(-4.0, 2.5, -5.0))
                : $cmd);
        }
        $this->renderer->renderToImage($moved2, self::SIZE, self::SIZE);
        $stats = $this->renderer->pointShadowStats();
        self::assertSame(2, $stats['lights']);
        self::assertSame(1, $stats['rendered'], 'only the light whose sphere holds the moved caster redraws');
        self::assertSame(1, $stats['reused']);
    }
}
