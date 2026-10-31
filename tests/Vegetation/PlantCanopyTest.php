<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Vegetation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PHPolygon\Geometry\MeshData;
use PHPolygon\Vegetation\GrowthStage;
use PHPolygon\Vegetation\PlantCanopy;
use PHPolygon\Vegetation\PlantForm;
use PHPolygon\Vegetation\PlantGenerator;
use PHPolygon\Vegetation\PlantLod;
use PHPolygon\Vegetation\PlantParts;
use PHPolygon\Vegetation\PlantSpec;
use PHPolygon\Vegetation\PlantVariant;

/**
 * Open crowns: gaps you can see through, wood inside the crown, the budgets of
 * the closed clumps. The gap and wood checks render the plant with a small
 * orthographic depth-buffer rasteriser, so they measure what a viewer sees.
 */
final class PlantCanopyTest extends TestCase
{
    /** @return array<string, array{0: PlantSpec}> */
    public static function canopySpecs(): array
    {
        $c = new PlantCanopy();
        return [
            'round' => [PlantSpec::tree(PlantForm::Round, 8.5, 3.0, 3, clearTrunk: 3.0, branchCount: 7, canopy: $c)],
            'round wide' => [PlantSpec::tree(PlantForm::Round, 8.5, 3.4, 17, clearTrunk: 2.8, branchCount: 6, canopy: $c)],
            'umbrella' => [PlantSpec::tree(PlantForm::Umbrella, 9.0, 3.2, 43, clearTrunk: 3.0, branchCount: 7, canopy: $c)],
            'column' => [PlantSpec::tree(PlantForm::Column, 10.0, 1.5, 50, clearTrunk: 1.8, canopy: $c)],
            'weeping' => [PlantSpec::tree(PlantForm::Weeping, 8.0, 3.4, 7, canopy: $c)],
            'bush' => [PlantSpec::bush(1.2, 1.8, 7, canopy: $c)],
            'hedge' => [PlantSpec::hedge(1.4, 1.2, 0.8, 5, canopy: $c)],
        ];
    }

    /** @return array<string, array{0: PlantSpec}> */
    public static function canopyTrees(): array
    {
        return array_filter(self::canopySpecs(), static fn (array $s): bool => $s[0]->form->isBroadleaf());
    }

    #[DataProvider('canopySpecs')]
    public function testSameSeedSameMeshAnotherSeedAnother(PlantSpec $spec): void
    {
        foreach (PlantLod::cases() as $lod) {
            $a = PlantGenerator::generate($spec, GrowthStage::Bloom, $lod);
            $b = PlantGenerator::generate($spec, GrowthStage::Bloom, $lod);
            self::assertSame($a->leaf->vertices, $b->leaf->vertices, $lod->name);
            self::assertSame($a->leaf->normals, $b->leaf->normals, $lod->name);
            self::assertSame($a->leaf->uvs, $b->leaf->uvs, $lod->name);
            self::assertSame($a->wood->vertices, $b->wood->vertices, $lod->name);
        }
        $other = new PlantSpec(
            form: $spec->form,
            height: $spec->height,
            crownRadius: $spec->crownRadius,
            seed: $spec->seed + 1,
            length: $spec->length,
            depth: $spec->depth,
            canopy: $spec->canopy,
        );
        self::assertNotSame(PlantGenerator::generate($spec)->leaf->vertices, PlantGenerator::generate($other)->leaf->vertices);
    }

    /** The open crown costs what the closed one cost, and every LOD is cheaper than the last. */
    #[DataProvider('canopyTrees')]
    public function testTreeBudgetsPerLod(PlantSpec $spec): void
    {
        self::assertLessThanOrEqual(1250, PlantGenerator::generate($spec, GrowthStage::Full, PlantLod::Lod0)->triangleCount());
        self::assertLessThanOrEqual(600, PlantGenerator::generate($spec, GrowthStage::Full, PlantLod::Lod1)->triangleCount());
        self::assertLessThanOrEqual(180, PlantGenerator::generate($spec, GrowthStage::Full, PlantLod::Lod2)->triangleCount());
        self::assertLessThanOrEqual(40, PlantGenerator::generate($spec, GrowthStage::Full, PlantLod::Impostor)->triangleCount());
    }

    #[DataProvider('canopySpecs')]
    public function testEveryLodIsCheaper(PlantSpec $spec): void
    {
        $prev = PHP_INT_MAX;
        foreach (PlantLod::cases() as $lod) {
            $n = PlantGenerator::generate($spec, GrowthStage::Full, $lod)->triangleCount();
            self::assertGreaterThan(0, $n, $lod->name);
            self::assertLessThan($prev, $n, $lod->name);
            $prev = $n;
        }
    }

    #[DataProvider('canopySpecs')]
    public function testWindingAgreesWithNormalsSoBacksStayLit(PlantSpec $spec): void
    {
        foreach (GrowthStage::cases() as $stage) {
            foreach (PlantLod::cases() as $lod) {
                $parts = PlantGenerator::generate($spec, $stage, $lod);
                foreach (['wood', 'leaf', 'accent'] as $part) {
                    $mesh = $parts->{$part};
                    self::assertInstanceOf(MeshData::class, $mesh);
                    if ($mesh->indices === []) {
                        continue;
                    }
                    self::assertGreaterThanOrEqual(0.97, self::windingAgreement($mesh), "{$part} at {$stage->name}/{$lod->name}");
                    for ($i = 0, $n = count($mesh->normals); $i < $n; $i += 3) {
                        $len = sqrt($mesh->normals[$i] ** 2 + $mesh->normals[$i + 1] ** 2 + $mesh->normals[$i + 2] ** 2);
                        self::assertEqualsWithDelta(1.0, $len, 1e-3, "{$part} normal {$i}");
                    }
                }
            }
        }
    }

    #[DataProvider('canopySpecs')]
    public function testStaysInsideTheSpeccedSize(PlantSpec $spec): void
    {
        foreach ([PlantLod::Lod0, PlantLod::Lod1, PlantLod::Lod2] as $lod) {
            $p = PlantGenerator::generate($spec, GrowthStage::Full, $lod);
            self::assertEqualsWithDelta($spec->height, $p->height, max(0.25, $spec->height * 0.16), "height at {$lod->name}");
            if ($spec->form === PlantForm::Hedge) {
                continue;
            }
            self::assertLessThanOrEqual($spec->crownRadius * 1.5, $p->crownRadius, "crown at {$lod->name}");
            self::assertGreaterThanOrEqual($spec->crownRadius * 0.85, $p->crownRadius, "crown at {$lod->name}");
        }
    }

    /** Sky through the crown, seen from the side and from below; a closed crown shows next to none. */
    #[DataProvider('canopyTrees')]
    public function testGapsShowThroughTheCrown(PlantSpec $spec): void
    {
        $open = PlantGenerator::generate($spec);
        // Along its axis a slim crown stacks up, whatever is open.
        foreach ($spec->form === PlantForm::Column ? [0] : [0, 1] as $axis) {
            $gaps = self::gapShare($open, $axis);
            self::assertGreaterThan(0.08, $gaps, "open crown along axis {$axis}");
            self::assertLessThan(0.6, $gaps, "still a crown along axis {$axis}");
        }
        $gaps1 = self::gapShare(PlantGenerator::generate($spec, GrowthStage::Full, PlantLod::Lod1), 0);
        self::assertGreaterThan(0.05, $gaps1, 'LOD1 keeps gaps');
    }

    public function testClosedClumpsAreTheBaselineOfTheGapMeasure(): void
    {
        $closed = PlantGenerator::generate(PlantSpec::tree(PlantForm::Round, 8.5, 3.0, 3, clearTrunk: 3.0, clumpCount: 6, branchCount: 7));
        $open = PlantGenerator::generate(PlantSpec::tree(PlantForm::Round, 8.5, 3.0, 3, clearTrunk: 3.0, clumpCount: 6, branchCount: 7, canopy: new PlantCanopy()));
        self::assertLessThan(0.06, self::gapShare($closed, 0), 'closed ball');
        self::assertGreaterThan(self::gapShare($closed, 0) * 3.0, self::gapShare($open, 0));
    }

    /** Wood in the crown: tips far out and high up, and they show from outside. */
    #[DataProvider('canopyTrees')]
    public function testBranchesReachIntoTheCrownAndShow(PlantSpec $spec): void
    {
        $p = PlantGenerator::generate($spec);
        $c = $p->crownCenter;
        $outer = 0.0;
        $high = 0.0;
        for ($i = 0, $n = count($p->wood->vertices); $i < $n; $i += 3) {
            $outer = max($outer, hypot($p->wood->vertices[$i] - $c->x, $p->wood->vertices[$i + 2] - $c->z));
            $high = max($high, $p->wood->vertices[$i + 1]);
        }
        self::assertGreaterThan($spec->crownRadius * 0.5, $outer, 'branches reach out into the crown');
        self::assertGreaterThan($c->y, $high, 'branches reach above the crown centre');
        self::assertGreaterThan(30, self::visibleWoodInCrown($p, 0), 'wood visible between the tufts');
    }

    public function testFromBelowMoreWoodShowsThanUnderAClosedCrown(): void
    {
        $args = [PlantForm::Round, 8.5, 3.0, 3];
        $closed = PlantGenerator::generate(PlantSpec::tree(...$args, clearTrunk: 3.0, branchCount: 7));
        $open = PlantGenerator::generate(PlantSpec::tree(...$args, clearTrunk: 3.0, branchCount: 7, canopy: new PlantCanopy()));
        self::assertGreaterThan(
            self::visibleWoodInCrown($closed, 0) * 2.0,
            self::visibleWoodInCrown($open, 0),
            'side view: wood between the tufts instead of under the ball',
        );
    }

    public function testLod2KeepsBranchesAndTheChainNeverGoesBald(): void
    {
        $spec = self::canopySpecs()['round'][0];
        $closedWood = PlantGenerator::generate(PlantSpec::tree(PlantForm::Round, 8.5, 3.0, 3, clearTrunk: 3.0, branchCount: 7), GrowthStage::Full, PlantLod::Lod2)->wood;
        $lod2 = PlantGenerator::generate($spec, GrowthStage::Full, PlantLod::Lod2);
        self::assertGreaterThan($closedWood->triangleCount(), $lod2->wood->triangleCount(), 'LOD2 has branches');
        self::assertGreaterThan(40, $lod2->leaf->triangleCount(), 'LOD2 silhouette of tufts');
    }

    public function testLod1DrawsTheSameWoodAsLod0(): void
    {
        $spec = self::canopySpecs()['round'][0];
        foreach ([GrowthStage::Bare, GrowthStage::Sprouting, GrowthStage::Half, GrowthStage::Full] as $stage) {
            $a = PlantGenerator::generate($spec, $stage, PlantLod::Lod0);
            $b = PlantGenerator::generate($spec, $stage, PlantLod::Lod1);
            self::assertSame($a->wood->vertices, $b->wood->vertices, $stage->name);
            self::assertSame($a->wood->indices, $b->wood->indices, $stage->name);
        }
    }

    public function testWoodLodSharesOnlyLod0AndLod1OfCanopyTrees(): void
    {
        $canopy = new PlantVariant('a', self::canopySpecs()['round'][0], 'w', 'l');
        self::assertSame(PlantLod::Lod0, $canopy->woodLod(PlantLod::Lod0));
        self::assertSame(PlantLod::Lod0, $canopy->woodLod(PlantLod::Lod1));
        self::assertSame(PlantLod::Lod2, $canopy->woodLod(PlantLod::Lod2));
        self::assertSame(PlantLod::Impostor, $canopy->woodLod(PlantLod::Impostor));
        $closed = new PlantVariant('b', PlantSpec::tree(PlantForm::Round, 9.0, 3.2, 1), 'w', 'l');
        self::assertSame(PlantLod::Lod1, $closed->woodLod(PlantLod::Lod1));
    }

    /** One draw at the far end: the stem rides in the foliage mesh. */
    #[DataProvider('canopySpecs')]
    public function testImpostorIsOneMesh(PlantSpec $spec): void
    {
        $p = PlantGenerator::generate($spec, GrowthStage::Full, PlantLod::Impostor);
        self::assertSame([], $p->wood->indices);
        self::assertNotSame([], $p->leaf->indices);
    }

    public function testCompactImpostorOfAPalmAndTheDefaultStaysTwoParts(): void
    {
        $plain = PlantGenerator::generate(PlantSpec::palm(5.6, 11), GrowthStage::Full, PlantLod::Impostor);
        $compact = PlantGenerator::generate(PlantSpec::palm(5.6, 11, compactImpostor: true), GrowthStage::Full, PlantLod::Impostor);
        self::assertNotSame([], $plain->wood->indices);
        self::assertSame([], $compact->wood->indices);
        self::assertSame($plain->triangleCount(), $compact->triangleCount());
    }

    public function testTuftsCarryPhaseAndTipInTheirUvs(): void
    {
        $leaf = PlantGenerator::generate(self::canopySpecs()['round'][0])->leaf;
        $phases = [];
        $tips = 0;
        for ($i = 0, $n = count($leaf->uvs); $i < $n; $i += 2) {
            $phases[sprintf('%.6f', $leaf->uvs[$i])] = true;
            self::assertGreaterThanOrEqual(0.0, $leaf->uvs[$i]);
            self::assertLessThan(1.0, $leaf->uvs[$i]);
            self::assertGreaterThanOrEqual(0.0, $leaf->uvs[$i + 1]);
            self::assertLessThanOrEqual(1.0, $leaf->uvs[$i + 1]);
            if ($leaf->uvs[$i + 1] === 1.0) {
                $tips++;
            }
        }
        self::assertGreaterThan(80, count($phases), 'a phase per tuft (90 tufts)');
        self::assertSame(90 * 5, $tips, 'five leaf tips per tuft');
    }

    public function testGrowthAddsTuftsWithoutMovingTheFirstOnes(): void
    {
        $spec = PlantSpec::bush(1.2, 1.8, 3, canopy: new PlantCanopy());
        // The bush keeps its size from Half on; sprouting shrinks it, Half and Full only differ in count.
        $half = PlantGenerator::generate($spec, GrowthStage::Full)->leaf;
        $bloom = PlantGenerator::generate($spec, GrowthStage::Bloom)->leaf;
        self::assertSame($half->vertices, $bloom->vertices);
        $tree = self::canopySpecs()['round'][0];
        $sprout = PlantGenerator::generate($tree, GrowthStage::Sprouting)->leaf->triangleCount();
        $middle = PlantGenerator::generate($tree, GrowthStage::Half)->leaf->triangleCount();
        $full = PlantGenerator::generate($tree, GrowthStage::Full)->leaf->triangleCount();
        self::assertLessThan($middle, $sprout);
        self::assertLessThan($full, $middle);
    }

    public function testBareCanopyTreeKeepsItsBranchesAndTwigs(): void
    {
        $bare = PlantGenerator::generate(self::canopySpecs()['round'][0], GrowthStage::Bare);
        self::assertSame([], $bare->leaf->indices);
        self::assertGreaterThan(PlantGenerator::generate(self::canopySpecs()['round'][0])->wood->triangleCount(), $bare->wood->triangleCount(), 'twigs on top');
    }

    public function testHedgeOutlineIsBrokenAndKeepsItsSize(): void
    {
        $spec = PlantSpec::hedge(4.0, 1.1, 0.8, 2, canopy: new PlantCanopy());
        foreach ([PlantLod::Lod0, PlantLod::Lod1, PlantLod::Lod2] as $lod) {
            [$min, $max] = self::boundsOf(PlantGenerator::generate($spec, GrowthStage::Full, $lod)->leaf);
            self::assertEqualsWithDelta(4.0, $max[0] - $min[0], 0.6, "length at {$lod->name}");
            self::assertEqualsWithDelta(0.8, $max[2] - $min[2], 0.42, "depth at {$lod->name}");
            self::assertEqualsWithDelta(1.1, $max[1], 0.22, "height at {$lod->name}");
        }
        // Crest line: height of the highest leaf per 20 cm along the hedge.
        $leaf = PlantGenerator::generate($spec)->leaf;
        $crest = [];
        for ($i = 0, $n = count($leaf->vertices); $i < $n; $i += 3) {
            $slot = (int) floor(($leaf->vertices[$i] + 2.0) / 0.2);
            $crest[$slot] = max($crest[$slot] ?? -INF, $leaf->vertices[$i + 1]);
        }
        self::assertGreaterThan(0.08, max($crest) - min($crest), 'the crest is no straight line');

        $closed = PlantGenerator::generate(PlantSpec::hedge(4.0, 1.1, 0.8, 2))->leaf;
        self::assertGreaterThan(self::silhouetteEdges($closed), self::silhouetteEdges($leaf), 'leaf points break the outline');
    }

    public function testInvalidCanopiesAreRejected(): void
    {
        foreach ([
            static fn () => new PlantCanopy(tufts: 0),
            static fn () => new PlantCanopy(groups: 0),
            static fn () => new PlantCanopy(tuftSize: 0.0),
            static fn () => new PlantCanopy(points: 2),
            static fn () => new PlantCanopy(hollow: 0.95),
        ] as $i => $make) {
            try {
                $make();
                self::fail("case {$i} accepted");
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testOptionsReachTheMesh(): void
    {
        $few = PlantGenerator::generate(PlantSpec::tree(PlantForm::Round, 9.0, 3.2, 1, canopy: new PlantCanopy(tufts: 30, points: 4)));
        self::assertSame(30 * 8, $few->leaf->triangleCount());
        $small = PlantGenerator::generate(PlantSpec::tree(PlantForm::Round, 9.0, 3.2, 1, canopy: new PlantCanopy(tuftSize: 0.5)));
        $large = PlantGenerator::generate(PlantSpec::tree(PlantForm::Round, 9.0, 3.2, 1, canopy: new PlantCanopy(tuftSize: 1.4)));
        self::assertGreaterThan(self::gapShare($large, 0) + 0.1, self::gapShare($small, 0));
    }

    /**
     * Share of the crown's outline that shows sky, along a view axis (0 = side
     * along Z, 1 = from straight below).
     */
    private static function gapShare(PlantParts $p, int $axis): float
    {
        [$leaf] = self::raster($p, $axis);
        $n = count($leaf);
        // Outline: per row, everything between the leftmost and rightmost covered cell.
        $inside = 0;
        $holes = 0;
        for ($y = 0; $y < $n; $y++) {
            $first = null;
            $last = null;
            for ($x = 0; $x < $n; $x++) {
                if ($leaf[$y][$x]) {
                    $first ??= $x;
                    $last = $x;
                }
            }
            if ($first === null) {
                continue;
            }
            for ($x = $first; $x <= $last; $x++) {
                $inside++;
                $holes += $leaf[$y][$x] ? 0 : 1;
            }
        }
        return $inside > 0 ? $holes / $inside : 0.0;
    }

    /** Cells where wood is the nearest surface, within the height of the crown. */
    private static function visibleWoodInCrown(PlantParts $p, int $axis): int
    {
        [, $wood] = self::raster($p, $axis);
        $n = 0;
        foreach ($wood as $row) {
            $n += array_sum($row);
        }
        return $n;
    }

    /**
     * Orthographic raster of the crown region on a 120 x 120 grid with a depth
     * buffer.
     *
     * @return array{0: list<list<bool>>, 1: list<list<int>>} leaf coverage (any depth), wood nearest
     */
    private static function raster(PlantParts $p, int $axis): array
    {
        [$minB, $maxB] = self::boundsOf($p->leaf);
        // u, v, depth per axis; the viewer looks from +depth toward -depth.
        $map = $axis === 0
            ? static fn (float $x, float $y, float $z): array => [$x, $y, $z]
            : static fn (float $x, float $y, float $z): array => [$x, $z, -$y];
        [$u0, $v0] = $axis === 0 ? [$minB[0], $minB[1]] : [$minB[0], $minB[2]];
        [$u1, $v1] = $axis === 0 ? [$maxB[0], $maxB[1]] : [$maxB[0], $maxB[2]];
        $size = 120;
        $su = ($size - 1) / max(1e-3, $u1 - $u0);
        $sv = ($size - 1) / max(1e-3, $v1 - $v0);
        $depth = array_fill(0, $size, array_fill(0, $size, -INF));
        $owner = array_fill(0, $size, array_fill(0, $size, 0));
        foreach ([1 => $p->leaf, 2 => $p->wood] as $id => $mesh) {
            $v = $mesh->vertices;
            for ($t = 0, $c = count($mesh->indices); $t + 2 < $c; $t += 3) {
                $tri = [];
                foreach ([0, 1, 2] as $k) {
                    $o = $mesh->indices[$t + $k] * 3;
                    [$a, $b, $d] = $map($v[$o], $v[$o + 1], $v[$o + 2]);
                    $tri[] = [($a - $u0) * $su, ($b - $v0) * $sv, $d];
                }
                self::fill($tri, $id, $size, $depth, $owner);
            }
        }
        $leaf = [];
        $wood = [];
        foreach ($owner as $y => $row) {
            foreach ($row as $x => $id) {
                $leaf[$y][$x] = $id !== 0;
                $wood[$y][$x] = $id === 2 ? 1 : 0;
            }
        }
        return [$leaf, $wood];
    }

    /**
     * @param list<array{0: float, 1: float, 2: float}> $tri
     * @param list<list<float>> $depth
     * @param list<list<int>> $owner
     */
    private static function fill(array $tri, int $id, int $size, array &$depth, array &$owner): void
    {
        [[$ax, $ay, $az], [$bx, $by, $bz], [$cx, $cy, $cz]] = $tri;
        $area = ($bx - $ax) * ($cy - $ay) - ($by - $ay) * ($cx - $ax);
        if (abs($area) < 1e-9) {
            return;
        }
        $x0 = max(0, (int) floor(min($ax, $bx, $cx)));
        $x1 = min($size - 1, (int) ceil(max($ax, $bx, $cx)));
        $y0 = max(0, (int) floor(min($ay, $by, $cy)));
        $y1 = min($size - 1, (int) ceil(max($ay, $by, $cy)));
        for ($y = $y0; $y <= $y1; $y++) {
            for ($x = $x0; $x <= $x1; $x++) {
                $w0 = (($bx - $x) * ($cy - $y) - ($by - $y) * ($cx - $x)) / $area;
                $w1 = (($cx - $x) * ($ay - $y) - ($cy - $y) * ($ax - $x)) / $area;
                $w2 = 1.0 - $w0 - $w1;
                if ($w0 < 0.0 || $w1 < 0.0 || $w2 < 0.0) {
                    continue;
                }
                $z = $w0 * $az + $w1 * $bz + $w2 * $cz;
                if ($z > $depth[$y][$x]) {
                    $depth[$y][$x] = $z;
                    $owner[$y][$x] = $id;
                }
            }
        }
    }

    private static function windingAgreement(MeshData $m): float
    {
        $v = $m->vertices;
        $n = $m->normals;
        $agree = 0;
        $total = 0;
        for ($t = 0, $c = count($m->indices); $t + 2 < $c; $t += 3) {
            $a = $m->indices[$t] * 3;
            $b = $m->indices[$t + 1] * 3;
            $d = $m->indices[$t + 2] * 3;
            $ux = $v[$b] - $v[$a];
            $uy = $v[$b + 1] - $v[$a + 1];
            $uz = $v[$b + 2] - $v[$a + 2];
            $wx = $v[$d] - $v[$a];
            $wy = $v[$d + 1] - $v[$a + 1];
            $wz = $v[$d + 2] - $v[$a + 2];
            $cx = $uy * $wz - $uz * $wy;
            $cy = $uz * $wx - $ux * $wz;
            $cz = $ux * $wy - $uy * $wx;
            if ($cx * $cx + $cy * $cy + $cz * $cz < 1e-14) {
                continue;
            }
            $total++;
            if ($cx * ($n[$a] + $n[$b] + $n[$d]) + $cy * ($n[$a + 1] + $n[$b + 1] + $n[$d + 1]) + $cz * ($n[$a + 2] + $n[$b + 2] + $n[$d + 2]) > 0.0) {
                $agree++;
            }
        }
        return $agree / max(1, $total);
    }

    /** @return array{0: array{0: float, 1: float, 2: float}, 1: array{0: float, 1: float, 2: float}} */
    private static function boundsOf(MeshData $m): array
    {
        $min = [INF, INF, INF];
        $max = [-INF, -INF, -INF];
        for ($i = 0, $n = count($m->vertices); $i < $n; $i += 3) {
            for ($k = 0; $k < 3; $k++) {
                $min[$k] = min($min[$k], $m->vertices[$i + $k]);
                $max[$k] = max($max[$k], $m->vertices[$i + $k]);
            }
        }
        return [$min, $max];
    }

    /** Direction changes along the side outline (top edge per column): a measure of how broken it is. */
    private static function silhouetteEdges(MeshData $m): int
    {
        $top = [];
        for ($i = 0, $n = count($m->vertices); $i < $n; $i += 3) {
            $slot = (int) floor(($m->vertices[$i] + 2.5) / 0.1);
            $top[$slot] = max($top[$slot] ?? -INF, $m->vertices[$i + 1]);
        }
        ksort($top);
        $changes = 0;
        $last = 0;
        $prev = null;
        foreach ($top as $h) {
            if ($prev !== null && abs($h - $prev) > 0.02) {
                $sign = $h > $prev ? 1 : -1;
                if ($sign !== $last) {
                    $changes++;
                    $last = $sign;
                }
            }
            $prev = $h;
        }
        return $changes;
    }
}
