<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Vegetation;

use PHPUnit\Framework\TestCase;
use PHPolygon\Component\FoliageInstances;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;
use PHPolygon\Scene\SceneBuilder;
use PHPolygon\Component\BoxCollider3D;
use PHPolygon\Component\Transform3D;
use PHPolygon\Vegetation\FoliageColliders;
use PHPolygon\Vegetation\GrowthStage;
use PHPolygon\Vegetation\PlantForm;
use PHPolygon\Vegetation\PlantLibrary;
use PHPolygon\Vegetation\PlantLod;
use PHPolygon\Vegetation\PlantPart;
use PHPolygon\Vegetation\PlantSpec;
use PHPolygon\Vegetation\PlantVariant;

final class PlantVariantTest extends TestCase
{
    protected function setUp(): void
    {
        MeshRegistry::clear();
        PlantLibrary::clear();
    }

    protected function tearDown(): void
    {
        MeshRegistry::clear();
        PlantLibrary::clear();
    }

    private static function tree(string $id = 'v_tree'): PlantVariant
    {
        return new PlantVariant($id, PlantSpec::tree(PlantForm::Round, 9.0, 3.2, 6), 'bark', 'leaf', 'blossom', ['leaf_b', 'leaf_c'], [GrowthStage::Bloom->value => 'leaf_gold']);
    }

    public function testRegistersEveryStageLodAndPartLazily(): void
    {
        $v = PlantLibrary::register(self::tree());
        self::assertSame($v, PlantLibrary::get('v_tree'));
        self::assertSame(0, MeshRegistry::materialisedCount(), 'nothing generated at registration');
        foreach (GrowthStage::cases() as $stage) {
            foreach (PlantLod::cases() as $lod) {
                foreach (PlantPart::cases() as $part) {
                    $id = $v->meshId($stage, $lod, $part);
                    self::assertSame("v_tree_s{$stage->value}_l{$lod->value}_{$part->value}", $id);
                    self::assertTrue(MeshRegistry::has($id));
                }
            }
        }
        $mesh = MeshRegistry::get($v->meshId(GrowthStage::Full, PlantLod::Lod0, PlantPart::Leaf));
        self::assertNotNull($mesh);
        self::assertSame($v->mesh(GrowthStage::Full, PlantLod::Lod0, PlantPart::Leaf), $mesh, 'registry and variant share one generation');
    }

    public function testPartsAndMaterials(): void
    {
        $v = self::tree();
        self::assertFalse($v->hasPart(GrowthStage::Bare, PlantLod::Lod0, PlantPart::Leaf));
        self::assertTrue($v->hasPart(GrowthStage::Bare, PlantLod::Lod0, PlantPart::Wood));
        self::assertFalse($v->hasPart(GrowthStage::Full, PlantLod::Lod0, PlantPart::Accent));
        self::assertTrue($v->hasPart(GrowthStage::Bloom, PlantLod::Lod0, PlantPart::Accent));
        self::assertSame(3, $v->tintCount());
        self::assertSame('leaf', $v->leafMaterial(GrowthStage::Full, 0));
        self::assertSame('leaf_b', $v->leafMaterial(GrowthStage::Full, 1));
        self::assertSame('leaf_c', $v->leafMaterial(GrowthStage::Full, 2));
        self::assertSame('leaf_gold', $v->leafMaterial(GrowthStage::Bloom, 2), 'stage material wins over tints');
        self::assertSame('bark', $v->partMaterial(PlantPart::Wood, GrowthStage::Full));
        self::assertSame('blossom', $v->partMaterial(PlantPart::Accent, GrowthStage::Bloom));

        $noAccent = new PlantVariant('v_plain', PlantSpec::bush(1.0, 1.0, 2), 'bark', 'leaf');
        self::assertFalse($noAccent->hasPart(GrowthStage::Bloom, PlantLod::Lod0, PlantPart::Accent));
    }

    public function testTreesGetASlimTrunkColliderBushesNone(): void
    {
        $v = self::tree();
        $box = $v->collider();
        self::assertNotNull($box);
        $parts = $v->parts(GrowthStage::Full, PlantLod::Lod0);
        self::assertLessThan(1.0, $box['size']->x, 'slim: about the trunk diameter');
        self::assertEqualsWithDelta($parts->trunkRadius * 2.2, $box['size']->x, 1e-9);
        self::assertLessThanOrEqual($parts->trunkHeight + 1e-9, $box['size']->y, 'never into the crown');
        self::assertGreaterThan(2.0, $box['size']->y);
        self::assertEqualsWithDelta($box['size']->y * 0.5, $box['center']->y, 1e-9, 'stands on the ground');

        self::assertNull((new PlantVariant('b', PlantSpec::bush(1.0, 1.0), 'bark', 'leaf'))->collider(), 'bushes are walk-through');
        self::assertNull((new PlantVariant('h', PlantSpec::hedge(3.0, 1.1), 'bark', 'leaf'))->collider(), 'hedges only block when asked');
        $hedge = (new PlantVariant('h2', PlantSpec::hedge(3.0, 1.1, 0.8), 'bark', 'leaf', hedgeCollider: true))->collider();
        self::assertNotNull($hedge);
        self::assertSame(3.0, $hedge['size']->x);
        self::assertGreaterThan(1.0, $hedge['size']->y, 'full height: the player cannot step onto it');
        self::assertNull((new PlantVariant('t', PlantSpec::tree(PlantForm::Round, 9.0, 3.2), 'bark', 'leaf', trunkCollider: false))->collider());
    }

    public function testFoliageCollidersFollowTheInstances(): void
    {
        PlantLibrary::register(self::tree());
        PlantLibrary::register(new PlantVariant('v_hedge', PlantSpec::hedge(3.0, 1.1, 0.8), 'bark', 'leaf', hedgeCollider: true));
        $trees = new FoliageInstances('v_tree');
        $trees->add(new Vec3(10.0, 2.0, 5.0), 1.0, 1.5);
        $trees->add(new Vec3(-4.0, 0.0, 0.0));
        $hedges = new FoliageInstances('v_hedge');
        $hedges->add(new Vec3(0.0, 0.0, 20.0), M_PI * 0.5);
        $bushes = new FoliageInstances('missing');
        $bushes->add(new Vec3(0.0, 0.0, 0.0));

        $b = new SceneBuilder();
        self::assertSame(2, FoliageColliders::declare($b, 'Trees', $trees));
        self::assertSame(1, FoliageColliders::declare($b, 'Hedges', $hedges));
        self::assertSame(0, FoliageColliders::declare($b, 'Nothing', $bushes));
        $decls = $b->getDeclarations();
        self::assertCount(3, $decls);
        $first = null;
        foreach ($decls[0]->getComponents() as $c) {
            if ($c instanceof Transform3D) {
                $first = $c;
            }
        }
        self::assertNotNull($first);
        self::assertGreaterThan(2.0, $first->position->y, 'collider centre above the foot');
        self::assertEqualsWithDelta(10.0, $first->position->x, 0.3);
        $box = null;
        foreach ($decls[2]->getComponents() as $c) {
            if ($c instanceof BoxCollider3D) {
                $box = $c;
            }
        }
        self::assertNotNull($box);
        self::assertTrue($box->isStatic);
    }

    public function testQuaternionYawMatchesTheInstanceMatrixYaw(): void
    {
        // FoliageColliders rotates with a quaternion, FoliageInstanceSystem builds
        // the instance matrix like Mat4::rotationY: both must turn the same way.
        $q = Quaternion::fromAxisAngle(new Vec3(0.0, 1.0, 0.0), 0.8);
        $a = $q->rotateVec3(new Vec3(1.0, 0.0, 0.0));
        $b = Mat4::rotationY(0.8)->transformPoint(new Vec3(1.0, 0.0, 0.0));
        self::assertEqualsWithDelta($b->x, $a->x, 1e-9);
        self::assertEqualsWithDelta($b->z, $a->z, 1e-9);
    }

    public function testFootprintAndBounds(): void
    {
        $v = self::tree();
        self::assertGreaterThan(0.2, $v->footprintRadius());
        self::assertLessThan(1.0, $v->footprintRadius());
        self::assertGreaterThan(4.5, $v->boundingRadius());
        $hedge = new PlantVariant('h', PlantSpec::hedge(6.0, 1.1, 0.8), 'bark', 'leaf');
        self::assertEqualsWithDelta(0.4, $hedge->footprintRadius(), 1e-9);
        self::assertGreaterThan(3.0, $hedge->boundingRadius());
    }
}
