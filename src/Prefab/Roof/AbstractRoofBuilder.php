<?php

declare(strict_types=1);

namespace PHPolygon\Prefab\Roof;

use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\Transform3D;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Geometry\WedgeMesh;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;
use PHPolygon\Scene\SceneBuilder;

abstract class AbstractRoofBuilder
{
    protected float $width;
    protected float $depth;
    protected float $roofHeight;
    protected float $overhang;
    protected int $rafterCount;
    protected float $panelThickness;
    protected string $prefix = '';

    public function withPrefix(string $prefix): static
    {
        $this->prefix = $prefix;
        return $this;
    }

    abstract public function build(
        SceneBuilder $builder,
        Vec3 $basePosition,
        Quaternion $baseRotation,
        RoofMaterials $materials,
    ): RoofResult;

    /**
     * Build a single tilted roof panel.
     */
    protected function buildPanel(
        SceneBuilder $builder,
        string $name,
        Vec3 $basePos,
        Quaternion $baseRot,
        float $span,
        float $rise,
        float $panelWidth,
        float $zOffset,
        bool $flipTilt,
        string $materialId,
    ): void {
        $slope = sqrt($span * $span + $rise * $rise);
        $angle = atan2($rise, $span);
        if ($flipTilt) {
            $angle = -$angle;
        }

        $midY = $rise * 0.5;
        $midZ = $zOffset;

        $yawQ = $this->extractYawQuaternion($baseRot);
        $tilt = Quaternion::fromAxisAngle(new Vec3(1.0, 0.0, 0.0), $angle);
        $panelRot = $yawQ->multiply($tilt);

        $pos = $this->transformPoint($basePos, $baseRot, new Vec3(0.0, $midY, $midZ));

        $builder->entity($this->prefix . $name)
            ->with(new Transform3D(
                position: $pos,
                rotation: $panelRot,
                scale: new Vec3($panelWidth, $this->panelThickness, $slope * 0.5),
            ))
            ->with(new MeshRenderer(meshId: 'box', materialId: $materialId));
    }

    /**
     * Build the ridge beam (cylinder along the roof peak).
     */
    protected function buildRidgeBeam(
        SceneBuilder $builder,
        Vec3 $basePos,
        Quaternion $baseRot,
        float $beamWidth,
        string $materialId,
    ): void {
        $pos = $this->transformPoint($basePos, $baseRot, new Vec3(0.0, $this->roofHeight, 0.0));

        $builder->entity($this->prefix . '_Ridge')
            ->with(new Transform3D(
                position: $pos,
                rotation: $baseRot,
                scale: new Vec3($beamWidth, 0.06, 0.06),
            ))
            ->with(new MeshRenderer(meshId: 'cylinder', materialId: $materialId));
    }

    /**
     * Build evenly spaced rafters along a roof slope.
     */
    protected function buildRafters(
        SceneBuilder $builder,
        string $suffix,
        Vec3 $basePos,
        Quaternion $baseRot,
        float $span,
        float $rise,
        float $panelWidth,
        float $zOffset,
        bool $flipTilt,
        string $materialId,
    ): int {
        if ($this->rafterCount <= 0) {
            return 0;
        }

        $slope = sqrt($span * $span + $rise * $rise);
        $angle = atan2($rise, $span);
        if ($flipTilt) {
            $angle = -$angle;
        }

        $yawQ = $this->extractYawQuaternion($baseRot);
        $tilt = Quaternion::fromAxisAngle(new Vec3(1.0, 0.0, 0.0), $angle);
        $rafterRot = $yawQ->multiply($tilt);

        $midY = $rise * 0.5;

        for ($i = 0; $i < $this->rafterCount; $i++) {
            $t = ($i + 0.5) / $this->rafterCount;
            $rx = -$panelWidth + $t * $panelWidth * 2.0;

            $pos = $this->transformPoint($basePos, $baseRot, new Vec3($rx, $midY, $zOffset));

            $builder->entity($this->prefix . "_Rafter{$suffix}_{$i}")
                ->with(new Transform3D(
                    position: $pos,
                    rotation: $rafterRot,
                    scale: new Vec3(0.03, 0.03, $slope * 0.5),
                ))
                ->with(new MeshRenderer(meshId: 'cylinder', materialId: $materialId));
        }

        return $this->rafterCount;
    }

    /**
     * Build a triangular gable wall fill using WedgeMesh.
     * The wedge slope matches the roof panels exactly by using the roof spans.
     *
     * For asymmetric roofs (different front/back spans), two half-wedges are built.
     *
     * @param float $frontSpan Front roof half-span (depth/2 + overhang + extension)
     * @param float $backSpan  Back roof half-span (depth/2 + overhang)
     */
    protected function buildGableWall(
        SceneBuilder $builder,
        string $name,
        Vec3 $basePos,
        Quaternion $baseRot,
        float $xOffset,
        float $wallThickness,
        string $materialId,
        float $frontSpan,
        float $backSpan,
    ): void {
        // Self-contained: register the wedge primitive this builder relies on,
        // so a scene using roofs doesn't have to pre-register it (previously it
        // only worked if another scene happened to register it first).
        if (! MeshRegistry::has('wedge_right_neg')) {
            MeshRegistry::register('wedge_right_neg', WedgeMesh::generate(-1.0));
        }

        // Two right-triangle wedges: each matches the exact slope of its roof panel.
        // Front half: peak at ridge (Z=0), base at front eave (Z=+frontSpan).
        //   Uses wedge_right_neg (peak at Z=-1 → after scale, peak at Z=0 edge).
        // Back half: peak at ridge (Z=0), base at back eave (Z=-backSpan).
        //   Uses wedge_right_pos (peak at Z=+1 → flipped, peak at Z=0 edge).

        // Front gable half
        $frontPos = $this->transformPoint($basePos, $baseRot,
            new Vec3($xOffset, $this->roofHeight * 0.5, $frontSpan * 0.5));
        $builder->entity($this->prefix . $name . 'F')
            ->with(new Transform3D(
                position: $frontPos,
                rotation: $baseRot,
                scale: new Vec3($wallThickness * 0.5, $this->roofHeight * 0.5, $frontSpan * 0.5),
            ))
            ->with(new MeshRenderer(meshId: 'wedge_right_neg', materialId: $materialId));

        // Back gable half
        $backPos = $this->transformPoint($basePos, $baseRot,
            new Vec3($xOffset, $this->roofHeight * 0.5, -$backSpan * 0.5));
        $yawQ = $this->extractYawQuaternion($baseRot);
        $flipRot = $yawQ->multiply(Quaternion::fromAxisAngle(new Vec3(0.0, 1.0, 0.0), M_PI));
        $builder->entity($this->prefix . $name . 'B')
            ->with(new Transform3D(
                position: $backPos,
                rotation: $flipRot,
                scale: new Vec3($wallThickness * 0.5, $this->roofHeight * 0.5, $backSpan * 0.5),
            ))
            ->with(new MeshRenderer(meshId: 'wedge_right_neg', materialId: $materialId));
    }

    /**
     * Transform a local offset point by the base position and rotation.
     * Uses the Quaternion convention (rotateVec3) to rotate the offset,
     * then adds to the base position.
     */
    protected function transformPoint(Vec3 $basePos, Quaternion $baseRot, Vec3 $localOffset): Vec3
    {
        $rotated = $baseRot->rotateVec3($localOffset);
        return $basePos->add($rotated);
    }

    /**
     * Extract the Y-axis rotation component from a quaternion.
     * Used when combining yaw with tilt rotations.
     */
    protected function extractYawQuaternion(Quaternion $rot): Quaternion
    {
        // For a Y-axis rotation quaternion (0, sinY, 0, cosY),
        // we can reconstruct it from the y and w components.
        $len = sqrt($rot->y * $rot->y + $rot->w * $rot->w);
        if ($len < 1e-8) {
            return Quaternion::identity();
        }
        return new Quaternion(0.0, $rot->y / $len, 0.0, $rot->w / $len);
    }
}
