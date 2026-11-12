<?php

declare(strict_types=1);

namespace PHPolygon\Geometry;

use PHPolygon\Animation\PoseRig;
use PHPolygon\Animation\QuatMath;

/**
 * A mesh with skinning data: up to four joint influences per vertex (joint index into
 * $jointNames, weight; the weights of a vertex sum to 1, unused slots have weight 0) and the
 * inverse bind matrices that take a bind-pose vertex into each joint's space.
 *
 * Matrices are 16 floats, column-major (translation in 12..14), like the rest of the
 * engine's flat matrices. A bone palette is the flat list of 16 floats per joint,
 * `jointWorld * inverseBind`; {@see palette()} builds it from a {@see PoseRig}.
 *
 * The CPU path here ({@see skin()}) is the reference for the GPU vertex-stage skinning
 * and the source for baked animation ({@see bakeClip()}, VatBaker). Pure and
 * deterministic.
 */
final readonly class SkinnedMeshData
{
    public const INFLUENCES = 4;

    /**
     * @param list<int>   $joints      4 joint indices per vertex
     * @param list<float> $weights     4 weights per vertex
     * @param list<string> $jointNames Joint order of the palette
     * @param list<list<float>> $inverseBind One 16-float matrix per joint
     */
    public function __construct(
        public MeshData $mesh,
        public array $joints,
        public array $weights,
        public array $jointNames,
        public array $inverseBind,
    ) {
        $n = $mesh->vertexCount();
        if (count($joints) !== $n * self::INFLUENCES || count($weights) !== $n * self::INFLUENCES) {
            throw new \InvalidArgumentException('SkinnedMeshData: need 4 joint indices and 4 weights per vertex');
        }
        if (count($inverseBind) !== count($jointNames)) {
            throw new \InvalidArgumentException('SkinnedMeshData: one inverse bind matrix per joint');
        }
        $max = count($jointNames);
        foreach ($joints as $j) {
            if ($j < 0 || $j >= $max) {
                throw new \InvalidArgumentException("SkinnedMeshData: joint index {$j} out of range");
            }
        }
    }

    /**
     * Build from named influences per vertex: the four strongest are kept and renormalised;
     * the inverse bind matrices come from the rig's rest pose.
     *
     * @param list<array<string, float>> $influences per vertex: joint name => weight
     */
    public static function fromInfluences(MeshData $mesh, array $influences, PoseRig $rig): self
    {
        if (count($influences) !== $mesh->vertexCount()) {
            throw new \InvalidArgumentException('SkinnedMeshData: one influence list per vertex');
        }
        $names = $rig->jointNames();
        $index = array_flip($names);
        $joints = [];
        $weights = [];
        foreach ($influences as $v => $inf) {
            arsort($inf);
            $inf = array_slice($inf, 0, self::INFLUENCES, true);
            $sum = array_sum($inf);
            if ($sum <= 0.0) {
                throw new \InvalidArgumentException("SkinnedMeshData: vertex {$v} has no positive weight");
            }
            $k = 0;
            foreach ($inf as $name => $w) {
                if (!isset($index[$name])) {
                    throw new \InvalidArgumentException("SkinnedMeshData: unknown joint '{$name}'");
                }
                $joints[] = $index[$name];
                $weights[] = $w / $sum;
                $k++;
            }
            for (; $k < self::INFLUENCES; $k++) {
                $joints[] = 0;
                $weights[] = 0.0;
            }
        }
        $bind = [];
        foreach (self::jointMatrices($rig, $rig->solveRaw([[], null])) as $m) {
            $bind[] = self::invertRigid($m);
        }

        return new self($mesh, $joints, $weights, $names, $bind);
    }

    /**
     * The bone palette for a raw pose ({@see \PHPolygon\Animation\Pose::raw()}): per joint
     * 16 floats, joint world matrix times inverse bind.
     *
     * @param array{0: array<string, array{0: float, 1: float, 2: float, 3: float}>, 1: array{0: float, 1: float, 2: float}|null} $rawPose
     * @return list<float>
     */
    public function palette(PoseRig $rig, array $rawPose): array
    {
        $world = self::jointMatrices($rig, $rig->solveRaw($rawPose));
        $out = [];
        foreach ($this->jointNames as $j => $name) {
            array_push($out, ...self::mul($world[$name], $this->inverseBind[$j]));
        }

        return $out;
    }

    /**
     * A palette as the little-endian float32 bytes the GPU path uploads (64 bytes per
     * joint). DrawSkinnedMesh accepts these instead of float lists: a crowd that
     * caches its packed poses skips the per-frame pack.
     *
     * @param list<float> $palette
     */
    public static function packPalette(array $palette): string
    {
        $out = '';
        foreach (array_chunk($palette, 4096) as $chunk) {
            $out .= pack('g*', ...$chunk);
        }

        return $out;
    }

    /**
     * CPU skinning: positions, normals and tangent xyz blended by the palette. Same
     * vertex order, UVs and indices as the source mesh.
     *
     * @param list<float> $palette
     */
    public function skin(array $palette): MeshData
    {
        $m = $this->mesh;
        $n = $m->vertexCount();
        $pos = $m->vertices;
        $nor = $m->normals;
        $tan = $m->tangents;
        $outP = [];
        $outN = [];
        $outT = $tan !== null ? [] : null;
        for ($v = 0; $v < $n; $v++) {
            $px = $py = $pz = $nx = $ny = $nz = $tx = $ty = $tz = 0.0;
            for ($k = 0; $k < self::INFLUENCES; $k++) {
                $w = $this->weights[$v * 4 + $k];
                if ($w === 0.0) {
                    continue;
                }
                $o = $this->joints[$v * 4 + $k] * 16;
                $x = (float) $pos[$v * 3];
                $y = (float) $pos[$v * 3 + 1];
                $z = (float) $pos[$v * 3 + 2];
                $px += $w * ($palette[$o] * $x + $palette[$o + 4] * $y + $palette[$o + 8] * $z + $palette[$o + 12]);
                $py += $w * ($palette[$o + 1] * $x + $palette[$o + 5] * $y + $palette[$o + 9] * $z + $palette[$o + 13]);
                $pz += $w * ($palette[$o + 2] * $x + $palette[$o + 6] * $y + $palette[$o + 10] * $z + $palette[$o + 14]);
                if (isset($nor[$v * 3])) {
                    $a = (float) $nor[$v * 3];
                    $b = (float) $nor[$v * 3 + 1];
                    $c = (float) $nor[$v * 3 + 2];
                    $nx += $w * ($palette[$o] * $a + $palette[$o + 4] * $b + $palette[$o + 8] * $c);
                    $ny += $w * ($palette[$o + 1] * $a + $palette[$o + 5] * $b + $palette[$o + 9] * $c);
                    $nz += $w * ($palette[$o + 2] * $a + $palette[$o + 6] * $b + $palette[$o + 10] * $c);
                }
                if ($tan !== null) {
                    $a = (float) $tan[$v * 4];
                    $b = (float) $tan[$v * 4 + 1];
                    $c = (float) $tan[$v * 4 + 2];
                    $tx += $w * ($palette[$o] * $a + $palette[$o + 4] * $b + $palette[$o + 8] * $c);
                    $ty += $w * ($palette[$o + 1] * $a + $palette[$o + 5] * $b + $palette[$o + 9] * $c);
                    $tz += $w * ($palette[$o + 2] * $a + $palette[$o + 6] * $b + $palette[$o + 10] * $c);
                }
            }
            array_push($outP, $px, $py, $pz);
            $l = sqrt($nx * $nx + $ny * $ny + $nz * $nz);
            array_push($outN, ...($l > 1e-12 ? [$nx / $l, $ny / $l, $nz / $l] : [0.0, 1.0, 0.0]));
            if ($outT !== null && $tan !== null) {
                $l = sqrt($tx * $tx + $ty * $ty + $tz * $tz);
                array_push($outT, ...($l > 1e-12 ? [$tx / $l, $ty / $l, $tz / $l] : [1.0, 0.0, 0.0]), ...[(float) $tan[$v * 4 + 3]]);
            }
        }

        return new MeshData($outP, $outN, $m->uvs, $m->indices, $outT);
    }

    /**
     * Skinned frames of a clip, sampled at $fps over its duration (a looping clip does not
     * repeat its first frame at the end). Feed the result to VatBaker::bake() (with
     * normals) for a vertex animation texture.
     *
     * @return list<MeshData>
     */
    public function bakeClip(PoseRig $rig, \PHPolygon\Animation\PoseClip $clip, float $fps): array
    {
        if ($fps <= 0.0 || $clip->duration <= 0.0) {
            throw new \InvalidArgumentException('SkinnedMeshData::bakeClip: fps and duration must be positive');
        }
        $count = max(1, (int) round($clip->duration * $fps));
        $frames = [];
        for ($f = 0; $f < $count; $f++) {
            $frames[] = $this->skin($this->palette($rig, $clip->sampleRaw($f / $fps)));
        }

        return $frames;
    }

    /**
     * Bounds that hold for every palette in $palettes (a culling box for an animation):
     * the union of the skinned bounds.
     *
     * @param list<list<float>> $palettes
     * @return array{min: array{0: float, 1: float, 2: float}, max: array{0: float, 1: float, 2: float}}
     */
    public function boundsOver(array $palettes): array
    {
        $min = [INF, INF, INF];
        $max = [-INF, -INF, -INF];
        foreach ($palettes as $p) {
            $b = $this->skin($p)->bounds();
            foreach ([0 => 'x', 1 => 'y', 2 => 'z'] as $a => $c) {
                $min[$a] = min($min[$a], $b['min']->$c);
                $max[$a] = max($max[$a], $b['max']->$c);
            }
        }

        return ['min' => $min, 'max' => $max];
    }

    /**
     * @param array<string, array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float, 6: float}> $solved
     * @return array<string, list<float>> joint name => world matrix
     */
    private static function jointMatrices(PoseRig $rig, array $solved): array
    {
        $out = [];
        foreach ($solved as $name => $j) {
            $out[$name] = self::fromQuatPos([$j[3], $j[4], $j[5], $j[6]], $j[0], $j[1], $j[2]);
        }

        return $out;
    }

    /**
     * @param array{0: float, 1: float, 2: float, 3: float} $q
     * @return list<float>
     */
    private static function fromQuatPos(array $q, float $x, float $y, float $z): array
    {
        [$ax, $ay, $az] = QuatMath::rotate($q, 1.0, 0.0, 0.0);
        [$bx, $by, $bz] = QuatMath::rotate($q, 0.0, 1.0, 0.0);
        [$cx, $cy, $cz] = QuatMath::rotate($q, 0.0, 0.0, 1.0);

        return [$ax, $ay, $az, 0.0, $bx, $by, $bz, 0.0, $cx, $cy, $cz, 0.0, $x, $y, $z, 1.0];
    }

    /**
     * Inverse of a rotation + translation matrix.
     *
     * @param list<float> $m
     * @return list<float>
     */
    private static function invertRigid(array $m): array
    {
        $tx = -($m[0] * $m[12] + $m[1] * $m[13] + $m[2] * $m[14]);
        $ty = -($m[4] * $m[12] + $m[5] * $m[13] + $m[6] * $m[14]);
        $tz = -($m[8] * $m[12] + $m[9] * $m[13] + $m[10] * $m[14]);

        return [$m[0], $m[4], $m[8], 0.0, $m[1], $m[5], $m[9], 0.0, $m[2], $m[6], $m[10], 0.0, $tx, $ty, $tz, 1.0];
    }

    /**
     * @param list<float> $a
     * @param list<float> $b
     * @return list<float> a * b, column-major
     */
    private static function mul(array $a, array $b): array
    {
        $o = [];
        for ($c = 0; $c < 4; $c++) {
            for ($r = 0; $r < 4; $r++) {
                $o[] =$a[$r] * $b[$c * 4] + $a[4 + $r] * $b[$c * 4 + 1] + $a[8 + $r] * $b[$c * 4 + 2] + $a[12 + $r] * $b[$c * 4 + 3];
            }
        }

        return $o;
    }
}
