<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Vegetation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PHPolygon\Geometry\MeshData;
use PHPolygon\Vegetation\BareStyle;
use PHPolygon\Vegetation\GrowthStage;
use PHPolygon\Vegetation\PlantForm;
use PHPolygon\Vegetation\PlantGenerator;
use PHPolygon\Vegetation\PlantLod;
use PHPolygon\Vegetation\PlantParts;
use PHPolygon\Vegetation\PlantSpec;

final class PlantGeneratorTest extends TestCase
{
    /** @return array<string, array{0: PlantSpec}> */
    public static function specs(): array
    {
        return [
            'round' => [PlantSpec::tree(PlantForm::Round, 9.0, 3.2, 13)],
            'umbrella' => [PlantSpec::tree(PlantForm::Umbrella, 8.0, 4.2, 22)],
            'column' => [PlantSpec::tree(PlantForm::Column, 11.0, 1.9, 55)],
            'weeping' => [PlantSpec::tree(PlantForm::Weeping, 8.0, 3.4, 7)],
            'conifer' => [PlantSpec::tree(PlantForm::Conifer, 10.0, 2.4, 88)],
            'palm' => [PlantSpec::palm(9.0, 5)],
            'bush' => [PlantSpec::bush(1.2, 1.4, 11)],
            'hedge' => [PlantSpec::hedge(3.0, 1.1, 0.9, 33)],
        ];
    }

    #[DataProvider('specs')]
    public function testSameSpecGivesIdenticalMeshes(PlantSpec $spec): void
    {
        foreach ([PlantLod::Lod0, PlantLod::Lod2, PlantLod::Impostor] as $lod) {
            $a = PlantGenerator::generate($spec, GrowthStage::Bloom, $lod);
            $b = PlantGenerator::generate($spec, GrowthStage::Bloom, $lod);
            foreach (['wood', 'leaf', 'accent'] as $part) {
                self::assertSame($a->{$part}->vertices, $b->{$part}->vertices, "{$part} vertices at {$lod->name}");
                self::assertSame($a->{$part}->normals, $b->{$part}->normals, "{$part} normals at {$lod->name}");
                self::assertSame($a->{$part}->indices, $b->{$part}->indices, "{$part} indices at {$lod->name}");
            }
        }
    }

    public function testAnotherSeedGivesAnotherCrown(): void
    {
        $a = PlantGenerator::generate(PlantSpec::tree(PlantForm::Round, 9.0, 3.2, 1));
        $b = PlantGenerator::generate(PlantSpec::tree(PlantForm::Round, 9.0, 3.2, 2));
        self::assertNotSame($a->leaf->vertices, $b->leaf->vertices);
    }

    public function testBroadleafTriangleBudgetPerLod(): void
    {
        // Seven clumps (the default) and nine clumps (the most the design uses).
        foreach ([7, 9] as $clumps) {
            $spec = PlantSpec::tree(PlantForm::Round, 9.0, 3.2, 13, clumpCount: $clumps);
            $lod0 = PlantGenerator::generate($spec, GrowthStage::Full, PlantLod::Lod0)->triangleCount();
            $lod1 = PlantGenerator::generate($spec, GrowthStage::Full, PlantLod::Lod1)->triangleCount();
            $lod2 = PlantGenerator::generate($spec, GrowthStage::Full, PlantLod::Lod2)->triangleCount();
            $imp = PlantGenerator::generate($spec, GrowthStage::Full, PlantLod::Impostor)->triangleCount();
            self::assertGreaterThanOrEqual(1000, $lod0, "LOD0 with {$clumps} clumps");
            self::assertLessThanOrEqual(1700, $lod0, "LOD0 with {$clumps} clumps");
            self::assertLessThanOrEqual(700, $lod1, "LOD1 with {$clumps} clumps");
            self::assertGreaterThanOrEqual(400, $lod1, "LOD1 with {$clumps} clumps");
            self::assertLessThanOrEqual(180, $lod2, "LOD2 with {$clumps} clumps");
            self::assertLessThanOrEqual(40, $imp, "impostor with {$clumps} clumps");
        }
    }

    #[DataProvider('specs')]
    public function testEveryLodIsCheaperThanTheOneBefore(PlantSpec $spec): void
    {
        $prev = PHP_INT_MAX;
        foreach (PlantLod::cases() as $lod) {
            $n = PlantGenerator::generate($spec, GrowthStage::Full, $lod)->triangleCount();
            self::assertGreaterThan(0, $n, $lod->name);
            self::assertLessThan($prev, $n, "{$lod->name} must be cheaper than the LOD before");
            $prev = $n;
        }
        self::assertLessThanOrEqual(1700, PlantGenerator::generate($spec, GrowthStage::Full, PlantLod::Lod0)->triangleCount());
    }

    #[DataProvider('specs')]
    public function testWindingAgreesWithNormalsAndNormalsAreUnit(PlantSpec $spec): void
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
                    [$agree, $total] = self::windingAgreement($mesh);
                    self::assertGreaterThanOrEqual(
                        0.97,
                        $agree / $total,
                        "{$part} at {$stage->name}/{$lod->name}: winding and normals must agree (CCW outward)",
                    );
                    for ($i = 0, $n = count($mesh->normals); $i < $n; $i += 3) {
                        $len = sqrt($mesh->normals[$i] ** 2 + $mesh->normals[$i + 1] ** 2 + $mesh->normals[$i + 2] ** 2);
                        self::assertEqualsWithDelta(1.0, $len, 1e-3, "{$part} normal {$i} is unit length");
                    }
                }
            }
        }
    }

    public function testCrownTopFacesUpAndOutward(): void
    {
        foreach (['round' => PlantForm::Round, 'umbrella' => PlantForm::Umbrella, 'weeping' => PlantForm::Weeping] as $name => $form) {
            $parts = PlantGenerator::generate(PlantSpec::tree($form, 9.0, 3.2, 3));
            $leaf = $parts->leaf;
            $up = 0;
            $total = 0;
            $outward = 0;
            $c = $parts->crownCenter;
            for ($i = 0, $n = count($leaf->vertices); $i < $n; $i += 3) {
                if ($leaf->vertices[$i + 1] < $parts->height * 0.9) {
                    continue;
                }
                $total++;
                if ($leaf->normals[$i + 1] > 0.3) {
                    $up++;
                }
                $dx = $leaf->vertices[$i] - $c->x;
                $dy = $leaf->vertices[$i + 1] - $c->y;
                $dz = $leaf->vertices[$i + 2] - $c->z;
                if ($dx * $leaf->normals[$i] + $dy * $leaf->normals[$i + 1] + $dz * $leaf->normals[$i + 2] > 0.0) {
                    $outward++;
                }
            }
            self::assertGreaterThan(10, $total, "{$name}: crown top has vertices");
            self::assertGreaterThanOrEqual(0.9, $up / $total, "{$name}: crown top normals point up");
            self::assertGreaterThanOrEqual(0.95, $outward / $total, "{$name}: crown top normals point outward");
        }
    }

    public function testPalmFrondsFaceUpOnTheirUpperSide(): void
    {
        $leaf = PlantGenerator::generate(PlantSpec::palm(9.0, 4))->leaf;
        $up = 0;
        $total = intdiv(count($leaf->normals), 3);
        for ($i = 1, $n = count($leaf->normals); $i < $n; $i += 3) {
            if ($leaf->normals[$i] > 0.0) {
                $up++;
            }
        }
        self::assertGreaterThanOrEqual(0.8, $up / $total, 'frond bands are wound with their upper side as front');
    }

    #[DataProvider('specs')]
    public function testFootSitsAtTheOriginOnTheGround(PlantSpec $spec): void
    {
        $parts = PlantGenerator::generate($spec);
        $minY = INF;
        foreach ([$parts->wood, $parts->leaf] as $mesh) {
            for ($i = 1, $n = count($mesh->vertices); $i < $n; $i += 3) {
                $minY = min($minY, $mesh->vertices[$i]);
            }
        }
        self::assertGreaterThanOrEqual(-0.2, $minY, 'sunk at most 0.2 m');
        self::assertLessThanOrEqual(0.15, $minY, 'reaches the ground');

        if ($spec->form->hasTrunk()) {
            // Everything of the trunk foot (below 0.3 m) hugs the axis.
            $r = $spec->trunkRadiusResolved() * 2.0;
            $wood = $parts->wood;
            for ($i = 0, $n = count($wood->vertices); $i < $n; $i += 3) {
                if ($wood->vertices[$i + 1] < 0.3) {
                    self::assertLessThanOrEqual($r, hypot($wood->vertices[$i], $wood->vertices[$i + 2]), 'trunk foot centred on the origin');
                }
            }
        }
    }

    #[DataProvider('specs')]
    public function testGrowthStagesAddFoliageAndAccent(PlantSpec $spec): void
    {
        $bare = PlantGenerator::generate($spec, GrowthStage::Bare);
        $sprout = PlantGenerator::generate($spec, GrowthStage::Sprouting);
        $half = PlantGenerator::generate($spec, GrowthStage::Half);
        $full = PlantGenerator::generate($spec, GrowthStage::Full);
        $bloom = PlantGenerator::generate($spec, GrowthStage::Bloom);

        self::assertSame([], $bare->leaf->indices, 'bare: no foliage');
        self::assertNotSame([], $sprout->leaf->indices, 'sprouting: some foliage');
        self::assertLessThan($full->leaf->triangleCount() + 1, $sprout->leaf->triangleCount());
        self::assertLessThanOrEqual($half->height + 1e-6, $sprout->height);
        self::assertLessThanOrEqual($full->height + 1e-6, $half->height);
        self::assertSame([], $full->accent->indices, 'full: no accent yet');
        self::assertSame($full->leaf->vertices, $bloom->leaf->vertices, 'bloom keeps the full crown');
        if ($spec->accentCount > 0) {
            self::assertNotSame([], $bloom->accent->indices, 'bloom: accent appears');
        }
    }

    public function testSaplingStandsBesideAStake(): void
    {
        $spec = PlantSpec::tree(PlantForm::Round, 9.0, 3.2, 5);
        $sprout = PlantGenerator::generate($spec, GrowthStage::Sprouting);
        $full = PlantGenerator::generate($spec, GrowthStage::Full);
        // The stake is a wooden post away from the trunk axis reaching 1.4 m+.
        self::assertGreaterThan(self::highOffAxis($full->wood, 0.15), self::highOffAxis($sprout->wood, 0.15));
        self::assertGreaterThan(1.3, self::highOffAxis($sprout->wood, 0.15));
    }

    public function testStumpStyleCutsTheTrunk(): void
    {
        $stump = PlantGenerator::generate(PlantSpec::tree(PlantForm::Round, 9.0, 3.2, 5, bareStyle: BareStyle::Stump), GrowthStage::Bare);
        self::assertLessThan(1.3, $stump->height);
        self::assertSame([], $stump->leaf->indices);
        $palm = PlantGenerator::generate(PlantSpec::palm(9.0, 5), GrowthStage::Bare);
        self::assertLessThan(1.7, $palm->height, 'palms default to a stump when bare');
    }

    public function testHedgeKeepsItsLengthAndDepth(): void
    {
        $spec = PlantSpec::hedge(4.0, 1.1, 0.8, 2);
        foreach ([PlantLod::Lod0, PlantLod::Lod1, PlantLod::Lod2] as $lod) {
            $leaf = PlantGenerator::generate($spec, GrowthStage::Full, $lod)->leaf;
            [$min, $max] = self::bounds($leaf);
            self::assertEqualsWithDelta(4.0, $max[0] - $min[0], 0.45, "length at {$lod->name}");
            self::assertEqualsWithDelta(0.8, $max[2] - $min[2], 0.2, "depth at {$lod->name}");
            self::assertEqualsWithDelta(1.1, $max[1], 0.15, "height at {$lod->name}");
        }
    }

    public function testImpostorKeepsTheSilhouetteOfLod2(): void
    {
        $spec = PlantSpec::tree(PlantForm::Round, 9.0, 3.2, 8);
        $lod2 = PlantGenerator::generate($spec, GrowthStage::Full, PlantLod::Lod2);
        $imp = PlantGenerator::generate($spec, GrowthStage::Full, PlantLod::Impostor);
        [$minA, $maxA] = self::bounds($lod2->leaf);
        [$minB, $maxB] = self::bounds($imp->leaf);
        self::assertEqualsWithDelta($maxA[1], $maxB[1], 0.6, 'top');
        self::assertEqualsWithDelta($maxA[0] - $minA[0], $maxB[0] - $minB[0], 0.8, 'width');
        self::assertSame([], $imp->accent->indices);
    }

    public function testPartsReportCrownAndTrunk(): void
    {
        $tree = PlantGenerator::generate(PlantSpec::tree(PlantForm::Round, 9.0, 3.2, 8));
        self::assertEqualsWithDelta(9.0, $tree->height, 0.6);
        self::assertGreaterThan(2.5, $tree->crownRadius);
        self::assertGreaterThan(0.0, $tree->trunkRadius);
        self::assertGreaterThan(2.0, $tree->trunkHeight, 'branch-free trunk people walk under');
        self::assertLessThan($tree->crownCenter->y, $tree->trunkHeight);
        $bush = PlantGenerator::generate(PlantSpec::bush(1.2, 1.4, 1));
        self::assertSame(0.0, $bush->trunkHeight);
    }

    public function testInvalidSpecsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PlantSpec(PlantForm::Hedge, 1.0, 0.4);
    }

    public function testStageFromProgress(): void
    {
        self::assertSame(GrowthStage::Bare, GrowthStage::fromProgress(0.0));
        self::assertSame(GrowthStage::Sprouting, GrowthStage::fromProgress(0.25));
        self::assertSame(GrowthStage::Half, GrowthStage::fromProgress(0.5));
        self::assertSame(GrowthStage::Full, GrowthStage::fromProgress(0.75));
        self::assertSame(GrowthStage::Bloom, GrowthStage::fromProgress(1.4));
    }

    /** @return array{0: int, 1: int} [triangles whose winding agrees with the vertex normals, triangles] */
    private static function windingAgreement(MeshData $m): array
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
            $dot = $cx * ($n[$a] + $n[$b] + $n[$d]) + $cy * ($n[$a + 1] + $n[$b + 1] + $n[$d + 1]) + $cz * ($n[$a + 2] + $n[$b + 2] + $n[$d + 2]);
            if ($dot > 0.0) {
                $agree++;
            }
        }
        return [$agree, max(1, $total)];
    }

    /** @return array{0: array{0: float, 1: float, 2: float}, 1: array{0: float, 1: float, 2: float}} */
    private static function bounds(MeshData $m): array
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

    /** Highest wood vertex farther than `$minR` beyond 2.5x the trunk radius from the axis below 2.5 m. */
    private static function highOffAxis(MeshData $wood, float $minR): float
    {
        $best = 0.0;
        for ($i = 0, $n = count($wood->vertices); $i < $n; $i += 3) {
            $r = hypot($wood->vertices[$i], $wood->vertices[$i + 2]);
            $y = $wood->vertices[$i + 1];
            if ($r > 0.3 + $minR && $y < 3.0) {
                $best = max($best, $y);
            }
        }
        return $best;
    }

    public function testPlantPartsAccessor(): void
    {
        $p = PlantGenerator::generate(PlantSpec::bush(1.0, 1.0, 3), GrowthStage::Bloom);
        self::assertInstanceOf(PlantParts::class, $p);
        self::assertSame($p->leaf, $p->part(\PHPolygon\Vegetation\PlantPart::Leaf));
        self::assertSame($p->accent, $p->part(\PHPolygon\Vegetation\PlantPart::Accent));
    }
}
