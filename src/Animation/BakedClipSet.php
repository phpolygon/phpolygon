<?php

declare(strict_types=1);

namespace PHPolygon\Animation;

use PHPolygon\Geometry\SkinnedMeshData;
use PHPolygon\Geometry\VatBaker;

/**
 * Several clips of one skinned mesh baked into ONE vertex animation texture, for crowds:
 * every instance of a mesh plays its own clip at its own phase with the same material and
 * one draw. The rows of the texture are the frames of all clips one after the other;
 * {@see params()} gives an instance its first row, frame count and time offset, which the
 * vertex stage reads from the instance params (a Material with {@see material}'s vatInfo
 * runs in per-instance mode; needs ProcModeShaderRegistry::enableInstanceParams()).
 *
 * The bake is geometry-only data (an RGBA8 image and numbers), so it can be made in a worker
 * and cached on disk by the caller. A texture is at most 16384 texels wide on every backend:
 * two texels per vertex without and three with baked normals, so use it for crowd-level
 * meshes (about 5400 vertices with normals); {@see bake()} throws beyond that.
 */
final readonly class BakedClipSet
{
    public const MAX_WIDTH = 16384;

    /**
     * @param array{rgba: string, width: int, height: int, texelsPerVertex: int, min: array{0: float, 1: float, 2: float}, extent: array{0: float, 1: float, 2: float}} $image
     * @param array<string, array{0: int, 1: int}> $clips clip name => [first row, frame count]
     */
    private function __construct(public array $image, public array $clips, public float $fps)
    {
    }

    /**
     * @param array<string, PoseClip> $clips
     */
    public static function bake(SkinnedMeshData $skin, PoseRig $rig, array $clips, float $fps, bool $normals = true): self
    {
        if ($clips === []) {
            throw new \InvalidArgumentException('BakedClipSet: no clips');
        }
        $frames = [];
        $table = [];
        foreach ($clips as $name => $clip) {
            $own = $skin->bakeClip($rig, $clip, $fps);
            $table[$name] = [count($frames), count($own)];
            array_push($frames, ...$own);
        }
        $width = $skin->mesh->vertexCount() * ($normals ? 3 : 2);
        if ($width > self::MAX_WIDTH) {
            throw new \InvalidArgumentException("BakedClipSet: {$width} texels per row exceed " . self::MAX_WIDTH . '; bake a lower-detail mesh');
        }

        return new self(VatBaker::bake($frames, $normals), $table, $fps);
    }

    /**
     * Material::$vatInfo for the baked image: per-instance clip mode, normals when baked.
     *
     * @return list<float>
     */
    public function vatInfo(): array
    {
        $i = $this->image;

        return [
            (float) $i['height'], $this->fps, $i['min'][0], $i['min'][1], $i['min'][2],
            $i['extent'][0], $i['extent'][1], $i['extent'][2],
            (float) $i['texelsPerVertex'], 1.0,
        ];
    }

    /**
     * The three instance params of an instance playing $clip, started $phase seconds ahead
     * (DrawMesh::$params, or InstanceParams::matrix() per instance).
     *
     * @return array{0: float, 1: float, 2: float}
     */
    public function params(string $clip, float $phase = 0.0): array
    {
        if (!isset($this->clips[$clip])) {
            throw new \InvalidArgumentException("BakedClipSet: unknown clip '{$clip}'");
        }

        return [(float) $this->clips[$clip][0], (float) $this->clips[$clip][1], $phase];
    }

    /** @return list<string> */
    public function clipNames(): array
    {
        return array_keys($this->clips);
    }

    /** Duration of a clip in seconds (frames / fps). */
    public function duration(string $clip): float
    {
        return $this->clips[$clip][1] / $this->fps;
    }
}
