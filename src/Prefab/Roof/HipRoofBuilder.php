<?php

declare(strict_types=1);

namespace PHPolygon\Prefab\Roof;

use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;
use PHPolygon\Scene\SceneBuilder;

/**
 * Walmdach — four sloped panels meeting at a shortened ridge.
 * No gable walls needed (all sides are covered by panels).
 */
class HipRoofBuilder extends AbstractRoofBuilder
{
    public function __construct(
        float $width,
        float $depth,
        float $roofHeight,
        float $overhang = 0.5,
        int $rafterCount = 3,
        float $panelThickness = 0.10,
    ) {
        $this->width = $width;
        $this->depth = $depth;
        $this->roofHeight = $roofHeight;
        $this->overhang = $overhang;
        $this->rafterCount = $rafterCount;
        $this->panelThickness = $panelThickness;
    }

    public function build(
        SceneBuilder $builder,
        Vec3 $basePosition,
        Quaternion $baseRotation,
        RoofMaterials $materials,
    ): RoofResult {
        $halfSpan = $this->depth * 0.5 + $this->overhang;

        // Ridge is shortened: only as long as (width - depth)
        $ridgeLen = max(0.1, ($this->width - $this->depth) * 0.5 + $this->overhang);
        $entityCount = 0;

        // Front and back panels (slope along Z)
        $panelWidthFB = $ridgeLen;
        $this->buildPanel(
            $builder, '_RoofFront', $basePosition, $baseRotation,
            span: $halfSpan, rise: $this->roofHeight,
            panelWidth: $panelWidthFB, zOffset: $halfSpan * 0.5,
            flipTilt: false, materialId: $materials->panel,
        );
        $this->buildPanel(
            $builder, '_RoofBack', $basePosition, $baseRotation,
            span: $halfSpan, rise: $this->roofHeight,
            panelWidth: $panelWidthFB, zOffset: -$halfSpan * 0.5,
            flipTilt: true, materialId: $materials->panelBack,
        );
        $entityCount += 2;

        // Left and right panels (slope along X, rotated 90°)
        $sideSpan = $this->width * 0.5 + $this->overhang;
        $sidePanelWidth = $halfSpan; // depth direction
        $yawQ = $this->extractYawQuaternion($baseRotation);

        // Left and right hip panels — Y rotations were swapped previously
        // (left used +90°, right used -90°), which placed each panel's mesh
        // +Z axis pointing toward the *opposite* eave and left their +Y
        // normals pointing INTO the building. With backface culling
        // disabled the exterior side of each panel ended up unlit, reading
        // as if the roof were "verkehrt herum". Correct mapping: rotation
        // around +Y by -90° brings mesh +Z to world -X (toward the LEFT
        // eave) and the +Y normal stays pointing up-and-outward; +90° does
        // the mirrored job for the right panel.
        $sideSlope = sqrt($sideSpan * $sideSpan + $this->roofHeight * $this->roofHeight);
        $sideAngle = atan2($this->roofHeight, $sideSpan);
        $sideTilt = Quaternion::fromAxisAngle(new Vec3(1.0, 0.0, 0.0), $sideAngle);

        $leftRot = $yawQ->multiply(Quaternion::fromAxisAngle(new Vec3(0.0, 1.0, 0.0), -M_PI * 0.5));
        $leftPos = $this->transformPoint($basePosition, $baseRotation,
            new Vec3(-$sideSpan * 0.5, $this->roofHeight * 0.5, 0.0));
        $builder->entity($this->prefix . '_RoofLeft')
            ->with(new \PHPolygon\Component\Transform3D(
                position: $leftPos,
                rotation: $leftRot->multiply($sideTilt),
                scale: new Vec3($sidePanelWidth, $this->panelThickness, $sideSlope * 0.5),
            ))
            ->with(new \PHPolygon\Component\MeshRenderer(meshId: 'box', materialId: $materials->panel));

        $rightRot = $yawQ->multiply(Quaternion::fromAxisAngle(new Vec3(0.0, 1.0, 0.0), M_PI * 0.5));
        $rightPos = $this->transformPoint($basePosition, $baseRotation,
            new Vec3($sideSpan * 0.5, $this->roofHeight * 0.5, 0.0));
        $builder->entity($this->prefix . '_RoofRight')
            ->with(new \PHPolygon\Component\Transform3D(
                position: $rightPos,
                rotation: $rightRot->multiply($sideTilt),
                scale: new Vec3($sidePanelWidth, $this->panelThickness, $sideSlope * 0.5),
            ))
            ->with(new \PHPolygon\Component\MeshRenderer(meshId: 'box', materialId: $materials->panelBack));
        $entityCount += 2;

        // Shortened ridge beam
        $this->buildRidgeBeam($builder, $basePosition, $baseRotation, $ridgeLen, $materials->ridge);
        $entityCount++;

        // Rafters on front and back
        $entityCount += $this->buildRafters(
            $builder, 'F', $basePosition, $baseRotation,
            span: $halfSpan, rise: $this->roofHeight,
            panelWidth: $panelWidthFB, zOffset: $halfSpan * 0.5,
            flipTilt: false, materialId: $materials->rafter,
        );
        $entityCount += $this->buildRafters(
            $builder, 'B', $basePosition, $baseRotation,
            span: $halfSpan, rise: $this->roofHeight,
            panelWidth: $panelWidthFB, zOffset: -$halfSpan * 0.5,
            flipTilt: true, materialId: $materials->rafter,
        );

        return new RoofResult(
            ridgeY: $basePosition->y + $this->roofHeight,
            eaveY: $basePosition->y,
            entityCount: $entityCount,
        );
    }
}
