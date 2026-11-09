<?php

declare(strict_types=1);

namespace PHPolygon\Tests\System;

use PHPUnit\Framework\TestCase;
use PHPolygon\Component\HeatSource;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\World;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetSky;
use PHPolygon\Rendering\PostProcess\FullscreenPassRegistry;
use PHPolygon\Rendering\PostProcess\HeatHaze;
use PHPolygon\Rendering\PostProcess\SunShafts;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\System\HeatHazeSystem;
use PHPolygon\System\SunShaftsSystem;

/**
 * A fullscreen pass with nothing to draw costs a whole-screen pass anyway: the
 * systems that feed sun shafts and heat haze switch their pass off while it would
 * draw nothing, and on again as soon as it would.
 */
final class IdlePostPassTest extends TestCase
{
    protected function tearDown(): void
    {
        FullscreenPassRegistry::setActive(SunShafts::NAME, true);
        FullscreenPassRegistry::setActive(HeatHaze::NAME, true);
    }

    private static function camera(Vec3 $forward): SetCamera
    {
        return new SetCamera(
            Mat4::lookAt(new Vec3(0.0, 0.0, 0.0), $forward, new Vec3(0.0, 1.0, 0.0)),
            Mat4::perspective(1.0, 1.0, 0.1, 100.0),
        );
    }

    public function testHeatHazeRunsOnlyWithASourceInReach(): void
    {
        $world = new World();
        $list = new RenderCommandList();
        $list->add(self::camera(new Vec3(0.0, 0.0, -1.0)));
        $system = new HeatHazeSystem($list);

        $system->render($world);
        self::assertFalse(FullscreenPassRegistry::isActive(HeatHaze::NAME), 'no source: the pass does not run');

        $world->createEntity()
            ->attach(new Transform3D(position: new Vec3(0.0, 0.0, -10.0)))
            ->attach(new HeatSource());
        $system->render($world);
        self::assertTrue(FullscreenPassRegistry::isActive(HeatHaze::NAME), 'a source ahead: the pass runs');
    }

    public function testSunShaftsRunOnlyWithTheSunOnScreen(): void
    {
        $sky = new SetSky(new Vec3(0.0, 0.4, -1.0), new Color(1.0, 0.9, 0.8), 1.0, new Color(0.3, 0.5, 0.9), new Color(0.7, 0.8, 0.9), new Color(0.3, 0.3, 0.3));

        $behind = new RenderCommandList();
        $behind->add($sky);
        $behind->add(self::camera(new Vec3(0.0, 0.0, 1.0)));
        (new SunShaftsSystem($behind))->render(new World());
        self::assertFalse(FullscreenPassRegistry::isActive(SunShafts::NAME), 'looking away from the sun: the pass does not run');

        $ahead = new RenderCommandList();
        $ahead->add($sky);
        $ahead->add(self::camera(new Vec3(0.0, 0.4, -1.0)));
        (new SunShaftsSystem($ahead))->render(new World());
        self::assertTrue(FullscreenPassRegistry::isActive(SunShafts::NAME), 'the sun ahead: the pass runs');
    }
}
