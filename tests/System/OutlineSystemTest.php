<?php

declare(strict_types=1);

namespace PHPolygon\Tests\System;

use PHPUnit\Framework\TestCase;
use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\Outline;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\World;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\DrawOutline;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\System\OutlineSystem;

final class OutlineSystemTest extends TestCase
{
    /** @return list<DrawOutline> */
    private static function outlines(World $world): array
    {
        $list = new RenderCommandList();
        (new OutlineSystem($list))->render($world);
        return $list->ofType(DrawOutline::class);
    }

    public function testOutlinedMeshEmitsOneCommandWithItsWorldMatrix(): void
    {
        $world = new World();
        $transform = new Transform3D(new Vec3(3.0, 0.0, -2.0));
        $world->createEntity()
            ->attach($transform)
            ->attach(new MeshRenderer('box', 'm'))
            ->attach(new Outline(color: new Color(0.2, 0.9, 1.0), widthPx: 4.0));
        $world->createEntity()
            ->attach(new Transform3D())
            ->attach(new MeshRenderer('other', 'm'));

        $draws = self::outlines($world);

        self::assertCount(1, $draws, 'only the outlined entity');
        self::assertSame('box', $draws[0]->meshId);
        self::assertSame($transform->getWorldMatrix(), $draws[0]->modelMatrix);
        self::assertSame(4.0, $draws[0]->widthPx);
        self::assertEqualsWithDelta(0.9, $draws[0]->color->g, 1e-6);
    }

    public function testChildrenAreOutlinedUnlessDisabled(): void
    {
        $world = new World();
        $root = $world->createEntity();
        $rootTransform = new Transform3D();
        $root->attach($rootTransform);
        $outline = new Outline();
        $root->attach($outline);

        foreach (['left', 'right'] as $i => $meshId) {
            $child = $world->createEntity();
            $childTransform = new Transform3D(new Vec3((float) $i, 0.0, 0.0));
            $child->attach($childTransform);
            $child->attach(new MeshRenderer($meshId, 'm'));
            $rootTransform->addChild($childTransform, $child->id, $root->id);
        }

        $meshes = array_map(static fn (DrawOutline $d): string => $d->meshId, self::outlines($world));
        sort($meshes);
        self::assertSame(['left', 'right'], $meshes, 'a root without a mesh still outlines its parts');

        $outline->includeChildren = false;
        self::assertSame([], self::outlines($world));
    }

    public function testDisabledOutlineAndHiddenMeshesEmitNothing(): void
    {
        $world = new World();
        $outline = new Outline();
        $world->createEntity()
            ->attach(new Transform3D())
            ->attach(new MeshRenderer('box', 'm'))
            ->attach($outline);
        $world->createEntity()
            ->attach(new Transform3D())
            ->attach(new MeshRenderer('hidden', 'm', visible: false))
            ->attach(new Outline());

        $outline->enabled = false;

        self::assertSame([], self::outlines($world));
    }
}
