<?php

declare(strict_types=1);

namespace PHPolygon\Prefab\Furniture;

use PHPolygon\Component\BoxCollider3D;
use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\Transform3D;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;
use PHPolygon\Scene\SceneBuilder;

class WindowBuilder
{
    private string $prefix = '';
    private string $style;
    private float $width;
    private float $height;
    private float $barThickness;
    private bool $hasFrame = false;
    private float $frameWidth = 0.04;
    private float $frameDepth = 0.08;

    private function __construct(string $style, float $width, float $height)
    {
        $this->style = $style;
        $this->width = $width;
        $this->height = $height;
        $this->barThickness = 0.02;
    }

    /** Cross-bar window (horizontal + vertical bar) */
    public static function cross(float $width = 0.7, float $height = 0.5): self
    {
        return new self('cross', $width, $height);
    }

    /** Horizontal bars only */
    public static function horizontal(float $width = 0.7, float $height = 0.5, int $bars = 2): self
    {
        $inst = new self('horizontal', $width, $height);
        return $inst;
    }

    public function withFrame(float $frameWidth = 0.04, float $frameDepth = 0.08): self
    {
        $this->hasFrame = true;
        $this->frameWidth = $frameWidth;
        $this->frameDepth = $frameDepth;
        return $this;
    }

    public function withPrefix(string $prefix): self
    {
        $this->prefix = $prefix;
        return $this;
    }

    public function build(SceneBuilder $builder, Vec3 $position, Quaternion $rotation, FurnitureMaterials $materials): FurnitureResult
    {
        $names = [];
        $p = $this->prefix;
        $bt = $this->barThickness;

        // Cross bars
        $builder->entity("{$p}_WindowH")
            ->with(new Transform3D(
                position: $position,
                rotation: $rotation,
                scale: new Vec3($bt * 0.5, $bt * 0.5, $this->width * 0.5),
            ))
            ->with(new MeshRenderer(meshId: 'cylinder', materialId: $materials->primary));
        $names[] = "{$p}_WindowH";

        if ($this->style !== 'horizontal') {
            $builder->entity("{$p}_WindowV")
                ->with(new Transform3D(
                    position: $position,
                    rotation: $rotation,
                    scale: new Vec3($bt * 0.5, $this->height * 0.5, $bt * 0.5),
                ))
                ->with(new MeshRenderer(meshId: 'cylinder', materialId: $materials->primary));
            $names[] = "{$p}_WindowV";
        }

        if ($this->hasFrame) {
            $fw = $this->frameWidth;
            $fd = $this->frameDepth;
            $hw = $this->width * 0.5;
            $hh = $this->height * 0.5;

            // Frame: left, right, top, bottom
            $frameParts = [
                ['L', new Vec3(-$hw - $fw * 0.5, 0.0, 0.0), new Vec3($fw * 0.5, $hh + $fw, $fd * 0.5)],
                ['R', new Vec3($hw + $fw * 0.5, 0.0, 0.0), new Vec3($fw * 0.5, $hh + $fw, $fd * 0.5)],
                ['T', new Vec3(0.0, $hh + $fw * 0.5, 0.0), new Vec3($hw, $fw * 0.5, $fd * 0.5)],
                ['B', new Vec3(0.0, -$hh - $fw * 0.5, 0.0), new Vec3($hw, $fw * 0.5, $fd * 0.5)],
            ];

            foreach ($frameParts as [$suffix, $offset, $scale]) {
                $framePos = $position->add($rotation->rotateVec3($offset));
                $builder->entity("{$p}_WindowFrame{$suffix}")
                    ->with(new Transform3D(position: $framePos, rotation: $rotation, scale: $scale))
                    ->with(new MeshRenderer(meshId: 'box', materialId: $materials->secondary));
                $names[] = "{$p}_WindowFrame{$suffix}";
            }
        }

        return new FurnitureResult(count($names), $names);
    }
}
