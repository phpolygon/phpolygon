<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Prefab;

use PHPUnit\Framework\TestCase;
use PHPolygon\Component\BoxCollider3D;
use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\PalmSway;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Vec3;
use PHPolygon\Prefab\PalmTree;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Scene\SceneBuilder;

final class PalmTreeTest extends TestCase
{
    protected function setUp(): void
    {
        MeshRegistry::clear();
        MaterialRegistry::clear();
    }

    protected function tearDown(): void
    {
        MeshRegistry::clear();
        MaterialRegistry::clear();
    }

    public function testBuildsAFewEntitiesInsteadOfSeventy(): void
    {
        $b = new SceneBuilder();
        (new PalmTree('Palm_A', new Vec3(3.0, 0.0, 4.0), 6.5, 0.12, 0, 10))->build($b);
        (new PalmTree('Palm_B', new Vec3(9.0, 0.0, 4.0), 5.0, 0.2, 1, 10))->build($b);
        $decls = $b->getDeclarations();
        self::assertLessThanOrEqual(8, count($decls), 'two palms, at most four entities each');

        $names = array_map(static fn ($d): string => $d->getName(), $decls);
        self::assertContains('Palm_A_Trunk', $names);
        self::assertContains('Palm_A_Fronds', $names);
        self::assertContains('Palm_A_Coconuts', $names, 'every third palm carries coconuts');
        self::assertContains('Palm_A_Collider', $names);
        self::assertNotContains('Palm_B_Coconuts', $names);

        $colliders = 0;
        foreach ($decls as $d) {
            foreach ($d->getComponents() as $c) {
                self::assertNotInstanceOf(PalmSway::class, $c, 'sway now comes from the material');
                if ($c instanceof MeshRenderer) {
                    self::assertNotNull(MeshRegistry::get($c->meshId), "{$c->meshId} registered");
                }
                if ($c instanceof BoxCollider3D) {
                    $colliders++;
                    self::assertLessThan(0.9, $c->size->x, 'slim trunk collider');
                }
            }
        }
        self::assertSame(2, $colliders);
    }

    public function testFrondsAreAlwaysTwoSided(): void
    {
        // A game that registered a plain one-sided frond material keeps its colour
        // but the fronds are drawn through a two-sided copy.
        MaterialRegistry::register('palm_leaves', new Material(albedo: Color::srgb('#3F8A3A')));
        $b = new SceneBuilder();
        (new PalmTree('P', new Vec3(0.0, 0.0, 0.0), 6.0, 0.1, 0))->build($b);
        $frondMaterial = null;
        foreach ($b->getDeclarations() as $d) {
            if ($d->getName() === 'P_Fronds') {
                foreach ($d->getComponents() as $c) {
                    if ($c instanceof MeshRenderer) {
                        $frondMaterial = $c->materialId;
                    }
                }
            }
        }
        self::assertSame('palm_leaves_foliage', $frondMaterial);
        $m = MaterialRegistry::get('palm_leaves_foliage');
        self::assertNotNull($m);
        self::assertTrue($m->twoSided);
        self::assertTrue($m->foliageWind);
        self::assertEquals(Color::srgb('#3F8A3A'), $m->albedo);
        $trunk = MaterialRegistry::get('palm_trunk');
        self::assertNotNull($trunk, 'missing trunk material gets a default');
        self::assertTrue($trunk->foliageWind);
    }

    public function testSameParametersShareMeshes(): void
    {
        $b = new SceneBuilder();
        (new PalmTree('A', new Vec3(0.0, 0.0, 0.0), 6.0, 0.1, 1))->build($b);
        $count = count(MeshRegistry::ids());
        (new PalmTree('B', new Vec3(5.0, 0.0, 0.0), 6.0, 0.1, 5))->build($b);
        self::assertSame($count, count(MeshRegistry::ids()), 'index 5 reuses the meshes of index 1');
    }
}
