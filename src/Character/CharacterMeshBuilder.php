<?php

declare(strict_types=1);

namespace PHPolygon\Character;

use PHPolygon\Character\DNA\Enum\Accessory;
use PHPolygon\Character\DNA\Enum\EyeShape;
use PHPolygon\Character\DNA\Enum\FacialHair;
use PHPolygon\Character\DNA\Enum\HairColor;
use PHPolygon\Character\DNA\Enum\HairStyle;
use PHPolygon\Character\DNA\Enum\NoseShape;
use PHPolygon\Character\DNA\Enum\SkinTone;
use PHPolygon\Character\DNA\PlayerProportions;
use PHPolygon\Component\CharacterDnaComponent;
use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\Entity;
use PHPolygon\ECS\World;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\CylinderMesh;
use PHPolygon\Geometry\LatheMesh;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Geometry\SkullMesh;
use PHPolygon\Geometry\SphereMesh;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec2;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;

/**
 * Builds a procedural humanoid rig from a {@see PlayerProportions} strand.
 *
 * The rig consists of ~80-120 entities (legs, arms, torso, head, hair cards,
 * accessories) parented to a single root entity. All parts use a shared set
 * of primitive meshes (box/cylinder/sphere/skull/lathe) and per-tone
 * materials registered by {@see registerDefaults()}.
 *
 * Typical usage:
 *
 *     CharacterMeshBuilder::registerDefaults();
 *     $root = $world->createEntity();
 *     $root->attach(new Transform3D(position: $pos));
 *     $root->attach(new CharacterDnaComponent($dna));
 *     $parts = CharacterMeshBuilder::buildOn($world, $root);
 */
final class CharacterMeshBuilder
{
    public const string MESH_BOX      = 'character_unit_box';
    public const string MESH_SPHERE   = 'character_unit_sphere';
    public const string MESH_CYLINDER = 'character_unit_cylinder';
    public const string MESH_SKULL    = 'character_skull';
    public const string MESH_TORSO    = 'character_torso_lathe';

    public const string MAT_EYE_WHITE      = 'character_eye_white';
    public const string MAT_MOUTH          = 'character_mouth';
    public const string MAT_CLOTH_DARK     = 'character_cloth_dark';
    public const string MAT_ACCESSORY_METAL = 'character_accessory_metal';
    public const string MAT_ACCESSORY_DARK  = 'character_accessory_dark';
    public const string MAT_ACCESSORY_GLASS = 'character_accessory_glass';

    private static bool $defaultsRegistered = false;

    /**
     * Register the meshes and materials the builder uses. Idempotent.
     * Call this once before the first {@see buildOn()} call (it is auto-invoked
     * by buildOn() if not yet registered).
     */
    public static function registerDefaults(): void
    {
        if (self::$defaultsRegistered) {
            return;
        }
        self::$defaultsRegistered = true;

        MeshRegistry::register(self::MESH_BOX,      BoxMesh::generate(1.0, 1.0, 1.0));
        MeshRegistry::register(self::MESH_SPHERE,   SphereMesh::generate(0.5, 12, 20));
        MeshRegistry::register(self::MESH_CYLINDER, CylinderMesh::generate(0.5, 1.0, 12));
        MeshRegistry::register(self::MESH_SKULL,    SkullMesh::generate(0.5, 32, 48));
        MeshRegistry::register(self::MESH_TORSO,    LatheMesh::generate([
            new Vec2(0.00, 0.00),
            new Vec2(0.42, 0.04),
            new Vec2(0.40, 0.30),
            new Vec2(0.46, 0.55),
            new Vec2(0.50, 0.78),
            new Vec2(0.42, 0.92),
            new Vec2(0.28, 0.98),
            new Vec2(0.00, 1.00),
        ], 24));

        MaterialRegistry::register(self::MAT_CLOTH_DARK, new Material(
            albedo: new Color(0.18, 0.18, 0.22), roughness: 0.85, metallic: 0.0,
        ));
        MaterialRegistry::register(self::MAT_EYE_WHITE, new Material(
            albedo: new Color(0.95, 0.94, 0.90), roughness: 0.4, metallic: 0.0,
        ));
        MaterialRegistry::register(self::MAT_MOUTH, new Material(
            albedo: new Color(0.35, 0.12, 0.12), roughness: 0.7, metallic: 0.0,
        ));
        MaterialRegistry::register(self::MAT_ACCESSORY_METAL, new Material(
            albedo: new Color(0.85, 0.78, 0.42), roughness: 0.30, metallic: 0.9,
        ));
        MaterialRegistry::register(self::MAT_ACCESSORY_DARK, new Material(
            albedo: new Color(0.08, 0.08, 0.10), roughness: 0.40, metallic: 0.1,
        ));
        MaterialRegistry::register(self::MAT_ACCESSORY_GLASS, new Material(
            albedo: new Color(0.12, 0.14, 0.18), roughness: 0.20, metallic: 0.0,
        ));

        foreach (SkinTone::cases() as $tone) {
            $albedo = Color::hex($tone->value);
            $luminance = 0.299 * $albedo->r + 0.587 * $albedo->g + 0.114 * $albedo->b;
            $sss = new Color(
                min(1.0, $albedo->r * 1.2 + 0.15),
                $albedo->g * 0.55,
                $albedo->b * 0.40,
            );
            $strength = 0.4 + $luminance * 0.5;
            MaterialRegistry::register(self::skinMaterialId($tone), Material::skin(
                albedo: $albedo,
                subsurfaceColor: $sss,
                subsurfaceStrength: $strength,
                roughness: 0.55,
            ));
        }
        foreach (HairColor::cases() as $color) {
            $albedo = Color::hex($color->value);
            MaterialRegistry::register(self::hairMaterialId($color), new Material(
                albedo: $albedo, roughness: 0.55, metallic: 0.0,
            ));
            MaterialRegistry::register(self::facialHairMaterialId($color), new Material(
                albedo: new Color($albedo->r * 0.70, $albedo->g * 0.70, $albedo->b * 0.70),
                roughness: 0.80,
                metallic: 0.0,
            ));
        }
        foreach (\PHPolygon\Character\DNA\Enum\EyeColor::cases() as $color) {
            MaterialRegistry::register(self::eyeMaterialId($color), new Material(
                albedo: Color::hex($color->value), roughness: 0.25, metallic: 0.0,
            ));
        }
    }

    /**
     * Force a re-registration of the default meshes/materials on the next call.
     * Intended for tests that need to reset the registry.
     */
    public static function resetDefaults(): void
    {
        self::$defaultsRegistered = false;
    }

    public static function skinMaterialId(SkinTone $tone): string
    {
        return 'character_skin_' . $tone->name;
    }

    public static function hairMaterialId(HairColor $color): string
    {
        return 'character_hair_' . $color->name;
    }

    public static function facialHairMaterialId(HairColor $color): string
    {
        return 'character_facial_hair_' . $color->name;
    }

    public static function eyeMaterialId(\PHPolygon\Character\DNA\Enum\EyeColor $color): string
    {
        return 'character_eye_' . $color->name;
    }

    /**
     * Build the humanoid rig under $root and return all created parts.
     *
     * $root must have a {@see Transform3D} component. The root's position
     * becomes the base of the rig (feet on the ground at the root's y).
     *
     * If $proportions is omitted, the root must carry a {@see CharacterDnaComponent}
     * from which proportions are decoded.
     *
     * @return list<Entity>
     */
    public static function buildOn(World $world, Entity $root, ?PlayerProportions $proportions = null): array
    {
        self::registerDefaults();

        if ($proportions === null) {
            $component = $root->tryGet(CharacterDnaComponent::class);
            if ($component === null) {
                throw new \LogicException(
                    'CharacterMeshBuilder::buildOn() requires either a PlayerProportions argument'
                    . ' or a CharacterDnaComponent on the root entity.'
                );
            }
            $proportions = $component->proportions();
        }

        $out = [];
        self::buildCharacter($world, $proportions, $root, $out);
        return $out;
    }

    /** @param list<Entity> $out */
    private static function buildCharacter(World $world, PlayerProportions $p, Entity $root, array &$out): void
    {
        $h = $p->bodyHeight;

        $bias    = $p->buildBias;
        $thickMul = 1.0 + 0.22 * $bias;
        $girthMul = 1.0 + 0.18 * $bias;
        $waistMul = 1.0 + 0.15 * $bias;

        $legH   = 0.82 * $p->limbLength * $h;
        $hipH   = 0.18 * $h;
        $chestH = 0.34 * $p->torsoLength * $h;
        $waistH = 0.20 * $p->torsoLength * $h;
        $neckH  = 0.05 * $h;
        $headH  = 0.26 * $p->skullHeight * $h;

        $hipW    = 0.34 * $p->hipWidth * $h;
        $waistW  = 0.30 * (0.6 + 0.4 * $p->shoulderWidth) * $h * $waistMul;
        $shldW   = 0.50 * $p->shoulderWidth * $h;
        $chestD  = 0.24 * $h * $girthMul;
        $waistD  = 0.20 * $h * $girthMul;
        $headW   = 0.20 * $p->skullWidth * $h;
        $headD   = 0.22 * $p->skullWidth * $h;
        $jawDrop = 0.05 * $p->jawWidth * $h;

        $legR   = 0.085 * (0.4 + 0.6 * $p->limbTaper) * $h * $thickMul;
        $armR   = 0.062 * (0.4 + 0.6 * $p->limbTaper) * $h * $thickMul;
        $armL   = 0.68  * $p->limbLength * $h;

        $skinMat = self::skinMaterialId($p->skinTone);

        $base = $root->get(Transform3D::class)->position;

        $hipY     = $base->y + $legH + $hipH * 0.5;
        $neckY    = $base->y + $legH + $hipH + $waistH + $chestH + $neckH * 0.5;
        $headY    = $neckY + $neckH * 0.5 + $headH * 0.5;
        $shldY    = $base->y + $legH + $hipH + $waistH + $chestH - 0.04 * $h;

        // Legs - segmented thigh + knee + calf + ankle.
        $legOffset = $hipW * 0.35;
        $thighH = $legH * 0.55;
        $calfH  = $legH * 0.45;
        $kneeY  = $base->y + $calfH;
        $thighR = $legR * 1.05;
        $calfR  = $legR * 0.92;
        $kneeR  = $legR * 1.10;
        $ankleR = $legR * 0.85;
        foreach ([-$legOffset, $legOffset] as $sx) {
            $out[] = self::spawnPart($world, self::MESH_CYLINDER, $skinMat,
                new Vec3($base->x + $sx, $kneeY + $thighH * 0.5, $base->z),
                new Vec3($thighR * 2, $thighH, $thighR * 2),
            );
            $out[] = self::spawnPart($world, self::MESH_SPHERE, $skinMat,
                new Vec3($base->x + $sx, $kneeY, $base->z),
                new Vec3($kneeR * 2, $kneeR * 2, $kneeR * 2),
            );
            $out[] = self::spawnPart($world, self::MESH_CYLINDER, $skinMat,
                new Vec3($base->x + $sx, $base->y + $calfH * 0.5, $base->z),
                new Vec3($calfR * 2, $calfH, $calfR * 2),
            );
            $out[] = self::spawnPart($world, self::MESH_SPHERE, $skinMat,
                new Vec3($base->x + $sx, $base->y + $ankleR, $base->z),
                new Vec3($ankleR * 2, $ankleR * 2, $ankleR * 2),
            );
        }

        // Feet - heel + toe boxes.
        $footW   = $legR * 2.4;
        $heelL   = $legR * 1.5;
        $toeL    = $legR * 1.9;
        $heelH   = 0.045 * $h;
        $toeH    = 0.030 * $h;
        foreach ([-$legOffset, $legOffset] as $sx) {
            $out[] = self::spawnPart($world, self::MESH_BOX, self::MAT_CLOTH_DARK,
                new Vec3($base->x + $sx, $base->y + $heelH * 0.5, $base->z - $heelL * 0.3),
                new Vec3($footW, $heelH, $heelL),
            );
            $out[] = self::spawnPart($world, self::MESH_BOX, self::MAT_CLOTH_DARK,
                new Vec3($base->x + $sx, $base->y + $toeH * 0.5, $base->z + ($heelL * 0.5) + $toeL * 0.3),
                new Vec3($footW * 0.85, $toeH, $toeL),
            );
        }

        // Hip belt
        $out[] = self::spawnPart($world, self::MESH_BOX, self::MAT_CLOTH_DARK,
            new Vec3($base->x, $hipY, $base->z),
            new Vec3($hipW, $hipH, $waistD * 1.05),
        );

        // Torso lathe - single revolved solid covering waist + chest.
        $torsoH       = $waistH + $chestH;
        $torsoBottom  = $base->y + $legH + $hipH - 0.005 * $h;
        $torsoBreadth = max($waistW, $shldW * 0.95);
        $torsoDepth   = max($waistD, $chestD * 0.95);
        $out[] = self::spawnPart($world, self::MESH_TORSO, $skinMat,
            new Vec3($base->x, $torsoBottom, $base->z),
            new Vec3($torsoBreadth, $torsoH, $torsoDepth),
        );

        // Shoulder caps
        foreach ([-1, 1] as $side) {
            $out[] = self::spawnPart($world, self::MESH_SPHERE, $skinMat,
                new Vec3($base->x + $side * $shldW * 0.5, $shldY, $base->z),
                new Vec3($armR * 2.4, $armR * 2.4, $armR * 2.4),
            );
        }

        // Neck + jawline collar
        $out[] = self::spawnPart($world, self::MESH_CYLINDER, $skinMat,
            new Vec3($base->x, $neckY, $base->z),
            new Vec3(0.085 * $h, $neckH, 0.085 * $h),
        );
        $out[] = self::spawnPart($world, self::MESH_SPHERE, $skinMat,
            new Vec3($base->x, $neckY + $neckH * 0.45, $base->z),
            new Vec3(0.11 * $h * $p->skullWidth, 0.04 * $h, 0.10 * $h * $p->skullWidth),
        );

        // Arms - segmented upper + elbow + forearm + wrist + palm + fingers.
        $armOffset       = $shldW * 0.5 + $armR;
        $armDriftOutward = $armR * 0.6;
        $upperArmH    = $armL * 0.50;
        $forearmH     = $armL * 0.45;
        $elbowY       = $shldY - $upperArmH;
        $wristY       = $elbowY - $forearmH;
        $upperArmR    = $armR * 1.05;
        $forearmR     = $armR * 0.88;
        $elbowR       = $armR * 1.08;
        $wristR       = $armR * 0.85;
        foreach ([-$armOffset, $armOffset] as $sx) {
            $dir       = $sx < 0 ? -1.0 : 1.0;
            $shoulderX = $base->x + $sx;
            $elbowX    = $base->x + $sx + $dir * $armDriftOutward * 0.5;
            $wristX    = $base->x + $sx + $dir * $armDriftOutward;

            $out[] = self::spawnPart($world, self::MESH_CYLINDER, $skinMat,
                new Vec3(($shoulderX + $elbowX) * 0.5, $shldY - $upperArmH * 0.5, $base->z),
                new Vec3($upperArmR * 2, $upperArmH, $upperArmR * 2),
            );
            $out[] = self::spawnPart($world, self::MESH_SPHERE, $skinMat,
                new Vec3($elbowX, $elbowY, $base->z),
                new Vec3($elbowR * 2, $elbowR * 2, $elbowR * 2),
            );
            $out[] = self::spawnPart($world, self::MESH_CYLINDER, $skinMat,
                new Vec3(($elbowX + $wristX) * 0.5, $wristY + $forearmH * 0.5, $base->z),
                new Vec3($forearmR * 2, $forearmH, $forearmR * 2),
            );
            $out[] = self::spawnPart($world, self::MESH_SPHERE, $skinMat,
                new Vec3($wristX, $wristY, $base->z),
                new Vec3($wristR * 2, $wristR * 2, $wristR * 2),
            );
            $palmY = $wristY - $armR * 0.85;
            $palmW = $armR * 2.2;
            $palmH = $armR * 0.9;
            $palmD = $armR * 2.2;
            $out[] = self::spawnPart($world, self::MESH_SPHERE, $skinMat,
                new Vec3($wristX, $palmY, $base->z),
                new Vec3($palmW, $palmH * 2.0, $palmD),
            );
            $fingerLen   = $armR * 1.4;
            $fingerR     = $armR * 0.30;
            $fingerTopY  = $palmY - $palmH * 0.7;
            $fingerCtrY  = $fingerTopY - $fingerLen * 0.5;
            for ($f = 0; $f < 4; $f++) {
                $offsetX = (($f - 1.5) / 1.5) * ($palmW * 0.30);
                $out[] = self::spawnPart($world, self::MESH_CYLINDER, $skinMat,
                    new Vec3($wristX + $offsetX, $fingerCtrY, $base->z),
                    new Vec3($fingerR * 2, $fingerLen, $fingerR * 2),
                );
            }
            $thumbDir = -$dir;
            $out[] = self::spawnPart($world, self::MESH_CYLINDER, $skinMat,
                new Vec3(
                    $wristX + $thumbDir * $palmW * 0.45,
                    $palmY - $palmH * 0.1,
                    $base->z + $palmD * 0.10,
                ),
                new Vec3($fingerR * 2.1, $fingerLen * 0.7, $fingerR * 2.1),
            );
        }

        // Head - procedural skull
        $out[] = self::spawnPart($world, self::MESH_SKULL, $skinMat,
            new Vec3($base->x, $headY, $base->z),
            new Vec3($headW, $headH, $headD),
        );

        // Ears
        $earScale   = $p->earSize;
        $earCenterY = $headY + $headH * 0.05;
        $earCenterX = $headW * 0.52;
        foreach ([-1, 1] as $earSide) {
            $out[] = self::spawnPart($world, self::MESH_SPHERE, $skinMat,
                new Vec3(
                    $base->x + $earSide * $earCenterX,
                    $earCenterY,
                    $base->z - $headD * 0.05,
                ),
                new Vec3(
                    $headW * 0.16 * $earScale,
                    $headH * 0.30 * $earScale,
                    $headD * 0.45 * $earScale,
                ),
            );
        }

        // Eyebrows
        $browMat = self::hairMaterialId($p->hairColor);
        $browOffsetX = $headW * 0.32 * $p->eyeSpacing;
        $browW = $headW * 0.32 * $p->eyebrowThickness;
        $browH = 0.018 * $h * (0.7 + 0.6 * $p->eyebrowThickness);
        $browD = 0.012 * $h;
        $browZ = $base->z + $headD * 0.5 - 0.003 * $h;
        $browY = $headY + $headH * 0.14;
        foreach ([-1, 1] as $bs) {
            $angle = $p->eyebrowAngle * $bs;
            $rot = Quaternion::fromAxisAngle(new Vec3(0.0, 0.0, 1.0), $angle);
            $out[] = self::spawnPartRot($world, self::MESH_BOX, $browMat,
                new Vec3($base->x + $bs * $browOffsetX, $browY, $browZ),
                new Vec3($browW, $browH, $browD),
                $rot,
            );
        }

        // Jaw
        $out[] = self::spawnPart($world, self::MESH_SPHERE, $skinMat,
            new Vec3($base->x, $headY - $headH * 0.35, $base->z + $headD * 0.05),
            new Vec3($headW * (0.55 + 0.35 * $p->jawWidth), $jawDrop, $headD * 0.9),
        );

        // Brow ridge
        $browProm = $p->browProminence;
        if ($browProm > 0.25) {
            $ridgeZ = $base->z + $headD * 0.5 - 0.005 * $h;
            $out[] = self::spawnPart($world, self::MESH_BOX, $skinMat,
                new Vec3($base->x, $headY + $headH * 0.20, $ridgeZ),
                new Vec3($headW * 0.7, 0.012 * $h, 0.025 * (0.5 + $browProm) * $h),
            );
        }

        // Nose
        [$noseW, $noseHi, $noseDp, $noseDip] = self::noseShapeDims($p->noseShape);
        $noseY = $headY - $headH * 0.05;
        $noseZ = $base->z + $headD * 0.5 + 0.015 * $h;
        $out[] = self::spawnPart($world, self::MESH_SPHERE, $skinMat,
            new Vec3($base->x, $noseY, $noseZ),
            new Vec3($noseW * $h, $noseHi * $h, $noseDp * $h),
        );
        if ($noseDip > 0.0) {
            $out[] = self::spawnPart($world, self::MESH_SPHERE, $skinMat,
                new Vec3(
                    $base->x,
                    $noseY - $noseHi * $h * $noseDip,
                    $noseZ + $noseDp * $h * 0.25,
                ),
                new Vec3($noseW * $h * 0.85, $noseHi * $h * 0.55, $noseDp * $h * 0.55),
            );
        }

        // Mouth
        $out[] = self::spawnPart($world, self::MESH_BOX, self::MAT_MOUTH,
            new Vec3($base->x, $headY - $headH * 0.28, $base->z + $headD * 0.5 - 0.002 * $h),
            new Vec3($headW * 0.35, 0.012 * $h, 0.005 * $h),
        );

        // Eyes
        $eyeOffsetX = $headW * 0.32 * $p->eyeSpacing;
        $eyeY       = $headY + $headH * 0.05;
        $eyeZ       = $base->z + $headD * 0.5 - 0.005 * $h;
        $eyeSocketZ = $eyeZ - $headD * 0.12;
        [$ex, $ey] = self::eyeShapeScale($p->eyeShape);
        $scleraSize = 0.055 * $h;
        $irisSize   = 0.028 * $h;

        foreach ([-$eyeOffsetX, $eyeOffsetX] as $sx) {
            $out[] = self::spawnPart($world, self::MESH_SPHERE, self::MAT_EYE_WHITE,
                new Vec3($base->x + $sx, $eyeY, $eyeSocketZ),
                new Vec3($scleraSize * $ex, $scleraSize * $ey, $scleraSize * 0.6),
            );
            $out[] = self::spawnPart($world, self::MESH_SPHERE, self::eyeMaterialId($p->eyeColor),
                new Vec3($base->x + $sx, $eyeY, $eyeSocketZ + 0.015 * $h),
                new Vec3($irisSize * $ex, $irisSize * $ey, $irisSize * 0.5),
            );
        }

        // Facial hair, age detail, hair, accessories
        self::buildFacialHair($world, $p, $base, $headY, $headW, $headH, $headD, $out);

        if ($p->age > 0.65) {
            $wrinkleZ = $base->z + $headD * 0.5 - 0.004 * $h;
            $wrinkleY = $eyeY - $scleraSize * 0.95;
            $wrinkleAlpha = ($p->age - 0.65) / 0.35;
            foreach ([-$eyeOffsetX, $eyeOffsetX] as $sx) {
                $out[] = self::spawnPart($world, self::MESH_BOX, self::MAT_MOUTH,
                    new Vec3($base->x + $sx, $wrinkleY, $wrinkleZ),
                    new Vec3($headW * 0.18, 0.005 * $h, 0.003 * $h * (0.5 + $wrinkleAlpha)),
                );
            }
        }

        self::buildHair($world, $p, $base, $headY, $headW, $headH, $headD, $out);
        self::buildAccessory(
            $world, $p, $base,
            $headY, $headW, $headH, $headD,
            $eyeOffsetX, $eyeY, $eyeZ,
            $earCenterX, $earCenterY,
            $neckY, $neckH, $shldW,
            $out,
        );
    }

    private static function spawnPart(World $world, string $mesh, string $material, Vec3 $pos, Vec3 $scale): Entity
    {
        $e = $world->createEntity();
        $e->attach(new MeshRenderer($mesh, $material));
        $e->attach(new Transform3D(position: $pos, scale: $scale));
        return $e;
    }

    private static function spawnPartRot(World $world, string $mesh, string $material, Vec3 $pos, Vec3 $scale, Quaternion $rot): Entity
    {
        $e = $world->createEntity();
        $e->attach(new MeshRenderer($mesh, $material));
        $e->attach(new Transform3D(position: $pos, rotation: $rot, scale: $scale));
        return $e;
    }

    /** @param list<Entity> $out */
    private static function spawnHairCard(World $world, string $material, Vec3 $top, float $length, float $thickness, array &$out): void
    {
        $out[] = self::spawnPart($world, self::MESH_CYLINDER, $material,
            new Vec3($top->x, $top->y - $length * 0.5, $top->z),
            new Vec3($thickness, $length, $thickness),
        );
    }

    /** @return array{0: float, 1: float, 2: float, 3: float} */
    private static function noseShapeDims(NoseShape $shape): array
    {
        return match ($shape) {
            NoseShape::Button   => [0.026, 0.030, 0.038, 0.0],
            NoseShape::Straight => [0.022, 0.055, 0.040, 0.0],
            NoseShape::Wide     => [0.038, 0.034, 0.034, 0.0],
            NoseShape::Pointed  => [0.018, 0.045, 0.058, 0.0],
            NoseShape::Hooked   => [0.024, 0.050, 0.044, 0.55],
        };
    }

    /** @return array{0: float, 1: float} */
    private static function eyeShapeScale(EyeShape $shape): array
    {
        return match ($shape) {
            EyeShape::Round      => [1.0, 1.0],
            EyeShape::Almond     => [1.25, 0.7],
            EyeShape::Narrow     => [1.0, 0.55],
            EyeShape::Wide       => [1.4, 1.0],
            EyeShape::Downturned => [1.2, 0.8],
            EyeShape::Upturned   => [1.2, 0.8],
        };
    }

    /** @param list<Entity> $out */
    private static function buildFacialHair(World $world, PlayerProportions $p, Vec3 $base, float $headY, float $headW, float $headH, float $headD, array &$out): void
    {
        if ($p->facialHair === FacialHair::None) {
            return;
        }

        $mat = self::facialHairMaterialId($p->hairColor);
        $faceZ = $base->z + $headD * 0.5 - 0.002;
        $mouthY = $headY - $headH * 0.28;

        switch ($p->facialHair) {
            case FacialHair::Stubble:
                $out[] = self::spawnPart($world, self::MESH_BOX, $mat,
                    new Vec3($base->x, $mouthY - $headH * 0.04, $faceZ),
                    new Vec3($headW * 0.85, $headH * 0.30, 0.004),
                );
                break;

            case FacialHair::Mustache:
                $out[] = self::spawnPart($world, self::MESH_BOX, $mat,
                    new Vec3($base->x, $mouthY + $headH * 0.04, $faceZ),
                    new Vec3($headW * 0.42, $headH * 0.07, 0.012),
                );
                break;

            case FacialHair::Goatee:
                $out[] = self::spawnPart($world, self::MESH_BOX, $mat,
                    new Vec3($base->x, $mouthY - $headH * 0.10, $faceZ),
                    new Vec3($headW * 0.22, $headH * 0.18, 0.012),
                );
                $out[] = self::spawnPart($world, self::MESH_SPHERE, $mat,
                    new Vec3($base->x, $mouthY - $headH * 0.22, $faceZ),
                    new Vec3($headW * 0.20, $headH * 0.14, 0.020),
                );
                break;

            case FacialHair::FullBeard:
                $out[] = self::spawnPart($world, self::MESH_BOX, $mat,
                    new Vec3($base->x, $mouthY - $headH * 0.16, $faceZ - 0.005),
                    new Vec3($headW * 0.92, $headH * 0.42, 0.030),
                );
                $out[] = self::spawnPart($world, self::MESH_SPHERE, $mat,
                    new Vec3($base->x, $mouthY - $headH * 0.30, $faceZ + 0.005),
                    new Vec3($headW * 0.55, $headH * 0.32, 0.045),
                );
                break;

            case FacialHair::Sideburns:
                foreach ([-1, 1] as $side) {
                    $out[] = self::spawnPart($world, self::MESH_BOX, $mat,
                        new Vec3(
                            $base->x + $side * $headW * 0.44,
                            $headY - $headH * 0.12,
                            $faceZ - 0.008,
                        ),
                        new Vec3($headW * 0.10, $headH * 0.45, 0.020),
                    );
                }
                break;

            default:
                break;
        }
    }

    /** @param list<Entity> $out */
    private static function buildAccessory(
        World $world,
        PlayerProportions $p,
        Vec3 $base,
        float $headY,
        float $headW,
        float $headH,
        float $headD,
        float $eyeOffsetX,
        float $eyeY,
        float $eyeZ,
        float $earCenterX,
        float $earCenterY,
        float $neckY,
        float $neckH,
        float $shldW,
        array &$out,
    ): void {
        switch ($p->accessory) {
            case Accessory::None:
                return;

            case Accessory::Glasses:
                $lensW = $headW * 0.30;
                $lensH = $headH * 0.18;
                $lensZ = $eyeZ + 0.030;
                foreach ([-$eyeOffsetX, $eyeOffsetX] as $sx) {
                    $out[] = self::spawnPart($world, self::MESH_BOX, self::MAT_ACCESSORY_GLASS,
                        new Vec3($base->x + $sx, $eyeY, $lensZ),
                        new Vec3($lensW * 0.85, $lensH * 0.85, 0.004),
                    );
                    $out[] = self::spawnPart($world, self::MESH_BOX, self::MAT_ACCESSORY_DARK,
                        new Vec3($base->x + $sx, $eyeY, $lensZ - 0.003),
                        new Vec3($lensW, $lensH, 0.006),
                    );
                }
                $out[] = self::spawnPart($world, self::MESH_BOX, self::MAT_ACCESSORY_DARK,
                    new Vec3($base->x, $eyeY, $lensZ - 0.003),
                    new Vec3($eyeOffsetX * 2.0 - $lensW * 0.95, $lensH * 0.10, 0.006),
                );
                return;

            case Accessory::Earrings:
                $earringR = $headW * 0.04 * $p->earSize;
                foreach ([-1, 1] as $side) {
                    $out[] = self::spawnPart($world, self::MESH_SPHERE, self::MAT_ACCESSORY_METAL,
                        new Vec3(
                            $base->x + $side * $earCenterX,
                            $earCenterY - $headH * 0.22 * $p->earSize,
                            $eyeZ - 0.02,
                        ),
                        new Vec3($earringR, $earringR, $earringR),
                    );
                }
                return;

            case Accessory::Headband:
                $out[] = self::spawnPart($world, self::MESH_CYLINDER, self::MAT_ACCESSORY_METAL,
                    new Vec3($base->x, $headY + $headH * 0.30, $base->z),
                    new Vec3($headW * 1.08, $headH * 0.10, $headD * 1.08),
                );
                return;

            case Accessory::Necklace:
                $arcWidth = $shldW * 0.55;
                $arcY = $neckY - $neckH * 0.40;
                $arcRingZ = $base->z + $headD * 0.45;
                $beadCount = 7;
                $beadR = $headW * 0.05;
                for ($i = 0; $i < $beadCount; $i++) {
                    $t = $i / ($beadCount - 1);
                    $bx = -$arcWidth + 2.0 * $arcWidth * $t;
                    $drop = 1.0 - 4.0 * ($t - 0.5) * ($t - 0.5);
                    $out[] = self::spawnPart($world, self::MESH_SPHERE, self::MAT_ACCESSORY_METAL,
                        new Vec3(
                            $base->x + $bx,
                            $arcY - $drop * $headH * 0.18,
                            $arcRingZ - $drop * 0.005,
                        ),
                        new Vec3($beadR, $beadR, $beadR),
                    );
                }
                return;
        }
    }

    /** @param list<Entity> $out */
    private static function buildHair(World $world, PlayerProportions $p, Vec3 $base, float $headY, float $headW, float $headH, float $headD, array &$out): void
    {
        if ($p->hairStyle === HairStyle::Bald) {
            return;
        }

        $mat = self::hairMaterialId($p->hairColor);
        $topY = $headY + $headH * 0.5;

        switch ($p->hairStyle) {
            case HairStyle::BuzzCut:
                $out[] = self::spawnPart($world, self::MESH_SPHERE, $mat,
                    new Vec3($base->x, $headY + $headH * 0.05, $base->z),
                    new Vec3($headW * 1.04, $headH * 1.05, $headD * 1.04),
                );
                break;

            case HairStyle::Short:
                $out[] = self::spawnPart($world, self::MESH_SPHERE, $mat,
                    new Vec3($base->x, $headY + $headH * 0.18, $base->z),
                    new Vec3($headW * 1.12, $headH * 0.95, $headD * 1.12),
                );
                break;

            case HairStyle::ShortCurly:
                $out[] = self::spawnPart($world, self::MESH_SPHERE, $mat,
                    new Vec3($base->x, $headY + $headH * 0.25, $base->z),
                    new Vec3($headW * 1.28, $headH * 1.05, $headD * 1.28),
                );
                $out[] = self::spawnPart($world, self::MESH_SPHERE, $mat,
                    new Vec3($base->x - $headW * 0.45, $headY + $headH * 0.35, $base->z),
                    new Vec3($headW * 0.5, $headH * 0.5, $headD * 0.5),
                );
                $out[] = self::spawnPart($world, self::MESH_SPHERE, $mat,
                    new Vec3($base->x + $headW * 0.45, $headY + $headH * 0.35, $base->z),
                    new Vec3($headW * 0.5, $headH * 0.5, $headD * 0.5),
                );
                break;

            case HairStyle::Medium:
                $out[] = self::spawnPart($world, self::MESH_SPHERE, $mat,
                    new Vec3($base->x, $headY + $headH * 0.15, $base->z),
                    new Vec3($headW * 1.18, $headH * 1.1, $headD * 1.18),
                );
                $mStrandLen   = $headH * 0.95;
                $mStrandThick = 0.022;
                $mStrandY     = $headY + $headH * 0.05;
                foreach ([
                    [-0.50, -0.05, 0.85],
                    [-0.45, -0.25, 0.95],
                    [-0.30, -0.45, 1.00],
                    [-0.10, -0.55, 1.03],
                    [ 0.10, -0.55, 1.03],
                    [ 0.30, -0.45, 1.00],
                    [ 0.45, -0.25, 0.95],
                    [ 0.50, -0.05, 0.85],
                ] as [$xF, $zF, $lm]) {
                    self::spawnHairCard($world, $mat,
                        new Vec3(
                            $base->x + $headW * $xF,
                            $mStrandY,
                            $base->z + $headD * $zF,
                        ),
                        $mStrandLen * $lm,
                        $mStrandThick,
                        $out,
                    );
                }
                break;

            case HairStyle::Long:
                $out[] = self::spawnPart($world, self::MESH_SPHERE, $mat,
                    new Vec3($base->x, $headY + $headH * 0.18, $base->z),
                    new Vec3($headW * 1.18, $headH * 1.1, $headD * 1.18),
                );
                $strandLen   = $headH * 2.2;
                $strandThick = 0.024;
                $strandY     = $headY + $headH * 0.10;
                foreach ([
                    [-0.55,  0.05, 0.85],
                    [-0.55, -0.10, 0.92],
                    [-0.50, -0.30, 0.97],
                    [-0.40, -0.45, 1.00],
                    [-0.25, -0.55, 1.02],
                    [-0.08, -0.58, 1.03],
                    [ 0.08, -0.58, 1.03],
                    [ 0.25, -0.55, 1.02],
                    [ 0.40, -0.45, 1.00],
                    [ 0.50, -0.30, 0.97],
                    [ 0.55, -0.10, 0.92],
                    [ 0.55,  0.05, 0.85],
                ] as [$xF, $zF, $lm]) {
                    self::spawnHairCard($world, $mat,
                        new Vec3(
                            $base->x + $headW * $xF,
                            $strandY,
                            $base->z + $headD * $zF,
                        ),
                        $strandLen * $lm,
                        $strandThick,
                        $out,
                    );
                }
                break;

            case HairStyle::Ponytail:
                $out[] = self::spawnPart($world, self::MESH_SPHERE, $mat,
                    new Vec3($base->x, $headY + $headH * 0.12, $base->z),
                    new Vec3($headW * 1.05, $headH * 0.9, $headD * 1.05),
                );
                $tailLen   = $headH * 1.5;
                $tailThick = 0.022;
                $tailY     = $headY + $headH * 0.02;
                $tailZ     = $base->z - $headD * 0.55;
                $out[] = self::spawnPart($world, self::MESH_SPHERE, $mat,
                    new Vec3($base->x, $tailY, $tailZ),
                    new Vec3(0.06, 0.06, 0.06),
                );
                foreach ([-0.025, -0.012, 0.0, 0.012, 0.025] as $xOff) {
                    self::spawnHairCard($world, $mat,
                        new Vec3($base->x + $xOff, $tailY - 0.015, $tailZ),
                        $tailLen,
                        $tailThick,
                        $out,
                    );
                }
                break;

            case HairStyle::Topknot:
                $out[] = self::spawnPart($world, self::MESH_SPHERE, $mat,
                    new Vec3($base->x, $headY + $headH * 0.05, $base->z),
                    new Vec3($headW * 1.02, $headH * 0.85, $headD * 1.02),
                );
                $out[] = self::spawnPart($world, self::MESH_SPHERE, $mat,
                    new Vec3($base->x, $topY + $headH * 0.18, $base->z),
                    new Vec3($headW * 0.5, $headH * 0.5, $headD * 0.5),
                );
                break;

            case HairStyle::Mohawk:
                $out[] = self::spawnPart($world, self::MESH_SPHERE, $mat,
                    new Vec3($base->x, $headY + $headH * 0.0, $base->z),
                    new Vec3($headW * 1.02, $headH * 0.85, $headD * 1.02),
                );
                $out[] = self::spawnPart($world, self::MESH_BOX, $mat,
                    new Vec3($base->x, $topY + $headH * 0.25, $base->z),
                    new Vec3(0.04, $headH * 0.7, $headD * 1.1),
                );
                break;

            case HairStyle::Braided:
                $out[] = self::spawnPart($world, self::MESH_SPHERE, $mat,
                    new Vec3($base->x, $headY + $headH * 0.12, $base->z),
                    new Vec3($headW * 1.1, $headH * 1.0, $headD * 1.1),
                );
                for ($i = 0; $i < 3; $i++) {
                    $out[] = self::spawnPart($world, self::MESH_SPHERE, $mat,
                        new Vec3($base->x, $headY - $headH * (0.4 + $i * 0.45), $base->z - $headD * 0.5),
                        new Vec3(0.06, 0.10, 0.06),
                    );
                }
                break;

            case HairStyle::Dreadlocks:
                $out[] = self::spawnPart($world, self::MESH_SPHERE, $mat,
                    new Vec3($base->x, $headY + $headH * 0.12, $base->z),
                    new Vec3($headW * 1.2, $headH * 1.05, $headD * 1.2),
                );
                $drLen   = $headH * 1.6;
                $drThick = 0.038;
                $drY     = $headY + $headH * 0.04;
                foreach ([
                    [-0.50,  0.05, 0.85],
                    [-0.55, -0.10, 0.95],
                    [-0.50, -0.30, 1.02],
                    [-0.35, -0.45, 1.05],
                    [-0.18, -0.55, 1.08],
                    [ 0.00, -0.58, 1.10],
                    [ 0.18, -0.55, 1.08],
                    [ 0.35, -0.45, 1.05],
                    [ 0.50, -0.30, 1.02],
                    [ 0.55, -0.10, 0.95],
                    [ 0.50,  0.05, 0.85],
                ] as [$xF, $zF, $lm]) {
                    self::spawnHairCard($world, $mat,
                        new Vec3(
                            $base->x + $headW * $xF,
                            $drY,
                            $base->z + $headD * $zF,
                        ),
                        $drLen * $lm,
                        $drThick,
                        $out,
                    );
                }
                break;

            case HairStyle::Mullet:
                $out[] = self::spawnPart($world, self::MESH_SPHERE, $mat,
                    new Vec3($base->x, $headY + $headH * 0.1, $base->z),
                    new Vec3($headW * 1.08, $headH * 0.95, $headD * 1.08),
                );
                $muStrandLen   = $headH * 1.30;
                $muStrandThick = 0.026;
                $muStrandY     = $headY - $headH * 0.10;
                foreach ([
                    [-0.40, -0.50, 0.90],
                    [-0.22, -0.58, 0.98],
                    [-0.05, -0.60, 1.02],
                    [ 0.05, -0.60, 1.02],
                    [ 0.22, -0.58, 0.98],
                    [ 0.40, -0.50, 0.90],
                ] as [$xF, $zF, $lm]) {
                    self::spawnHairCard($world, $mat,
                        new Vec3(
                            $base->x + $headW * $xF,
                            $muStrandY,
                            $base->z + $headD * $zF,
                        ),
                        $muStrandLen * $lm,
                        $muStrandThick,
                        $out,
                    );
                }
                break;

            default:
                break;
        }
    }
}
