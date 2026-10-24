<?php

declare(strict_types=1);

namespace PHPolygon\Vegetation;

use PHPolygon\Geometry\MeshData;
use PHPolygon\Math\Vec3;
use PHPolygon\Runtime\PerfProfiler;

/**
 * Generates the plant family: broadleaf trees (trunk lathed with root flare,
 * main branches swept and tapered, crown of 5-9 deformed foliage clumps with
 * smooth normals), needle trees, palms (curved ringed trunk sweep, fronds as
 * folded bands), bushes and hedges - at every {@see GrowthStage} and
 * {@see PlantLod}.
 *
 * Deterministic: the same {@see PlantSpec} (seed included), stage and LOD
 * always produce identical buffers. Game-agnostic: every size, count and seed
 * comes from the spec; materials are chosen by the caller per {@see PlantPart}.
 *
 * Budgets for a default broadleaf tree (7 clumps): Lod0 about 1300-1600
 * triangles, Lod1 about 600, Lod2 about 140, impostor about 20.
 */
final class PlantGenerator
{
    private const float GOLDEN = 2.399963229728653;

    public static function generate(PlantSpec $spec, GrowthStage $stage = GrowthStage::Full, PlantLod $lod = PlantLod::Lod0): PlantParts
    {
        return PerfProfiler::section('mesh.generate.plant', static function () use ($spec, $stage, $lod): PlantParts {
            if ($lod === PlantLod::Impostor) {
                return self::impostor(self::generate($spec, $stage, PlantLod::Lod2));
            }
            return match ($spec->form) {
                PlantForm::Round, PlantForm::Umbrella, PlantForm::Column, PlantForm::Weeping => self::broadleaf($spec, $stage, $lod->value),
                PlantForm::Conifer => self::conifer($spec, $stage, $lod->value),
                PlantForm::Palm => self::palm($spec, $stage, $lod->value),
                PlantForm::Bush => self::bush($spec, $stage, $lod->value),
                PlantForm::Hedge => self::hedge($spec, $stage, $lod->value),
            };
        });
    }

    /** Deterministic pseudo-random value in [0, 1) for (seed, index). */
    public static function rnd(int $seed, int $index): float
    {
        return crc32($seed . ':' . $index) / 4294967296.0;
    }

    // ------------------------------------------------------------------
    //  Broadleaf
    // ------------------------------------------------------------------

    private static function broadleaf(PlantSpec $s, GrowthStage $g, int $lod): PlantParts
    {
        $seed = $s->seed;
        $size = $g->size();
        $young = match ($g) {
            GrowthStage::Sprouting => 0.45,
            GrowthStage::Half => 0.85,
            default => 1.0,
        };
        $H = $s->height * $size;
        $R = $s->crownRadius * $young;
        $r0 = $s->trunkRadiusResolved() * $young;
        $clear = $s->clearTrunkResolved() * $size;
        $wood = new PlantMeshBuilder();
        $leaf = new PlantMeshBuilder();
        $accent = new PlantMeshBuilder();

        if ($g === GrowthStage::Bare && $s->bareStyle === BareStyle::Stump) {
            $h = self::stump($wood, $r0, $seed, $lod, 0.5, 0.9);
            return self::finish($wood, $leaf, $accent, new Vec3(0.0, $h, 0.0), $r0, $h);
        }

        [$ry, $cy] = match ($s->form) {
            PlantForm::Umbrella => [$R * 0.45, $H - $R * 0.45],
            PlantForm::Column => [max(($H - $clear) * 0.5, $R), $H - max(($H - $clear) * 0.5, $R)],
            PlantForm::Weeping => [$R * 0.9, $H - $R * 0.9],
            default => [$R * 0.82, $H - $R * 0.82],
        };
        $crownBottom = $cy - $ry;
        $trunkTop = $s->form === PlantForm::Column
            ? $H * 0.82
            : max($clear + 0.3, $crownBottom + ($cy - $crownBottom) * 0.7);
        $clear = min($clear, $trunkTop - 0.2);

        // Trunk: lathed profile with root flare (sunk 0.15 m) and taper.
        $profile = $lod >= 2
            ? [[$r0 * 1.5, -0.15], [$r0, 0.5], [$r0 * 0.6, $trunkTop], [0.0, $trunkTop + $r0 * 0.4]]
            : [
                [$r0 * 1.7, -0.15], [$r0 * 1.42, 0.05], [$r0 * 1.12, 0.32], [$r0, 0.85],
                [$r0 * 0.82, $trunkTop * 0.6], [$r0 * 0.55, $trunkTop], [0.0, $trunkTop + $r0 * 0.4],
            ];
        $wood->addLathe($profile, [10, 7, 5][$lod]);

        // Main branches: tapered sweeps from the trunk into the crown.
        $bare = $g === GrowthStage::Bare;
        $branchCount = $lod >= 2 ? ($bare ? min(3, $s->branchCount) : 0) : $s->branchCount;
        if ($g === GrowthStage::Sprouting) {
            $branchCount = min($branchCount, 3);
        }
        $reach = match ($s->form) {
            PlantForm::Umbrella => 0.8,
            PlantForm::Column => 0.35,
            PlantForm::Weeping => 0.7,
            default => 0.62,
        };
        $sides = [6, 4, 3][$lod];
        $base = self::rnd($seed, 11) * 2.0 * M_PI;
        for ($b = 0; $b < $branchCount; $b++) {
            $a = $base + $b * self::GOLDEN + (self::rnd($seed, 20 + $b) - 0.5) * 0.5;
            $t = $branchCount > 1 ? $b / ($branchCount - 1) : 0.5;
            $startY = $clear + ($trunkTop - $clear) * (0.1 + 0.8 * $t);
            $endR = $R * $reach * (0.85 + 0.3 * self::rnd($seed, 30 + $b));
            $endY = match ($s->form) {
                PlantForm::Column => $startY + ($H - $startY) * 0.3,
                PlantForm::Umbrella => $cy - $ry * 0.2,
                PlantForm::Weeping => $cy + $ry * 0.1,
                default => $cy + $ry * (self::rnd($seed, 40 + $b) - 0.35) * 0.6,
            };
            $ex = cos($a) * $endR;
            $ez = sin($a) * $endR;
            $rise = $endY - $startY;
            $start = new Vec3(0.0, $startY, 0.0);
            $p1 = new Vec3($ex * 0.3, $startY + $rise * 0.55, $ez * 0.3);
            $p2 = new Vec3($ex * 0.7, $startY + $rise * 0.9, $ez * 0.7);
            $end = new Vec3($ex, $endY, $ez);
            if ($lod === 0) {
                $wood->addTube([$start, $p1, $p2, $end], [$r0 * 0.45, $r0 * 0.36, $r0 * 0.25, $r0 * 0.15], $sides);
            } else {
                $wood->addTube([$start, $p1, $end], [$r0 * 0.45, $r0 * 0.3, $r0 * 0.15], $sides);
            }
            if ($bare && $lod <= 1) {
                // Leafless: fine twigs make the crown readable from the first moment.
                $twigs = $lod === 0 ? 3 : 2;
                for ($tw = 0; $tw < $twigs; $tw++) {
                    $aa = $a + ($tw - ($twigs - 1) * 0.5) * 0.8;
                    $len = $R * (0.3 + 0.15 * self::rnd($seed, 100 + $b * 7 + $tw));
                    $tip = new Vec3($ex + cos($aa) * $len, $endY + 0.4 + $len * 0.6, $ez + sin($aa) * $len);
                    $mid = new Vec3(($ex + $tip->x) * 0.5, ($endY + $tip->y) * 0.5 + 0.1, ($ez + $tip->z) * 0.5);
                    $wood->addTube([$end, $mid, $tip], [$r0 * 0.13, $r0 * 0.08, $r0 * 0.03], 3);
                }
            }
        }

        // Crown: deformed foliage clumps.
        /** @var list<array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float}> $clumps */
        $clumps = [];
        if (!$bare) {
            $full = [$s->clumpCount, max(1, min(5, (int) ceil($s->clumpCount * 0.6))), 1][$lod];
            $n = match ($g) {
                GrowthStage::Sprouting => max(1, (int) ceil($full * 0.5)),
                GrowthStage::Half => max(1, (int) ceil($full * 0.85)),
                default => $full,
            };
            $grow = $lod === 1 ? 1.12 : 1.0;
            if ($n === 1) {
                $clumps[] = [0.0, $cy, 0.0, $R * 0.98, $ry * 0.98, $R * 0.98];
            } else {
                $clumps[] = match ($s->form) {
                    PlantForm::Column => [0.0, $cy, 0.0, $R * 0.7 * $grow, $ry * 0.55, $R * 0.7 * $grow],
                    PlantForm::Umbrella => [0.0, $cy + $ry * 0.15, 0.0, $R * 0.6 * $grow, $R * 0.33, $R * 0.6 * $grow],
                    default => [0.0, $cy + $ry * 0.12, 0.0, $R * 0.6 * $grow, $R * 0.6 * 0.85 * $grow, $R * 0.6 * $grow],
                };
                $a0 = self::rnd($seed, 50) * 2.0 * M_PI;
                for ($i = 0; $i < $n - 1; $i++) {
                    $a = $a0 + $i * self::GOLDEN;
                    $u = self::rnd($seed, 51 + $i);
                    $v = self::rnd($seed, 70 + $i);
                    switch ($s->form) {
                        case PlantForm::Column:
                            $frac = $n > 2 ? $i / ($n - 2) : 0.5;
                            $ring = $R * 0.35;
                            $y = $cy - $ry * 0.72 + $frac * $ry * 1.44;
                            $r = $R * (0.62 - 0.2 * abs($frac - 0.5)) * $grow;
                            $rv = $r * 1.15;
                            break;
                        case PlantForm::Umbrella:
                            $ring = $R * 0.6;
                            $y = $cy + ($u - 0.5) * $ry * 0.5;
                            $r = $R * (0.42 + 0.12 * $v) * $grow;
                            $rv = $r * 0.6;
                            break;
                        case PlantForm::Weeping:
                            $ring = $R * 0.66;
                            $y = $cy - $ry * 0.25 - $u * $ry * 0.35;
                            $r = $R * (0.38 + 0.12 * $v) * $grow;
                            $rv = $r * 1.25;
                            break;
                        default:
                            $ring = $R * 0.58;
                            $y = $cy + ($u - 0.45) * $ry * 0.7;
                            $r = $R * (0.42 + 0.14 * $v) * $grow;
                            $rv = $r * 0.88;
                    }
                    $clumps[] = [cos($a) * $ring, $y, sin($a) * $ring, $r, $rv, $r];
                }
            }
            [$st, $sl] = [[8, 10], [6, 9], [6, 10]][$lod];
            $rough = $lod >= 2 ? 0.1 : 0.16;
            foreach ($clumps as $k => [$x, $y, $z, $rx, $rv, $rz]) {
                $leaf->addClump($x, $y, $z, $rx, $rv, $rz, $st, $sl, $rough, $seed * 0.37 + $k * 1.7);
            }
        }

        self::addAccent($accent, $s, $g, $lod, $clumps, max(0.08, min(0.28, $R * 0.07)));
        if ($g->hasStake() && $lod <= 1) {
            self::addStake($wood, $r0, $H, $base);
        }

        // Lean: the trunk tip moves `lean * height`, the bend grows with y².
        $lean = $s->lean;
        $dx = cos($s->leanDirection);
        $dz = sin($s->leanDirection);
        $bend = static function (float $x, float $y, float $z) use ($lean, $H, $dx, $dz): array {
            if ($y <= 0.0 || $lean === 0.0) {
                return [$x, $y, $z];
            }
            $o = $lean * $y * $y / $H;
            return [$x + $dx * $o, $y, $z + $dz * $o];
        };
        $o = $lean * max(0.0, $cy) * max(0.0, $cy) / $H;
        $crown = $bare ? new Vec3($dx * $o, $cy, $dz * $o) : new Vec3($dx * $o, $cy, $dz * $o);
        return self::finish($wood, $leaf, $accent, $crown, $r0, max(0.0, $clear), $bend);
    }

    // ------------------------------------------------------------------
    //  Conifer
    // ------------------------------------------------------------------

    private static function conifer(PlantSpec $s, GrowthStage $g, int $lod): PlantParts
    {
        $seed = $s->seed;
        $size = $g->size();
        $young = match ($g) {
            GrowthStage::Sprouting => 0.5,
            GrowthStage::Half => 0.85,
            default => 1.0,
        };
        $H = $s->height * $size;
        $R = $s->crownRadius * $young;
        $r0 = $s->trunkRadiusResolved() * $young;
        $clear = $s->clearTrunkResolved() * $size;
        $wood = new PlantMeshBuilder();
        $leaf = new PlantMeshBuilder();
        $accent = new PlantMeshBuilder();

        if ($g === GrowthStage::Bare && $s->bareStyle === BareStyle::Stump) {
            $h = self::stump($wood, $r0, $seed, $lod, 0.5, 0.9);
            return self::finish($wood, $leaf, $accent, new Vec3(0.0, $h, 0.0), $r0, $h);
        }

        $wood->addLathe(
            [[$r0 * 1.6, -0.15], [$r0 * 1.2, 0.2], [$r0, 0.6], [$r0 * 0.4, $H * 0.9], [0.0, $H * 0.93]],
            [8, 6, 5][$lod],
        );

        $seedF = $seed * 0.61;
        /** @var list<array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float}> $clumps */
        $clumps = [];
        if ($g === GrowthStage::Bare) {
            // Dead whorls of short drooping branches.
            $whorls = [4, 2, 1][$lod];
            for ($w = 0; $w < $whorls; $w++) {
                $y = $clear + ($H * 0.85 - $clear) * ($w + 0.5) / $whorls;
                $len = $R * (1.0 - 0.6 * $y / $H);
                for ($b = 0; $b < 4; $b++) {
                    $a = self::rnd($seed, 200 + $w) * 2.0 * M_PI + $b * M_PI * 0.5;
                    $tip = new Vec3(cos($a) * $len, $y - $len * 0.25, sin($a) * $len);
                    $mid = new Vec3(cos($a) * $len * 0.5, $y - $len * 0.05, sin($a) * $len * 0.5);
                    $wood->addTube([new Vec3(0.0, $y, 0.0), $mid, $tip], [$r0 * 0.25, $r0 * 0.15, $r0 * 0.05], 3);
                }
            }
        } else {
            $n = [5, 3, 1][$lod];
            $seg = [12, 9, 8][$lod];
            for ($i = 0; $i < $n; $i++) {
                $frac = $i / $n;
                $yb = $clear + ($H - $clear) * $frac * 0.82;
                $top = ($i === $n - 1) ? $H : $yb + ($H - $clear) * (0.36 + 0.1 * (1.0 - $frac));
                $rr = $R * (1.0 - 0.72 * $frac);
                $th = $top - $yb;
                $tier = $i;
                $jag = static function (float $x, float $y, float $z) use ($rr, $tier, $seedF): array {
                    $rad = sqrt($x * $x + $z * $z);
                    if ($rad < 1e-6) {
                        return [$x, $y, $z];
                    }
                    $theta = atan2($z, $x);
                    $k = 1.0 + 0.1 * sin(6.0 * $theta + $tier * 1.3 + $seedF) * min(1.0, $rad / max($rr, 1e-3));
                    return [$x * $k, $y, $z * $k];
                };
                $leaf->addLathe(
                    [[0.0, $yb + $th * 0.12], [$rr * 0.9, $yb], [$rr, $yb + $th * 0.06], [$rr * 0.55, $yb + $th * 0.42], [0.0, $top]],
                    $seg,
                    $jag,
                    true,
                );
                $clumps[] = [0.0, $yb + $th * 0.25, 0.0, $rr * 0.8, $th * 0.2, $rr * 0.8];
            }
        }

        self::addAccent($accent, $s, $g, $lod, $clumps, max(0.05, min(0.14, $R * 0.05)));
        if ($g->hasStake() && $lod <= 1) {
            self::addStake($wood, $r0, $H, self::rnd($seed, 11) * 2.0 * M_PI);
        }

        $lean = $s->lean;
        $dx = cos($s->leanDirection);
        $dz = sin($s->leanDirection);
        $bend = static function (float $x, float $y, float $z) use ($lean, $H, $dx, $dz): array {
            if ($y <= 0.0 || $lean === 0.0) {
                return [$x, $y, $z];
            }
            $o = $lean * $y * $y / $H;
            return [$x + $dx * $o, $y, $z + $dz * $o];
        };
        $cy = ($clear + $H) * 0.5;
        $o = $lean * $cy * $cy / $H;
        return self::finish($wood, $leaf, $accent, new Vec3($dx * $o, $cy, $dz * $o), $r0, max($clear, 0.5), $bend);
    }

    // ------------------------------------------------------------------
    //  Palm
    // ------------------------------------------------------------------

    private static function palm(PlantSpec $s, GrowthStage $g, int $lod): PlantParts
    {
        $seed = $s->seed;
        $size = $g->size();
        $young = match ($g) {
            GrowthStage::Sprouting => 0.55,
            GrowthStage::Half => 0.85,
            default => 1.0,
        };
        $H = $s->height * $size;
        $L = $s->frondLengthResolved() * $young;
        $r0 = $s->trunkRadiusResolved() * max(0.6, $young);
        $wood = new PlantMeshBuilder();
        $leaf = new PlantMeshBuilder();
        $accent = new PlantMeshBuilder();

        if ($g === GrowthStage::Bare && $s->bareStyle === BareStyle::Stump) {
            $h = self::stump($wood, $r0, $seed, $lod, 0.9, 1.4);
            return self::finish($wood, $leaf, $accent, new Vec3(0.0, $h, 0.0), $r0, $h);
        }

        // Curved trunk: a sweep whose rings alternate in radius (leaf scars).
        $k = [26, 10, 4][$lod];
        $sides = [8, 6, 5][$lod];
        $dx = cos($s->leanDirection);
        $dz = sin($s->leanDirection);
        $lean = $s->lean;
        $path = [];
        $radii = [];
        for ($i = 0; $i < $k; $i++) {
            $t = $i / ($k - 1);
            $y = -0.15 + ($H + 0.15) * $t;
            $off = $lean * $H * (0.35 * $t + 0.65 * $t * $t);
            $path[] = new Vec3($dx * $off, $y, $dz * $off);
            $r = $r0 * (1.0 + 0.55 * max(0.0, 1.0 - $t * 8.0)) * (1.0 - 0.28 * $t);
            if ($lod === 0 && ($i % 2) === 1 && $t > 0.05 && $t < 0.97) {
                $r *= 1.1;
            }
            $radii[] = $r;
        }
        $wood->addTube($path, $radii, $sides);
        $top = $path[$k - 1];
        $wood->addClump($top->x, $top->y, $top->z, $r0 * 1.25, $r0 * 1.6, $r0 * 1.25, [6, 5, 4][$lod], [8, 7, 6][$lod], 0.05, $seed * 0.3);

        if ($g !== GrowthStage::Bare) {
            $f = [$s->frondCount, max(6, (int) round($s->frondCount * 0.6)), max(5, (int) round($s->frondCount * 0.4))][$lod];
            $f = match ($g) {
                GrowthStage::Sprouting => max(4, (int) round($f * 0.55)),
                GrowthStage::Half => max(4, (int) round($f * 0.85)),
                default => $f,
            };
            $segs = [10, 6, 3][$lod];
            $yaw0 = self::rnd($seed, 300) * 2.0 * M_PI;
            for ($i = 0; $i < $f; $i++) {
                $frond = new PlantMeshBuilder();
                $yaw = $yaw0 + $i * self::GOLDEN + (self::rnd($seed, 310 + $i) - 0.5) * 0.3;
                $elev = 0.75 - 0.9 * ($i / max(1, $f - 1)) + (self::rnd($seed, 330 + $i) - 0.5) * 0.25;
                $len = $L * (0.8 + 0.25 * self::rnd($seed, 350 + $i));
                $droop = 0.5 + 0.35 * self::rnd($seed, 370 + $i) + ($elev < 0.2 ? 0.2 : 0.0);
                $hx = cos($yaw);
                $hz = sin($yaw);
                $width = 0.27 * $len;
                $points = [];
                for ($j = 0; $j <= $segs; $j++) {
                    $u = $j / $segs;
                    $points[] = new Vec3(
                        $top->x + $hx * $u * $len * cos($elev),
                        $top->y + $u * $len * sin($elev) - $droop * $len * $u * $u,
                        $top->z + $hz * $u * $len * cos($elev),
                    );
                }
                $rows = [];
                for ($j = 0; $j <= $segs; $j++) {
                    $u = $j / $segs;
                    $p = $points[$j];
                    $q = $j < $segs ? $points[$j + 1] : $points[$j];
                    $o = $j < $segs ? $points[$j] : $points[$j - 1];
                    $tan = $q->sub($o)->normalize();
                    $side = $tan->cross(new Vec3(0.0, 1.0, 0.0));
                    if ($side->lengthSquared() < 1e-9) {
                        $side = new Vec3(-$hz, 0.0, $hx);
                    }
                    $side = $side->normalize();
                    $up = $side->cross($tan)->normalize();
                    $w = $width * (0.25 + 0.75 * sin(M_PI * min(1.0, 0.1 + $u))) * (1.0 - 0.5 * $u);
                    if ($lod === 0 && ($j % 2) === 1) {
                        $w *= 0.8; // leaflet tips
                    }
                    $fold = 0.3 * $w;
                    $l = $frond->addVertex($p->x - $side->x * $w - $up->x * $fold, $p->y - $side->y * $w - $up->y * $fold, $p->z - $side->z * $w - $up->z * $fold);
                    $c = $frond->addVertex($p->x, $p->y, $p->z);
                    $r = $frond->addVertex($p->x + $side->x * $w - $up->x * $fold, $p->y + $side->y * $w - $up->y * $fold, $p->z + $side->z * $w - $up->z * $fold);
                    $rows[] = [$l, $c, $r];
                }
                for ($j = 0; $j < $segs; $j++) {
                    [$l0, $c0, $r0v] = $rows[$j];
                    [$l1, $c1, $r1v] = $rows[$j + 1];
                    $frond->addTriangle($c0, $c1, $l0);
                    $frond->addTriangle($l0, $c1, $l1);
                    $frond->addTriangle($c0, $r0v, $c1);
                    $frond->addTriangle($r0v, $r1v, $c1);
                }
                $leaf->append(PlantMeshBuilder::smoothNormals($frond->toMeshData()));
            }
        }

        if ($g->hasAccent() && $s->accentCount > 0 && $lod <= 1) {
            $n = $lod === 0 ? $s->accentCount : max(1, intdiv($s->accentCount * 2, 3));
            $fr = max(0.08, min(0.16, $H * 0.014));
            for ($i = 0; $i < $n; $i++) {
                $a = self::rnd($seed, 400 + $i) * 2.0 * M_PI;
                $accent->addClump(
                    $top->x + cos($a) * $r0 * 1.45,
                    $top->y - $r0 * 1.2 - 0.15 * self::rnd($seed, 420 + $i),
                    $top->z + sin($a) * $r0 * 1.45,
                    $fr, $fr * 1.1, $fr,
                    $lod === 0 ? 4 : 3, $lod === 0 ? 6 : 4, 0.0, (float) $i,
                );
            }
        }
        if ($g->hasStake() && $lod <= 1) {
            self::addStake($wood, $r0, $H, self::rnd($seed, 11) * 2.0 * M_PI);
        }

        return self::finish($wood, $leaf, $accent, $top, $r0, $H);
    }

    // ------------------------------------------------------------------
    //  Bush and hedge
    // ------------------------------------------------------------------

    private static function bush(PlantSpec $s, GrowthStage $g, int $lod): PlantParts
    {
        $seed = $s->seed;
        $scale = match ($g) {
            GrowthStage::Sprouting => 0.55,
            GrowthStage::Half => 0.85,
            default => 1.0,
        };
        $h = $s->height * $scale;
        $w = $s->crownRadius * $scale;
        $wood = new PlantMeshBuilder();
        $leaf = new PlantMeshBuilder();
        $accent = new PlantMeshBuilder();
        /** @var list<array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float}> $clumps */
        $clumps = [];
        $a0 = self::rnd($seed, 60) * 2.0 * M_PI;

        if ($g === GrowthStage::Bare) {
            $n = [7, 4, 3][$lod];
            for ($i = 0; $i < $n; $i++) {
                $a = $a0 + $i * self::GOLDEN;
                $tip = new Vec3(cos($a) * $w * 0.7, $h * (0.6 + 0.3 * self::rnd($seed, 61 + $i)), sin($a) * $w * 0.7);
                $mid = new Vec3($tip->x * 0.4, $tip->y * 0.55, $tip->z * 0.4);
                $wood->addTube([new Vec3(0.0, -0.05, 0.0), $mid, $tip], [0.03 * $h + 0.01, 0.02 * $h + 0.006, 0.006], 3);
            }
            return self::finish($wood, $leaf, $accent, new Vec3(0.0, $h * 0.5, 0.0), 0.0, 0.0);
        }

        if ($lod >= 2) {
            $clumps[] = [0.0, $h * 0.5, 0.0, $w * 0.95, $h * 0.5, $w * 0.95];
        } else {
            $count = $lod === 0 ? $s->clumpCount : max(2, (int) ceil($s->clumpCount * 0.5));
            if ($g === GrowthStage::Sprouting) {
                $count = max(2, (int) ceil($count * 0.6));
            }
            $grow = $lod === 1 ? 1.12 : 1.0;
            for ($i = 0; $i < $count; $i++) {
                $a = $a0 + $i * self::GOLDEN;
                $off = $w * 0.3;
                $r = (0.42 + 0.12 * self::rnd($seed, 62 + $i)) * $w * $grow;
                $clumps[] = [cos($a) * $off, $h * 0.35 + (self::rnd($seed, 80 + $i) - 0.5) * $h * 0.08, sin($a) * $off, $r, $h * 0.45, $r];
            }
            $clumps[] = [0.0, $h * 0.62, 0.0, $w * 0.55 * $grow, $h * 0.38, $w * 0.55 * $grow];
        }
        [$st, $sl] = [[7, 9], [5, 8], [5, 8]][$lod];
        foreach ($clumps as $k => [$x, $y, $z, $rx, $ry, $rz]) {
            $leaf->addClump($x, $y, $z, $rx, $ry, $rz, $st, $sl, $lod >= 2 ? 0.1 : 0.18, $seed * 0.53 + $k * 0.7, -0.6);
        }
        self::addAccent($accent, $s, $g, $lod, $clumps, max(0.04, min(0.09, $w * 0.07)));
        return self::finish($wood, $leaf, $accent, new Vec3(0.0, $h * 0.5, 0.0), 0.0, 0.0);
    }

    private static function hedge(PlantSpec $s, GrowthStage $g, int $lod): PlantParts
    {
        $seed = $s->seed;
        $len = $s->length;
        $scale = match ($g) {
            GrowthStage::Bare => 0.45,
            GrowthStage::Sprouting => 0.5,
            GrowthStage::Half => 0.8,
            default => 1.0,
        };
        $h = $s->height * $scale;
        $d = $s->depth * match ($g) {
            GrowthStage::Sprouting => 0.7,
            GrowthStage::Half => 0.9,
            default => 1.0,
        };
        $wood = new PlantMeshBuilder();
        $leaf = new PlantMeshBuilder();
        $accent = new PlantMeshBuilder();
        /** @var list<array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float}> $clumps */
        $clumps = [];

        if ($g === GrowthStage::Bare) {
            if ($lod <= 1) {
                $n = max(2, (int) ceil($len / ($lod === 0 ? 0.35 : 0.7)));
                for ($i = 0; $i < $n; $i++) {
                    $x = -$len * 0.5 + ($i + 0.5) * $len / $n;
                    $zz = (self::rnd($seed, 500 + $i) - 0.5) * $d * 0.4;
                    $lean = (self::rnd($seed, 520 + $i) - 0.5) * 0.3;
                    $wood->addTube(
                        [new Vec3($x, -0.05, $zz), new Vec3($x + $lean * 0.5, $h * 0.5, $zz), new Vec3($x + $lean, $h, $zz + $lean * 0.3)],
                        [0.025, 0.016, 0.006],
                        3,
                    );
                }
            }
            return self::finish($wood, $leaf, $accent, new Vec3(0.0, $h * 0.5, 0.0), 0.0, 0.0);
        }

        $segLen = [1.0, 2.0, $len][$lod];
        $n = max(1, (int) round($len / $segLen));
        $cell = $len / $n;
        $rx = $n === 1 ? $len * 0.5 : $cell * 0.5 * 1.12;
        for ($i = 0; $i < $n; $i++) {
            $cx = -$len * 0.5 + ($i + 0.5) * $cell;
            if ($n > 1 && $i === 0) {
                $cx += $rx - $cell * 0.5;
            } elseif ($n > 1 && $i === $n - 1) {
                $cx -= $rx - $cell * 0.5;
            }
            $clumps[] = [$cx, $h * 0.5, 0.0, $rx, $h * 0.5, $d * 0.5];
        }
        [$st, $sl] = [[7, 10], [5, 8], [4, 8]][$lod];
        foreach ($clumps as $k => [$x, $y, $z, $cx, $cy, $cz]) {
            $leaf->addClump($x, $y, $z, $cx, $cy, $cz, $st, $sl, 0.05, $seed * 0.71 + $k * 1.3, -0.9, 0.35);
        }
        self::addAccent($accent, $s, $g, $lod, $clumps, 0.06);
        return self::finish($wood, $leaf, $accent, new Vec3(0.0, $h * 0.5, 0.0), 0.0, 0.0);
    }

    // ------------------------------------------------------------------
    //  Shared pieces
    // ------------------------------------------------------------------

    /** A cut stump with root flare; returns its height. */
    private static function stump(PlantMeshBuilder $wood, float $r0, int $seed, int $lod, float $hMin, float $hMax): float
    {
        $h = $hMin + ($hMax - $hMin) * self::rnd($seed, 90);
        $slant = 0.15 + 0.1 * self::rnd($seed, 91);
        $wood->addLathe(
            [[$r0 * 1.7, -0.15], [$r0 * 1.4, 0.05], [$r0 * 1.1, 0.3], [$r0 * 1.02, $h], [$r0 * 0.75, $h + 0.03], [0.0, $h + 0.05]],
            [10, 7, 5][$lod],
            static fn (float $x, float $y, float $z): array => [$x, $y > $h * 0.9 ? $y + $x * $slant : $y, $z],
        );
        return $h + 0.05 + $r0 * $slant;
    }

    /** Support stake with a tie, beside the trunk of a young plant. */
    private static function addStake(PlantMeshBuilder $wood, float $r0, float $height, float $angle): void
    {
        $dist = $r0 * 2.5 + 0.25;
        $sx = cos($angle) * $dist;
        $sz = sin($angle) * $dist;
        $stakeH = min(max(1.4, $height * 0.55), $height * 0.9);
        $wood->addTube([new Vec3($sx, -0.1, $sz), new Vec3($sx, $stakeH, $sz)], [0.035, 0.03], 4);
        $tieY = $stakeH * 0.85;
        $wood->addTube(
            [new Vec3($sx, $tieY, $sz), new Vec3(cos($angle) * $r0 * 0.8, $tieY, sin($angle) * $r0 * 0.8)],
            [0.018, 0.018],
            3,
        );
    }

    /**
     * Blossoms / fruit spread over the upper, outer surface of the clumps.
     *
     * @param list<array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float}> $clumps
     */
    private static function addAccent(PlantMeshBuilder $accent, PlantSpec $s, GrowthStage $g, int $lod, array $clumps, float $radius): void
    {
        if (!$g->hasAccent() || $s->accentCount <= 0 || $clumps === []) {
            return;
        }
        $n = [$s->accentCount, (int) ceil($s->accentCount * 0.5), (int) ceil($s->accentCount * 0.25)][$lod];
        [$st, $sl] = [[3, 4], [2, 4], [2, 3]][$lod];
        $count = count($clumps);
        for ($k = 0; $k < $n; $k++) {
            [$cx, $cy, $cz, $rx, $ry, $rz] = $clumps[$k % $count];
            $a = self::rnd($s->seed, 600 + $k) * 2.0 * M_PI;
            $dy = 0.1 + 0.8 * self::rnd($s->seed, 700 + $k);
            $ring = sqrt(max(0.0, 1.0 - $dy * $dy));
            $accent->addClump(
                $cx + cos($a) * $ring * $rx * 1.1,
                $cy + $dy * $ry * 1.1,
                $cz + sin($a) * $ring * $rz * 1.1,
                $radius, $radius * 0.8, $radius,
                $st, $sl, 0.0, (float) $k,
            );
        }
    }

    /**
     * @param (callable(float, float, float): array{0: float, 1: float, 2: float})|null $deform
     */
    private static function finish(
        PlantMeshBuilder $wood,
        PlantMeshBuilder $leaf,
        PlantMeshBuilder $accent,
        Vec3 $crownCenter,
        float $trunkRadius,
        float $trunkHeight,
        ?callable $deform = null,
    ): PlantParts {
        $w = $wood->toMeshData();
        $l = $leaf->toMeshData();
        $a = $accent->toMeshData();
        if ($deform !== null) {
            $w = PlantMeshBuilder::deform($w, $deform);
            $l = PlantMeshBuilder::deform($l, $deform);
            $a = PlantMeshBuilder::deform($a, $deform);
        }
        $top = 0.0;
        foreach ([$w, $l, $a] as $mesh) {
            for ($i = 1, $c = count($mesh->vertices); $i < $c; $i += 3) {
                $top = max($top, (float) $mesh->vertices[$i]);
            }
        }
        return new PlantParts(
            wood: $w,
            leaf: $l,
            accent: $a,
            height: $top,
            crownCenter: $crownCenter,
            crownRadius: self::horizontalRadius($l->vertices !== [] ? $l : $w, $crownCenter),
            trunkRadius: $trunkRadius,
            trunkHeight: $trunkHeight,
        );
    }

    private static function horizontalRadius(MeshData $mesh, Vec3 $center): float
    {
        $r = 0.0;
        $v = $mesh->vertices;
        for ($i = 0, $c = count($v); $i + 2 < $c; $i += 3) {
            $dx = (float) $v[$i] - $center->x;
            $dz = (float) $v[$i + 2] - $center->z;
            $r = max($r, $dx * $dx + $dz * $dz);
        }
        return sqrt($r);
    }

    // ------------------------------------------------------------------
    //  Impostor
    // ------------------------------------------------------------------

    /**
     * Crossed-plane impostor built from the Lod2 silhouette: two vertical
     * strips (X and Z) per part whose half-width follows the part's profile
     * over height. Normals lean outward from the crown centre so the planes
     * shade like a volume; the foliage material lights both sides.
     */
    private static function impostor(PlantParts $p): PlantParts
    {
        $wood = new PlantMeshBuilder();
        $leaf = new PlantMeshBuilder();
        $c = $p->crownCenter;
        if ($p->wood->vertices !== []) {
            if ($p->trunkRadius > 0.0 && $p->leaf->vertices !== []) {
                $topY = max(0.5, self::minY($p->leaf) + 0.2);
                $hw = $p->trunkRadius * 1.1;
                self::crossedStrip($wood, [[-0.1, $hw * 1.3], [$topY, $hw * 0.7]], 0.0, 0.0, new Vec3(0.0, $topY * 0.5, 0.0));
            } else {
                self::crossedStrip($wood, self::profile($p->wood, 0.0, 0.0, 3), 0.0, 0.0, new Vec3(0.0, $p->height * 0.5, 0.0));
            }
        }
        if ($p->leaf->vertices !== []) {
            self::crossedStrip($leaf, self::profile($p->leaf, $c->x, $c->z, 5), $c->x, $c->z, $c);
        }
        return new PlantParts(
            wood: $wood->toMeshData(),
            leaf: $leaf->toMeshData(),
            accent: new MeshData([], [], [], []),
            height: $p->height,
            crownCenter: $c,
            crownRadius: $p->crownRadius,
            trunkRadius: $p->trunkRadius,
            trunkHeight: $p->trunkHeight,
        );
    }

    private static function minY(MeshData $mesh): float
    {
        $m = INF;
        for ($i = 1, $n = count($mesh->vertices); $i < $n; $i += 3) {
            $m = min($m, (float) $mesh->vertices[$i]);
        }
        return is_finite($m) ? $m : 0.0;
    }

    /**
     * Half-width of the mesh around (cx, cz) at `$levels` heights, as
     * [y, halfWidthX, halfWidthZ] rows bottom to top.
     *
     * @return list<array{0: float, 1: float, 2?: float}>
     */
    private static function profile(MeshData $mesh, float $cx, float $cz, int $levels): array
    {
        $v = $mesh->vertices;
        $minY = INF;
        $maxY = -INF;
        for ($i = 1, $n = count($v); $i < $n; $i += 3) {
            $minY = min($minY, (float) $v[$i]);
            $maxY = max($maxY, (float) $v[$i]);
        }
        if (!is_finite($minY) || $maxY - $minY < 1e-4) {
            return [];
        }
        $step = ($maxY - $minY) / ($levels - 1);
        $rows = [];
        for ($k = 0; $k < $levels; $k++) {
            $y = $minY + $k * $step;
            $ex = 0.0;
            $ez = 0.0;
            for ($i = 0, $n = count($v); $i + 2 < $n; $i += 3) {
                if (abs((float) $v[$i + 1] - $y) <= $step * 0.6) {
                    $ex = max($ex, abs((float) $v[$i] - $cx));
                    $ez = max($ez, abs((float) $v[$i + 2] - $cz));
                }
            }
            // Ends taper to a quarter so the silhouette closes like the volume.
            if ($k === 0 || $k === $levels - 1) {
                $ex *= 0.5;
                $ez *= 0.5;
            }
            $rows[] = [$y, $ex, $ez];
        }
        return $rows;
    }

    /**
     * Two crossed vertical strips through (cx, cz).
     *
     * @param list<array{0: float, 1: float, 2?: float}> $rows [y, halfWidthX, halfWidthZ]
     */
    private static function crossedStrip(PlantMeshBuilder $out, array $rows, float $cx, float $cz, Vec3 $center): void
    {
        $count = count($rows);
        if ($count < 2) {
            return;
        }
        foreach ([0, 1] as $axis) {
            $ids = [];
            foreach ($rows as $row) {
                $y = $row[0];
                $e = $axis === 0 ? $row[1] : ($row[2] ?? $row[1]);
                foreach ([-1.0, 1.0] as $sign) {
                    // Strip in XY (normal +Z) or ZY (normal +X). "Left" first.
                    if ($axis === 0) {
                        $x = $cx + $sign * $e;
                        $z = $cz;
                        $pn = [0.0, 0.0, 1.0];
                    } else {
                        $x = $cx;
                        $z = $cz - $sign * $e;
                        $pn = [1.0, 0.0, 0.0];
                    }
                    $rx = $x - $center->x;
                    $ry = $y - $center->y;
                    $rz = $z - $center->z;
                    $rl = sqrt($rx * $rx + $ry * $ry + $rz * $rz);
                    if ($rl > 1e-6) {
                        $rx /= $rl;
                        $ry /= $rl;
                        $rz /= $rl;
                    }
                    $nx = $pn[0] * 0.45 + $rx * 0.9;
                    $ny = $pn[1] * 0.45 + $ry * 0.9;
                    $nz = $pn[2] * 0.45 + $rz * 0.9;
                    $nl = sqrt($nx * $nx + $ny * $ny + $nz * $nz);
                    if ($nl < 1e-6) {
                        [$nx, $ny, $nz] = $pn;
                        $nl = 1.0;
                    }
                    $ids[] = $out->addVertex($x, $y, $z, $nx / $nl, $ny / $nl, $nz / $nl);
                }
            }
            for ($k = 0; $k < $count - 1; $k++) {
                $l0 = $ids[$k * 2];
                $r0 = $ids[$k * 2 + 1];
                $l1 = $ids[($k + 1) * 2];
                $r1 = $ids[($k + 1) * 2 + 1];
                $out->addTriangle($l0, $r0, $r1);
                $out->addTriangle($l0, $r1, $l1);
            }
        }
    }
}
