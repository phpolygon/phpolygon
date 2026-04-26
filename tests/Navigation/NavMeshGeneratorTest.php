<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Navigation;

use PHPUnit\Framework\TestCase;
use PHPolygon\Math\Vec3;
use PHPolygon\Navigation\NavMeshGenerator;
use PHPolygon\Navigation\NavMeshGeneratorConfig;
use PHPolygon\Physics\Triangle;

class NavMeshGeneratorTest extends TestCase
{
    /**
     * Create a flat ground plane as two triangles (10x10, Y=0).
     *
     * @return Triangle[]
     */
    private function createFlatGround(float $size = 10.0): array
    {
        $h = $size / 2;
        return [
            new Triangle(
                new Vec3(-$h, 0, -$h),
                new Vec3($h, 0, -$h),
                new Vec3($h, 0, $h),
            ),
            new Triangle(
                new Vec3(-$h, 0, -$h),
                new Vec3($h, 0, $h),
                new Vec3(-$h, 0, $h),
            ),
        ];
    }

    public function testGeneratesNavMeshFromFlatGround(): void
    {
        $generator = new NavMeshGenerator(new NavMeshGeneratorConfig(cellSize: 1.0));
        $mesh = $generator->generate($this->createFlatGround());

        $this->assertGreaterThan(0, $mesh->polygonCount());
    }

    public function testFiltersSteepTriangles(): void
    {
        // Vertical wall (normal = horizontal) should not be walkable
        $wall = [
            new Triangle(
                new Vec3(0, 0, 0),
                new Vec3(0, 10, 0),
                new Vec3(0, 10, 10),
            ),
            new Triangle(
                new Vec3(0, 0, 0),
                new Vec3(0, 10, 10),
                new Vec3(0, 0, 10),
            ),
        ];

        $generator = new NavMeshGenerator(new NavMeshGeneratorConfig(
            cellSize: 1.0,
            agentMaxSlope: 45.0,
        ));
        $mesh = $generator->generate($wall);

        $this->assertSame(0, $mesh->polygonCount());
    }

    public function testEmptyInputProducesEmptyNavMesh(): void
    {
        $generator = new NavMeshGenerator();
        $mesh = $generator->generate([]);
        $this->assertSame(0, $mesh->polygonCount());
    }

    public function testGeneratedPolygonsHaveNeighbors(): void
    {
        $generator = new NavMeshGenerator(new NavMeshGeneratorConfig(cellSize: 1.0));
        $mesh = $generator->generate($this->createFlatGround());

        // On a flat ground, at least some polygons should have neighbors
        $hasNeighbor = false;
        foreach ($mesh->getPolygons() as $poly) {
            if (count($poly->neighborIds) > 0) {
                $hasNeighbor = true;
                break;
            }
        }

        $this->assertTrue($hasNeighbor, 'At least one polygon should have neighbors');
    }

    public function testFindPolygonAtCenterOfGeneratedMesh(): void
    {
        $generator = new NavMeshGenerator(new NavMeshGeneratorConfig(cellSize: 1.0));
        $mesh = $generator->generate($this->createFlatGround());

        // Center of the ground plane should be findable
        $poly = $mesh->findNearestPolygon(new Vec3(0, 0, 0), 5.0);
        $this->assertNotNull($poly);
    }

    public function testSmallRegionsAreFiltered(): void
    {
        // A very small triangle that produces fewer cells than regionMinSize
        $tiny = [
            new Triangle(
                new Vec3(0, 0, 0),
                new Vec3(0.1, 0, 0),
                new Vec3(0, 0, 0.1),
            ),
        ];

        $generator = new NavMeshGenerator(new NavMeshGeneratorConfig(
            cellSize: 0.3,
            regionMinSize: 100, // Very high threshold
        ));
        $mesh = $generator->generate($tiny);

        // The tiny region should be filtered out
        $this->assertSame(0, $mesh->polygonCount());
    }

    public function testSlopeAtMaxLimitIsWalkable(): void
    {
        // 45-degree slope: rise = run, large enough for rasterization
        $slope = [
            new Triangle(
                new Vec3(0, 0, 0),
                new Vec3(20, 20, 0),
                new Vec3(0, 0, 20),
            ),
            new Triangle(
                new Vec3(20, 20, 0),
                new Vec3(20, 20, 20),
                new Vec3(0, 0, 20),
            ),
        ];

        $generator = new NavMeshGenerator(new NavMeshGeneratorConfig(
            cellSize: 2.0,
            agentMaxSlope: 46.0, // Just above 45 degrees
            agentMaxClimb: 3.0,  // High enough for 2m steps on the slope
            regionMinSize: 1,
        ));
        $mesh = $generator->generate($slope);

        $this->assertGreaterThan(0, $mesh->polygonCount());
    }

    public function testConfigRoundtrip(): void
    {
        $config = new NavMeshGeneratorConfig(
            cellSize: 0.5,
            agentHeight: 2.0,
            agentRadius: 0.6,
        );

        $restored = NavMeshGeneratorConfig::fromArray($config->toArray());
        $this->assertEqualsWithDelta(0.5, $restored->cellSize, 1e-6);
        $this->assertEqualsWithDelta(2.0, $restored->agentHeight, 1e-6);
        $this->assertEqualsWithDelta(0.6, $restored->agentRadius, 1e-6);
    }
}
