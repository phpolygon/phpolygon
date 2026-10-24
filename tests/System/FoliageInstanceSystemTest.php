<?php

declare(strict_types=1);

namespace PHPolygon\Tests\System;

use PHPUnit\Framework\TestCase;
use PHPolygon\Component\FoliageInstances;
use PHPolygon\Component\Wind;
use PHPolygon\ECS\World;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\DrawMeshInstanced;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetWind;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\NullRenderer3D;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\System\FoliageInstanceSystem;
use PHPolygon\System\Renderer3DSystem;
use PHPolygon\Vegetation\GrowthStage;
use PHPolygon\Vegetation\PlantForm;
use PHPolygon\Vegetation\PlantLibrary;
use PHPolygon\Vegetation\PlantLod;
use PHPolygon\Vegetation\PlantPart;
use PHPolygon\Vegetation\PlantSpec;
use PHPolygon\Vegetation\PlantVariant;

final class FoliageInstanceSystemTest extends TestCase
{
    private RenderCommandList $list;
    private FoliageInstanceSystem $system;
    private World $world;

    protected function setUp(): void
    {
        MeshRegistry::clear();
        MaterialRegistry::clear();
        PlantLibrary::clear();
        MaterialRegistry::register('test_bark', Material::bark(Color::srgb('#6B4A32')));
        MaterialRegistry::register('test_leaf', Material::foliage(Color::srgb('#5E9E3A')));
        MaterialRegistry::register('test_leaf_b', Material::foliage(Color::srgb('#7DB548')));
        MaterialRegistry::register('test_fresh', Material::foliage(Color::srgb('#9BC75A')));
        MaterialRegistry::register('test_bloom', new Material(albedo: Color::srgb('#E040C0')));
        PlantLibrary::register(new PlantVariant(
            id: 'test_tree',
            spec: PlantSpec::tree(PlantForm::Round, 9.0, 3.2, 4),
            woodMaterial: 'test_bark',
            leafMaterial: 'test_leaf',
            accentMaterial: 'test_bloom',
            stageLeafMaterials: [GrowthStage::Sprouting->value => 'test_fresh'],
            accentGlow: new Color(2.0, 0.4, 1.6),
        ));
        $this->list = new RenderCommandList();
        $this->system = new FoliageInstanceSystem($this->list);
        $this->world = new World();
    }

    protected function tearDown(): void
    {
        PlantLibrary::clear();
        MeshRegistry::clear();
        MaterialRegistry::clear();
    }

    /** Camera at the origin, eye height 1.7 m, looking down -Z. */
    private function camera(float $x = 0.0, float $z = 0.0, float $lookZ = -1.0): void
    {
        $this->list->clear();
        $this->list->add(new SetCamera(
            Mat4::lookAt(new Vec3($x, 1.7, $z), new Vec3($x, 1.7, $z + $lookZ), new Vec3(0.0, 1.0, 0.0)),
            Mat4::perspective(deg2rad(70.0), 16 / 9, 0.1, 2000.0),
        ));
    }

    private function foliage(): FoliageInstances
    {
        $f = new FoliageInstances('test_tree');
        $this->world->createEntity()->attach($f);
        return $f;
    }

    /** @return list<DrawMeshInstanced> */
    private function draws(): array
    {
        $this->system->render($this->world);
        $out = [];
        foreach ($this->list->getCommands() as $cmd) {
            if ($cmd instanceof DrawMeshInstanced) {
                $out[] = $cmd;
            }
        }
        return $out;
    }

    public function testDistancePicksLodAndShadowsStayNear(): void
    {
        $f = $this->foliage();
        $f->add(new Vec3(0.0, 0.0, -10.0));   // LOD0, shadow
        $f->add(new Vec3(3.0, 0.0, -60.0));   // LOD1, shadow
        $f->add(new Vec3(-3.0, 0.0, -100.0)); // LOD1, beyond shadow distance
        $f->add(new Vec3(0.0, 0.0, -200.0));  // LOD2
        $f->add(new Vec3(0.0, 0.0, -400.0));  // impostor
        $this->camera();

        $byMesh = [];
        foreach ($this->draws() as $d) {
            $byMesh[$d->meshId][] = $d;
        }
        $v = PlantLibrary::get('test_tree');
        self::assertNotNull($v);
        foreach ([PlantLod::Lod0, PlantLod::Lod2, PlantLod::Impostor] as $lod) {
            $id = $v->meshId(GrowthStage::Full, $lod, PlantPart::Leaf);
            self::assertArrayHasKey($id, $byMesh, "{$lod->name} foliage drawn");
            self::assertSame(1, $byMesh[$id][0]->instanceCount);
        }
        $lod1 = $byMesh[$v->meshId(GrowthStage::Full, PlantLod::Lod1, PlantPart::Leaf)];
        self::assertCount(2, $lod1, 'LOD1 split into a shadow and a no-shadow batch');
        $shadows = array_map(static fn (DrawMeshInstanced $d): bool => $d->castShadows, $lod1);
        sort($shadows);
        self::assertSame([false, true], $shadows);
        self::assertTrue($byMesh[$v->meshId(GrowthStage::Full, PlantLod::Lod0, PlantPart::Wood)][0]->castShadows);
        self::assertFalse($byMesh[$v->meshId(GrowthStage::Full, PlantLod::Lod2, PlantPart::Leaf)][0]->castShadows);
        self::assertFalse($byMesh[$v->meshId(GrowthStage::Full, PlantLod::Impostor, PlantPart::Leaf)][0]->castShadows);

        // Every batch is static, keyed per group and part, and its meshes exist.
        $keys = [];
        foreach ($byMesh as $id => $draws) {
            self::assertNotNull(MeshRegistry::get($id), "{$id} is registered");
            foreach ($draws as $d) {
                self::assertTrue($d->isStatic);
                self::assertNotNull($d->staticKey);
                $keys[] = $d->staticKey;
                self::assertCount($d->instanceCount * 16, $d->flatMatrices);
            }
        }
        self::assertSame(count($keys), count(array_unique($keys)), 'static keys are distinct');
    }

    public function testInstanceMatrixHoldsPositionYawAndScale(): void
    {
        $f = $this->foliage();
        $f->add(new Vec3(4.0, 0.5, -12.0), 0.7, 1.3);
        $this->camera();
        $draw = $this->draws()[0];
        $expected = FoliageInstanceSystem::instanceMatrix($f, 0)->toArray();
        foreach ($expected as $k => $value) {
            self::assertEqualsWithDelta($value, $draw->flatMatrices[$k], 1e-9, "matrix element {$k}");
        }
    }

    public function testUnchangedFrameResendsTheSameBatches(): void
    {
        $f = $this->foliage();
        for ($i = 0; $i < 30; $i++) {
            $f->add(new Vec3(($i % 6) * 9.0 - 25.0, 0.0, -10.0 - intdiv($i, 6) * 25.0), $i * 0.3);
        }
        $this->camera();
        $first = $this->draws();
        self::assertGreaterThan(0, $this->system->lastRebuildCount);

        $this->camera(0.5, -0.4); // moved less than the rebuild distance
        $second = $this->draws();
        self::assertSame(0, $this->system->lastRebuildCount, 'no rebuild for a small camera step');
        self::assertCount(count($first), $second);
        foreach ($first as $k => $cmd) {
            self::assertSame($cmd, $second[$k], 'the very same batch objects are sent again');
        }

        $this->camera(0.0, -30.0); // walked 30 m: LODs change
        $this->draws();
        self::assertGreaterThan(0, $this->system->lastRebuildCount);
    }

    public function testCellsBehindTheCameraAreCulled(): void
    {
        $f = $this->foliage();
        $f->add(new Vec3(0.0, 0.0, -30.0));
        $f->add(new Vec3(0.0, 0.0, 300.0)); // far behind the camera
        $this->camera();
        $count = 0;
        foreach ($this->draws() as $d) {
            if ($d->materialId === 'test_bark') {
                $count += $d->instanceCount;
            }
        }
        self::assertSame(1, $count, 'only the plant in front is drawn');

        $this->system->frustumCulling = false;
        $f->setLeafScale(0, 1.0); // no-op, but the system state stays valid
        $this->camera(0.0, 0.0);
        $this->system = new FoliageInstanceSystem($this->list, frustumCulling: false);
        $count = 0;
        foreach ($this->draws() as $d) {
            if ($d->materialId === 'test_bark') {
                $count += $d->instanceCount;
            }
        }
        self::assertSame(2, $count, 'without culling both are drawn');
    }

    public function testGrowthStagesSwapMeshesAndMaterials(): void
    {
        $f = $this->foliage();
        $f->add(new Vec3(0.0, 0.0, -10.0), stage: GrowthStage::Bare);
        $f->add(new Vec3(5.0, 0.0, -10.0), stage: GrowthStage::Sprouting);
        $f->add(new Vec3(-5.0, 0.0, -10.0), stage: GrowthStage::Bloom);
        $this->camera();
        $v = PlantLibrary::get('test_tree');
        self::assertNotNull($v);
        $meshes = [];
        foreach ($this->draws() as $d) {
            $meshes[$d->meshId] = $d->materialId;
        }
        self::assertArrayHasKey($v->meshId(GrowthStage::Bare, PlantLod::Lod0, PlantPart::Wood), $meshes);
        self::assertArrayNotHasKey($v->meshId(GrowthStage::Bare, PlantLod::Lod0, PlantPart::Leaf), $meshes, 'bare: no foliage draw');
        self::assertSame('test_fresh', $meshes[$v->meshId(GrowthStage::Sprouting, PlantLod::Lod0, PlantPart::Leaf)], 'stage leaf material');
        self::assertSame('test_bloom', $meshes[$v->meshId(GrowthStage::Bloom, PlantLod::Lod0, PlantPart::Accent)], 'bloom accent');
        self::assertArrayNotHasKey($v->meshId(GrowthStage::Full, PlantLod::Lod0, PlantPart::Accent), $meshes);

        // Same place, next stage: the bare tree grows, nothing moves.
        $f->setStage(0, GrowthStage::Half);
        $this->camera();
        $meshes = [];
        foreach ($this->draws() as $d) {
            $meshes[$d->meshId] = true;
        }
        self::assertArrayNotHasKey($v->meshId(GrowthStage::Bare, PlantLod::Lod0, PlantPart::Wood), $meshes);
        self::assertArrayHasKey($v->meshId(GrowthStage::Half, PlantLod::Lod0, PlantPart::Leaf), $meshes);
        self::assertEqualsWithDelta(0.0, $f->position(0)->x, 1e-9);
    }

    public function testLeafScaleShrinksTheCrownAroundItsCentre(): void
    {
        $f = $this->foliage();
        $f->add(new Vec3(2.0, 0.0, -10.0), 0.0, 1.0, GrowthStage::Full, 0.5);
        $this->camera();
        $wood = null;
        $leaf = null;
        foreach ($this->draws() as $d) {
            if ($d->materialId === 'test_bark') {
                $wood = $d;
            } elseif ($d->materialId === 'test_leaf') {
                $leaf = $d;
            }
        }
        self::assertNotNull($wood);
        self::assertNotNull($leaf);
        self::assertEqualsWithDelta(1.0, $wood->flatMatrices[5], 1e-9, 'wood keeps full scale');
        self::assertEqualsWithDelta(0.5, $leaf->flatMatrices[5], 1e-9, 'foliage at half scale');
        $v = PlantLibrary::get('test_tree');
        self::assertNotNull($v);
        $c = $v->parts(GrowthStage::Full, PlantLod::Lod0)->crownCenter;
        // The crown centre maps to the same world point in both matrices.
        $woodC = [$wood->flatMatrices[12] + $c->x, $wood->flatMatrices[13] + $c->y, $wood->flatMatrices[14] + $c->z];
        $leafC = [
            $leaf->flatMatrices[12] + 0.5 * $c->x,
            $leaf->flatMatrices[13] + 0.5 * $c->y,
            $leaf->flatMatrices[14] + 0.5 * $c->z,
        ];
        for ($k = 0; $k < 3; $k++) {
            self::assertEqualsWithDelta($woodC[$k], $leafC[$k], 1e-9);
        }
    }

    public function testGlowDerivesAnEmissiveAccentMaterial(): void
    {
        $f = $this->foliage();
        $f->add(new Vec3(0.0, 0.0, -10.0), stage: GrowthStage::Bloom);
        $f->glow = 0.5;
        $this->camera();
        $accent = null;
        foreach ($this->draws() as $d) {
            if (str_starts_with($d->materialId, 'test_bloom')) {
                $accent = $d;
            }
        }
        self::assertNotNull($accent);
        self::assertSame('test_bloom#glow4', $accent->materialId);
        $m = MaterialRegistry::get('test_bloom#glow4');
        self::assertNotNull($m);
        self::assertEqualsWithDelta(1.0, $m->emission->r, 1e-9);
        self::assertEqualsWithDelta(0.8, $m->emission->b, 1e-9);
        self::assertSame('test_bloom', FoliageInstanceSystem::glowMaterial('test_bloom', new Color(1.0, 1.0, 1.0), 0));
    }

    public function testTintsSpreadInstancesOverFoliageMaterials(): void
    {
        PlantLibrary::register(new PlantVariant(
            id: 'test_tinted',
            spec: PlantSpec::bush(1.2, 1.4, 3),
            woodMaterial: 'test_bark',
            leafMaterial: 'test_leaf',
            leafTints: ['test_leaf_b'],
        ));
        $f = new FoliageInstances('test_tinted');
        $this->world->createEntity()->attach($f);
        for ($i = 0; $i < 40; $i++) {
            $f->add(new Vec3($i * 1.7 - 34.0, 0.0, -8.0 - ($i % 5)));
        }
        $this->camera();
        $materials = [];
        foreach ($this->draws() as $d) {
            $materials[$d->materialId] = ($materials[$d->materialId] ?? 0) + $d->instanceCount;
        }
        self::assertArrayHasKey('test_leaf', $materials);
        self::assertArrayHasKey('test_leaf_b', $materials);
        self::assertSame(40, $materials['test_leaf'] + $materials['test_leaf_b']);
    }

    public function testNoCameraNoDraws(): void
    {
        $f = $this->foliage();
        $f->add(new Vec3(0.0, 0.0, -10.0));
        $this->list->clear();
        self::assertSame([], $this->draws());
    }

    public function testRendererSystemTurnsTheSceneWindIntoSetWind(): void
    {
        $wind = new Wind(0.8);
        $wind->intensity = 1.3;
        $wind->direction = new Vec3(1.0, 0.0, 0.0);
        $wind->foliageMotion = 0.3;
        $this->world->createEntity()->attach($wind);
        $renderer = new NullRenderer3D();
        $list = new RenderCommandList();
        $this->world->addSystem(new Renderer3DSystem($renderer, $list));
        $this->world->render();
        $set = null;
        foreach ($renderer->getLastCommandList()?->getCommands() ?? [] as $cmd) {
            if ($cmd instanceof SetWind) {
                $set = $cmd;
            }
        }
        self::assertNotNull($set);
        self::assertSame(1.3, $set->intensity);
        self::assertSame(1.0, $set->direction->x);
        self::assertSame(0.3, $set->foliageMotion);
        self::assertSame(100.0, $set->foliageFadeStart);
    }
}
