<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\BatchBounds;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\AddPointLight;
use PHPolygon\Rendering\Command\AddSpotLight;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\DrawMeshInstanced;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Frustum;
use PHPolygon\Rendering\InstancedBatchCuller;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\ProcModeRegistry;
use PHPolygon\Rendering\VioRenderer3D;
use PHPUnit\Framework\TestCase;

/**
 * Whole instanced batches against the view and the shadow volumes: a batch is
 * dropped only when its sphere - derived by the engine, nobody passes one -
 * lies completely outside; everything in view comes back untouched; a caster
 * out of view stays in the shadow pass; the derived sphere of a static batch
 * outlives frames in which the batch is culled.
 */
final class InstancedBatchCullerTest extends TestCase
{
    private InstancedBatchCuller $culler;

    protected function setUp(): void
    {
        putenv('PHPOLYGON_NO_CULL');
        MeshRegistry::clear();
        MaterialRegistry::clear();
        ProcModeRegistry::clear();
        BatchBounds::clearCache();
        MeshRegistry::register('box', BoxMesh::generate(1.0, 1.0, 1.0));
        MaterialRegistry::register('stone', new Material(albedo: new Color(0.5, 0.5, 0.5)));
        MaterialRegistry::register('leaves', new Material(albedo: new Color(0.2, 0.5, 0.2), foliageWind: true));
        $this->culler = new InstancedBatchCuller();
        $this->culler->beginFrame();
    }

    protected function tearDown(): void
    {
        putenv('PHPOLYGON_NO_CULL');
        MeshRegistry::clear();
        MaterialRegistry::clear();
        ProcModeRegistry::clear();
        BatchBounds::clearCache();
    }

    /**
     * Eye at the origin looking down -Z, 60 degree square frustum, far plane at 500.
     *
     * @return list<array{0: float, 1: float, 2: float, 3: float}>
     */
    private static function view(): array
    {
        return Frustum::planes(
            Mat4::perspective(deg2rad(60.0), 1.0, 0.1, 500.0),
            Mat4::lookAt(new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 0.0, -1.0), new Vec3(0.0, 1.0, 0.0)),
        );
    }

    /**
     * A row of unit boxes along X, 2 m apart, centred on ($x, $y, $z).
     *
     * @return list<float> column-major, 16 floats per instance
     */
    private static function row(float $x, float $y, float $z, int $count = 4): array
    {
        $flat = [];
        for ($i = 0; $i < $count; $i++) {
            array_push($flat, ...Mat4::translation($x + ($i - ($count - 1) / 2) * 2.0, $y, $z)->toArray());
        }
        return $flat;
    }

    private static function staticRow(float $x, float $y, float $z, ?string $key = 'row', int $count = 4): DrawMeshInstanced
    {
        return DrawMeshInstanced::flat('box', 'stone', self::row($x, $y, $z, $count), $count, isStatic: true, staticKey: $key);
    }

    public function testBatchOutsideTheFrustumIsDroppedWithoutAnyBoundPassed(): void
    {
        $camera = new SetCamera(Mat4::identity(), Mat4::identity());
        $ahead = self::staticRow(0.0, 0.0, -20.0, 'ahead');
        $behind = self::staticRow(0.0, 0.0, 20.0, 'behind');
        $aside = self::staticRow(200.0, 0.0, -20.0, 'aside');
        $single = new DrawMesh('box', 'stone', Mat4::translation(0.0, 0.0, 20.0));

        $visible = $this->culler->visible([$camera, $ahead, $behind, $single, $aside], self::view());

        self::assertSame([$camera, $ahead, $single], $visible, 'only instanced batches are tested, the order stays');
        self::assertSame(['batches' => 3, 'culledView' => 2, 'culledShadow' => 0, 'derived' => 3], $this->culler->stats());
    }

    public function testEverythingInViewComesBackAsTheSameList(): void
    {
        $commands = [
            self::staticRow(0.0, 0.0, -20.0, 'a'),
            new DrawMesh('box', 'stone', Mat4::translation(0.0, 0.0, -5.0)),
            self::staticRow(3.0, 1.0, -60.0, 'b'),
            new DrawMeshInstanced('box', 'stone', [Mat4::translation(0.0, 0.0, -3.0), Mat4::translation(1.0, 0.0, -3.0)]),
        ];

        self::assertSame($commands, $this->culler->visible($commands, self::view()));
        self::assertSame(0, $this->culler->stats()['culledView']);
    }

    public function testBatchReachingIntoTheFrustumIsKept(): void
    {
        // Centre left of the frustum's side plane (x = -z * tan 30 = 11.5 at z = -20),
        // the last box of the row inside it.
        $straddling = self::staticRow(-15.0, 0.0, -20.0, 'straddling', 6);
        $visible = $this->culler->visible([$straddling], self::view());

        self::assertSame([$straddling], $visible);
    }

    public function testBatchBeyondTheFarPlaneIsDropped(): void
    {
        $inside = self::staticRow(0.0, 0.0, -495.0, 'inside');
        $beyond = self::staticRow(0.0, 0.0, -520.0, 'beyond');

        self::assertSame([$inside], $this->culler->visible([$inside, $beyond], self::view()));
    }

    public function testEveryCpuStorageModeIsDerived(): void
    {
        $flat = self::row(0.0, 0.0, 30.0);
        $matrices = [Mat4::translation(-1.0, 0.0, 30.0), Mat4::translation(1.0, 0.0, 30.0)];
        $commands = [
            DrawMeshInstanced::flat('box', 'stone', $flat, 4),
            DrawMeshInstanced::packed('box', 'stone', pack('f*', ...$flat), 4),
            new DrawMeshInstanced('box', 'stone', $matrices),
            new DrawMeshInstanced('box', 'stone', $matrices, isStatic: true),
        ];

        self::assertSame([], $this->culler->visible($commands, self::view()), 'flat, packed, Mat4 and unkeyed static: all behind the camera');
    }

    public function testExplicitBoundOverridesAndUnboundedIsNeverCulled(): void
    {
        $flat = self::row(0.0, 0.0, 30.0);
        // Matrices behind the camera, but the caller says the batch is ahead.
        $claimedAhead = DrawMeshInstanced::flat('box', 'stone', $flat, 4, bounds: new BatchBounds(0.0, 0.0, -30.0, 3.0));
        $pinned = DrawMeshInstanced::flat('box', 'stone', $flat, 4, bounds: BatchBounds::unbounded());
        // Matrices ahead, bound behind.
        $claimedBehind = DrawMeshInstanced::flat('box', 'stone', self::row(0.0, 0.0, -30.0), 4, bounds: new BatchBounds(0.0, 0.0, 30.0, 3.0));

        self::assertSame([$claimedAhead, $pinned], $this->culler->visible([$claimedAhead, $pinned, $claimedBehind], self::view()));
        self::assertSame(0, $this->culler->stats()['derived']);
    }

    public function testStorageBufferBatchIsCulledOnlyWithAnExplicitBound(): void
    {
        $plain = DrawMeshInstanced::fromStorageBuffer('box', 'stone', new \stdClass(), 100);
        $bounded = DrawMeshInstanced::fromStorageBuffer('box', 'stone', new \stdClass(), 100, bounds: new BatchBounds(0.0, 0.0, 50.0, 10.0));

        self::assertSame([$plain], $this->culler->visible([$plain, $bounded], self::view()));
    }

    public function testNonStaticBatchIsDerivedOnlyUpToTheLimit(): void
    {
        $max = InstancedBatchCuller::DYNAMIC_DERIVE_MAX;
        $small = DrawMeshInstanced::flat('box', 'stone', self::row(0.0, 0.0, 300.0, $max), $max);
        $large = DrawMeshInstanced::flat('box', 'stone', self::row(0.0, 0.0, 300.0, $max + 1), $max + 1);
        $largeStatic = DrawMeshInstanced::flat('box', 'stone', self::row(0.0, 0.0, 300.0, $max + 1), $max + 1, isStatic: true, staticKey: 'big');

        self::assertSame([$large], $this->culler->visible([$small, $large, $largeStatic], self::view()), 'a large non-static batch is never walked, so never culled');
    }

    public function testNonStaticBatchIsWalkedOncePerFrame(): void
    {
        $batch = DrawMeshInstanced::flat('box', 'stone', self::row(0.0, 0.0, 30.0), 4);

        $this->culler->reachesSpheres($batch, [[0.0, 0.0, 0.0, 5.0]]);
        $this->culler->reachesPlanes($batch, self::view());
        $this->culler->visible([$batch], self::view());
        self::assertSame(1, $this->culler->stats()['derived'], 'shadow and colour tests share the sphere');

        $this->culler->beginFrame();
        $this->culler->visible([$batch], self::view());
        self::assertSame(1, $this->culler->stats()['derived'], 'and it is derived again next frame: the batch may have moved');
    }

    public function testVertexAnimatedMaterialIsNotCulledWithADerivedBound(): void
    {
        MaterialRegistry::register('water_surface', new Material(albedo: new Color(0.1, 0.3, 0.5)));
        ProcModeRegistry::map('water_surface', 2);
        MaterialRegistry::register('custom', new Material(albedo: new Color(1.0, 1.0, 1.0), shader: 'my_shader'));
        $flat = self::row(0.0, 0.0, 30.0);
        $kept = [];
        foreach (['leaves', 'water_surface', 'custom', 'unregistered'] as $materialId) {
            $kept[] = DrawMeshInstanced::flat('box', $materialId, $flat, 4, isStatic: true, staticKey: $materialId);
        }
        // The caller knows how far its leaves sway: its bound is trusted.
        $bounded = DrawMeshInstanced::flat('box', 'leaves', $flat, 4, bounds: new BatchBounds(0.0, 0.0, 30.0, 6.0));

        self::assertSame($kept, $this->culler->visible([...$kept, $bounded], self::view()));
    }

    public function testUnknownMeshIsNeverCulled(): void
    {
        $batch = DrawMeshInstanced::flat('not_registered', 'stone', self::row(0.0, 0.0, 30.0), 4, isStatic: true, staticKey: 'x');

        self::assertSame([$batch], $this->culler->visible([$batch], self::view()));
    }

    public function testWithoutDerivingOnlyExplicitBoundsCull(): void
    {
        $this->culler->beginFrame(false);
        $derived = self::staticRow(0.0, 0.0, 30.0);
        $bounded = DrawMeshInstanced::flat('box', 'stone', self::row(0.0, 0.0, 30.0), 4, bounds: new BatchBounds(0.0, 0.0, 30.0, 6.0));

        self::assertSame([$derived], $this->culler->visible([$derived, $bounded], self::view()));
    }

    public function testNoCullSwitchDisablesEveryTest(): void
    {
        putenv('PHPOLYGON_NO_CULL=1');
        $this->culler->beginFrame();
        $behind = self::staticRow(0.0, 0.0, 30.0);
        $bounded = DrawMeshInstanced::flat('box', 'stone', self::row(0.0, 0.0, 30.0), 4, bounds: new BatchBounds(0.0, 0.0, 30.0, 6.0));

        self::assertSame([$behind, $bounded], $this->culler->visible([$behind, $bounded], self::view()));
        self::assertTrue($this->culler->reachesPlanes($behind, self::view()));
        self::assertTrue($this->culler->reachesSpheres($behind, [[500.0, 0.0, 0.0, 1.0]]));
        self::assertNull($this->culler->sphereOf($behind));
        self::assertSame(['batches' => 2, 'culledView' => 0, 'culledShadow' => 0, 'derived' => 0], $this->culler->stats());

        putenv('PHPOLYGON_NO_CULL');
        $this->culler->beginFrame();
        self::assertSame([], $this->culler->visible([$behind, $bounded], self::view()), 'and back on without the switch');
    }

    public function testStaticSphereSurvivesCulledFramesAndIsNotDerivedAgain(): void
    {
        // The caller keeps its float array and re-sends it, as a static batch does.
        $flat = self::row(0.0, 0.0, -20.0);
        $send = static fn (): DrawMeshInstanced => DrawMeshInstanced::flat('box', 'stone', $flat, 4, isStatic: true, staticKey: 'row');
        $ahead = self::view();
        // The same camera turned round: the row is behind it.
        $turned = Frustum::planes(
            Mat4::perspective(deg2rad(60.0), 1.0, 0.1, 500.0),
            Mat4::lookAt(new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 0.0, 1.0), new Vec3(0.0, 1.0, 0.0)),
        );

        $first = $send();
        self::assertSame([$first], $this->culler->visible([$first], $ahead));
        self::assertSame(1, $this->culler->stats()['derived']);
        $sphere = $this->culler->sphereOf($first);

        for ($frame = 0; $frame < 3; $frame++) {
            $this->culler->beginFrame();
            self::assertSame([], $this->culler->visible([$send()], $turned), 'culled while the camera looks away');
            self::assertSame(0, $this->culler->stats()['derived']);
            self::assertSame(1, $this->culler->cachedCount(), 'a culled frame evicts nothing');
        }

        $this->culler->beginFrame();
        $back = $send();
        self::assertSame([$back], $this->culler->visible([$back], $ahead), 'drawn again the first frame it is in view');
        self::assertSame(0, $this->culler->stats()['derived'], 'from the stored sphere');
        self::assertSame($sphere, $this->culler->sphereOf($back));
    }

    public function testChangedStaticBatchIsDerivedAgain(): void
    {
        $batch = self::staticRow(0.0, 0.0, -20.0);
        self::assertSame([$batch], $this->culler->visible([$batch], self::view()));

        // The same key, the instances moved behind the camera.
        $this->culler->beginFrame();
        $moved = self::staticRow(0.0, 0.0, 20.0);
        self::assertSame([], $this->culler->visible([$moved], self::view()));
        self::assertSame(1, $this->culler->stats()['derived']);

        // Equal floats in a new array are the same batch (compared whole).
        $this->culler->beginFrame();
        self::assertSame([], $this->culler->visible([self::staticRow(0.0, 0.0, 20.0)], self::view()));
        self::assertSame(0, $this->culler->stats()['derived']);

        // A mesh re-registered larger changes the sphere too.
        $this->culler->beginFrame();
        MeshRegistry::register('box', BoxMesh::generate(60.0, 1.0, 60.0));
        $grown = self::staticRow(0.0, 0.0, 20.0);
        self::assertSame([$grown], $this->culler->visible([$grown], self::view()), 'the larger mesh reaches past the camera');
        self::assertSame(1, $this->culler->stats()['derived']);
    }

    public function testStaticMat4BatchFollowsTheRenderersFingerprintRule(): void
    {
        $matrices = [Mat4::translation(-1.0, 0.0, -20.0), Mat4::translation(0.0, 0.0, -20.0), Mat4::translation(1.0, 0.0, -20.0)];
        $send = static fn (array $m): DrawMeshInstanced => new DrawMeshInstanced('box', 'stone', $m, isStatic: true, staticKey: 'mats');

        $this->culler->visible([$send($matrices)], self::view());
        self::assertSame(1, $this->culler->stats()['derived']);

        // The same Mat4 objects, then new objects with the same values.
        $this->culler->beginFrame();
        $this->culler->visible([$send($matrices)], self::view());
        $rebuilt = array_map(static fn (Mat4 $m): Mat4 => new Mat4($m->toArray()), $matrices);
        $this->culler->visible([$send($rebuilt)], self::view());
        self::assertSame(0, $this->culler->stats()['derived']);

        // Another last matrix, or another count: a different batch.
        $this->culler->beginFrame();
        $kept = $send([$matrices[0], $matrices[1], Mat4::translation(1.0, 0.0, 40.0)]);
        self::assertSame([$kept], $this->culler->visible([$kept], self::view()), 'one instance left the view, two are still in it');
        self::assertSame(1, $this->culler->stats()['derived']);
        self::assertSame([], $this->culler->visible([$send([Mat4::translation(0.0, 0.0, 30.0), Mat4::translation(0.0, 0.0, 40.0)])], self::view()));
        self::assertSame(2, $this->culler->stats()['derived']);
    }

    public function testBatchesSharingMeshAndMaterialKeepTheirOwnSphereUnderTheirKey(): void
    {
        $near = self::staticRow(0.0, 0.0, -20.0, 'near');
        $gone = self::staticRow(0.0, 0.0, 20.0, 'gone');
        for ($frame = 0; $frame < 2; $frame++) {
            $this->culler->beginFrame();
            self::assertSame([$near], $this->culler->visible([$near, $gone], self::view()));
        }
        self::assertSame(0, $this->culler->stats()['derived'], 'neither evicts the other');
        self::assertSame(2, $this->culler->cachedCount());

        $this->culler->forget('gone');
        self::assertSame(1, $this->culler->cachedCount());
        $this->culler->forget();
        self::assertSame(0, $this->culler->cachedCount());
    }

    /** The renderer's private shadow geometry, without a GPU context. */
    private static function rendererCall(string $method, mixed ...$args): mixed
    {
        $renderer = (new \ReflectionClass(VioRenderer3D::class))->newInstanceWithoutConstructor();

        return (new \ReflectionMethod(VioRenderer3D::class, $method))->invoke($renderer, ...$args);
    }

    public function testCasterOutOfViewButInsideACascadeStaysInTheShadowPass(): void
    {
        // Sun from behind the camera and above; the camera at the origin looks down -Z.
        $sun = new Vec3(0.0, -1.0, -1.0);
        /** @var list<array{0: float, 1: float, 2: float, 3: float}> $volume */
        $volume = self::rendererCall('sunCasterVolume', $sun, new Vec3(0.0, 0.0, 0.0));
        self::assertCount(4, $volume);
        $margin = (new \ReflectionClassConstant(VioRenderer3D::class, 'SHADOW_CASTER_MARGIN'))->getValue();
        self::assertIsFloat($margin);

        // 10 m behind the camera: its shadow falls forward into the view.
        $behind = self::staticRow(0.0, 2.0, 10.0, 'behind');
        // High above toward the sun, far outside any cascade's depth range.
        $tall = self::staticRow(0.0, 400.0, 400.0, 'tall');
        // Half a kilometre to the side: no cascade reaches it.
        $remote = self::staticRow(600.0, 0.0, 10.0, 'remote');

        self::assertSame([], $this->culler->visible([$behind, $tall, $remote], self::view()), 'none of them is in view');
        self::assertTrue($this->culler->reachesPlanes($behind, $volume, $margin), 'out of view, but it shadows what is in view');
        self::assertTrue($this->culler->reachesPlanes($tall, $volume, $margin), 'toward the sun there is no limit');
        self::assertFalse($this->culler->reachesPlanes($remote, $volume, $margin));
        self::assertSame(1, $this->culler->stats()['culledShadow']);
    }

    public function testNoCasterThatACascadeCouldRasteriseIsDropped(): void
    {
        $sizes = (new \ReflectionClassConstant(VioRenderer3D::class, 'CASCADE_ORTHO_SIZES'))->getValue();
        $margin = (new \ReflectionClassConstant(VioRenderer3D::class, 'SHADOW_CASTER_MARGIN'))->getValue();
        self::assertIsArray($sizes);
        self::assertIsFloat($margin);
        mt_srand(4711);
        $rand = static fn (float $lo, float $hi): float => $lo + ($hi - $lo) * mt_rand() / mt_getrandmax();
        $inCascade = 0;
        $dropped = 0;
        foreach ([new Vec3(0.0, -1.0, 0.0), new Vec3(0.3, -1.0, 0.2), new Vec3(-0.8, -0.25, 0.5), new Vec3(0.05, -0.1, -1.0)] as $sun) {
            for ($c = 0; $c < 6; $c++) {
                // Off-grid camera positions: every cascade snaps its centre differently.
                $centre = new Vec3($rand(-900.0, 900.0), $rand(-40.0, 80.0), $rand(-900.0, 900.0));
                /** @var list<array{0: float, 1: float, 2: float, 3: float}> $volume */
                $volume = self::rendererCall('sunCasterVolume', $sun, $centre);
                $cascades = [];
                foreach ($sizes as $size) {
                    /** @var array{0: Mat4, 1: Mat4} $pv */
                    $pv = self::rendererCall('computeLightProjView', $sun, $centre, $size);
                    $cascades[] = Frustum::planes($pv[0], $pv[1]);
                }
                for ($i = 0; $i < 400; $i++) {
                    $x = $centre->x + $rand(-260.0, 260.0);
                    $y = $centre->y + $rand(-260.0, 260.0);
                    $z = $centre->z + $rand(-260.0, 260.0);
                    $r = $rand(0.0, 12.0);
                    $batch = DrawMeshInstanced::flat('box', 'stone', [], 0, bounds: new BatchBounds($x, $y, $z, $r));
                    $kept = $this->culler->reachesPlanes($batch, $volume, $margin);
                    $dropped += $kept ? 0 : 1;
                    foreach ($cascades as $k => $planes) {
                        if (!Frustum::sphereOutside($planes, $x, $y, $z, $r)) {
                            $inCascade++;
                            self::assertTrue($kept, "a caster inside cascade {$k} was dropped");
                            break;
                        }
                    }
                }
            }
        }
        self::assertGreaterThan(500, $inCascade, 'the sample reaches into the cascades');
        self::assertGreaterThan(500, $dropped, 'and beyond them');
    }

    public function testLocalLightCastersAreKeptWithinTheLightsRange(): void
    {
        $white = new Color(1.0, 1.0, 1.0);
        $spheres = VioRenderer3D::localShadowLightSpheres(
            [
                new AddSpotLight(new Vec3(300.0, 4.0, 0.0), new Vec3(0.0, -1.0, 0.0), $white, 5.0, 12.0, 0.5, 0.1),
                new AddSpotLight(new Vec3(0.0, 4.0, 50.0), new Vec3(0.0, -1.0, 0.0), $white, 5.0, 12.0, 0.5, 0.1, castsShadow: true),
                new AddSpotLight(new Vec3(700.0, 4.0, 0.0), new Vec3(0.0, -1.0, 0.0), $white, 5.0, 12.0, 0.5, 0.1, castsShadow: true),
            ],
            [
                new AddPointLight(new Vec3(-80.0, 2.0, 0.0), $white, 3.0, 9.0, castsShadow: true),
                new AddPointLight(new Vec3(-200.0, 2.0, 0.0), $white, 3.0, 9.0),
                new AddPointLight(new Vec3(90.0, 2.0, 30.0), $white, 3.0, 6.0, castsShadow: true),
            ],
        );
        self::assertSame([[0.0, 4.0, 50.0, 12.0], [-80.0, 2.0, 0.0, 9.0], [90.0, 2.0, 30.0, 6.0]], $spheres,
            'the first shadow-casting spot light and every shadow-casting point light');

        // Behind the camera, under the spot light.
        $lit = self::staticRow(0.0, 0.0, 45.0, 'lit');
        // 20 m from the nearest light: out of every range.
        $dark = self::staticRow(0.0, 0.0, -20.0, 'dark');
        self::assertTrue($this->culler->reachesSpheres($lit, $spheres));
        self::assertFalse($this->culler->reachesSpheres($dark, $spheres));
        self::assertTrue($this->culler->reachesSpheres($dark, []), 'no light volume known: nothing is dropped');
    }
}
