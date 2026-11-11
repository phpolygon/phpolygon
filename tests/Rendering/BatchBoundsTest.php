<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\MeshData;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\BatchBounds;
use PHPolygon\Rendering\Command\DrawMeshInstanced;
use PHPUnit\Framework\TestCase;

/**
 * The sphere the renderer derives for an instanced batch must hold every
 * vertex of every instance - under rotation, non-uniform scale, shear and for
 * a mesh that is not centred on its origin.
 */
final class BatchBoundsTest extends TestCase
{
    protected function setUp(): void
    {
        MeshRegistry::clear();
        BatchBounds::clearCache();
        MeshRegistry::register('box', BoxMesh::generate(1.0, 1.0, 1.0));
        // A box that hangs off its origin: 2 x 4 x 1, between (3, 0, -0.5) and (5, 4, 0.5).
        $box = BoxMesh::generate(2.0, 4.0, 1.0);
        $shifted = [];
        foreach (array_chunk($box->vertices, 3) as [$x, $y, $z]) {
            array_push($shifted, $x + 4.0, $y + 2.0, $z);
        }
        MeshRegistry::register('offset', new MeshData($shifted, $box->normals, $box->uvs, $box->indices));
    }

    protected function tearDown(): void
    {
        MeshRegistry::clear();
        BatchBounds::clearCache();
    }

    /** @return list<Mat4> */
    private static function matrices(): array
    {
        $shear = Mat4::identity()->toArray();
        $shear[4] = 1.5; // x grows with y
        return [
            Mat4::translation(10.0, 0.0, -20.0),
            Mat4::trs(new Vec3(-30.0, 5.0, 12.0), Quaternion::fromEuler(0.4, 1.1, -0.7), new Vec3(1.0, 6.0, 0.5)),
            Mat4::trs(new Vec3(0.0, -8.0, 40.0), Quaternion::fromEuler(1.3, 0.2, 2.0), new Vec3(3.0, 3.0, 3.0)),
            Mat4::translation(25.0, 2.0, 3.0)->multiply(new Mat4($shear)),
            // A rotated child of a non-uniformly scaled parent: sheared columns.
            Mat4::scaling(4.0, 1.0, 1.0)->multiply(Mat4::trs(new Vec3(2.0, 1.0, 0.0), Quaternion::fromEuler(0.0, 0.0, 0.8), new Vec3(1.0, 1.0, 1.0))),
        ];
    }

    /**
     * @param array{0: float, 1: float, 2: float, 3: float} $sphere
     * @param list<Mat4> $matrices
     */
    private static function assertHoldsEveryVertex(array $sphere, string $meshId, array $matrices): void
    {
        $mesh = MeshRegistry::get($meshId);
        self::assertNotNull($mesh);
        foreach ($matrices as $i => $matrix) {
            foreach (array_chunk($mesh->vertices, 3) as [$x, $y, $z]) {
                $p = $matrix->transformPoint(new Vec3($x, $y, $z));
                $d = sqrt(($p->x - $sphere[0]) ** 2 + ($p->y - $sphere[1]) ** 2 + ($p->z - $sphere[2]) ** 2);
                self::assertLessThanOrEqual($sphere[3] + 1e-6, $d, "instance {$i} of '{$meshId}' has a vertex outside the sphere");
            }
        }
    }

    public function testMeshRadiusIsMeasuredFromTheOriginAndFollowsReRegistration(): void
    {
        self::assertEqualsWithDelta(sqrt(0.75), BatchBounds::meshRadius('box'), 1e-9);
        // Farthest corner of the hanging box: (5, 4, +-0.5).
        self::assertEqualsWithDelta(sqrt(25.0 + 16.0 + 0.25), BatchBounds::meshRadius('offset'), 1e-9);
        self::assertNull(BatchBounds::meshRadius('missing'));

        MeshRegistry::register('box', BoxMesh::generate(4.0, 4.0, 4.0));
        self::assertEqualsWithDelta(sqrt(12.0), BatchBounds::meshRadius('box'), 1e-9, 'a re-registered mesh is measured again');
    }

    public function testSphereHoldsEveryVertexOfEveryInstance(): void
    {
        $matrices = self::matrices();
        foreach (['box', 'offset'] as $meshId) {
            $radius = BatchBounds::meshRadius($meshId);
            self::assertNotNull($radius);
            $sphere = BatchBounds::sphereOfMatrices($matrices, $radius);
            self::assertNotNull($sphere);
            self::assertHoldsEveryVertex($sphere, $meshId, $matrices);
        }
    }

    public function testEveryStorageModeGivesTheSameSphere(): void
    {
        $matrices = self::matrices();
        $flat = [];
        foreach ($matrices as $m) {
            array_push($flat, ...$m->toArray());
        }
        $fromMat4 = BatchBounds::of(new DrawMeshInstanced('offset', 'm', $matrices));
        $fromFlat = BatchBounds::of(DrawMeshInstanced::flat('offset', 'm', $flat, count($matrices)));
        $fromPacked = BatchBounds::of(DrawMeshInstanced::packed('offset', 'm', pack('f*', ...$flat), count($matrices)));

        self::assertNotNull($fromMat4);
        self::assertNotNull($fromFlat);
        self::assertNotNull($fromPacked);
        self::assertSame([$fromMat4->x, $fromMat4->y, $fromMat4->z, $fromMat4->radius], [$fromFlat->x, $fromFlat->y, $fromFlat->z, $fromFlat->radius]);
        // Packed matrices are f32: the same sphere up to that rounding.
        self::assertEqualsWithDelta($fromMat4->radius, $fromPacked->radius, 1e-3);
        self::assertEqualsWithDelta($fromMat4->x, $fromPacked->x, 1e-3);
    }

    public function testFlatBatchCountsOnlyItsInstances(): void
    {
        $near = Mat4::translation(0.0, 0.0, -5.0)->toArray();
        $far = Mat4::translation(900.0, 0.0, 0.0)->toArray();
        $sphere = BatchBounds::sphereOfFlat([...$near, ...$far], 1, 1.0);

        self::assertSame([0.0, 0.0, -5.0], array_slice($sphere ?? [], 0, 3), 'the float past instanceCount is not drawn and not bounded');
    }

    public function testNothingToBound(): void
    {
        self::assertNull(BatchBounds::sphereOfMatrices([], 1.0));
        self::assertNull(BatchBounds::sphereOfFlat([], 0, 1.0));
        self::assertNull(BatchBounds::of(new DrawMeshInstanced('missing', 'm', [Mat4::identity()])), 'unregistered mesh');
        self::assertNull(BatchBounds::of(DrawMeshInstanced::fromStorageBuffer('box', 'm', new \stdClass(), 4)), 'matrices on the GPU');

        $nan = Mat4::identity()->toArray();
        $nan[12] = NAN;
        self::assertNull(BatchBounds::sphereOfFlat($nan, 1, 1.0), 'a batch without a finite position has no sphere');
    }

    public function testUnboundedIsSharedAndRecognised(): void
    {
        self::assertSame(BatchBounds::unbounded(), BatchBounds::unbounded());
        self::assertTrue(BatchBounds::unbounded()->isUnbounded());
        self::assertFalse((new BatchBounds(0.0, 0.0, 0.0, 5.0))->isUnbounded());
    }
}
