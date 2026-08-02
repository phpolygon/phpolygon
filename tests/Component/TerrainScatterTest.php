<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Component;

use PHPolygon\Component\InstancedTerrain;
use PHPolygon\Component\Terrain;
use PHPolygon\Component\TerrainScatter;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\Serializer\AttributeSerializer;
use PHPolygon\ECS\World;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Vec3;
use PHPolygon\System\TerrainRegenerationSystem;
use PHPolygon\Terrain\HeightmapData;
use PHPUnit\Framework\TestCase;

class TerrainScatterTest extends TestCase
{
    protected function setUp(): void
    {
        MeshRegistry::clear();
    }

    /** Density painted across the whole grid of a 17x17 terrain. */
    private function fullDensity(): string
    {
        return base64_encode(str_repeat("\xFF", 17 * 17));
    }

    /** @param array<string, mixed> $overrides */
    private function set(array $overrides = []): array
    {
        return array_merge([
            'id' => 'pines',
            'meshId' => 'pine',
            'materialId' => 'bark',
            'seed' => 1337,
            'density' => 0.05,
            'densityMap' => $this->fullDensity(),
            'minHeight' => 0.0,
            'maxHeight' => 1.0,
            'minSlope' => 0.0,
            'maxSlope' => 90.0,
            'minScale' => 0.8,
            'maxScale' => 1.2,
            'alignToNormal' => false,
            'randomYaw' => 360.0,
        ], $overrides);
    }

    private function terrain(): Terrain
    {
        $terrain = new Terrain();
        $terrain->meshIdPrefix = 'island';
        $terrain->chunkSize = 8;
        $terrain->setHeightmap(HeightmapData::flat(17, 17, 64.0, 64.0, 0.0, 20.0, 0.25));

        return $terrain;
    }

    /** @return array{World, Terrain, TerrainScatter, int} */
    private function world(TerrainScatter $scatter, ?Vec3 $position = null): array
    {
        $world = new World();
        $terrain = $this->terrain();
        $entity = $world->createEntity();
        $entity->attach(new Transform3D($position ?? Vec3::zero()));
        $entity->attach($terrain);
        $entity->attach($scatter);

        return [$world, $terrain, $scatter, $entity->id];
    }

    public function testRebuildCreatesAnInstancedEntityPerSet(): void
    {
        $scatter = new TerrainScatter();
        $scatter->sets = [$this->set(), $this->set(['id' => 'rocks', 'meshId' => 'rock', 'seed' => 7])];
        [$world, , , $entityId] = $this->world($scatter);

        $scatter->rebuild($world, $entityId);

        $this->assertCount(2, $scatter->instanceEntityIds);
        foreach ($scatter->instanceEntityIds as $id) {
            $this->assertTrue($world->hasComponent($id, InstancedTerrain::class));
        }
    }

    public function testInstancesAreGroupedUnderTheSetsMeshAndMaterial(): void
    {
        $scatter = new TerrainScatter();
        $scatter->sets = [$this->set()];
        [$world, , , $entityId] = $this->world($scatter);

        $scatter->rebuild($world, $entityId);

        $instanced = $world->getComponent($scatter->instanceEntityIds[0], InstancedTerrain::class);
        $this->assertInstanceOf(InstancedTerrain::class, $instanced);
        $this->assertSame('pine', $instanced->meshId);
        $this->assertArrayHasKey('bark', $instanced->matricesByMaterial);
        $this->assertNotEmpty($instanced->matricesByMaterial['bark']);
    }

    public function testInstanceTransformsIncludeTheTerrainWorldPosition(): void
    {
        // InstancedTerrainSystem submits these matrices as-is, so the terrain's
        // own placement has to be folded in or the forest renders at the origin.
        $atOrigin = new TerrainScatter();
        $atOrigin->sets = [$this->set()];
        [$worldA, , , $idA] = $this->world($atOrigin);
        $atOrigin->rebuild($worldA, $idA);

        $offset = new TerrainScatter();
        $offset->sets = [$this->set()];
        [$worldB, , , $idB] = $this->world($offset, new Vec3(1000.0, 5.0, -400.0));
        $offset->rebuild($worldB, $idB);

        $first = $worldA->getComponent($atOrigin->instanceEntityIds[0], InstancedTerrain::class);
        $second = $worldB->getComponent($offset->instanceEntityIds[0], InstancedTerrain::class);
        $this->assertInstanceOf(InstancedTerrain::class, $first);
        $this->assertInstanceOf(InstancedTerrain::class, $second);

        $a = $first->matricesByMaterial['bark'][0]->getTranslation();
        $b = $second->matricesByMaterial['bark'][0]->getTranslation();

        $this->assertEqualsWithDelta($a->x + 1000.0, $b->x, 1e-4);
        $this->assertEqualsWithDelta($a->y + 5.0, $b->y, 1e-4);
        $this->assertEqualsWithDelta($a->z - 400.0, $b->z, 1e-4);
    }

    public function testSetsWithoutAMeshRenderNothing(): void
    {
        // An unassigned mesh is a normal authoring state, not an error.
        $scatter = new TerrainScatter();
        $scatter->sets = [$this->set(['meshId' => ''])];
        [$world, , , $entityId] = $this->world($scatter);

        $scatter->rebuild($world, $entityId);

        $this->assertSame([], $scatter->instanceEntityIds);
    }

    public function testRebuildReplacesPreviousInstances(): void
    {
        $scatter = new TerrainScatter();
        $scatter->sets = [$this->set()];
        [$world, , , $entityId] = $this->world($scatter);

        $scatter->rebuild($world, $entityId);
        $before = $scatter->instanceEntityIds;
        $scatter->rebuild($world, $entityId);

        $this->assertCount(1, $scatter->instanceEntityIds);
        $this->assertSame(
            1,
            $world->componentCount(InstancedTerrain::class),
            'a rebuild must not leave the previous set behind',
        );
        $this->assertNotEmpty($before);
    }

    public function testRemovingAllSetsClearsTheInstances(): void
    {
        $scatter = new TerrainScatter();
        $scatter->sets = [$this->set()];
        [$world, , , $entityId] = $this->world($scatter);
        $scatter->rebuild($world, $entityId);

        $scatter->sets = [];
        $scatter->rebuild($world, $entityId);

        $this->assertSame([], $scatter->instanceEntityIds);
        $this->assertSame(0, $world->componentCount(InstancedTerrain::class));
    }

    public function testScatterWithoutATerrainSiblingClearsInstances(): void
    {
        $world = new World();
        $scatter = new TerrainScatter();
        $scatter->sets = [$this->set()];
        $entity = $world->createEntity();
        $entity->attach($scatter);

        $scatter->rebuild($world, $entity->id);

        $this->assertSame([], $scatter->instanceEntityIds);
    }

    public function testSculptingReplacesInstancesRatherThanAccumulating(): void
    {
        $scatter = new TerrainScatter();
        $scatter->sets = [$this->set()];
        [$world, $terrain, , $entityId] = $this->world($scatter);
        $scatter->rebuild($world, $entityId);

        $terrain->setHeightmap(HeightmapData::flat(17, 17, 64.0, 64.0, 0.0, 20.0, 0.75));
        $scatter->rebuild($world, $entityId);

        $this->assertSame(1, $world->componentCount(InstancedTerrain::class));
    }

    public function testInstanceLimitIsHonoured(): void
    {
        $scatter = new TerrainScatter();
        $scatter->instanceLimit = 12;
        $scatter->sets = [$this->set(['density' => 10.0])];
        [$world, , , $entityId] = $this->world($scatter);

        $scatter->rebuild($world, $entityId);

        $instanced = $world->getComponent($scatter->instanceEntityIds[0], InstancedTerrain::class);
        $this->assertInstanceOf(InstancedTerrain::class, $instanced);
        $this->assertCount(12, $instanced->matricesByMaterial['bark']);
    }

    public function testGeneratedInstanceEntitiesAreNotSerialised(): void
    {
        $scatter = new TerrainScatter();
        $scatter->sets = [$this->set()];
        [$world, , , $entityId] = $this->world($scatter);
        $scatter->rebuild($world, $entityId);

        $serialized = (new AttributeSerializer())->toArray($scatter);

        $this->assertArrayNotHasKey('instanceEntityIds', $serialized);
        $this->assertArrayHasKey('sets', $serialized);
    }

    public function testSetsSurviveAComponentRoundTrip(): void
    {
        $scatter = new TerrainScatter();
        $scatter->sets = [$this->set()];
        $serializer = new AttributeSerializer();

        $restored = $serializer->fromArray($serializer->toArray($scatter), TerrainScatter::class);

        $this->assertInstanceOf(TerrainScatter::class, $restored);
        $this->assertSame($scatter->sets, $restored->sets);
    }

    public function testRegenerationSystemDrivesScatterAlongsideTerrain(): void
    {
        $world = new World();
        $world->addSystem(new TerrainRegenerationSystem(
            [Terrain::class, TerrainScatter::class],
            debounceSeconds: 0.1,
        ));

        $terrain = $this->terrain();
        $scatter = new TerrainScatter();
        $scatter->sets = [$this->set()];
        $entity = $world->createEntity();
        $entity->attach(new Transform3D());
        $entity->attach($terrain);
        $entity->attach($scatter);

        $world->update(0.016); // adopt baseline
        $this->assertSame([], $scatter->instanceEntityIds);

        $scatter->sets = [$this->set(['seed' => 4242])];
        $world->update(0.016);
        $world->update(0.2); // debounce elapsed

        $this->assertCount(1, $scatter->instanceEntityIds);
    }
}
