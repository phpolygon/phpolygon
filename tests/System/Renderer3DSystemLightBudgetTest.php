<?php

declare(strict_types=1);

namespace PHPolygon\Tests\System;

use PHPUnit\Framework\TestCase;
use PHPolygon\Component\PointLight;
use PHPolygon\Component\RectAreaLight;
use PHPolygon\Component\SpotLight;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\World;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Command\AddPointLight;
use PHPolygon\Rendering\Command\AddRectLight;
use PHPolygon\Rendering\Command\AddSpotLight;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\NullRenderer3D;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\System\Renderer3DSystem;

/**
 * The per-kind local-light budget of Renderer3DSystem (GraphicsSettings::$pointLightBudget and
 * its siblings, pushed in through setLightBudget()): the nearest lights win, a light that is in
 * keeps its place at the border, and 0 leaves the system's own limits alone.
 */
final class Renderer3DSystemLightBudgetTest extends TestCase
{
    private NullRenderer3D $spy;
    private RenderCommandList $list;
    private Renderer3DSystem $system;

    protected function setUp(): void
    {
        $this->spy = new class extends NullRenderer3D {
            /** @var list<object> */
            public array $commands = [];

            public function render(RenderCommandList $commandList): void
            {
                $this->commands = $commandList->getCommands();
            }
        };
        $this->list = new RenderCommandList();
        $this->system = new Renderer3DSystem($this->spy, $this->list);
    }

    private static function camera(float $x = 0.0, float $z = 0.0): SetCamera
    {
        return new SetCamera(
            Mat4::lookAt(new Vec3($x, 0.0, $z), new Vec3($x, 0.0, $z - 1.0), new Vec3(0.0, 1.0, 0.0)),
            Mat4::perspective(deg2rad(60.0), 1.0, 0.1, 500.0),
        );
    }

    private function frame(World $world, ?SetCamera $camera = null): void
    {
        $this->list->add($camera ?? self::camera());
        $this->system->render($world);
    }

    /** @return list<float> the z of every emitted light of a class, in emission order */
    private function zs(string $class): array
    {
        $out = [];
        foreach ($this->spy->commands as $cmd) {
            if ($cmd instanceof $class) {
                $out[] = $cmd instanceof AddRectLight ? $cmd->center->z : $cmd->position->z;
            }
        }
        return $out;
    }

    /** $n lights of every kind on a line down -Z, 3 m apart, the first 5 m ahead. */
    private function lineOfLights(int $n): World
    {
        $world = new World();
        for ($i = 0; $i < $n; $i++) {
            $z = -5.0 - 3.0 * $i;
            $world->createEntity()->attach(new Transform3D(new Vec3(0.0, 0.0, $z)))->attach(new PointLight(intensity: 1.0, radius: 1.0));
            $world->createEntity()->attach(new Transform3D(new Vec3(1.0, 0.0, $z)))->attach(new SpotLight(intensity: 1.0, range: 1.0));
            $world->createEntity()->attach(new Transform3D(new Vec3(2.0, 0.0, $z)))->attach(new RectAreaLight(intensity: 1.0, range: 1.0));
        }
        return $world;
    }

    public function testWithoutABudgetTheSystemLimitsApply(): void
    {
        $this->frame($this->lineOfLights(12));

        self::assertCount(Renderer3DSystem::MAX_POINT_LIGHTS, $this->zs(AddPointLight::class));
        self::assertCount(Renderer3DSystem::MAX_SPOT_LIGHTS, $this->zs(AddSpotLight::class));
        self::assertCount(Renderer3DSystem::MAX_RECT_LIGHTS, $this->zs(AddRectLight::class));
    }

    public function testABudgetKeepsTheNearestLightsOfItsKind(): void
    {
        $this->system->setLightBudget(3, 2, 1);
        $this->frame($this->lineOfLights(12));

        self::assertSame([-5.0, -8.0, -11.0], $this->sorted($this->zs(AddPointLight::class)));
        self::assertSame([-5.0, -8.0], $this->sorted($this->zs(AddSpotLight::class)));
        self::assertSame([-5.0], $this->sorted($this->zs(AddRectLight::class)));
    }

    public function testABudgetOfOneKindLeavesTheOthersAlone(): void
    {
        $this->system->setLightBudget(2, 0, 0);
        $this->frame($this->lineOfLights(12));

        self::assertCount(2, $this->zs(AddPointLight::class));
        self::assertCount(Renderer3DSystem::MAX_SPOT_LIGHTS, $this->zs(AddSpotLight::class));
        self::assertCount(Renderer3DSystem::MAX_RECT_LIGHTS, $this->zs(AddRectLight::class));
    }

    public function testABudgetAboveTheLimitChangesNothing(): void
    {
        $this->system->setLightBudget(100, 100, 100);
        $this->frame($this->lineOfLights(12));

        self::assertCount(Renderer3DSystem::MAX_POINT_LIGHTS, $this->zs(AddPointLight::class));
        self::assertCount(Renderer3DSystem::MAX_SPOT_LIGHTS, $this->zs(AddSpotLight::class));
        self::assertCount(Renderer3DSystem::MAX_RECT_LIGHTS, $this->zs(AddRectLight::class));
    }

    public function testFewerLightsThanTheBudgetAllBurn(): void
    {
        $this->system->setLightBudget(8, 4, 4);
        $this->frame($this->lineOfLights(2));

        self::assertCount(2, $this->zs(AddPointLight::class));
        self::assertCount(2, $this->zs(AddSpotLight::class));
        self::assertCount(2, $this->zs(AddRectLight::class));
    }

    /** The path the Engine takes: the persisted settings reach the system, a lifted budget is lifted. */
    public function testTheBudgetComesFromTheGraphicsSettings(): void
    {
        $settings = GraphicsSettings::fromJson(['pointLightBudget' => 4, 'spotLightBudget' => 2, 'rectLightBudget' => 1]);
        $this->system->applyGraphicsSettings($settings);
        $this->frame($this->lineOfLights(12));
        self::assertCount(4, $this->zs(AddPointLight::class));
        self::assertCount(2, $this->zs(AddSpotLight::class));
        self::assertCount(1, $this->zs(AddRectLight::class));

        $this->system->applyGraphicsSettings(new GraphicsSettings());
        $this->frame($this->lineOfLights(12));
        self::assertCount(Renderer3DSystem::MAX_POINT_LIGHTS, $this->zs(AddPointLight::class));
    }

    public function testTheBudgetCanBeLiftedAgain(): void
    {
        $this->system->setLightBudget(1, 1, 1);
        $this->system->setLightBudget(0, 0, 0);
        $this->frame($this->lineOfLights(12));

        self::assertCount(Renderer3DSystem::MAX_POINT_LIGHTS, $this->zs(AddPointLight::class));
    }

    /** Two lamps almost the same distance from a camera swaying between them keep the lamp that is in. */
    public function testALightAtTheBorderDoesNotFlicker(): void
    {
        $world = new World();
        $world->createEntity()->attach(new Transform3D(new Vec3(-3.0, 0.0, -20.0)))->attach(new PointLight(intensity: 1.0, radius: 1.0));
        $world->createEntity()->attach(new Transform3D(new Vec3(3.0, 0.0, -20.0)))->attach(new PointLight(intensity: 1.0, radius: 1.0));
        $this->system->setLightBudget(1, 0, 0);

        $kept = [];
        foreach ([-0.5, 0.5, -0.5, 0.5, -0.5, 0.5] as $x) {
            $this->frame($world, self::camera($x));
            $lights = [];
            foreach ($this->spy->commands as $cmd) {
                if ($cmd instanceof AddPointLight) {
                    $lights[] = $cmd->position->x;
                }
            }
            self::assertCount(1, $lights);
            $kept[] = $lights[0];
        }
        self::assertCount(1, array_unique($kept), 'the camera sways by a metre, the lit lamp stays');
    }

    /**
     * @param list<float> $values
     * @return list<float>
     */
    private function sorted(array $values): array
    {
        rsort($values);
        return $values;
    }
}
