<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Component;

use PHPolygon\Component\HeightmapCollider3D;
use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\Terrain;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\Serializer\AttributeSerializer;
use PHPolygon\ECS\World;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Vec3;
use PHPolygon\System\TerrainRegenerationSystem;
use PHPolygon\Terrain\HeightmapData;
use PHPUnit\Framework\TestCase;

class TerrainTest extends TestCase
{
    protected function setUp(): void
    {
        MeshRegistry::clear();
    }

    public function testRebuildPublishesOneMeshPerChunk(): void
    {
        $world = new World();
        $terrain = $this->terrain();
        $entity = $world->createEntity();
        $entity->attach(new Transform3D());
        $entity->attach($terrain);

        $terrain->rebuild($world, $entity->id);

        // 8 quads per axis / chunk size 4 = 2x2 chunks.
        $this->assertTrue(MeshRegistry::has('island_c0_0'));
        $this->assertTrue(MeshRegistry::has('island_c1_1'));
        $this->assertCount(4, $terrain->chunkEntityIds);
    }

    public function testChunkEntitiesRenderTheTerrainMaterial(): void
    {
        $world = new World();
        $terrain = $this->terrain();
        $terrain->materialId = 'grass';
        $terrain->castShadows = false;
        $entity = $world->createEntity();
        $entity->attach(new Transform3D());
        $entity->attach($terrain);

        $terrain->rebuild($world, $entity->id);

        $renderer = $world->getComponent($terrain->chunkEntityIds[0], MeshRenderer::class);
        $this->assertInstanceOf(MeshRenderer::class, $renderer);
        $this->assertSame('grass', $renderer->materialId);
        $this->assertFalse($renderer->castShadows);
        $this->assertSame('island_c0_0', $renderer->meshId);
    }

    public function testChunkEntitiesAreParentedToTheTerrain(): void
    {
        $world = new World();
        $terrain = $this->terrain();
        $entity = $world->createEntity();
        $parentTransform = new Transform3D();
        $entity->attach($parentTransform);
        $entity->attach($terrain);

        $terrain->rebuild($world, $entity->id);

        foreach ($terrain->chunkEntityIds as $chunkId) {
            $childTransform = $world->getComponent($chunkId, Transform3D::class);
            $this->assertInstanceOf(Transform3D::class, $childTransform);
            $this->assertSame($entity->id, $childTransform->parentEntityId);
        }
        $this->assertSame($terrain->chunkEntityIds, $parentTransform->childEntityIds);
    }

    public function testRebuildWithUnchangedLayoutReusesChunkEntities(): void
    {
        $world = new World();
        $terrain = $this->terrain();
        $entity = $world->createEntity();
        $entity->attach(new Transform3D());
        $entity->attach($terrain);

        $terrain->rebuild($world, $entity->id);
        $before = $terrain->chunkEntityIds;

        // Sculpting changes heights but not the chunk layout.
        $terrain->heights = HeightmapData::fromFunction(
            static fn (float $x, float $z): float => $x * 0.5,
            gridWidth: 9,
            gridDepth: 9,
            sizeX: 16.0,
            sizeZ: 16.0,
            minHeight: -10.0,
            maxHeight: 10.0,
        )->encode();
        $terrain->rebuild($world, $entity->id);

        $this->assertSame($before, $terrain->chunkEntityIds, 'geometry-only edits must not churn entities');
    }

    public function testChangingChunkSizeReplacesChunkEntities(): void
    {
        $world = new World();
        $terrain = $this->terrain();
        $entity = $world->createEntity();
        $entity->attach(new Transform3D());
        $entity->attach($terrain);

        $terrain->rebuild($world, $entity->id);
        $parentTransform = $world->getComponent($entity->id, Transform3D::class);
        $this->assertInstanceOf(Transform3D::class, $parentTransform);

        $terrain->chunkSize = 8; // now a single chunk
        $terrain->rebuild($world, $entity->id);

        $this->assertCount(1, $terrain->chunkEntityIds);
        // Entity ids are recycled through the world's free list, so a stale id
        // may legitimately be alive again as the new chunk. What must hold is
        // that the hierarchy contains exactly the current chunk set.
        $this->assertSame($terrain->chunkEntityIds, $parentTransform->childEntityIds);
    }

    public function testRebuildRecreatesChunksAfterTheirEntitiesAreDestroyed(): void
    {
        $world = new World();
        $terrain = $this->terrain();
        $entity = $world->createEntity();
        $entity->attach(new Transform3D());
        $entity->attach($terrain);

        $terrain->rebuild($world, $entity->id);
        foreach ($terrain->chunkEntityIds as $chunkId) {
            $world->destroyEntity($chunkId);
        }

        $terrain->rebuild($world, $entity->id);

        $this->assertCount(4, $terrain->chunkEntityIds);
        foreach ($terrain->chunkEntityIds as $chunkId) {
            $this->assertTrue($world->isAlive($chunkId));
        }
    }

    public function testRebuildPopulatesASiblingCollider(): void
    {
        $world = new World();
        $terrain = $this->terrain();
        $terrain->heights = HeightmapData::fromFunction(
            static fn (float $x, float $z): float => 5.0,
            gridWidth: 9,
            gridDepth: 9,
            sizeX: 16.0,
            sizeZ: 16.0,
            minHeight: -10.0,
            maxHeight: 10.0,
        )->encode();

        $collider = new HeightmapCollider3D();
        $entity = $world->createEntity();
        $entity->attach(new Transform3D());
        $entity->attach($terrain);
        $entity->attach($collider);

        $terrain->rebuild($world, $entity->id);

        $this->assertTrue($collider->isPopulated());
        $this->assertSame(9, $collider->gridWidth);
        $this->assertSame(-8.0, $collider->worldMinX);
        $this->assertSame(8.0, $collider->worldMaxZ);
        $this->assertEqualsWithDelta(5.0, $collider->getHeightAt(0.0, 0.0), 1e-3);
    }

    public function testColliderBoundsAndHeightsFollowTheTerrainWorldPosition(): void
    {
        $world = new World();
        $terrain = $this->terrain();
        $terrain->heights = HeightmapData::flat(9, 9, 16.0, 16.0, -10.0, 10.0, 0.5)->encode();

        $collider = new HeightmapCollider3D();
        $entity = $world->createEntity();
        $entity->attach(new Transform3D(new Vec3(100.0, 20.0, -50.0)));
        $entity->attach($terrain);
        $entity->attach($collider);

        $terrain->rebuild($world, $entity->id);

        $this->assertSame(92.0, $collider->worldMinX);
        $this->assertSame(108.0, $collider->worldMaxX);
        $this->assertSame(-58.0, $collider->worldMinZ);
        // Flat terrain at normalised 0.5 over [-10, 10] is local Y 0, plus the
        // entity's own Y offset.
        $this->assertEqualsWithDelta(20.0, $collider->getHeightAt(100.0, -50.0), 1e-3);
    }

    public function testColliderIsLeftAloneWhenGenerationIsDisabled(): void
    {
        $world = new World();
        $terrain = $this->terrain();
        $terrain->generateCollider = false;
        $collider = new HeightmapCollider3D();
        $entity = $world->createEntity();
        $entity->attach(new Transform3D());
        $entity->attach($terrain);
        $entity->attach($collider);

        $terrain->rebuild($world, $entity->id);

        $this->assertFalse($collider->isPopulated());
    }

    public function testRebuildWorksWithoutATransformOrCollider(): void
    {
        $world = new World();
        $terrain = $this->terrain();
        $entity = $world->createEntity();
        $entity->attach($terrain);

        $terrain->rebuild($world, $entity->id);

        $this->assertCount(4, $terrain->chunkEntityIds);
    }

    public function testSetHeightmapAdoptsGridAndRange(): void
    {
        $terrain = new Terrain();

        $terrain->setHeightmap(HeightmapData::flat(17, 33, 64.0, 128.0, -5.0, 45.0, 1.0));

        $this->assertSame(17, $terrain->gridWidth);
        $this->assertSame(33, $terrain->gridDepth);
        $this->assertSame(64.0, $terrain->sizeX);
        $this->assertSame(128.0, $terrain->sizeZ);
        $this->assertSame(45.0, $terrain->toHeightmap()->heightAt(0, 0));
    }

    public function testGeneratedChunkEntitiesAreNotSerialised(): void
    {
        $world = new World();
        $terrain = $this->terrain();
        $entity = $world->createEntity();
        $entity->attach(new Transform3D());
        $entity->attach($terrain);
        $terrain->rebuild($world, $entity->id);

        $serialized = (new AttributeSerializer())->toArray($terrain);

        $this->assertArrayNotHasKey('chunkEntityIds', $serialized, 'derived chunk ids must stay out of the scene document');
        $this->assertArrayHasKey('heights', $serialized, 'the heightmap itself must round-trip');
    }

    public function testHeightmapSurvivesAComponentRoundTrip(): void
    {
        $terrain = $this->terrain();
        $serializer = new AttributeSerializer();

        $restored = $serializer->fromArray($serializer->toArray($terrain), Terrain::class);

        $this->assertInstanceOf(Terrain::class, $restored);
        $this->assertSame($terrain->heights, $restored->heights);
        $this->assertEqualsWithDelta(
            $terrain->toHeightmap()->heightAt(4, 4),
            $restored->toHeightmap()->heightAt(4, 4),
            1e-3,
        );
    }

    public function testRegenerationSystemRebuildsTerrainWhenAPropertyChanges(): void
    {
        $world = new World();
        $world->addSystem(new TerrainRegenerationSystem([Terrain::class], debounceSeconds: 0.1));
        $terrain = $this->terrain();
        $entity = $world->createEntity();
        $entity->attach(new Transform3D());
        $entity->attach($terrain);

        $world->update(0.016); // adopt baseline
        $this->assertSame([], $terrain->chunkEntityIds, 'no rebuild without a change');

        $terrain->chunkSize = 8;
        $world->update(0.016);
        $world->update(0.2); // debounce elapsed

        $this->assertCount(1, $terrain->chunkEntityIds);
    }

    private function terrain(): Terrain
    {
        $terrain = new Terrain();
        $terrain->meshIdPrefix = 'island';
        $terrain->chunkSize = 4;
        $terrain->setHeightmap(HeightmapData::flat(9, 9, 16.0, 16.0, -10.0, 10.0, 0.5));

        return $terrain;
    }
}
