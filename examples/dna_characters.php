<?php

/**
 * PHPolygon - Visual Character DNA showcase
 *
 * Lines up 5 procedural humanoids built purely from the DNA system:
 *   CharacterDNA -> GeneDecoder -> PlayerProportions -> primitive rig
 *
 * Each character is a stick-figure rig of unit primitives (box torso/hip,
 * sphere head, cylinder limbs, eye dots, style-dependent hair blob) whose
 * scale, position, and material are driven entirely by the decoded
 * proportions. No external models, no Blender.
 *
 * Controls:
 *   SPACE - Re-roll all characters
 *   F1    - Toggle slow turntable rotation
 *   ESC   - Quit
 *
 * Run: php examples/dna_characters.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use PHPolygon\Character\DNA\CharacterDNA;
use PHPolygon\Character\DNA\Enum\Accessory;
use PHPolygon\Character\DNA\Enum\EyeColor;
use PHPolygon\Character\DNA\Enum\EyeShape;
use PHPolygon\Character\DNA\Enum\FacialHair;
use PHPolygon\Character\DNA\Enum\HairColor;
use PHPolygon\Character\DNA\Enum\HairStyle;
use PHPolygon\Character\DNA\Enum\NoseShape;
use PHPolygon\Character\DNA\Enum\SkinTone;
use PHPolygon\Character\DNA\GeneDecoder;
use PHPolygon\Character\DNA\PlayerProportions;
use PHPolygon\Component\Camera3DComponent;
use PHPolygon\Component\DirectionalLight;
use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\Entity;
use PHPolygon\ECS\World;
use PHPolygon\Engine;
use PHPolygon\EngineConfig;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\CylinderMesh;
use PHPolygon\Geometry\LatheMesh;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Geometry\PlaneMesh;
use PHPolygon\Geometry\SphereMesh;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec2;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\Command\SetFog;
use PHPolygon\Rendering\Command\SetSkybox;
use PHPolygon\Rendering\CubemapRegistry;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\ProceduralSky;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\System\Camera3DSystem;
use PHPolygon\System\Renderer3DSystem;

const CHARACTER_COUNT  = 5;
const CHARACTER_SPACING = 1.6;

$engine = new Engine(new EngineConfig(
    title:                  'PHPolygon - DNA Characters',
    width:                  1280,
    height:                 720,
    is3D:                   true,
    skipSplash:             true,
    firstLaunchCalibration: false,
));

$decoder        = new GeneDecoder();
$characterRoots = [];   // list<Entity>     root anchors per character (for rotation)
$characterParts = [];   // list<list<Entity>> all parts per character (for destruction)
$turntable      = true;
$angle          = 0.0;

$engine->onInit(function () use ($engine, $decoder, &$characterRoots, &$characterParts): void {
    registerMeshes();
    registerMaterials();

    $sunDir = (new Vec3(-0.4, -0.8, -0.5))->normalize();
    CubemapRegistry::registerProcedural('sky', ProceduralSky::sunset($sunDir)->generate(256));

    $renderer3D = $engine->renderer3D
        ?? throw new \RuntimeException('Renderer3D is required (set is3D: true).');

    $commandList = $engine->commandList3D ?? new RenderCommandList();
    $engine->world->addSystem(new Camera3DSystem($commandList, 1280, 720));
    $engine->world->addSystem(new Renderer3DSystem($renderer3D, $commandList));

    $camera = $engine->world->createEntity();
    $camera->attach(new Camera3DComponent(fov: 45.0, near: 0.1, far: 60.0, active: true));
    $camera->attach(new Transform3D(position: new Vec3(0.0, 1.5, 5.5)));

    $sun = $engine->world->createEntity();
    $sun->attach(new DirectionalLight(
        direction: new Vec3(-0.4, -1.0, -0.5),
        color: new Color(1.0, 0.95, 0.85),
        intensity: 1.3,
    ));
    $sun->attach(new Transform3D());

    $ground = $engine->world->createEntity();
    $ground->attach(new MeshRenderer('ground', 'stone_floor'));
    $ground->attach(new Transform3D(position: new Vec3(0.0, 0.0, 0.0)));

    $commandList->add(new SetAmbientLight(new Color(0.30, 0.30, 0.36), 1.0));
    $commandList->add(new SetFog(new Color(0.55, 0.55, 0.65), 25.0, 60.0));
    $commandList->add(new SetSkybox('sky'));

    spawnCharacters($engine->world, $decoder, $characterRoots, $characterParts);
});

$engine->onUpdate(function (Engine $engine, float $dt) use ($decoder, &$characterRoots, &$characterParts, &$turntable, &$angle): void {
    if ($engine->input->isKeyPressed(256)) { // ESC
        $engine->stop();
    }

    if ($engine->input->isKeyPressed(32)) {  // SPACE - reroll
        despawnCharacters($characterParts);
        $characterRoots = [];
        $characterParts = [];
        spawnCharacters($engine->world, $decoder, $characterRoots, $characterParts);
    }

    if ($engine->input->isKeyPressed(290)) { // F1 - toggle turntable
        $turntable = !$turntable;
    }

    if ($turntable) {
        $angle += $dt * 0.4;
    }

    $rotation = Quaternion::fromAxisAngle(new Vec3(0.0, 1.0, 0.0), $angle);
    foreach ($characterRoots as $root) {
        /** @var Transform3D $t */
        $t = $root->get(Transform3D::class);
        $t->rotation = $rotation;
    }
});

$engine->run();

// ---------------------------------------------------------------------------
// Mesh + material setup (called once)
// ---------------------------------------------------------------------------

function registerMeshes(): void
{
    MeshRegistry::register('ground',        PlaneMesh::generate(40.0, 40.0));
    MeshRegistry::register('unit_box',      BoxMesh::generate(1.0, 1.0, 1.0));
    MeshRegistry::register('unit_sphere',   SphereMesh::generate(0.5, 12, 20));
    MeshRegistry::register('unit_cylinder', CylinderMesh::generate(0.5, 1.0, 12));
    MeshRegistry::register('eye_sphere',    SphereMesh::generate(0.5, 8, 12));

    // Normalised torso profile: revolved around the Y axis to give one
    // single solid that replaces the waist box + hip-waist caps + chest
    // box. Profile lives in [0, 0.5] radius and [0, 1] height, so the
    // mesh fits a 1x1x1 unit box and is positioned/sized per-character
    // through Transform3D.scale.
    MeshRegistry::register('torso_lathe', LatheMesh::generate([
        new Vec2(0.00, 0.00),   // bottom pole (sits inside the hip belt)
        new Vec2(0.42, 0.04),   // hip joint - ring picks up quickly
        new Vec2(0.40, 0.30),   // waist - narrowest point of the silhouette
        new Vec2(0.46, 0.55),   // chest swell
        new Vec2(0.50, 0.78),   // upper chest / pec line
        new Vec2(0.42, 0.92),   // shoulder slope
        new Vec2(0.28, 0.98),   // neck approach
        new Vec2(0.00, 1.00),   // top pole (hidden under the neck)
    ], 24));
}

function registerMaterials(): void
{
    MaterialRegistry::register('stone_floor', new Material(
        albedo: new Color(0.35, 0.34, 0.32), roughness: 0.95, metallic: 0.0,
    ));
    MaterialRegistry::register('cloth_dark', new Material(
        albedo: new Color(0.18, 0.18, 0.22), roughness: 0.85, metallic: 0.0,
    ));
    MaterialRegistry::register('eye_white', new Material(
        albedo: new Color(0.95, 0.94, 0.90), roughness: 0.4, metallic: 0.0,
    ));
    MaterialRegistry::register('mouth', new Material(
        albedo: new Color(0.35, 0.12, 0.12), roughness: 0.7, metallic: 0.0,
    ));

    foreach (SkinTone::cases() as $tone) {
        $albedo = Color::hex($tone->value);
        $luminance = 0.299 * $albedo->r + 0.587 * $albedo->g + 0.114 * $albedo->b;
        // Warm transmission tint derived from the albedo - red dominant,
        // green/blue dropped, so the wrap-diffuse + terminator bleed reads
        // as blood under the skin rather than a flat colour shift.
        $sss = new Color(
            min(1.0, $albedo->r * 1.2 + 0.15),
            $albedo->g * 0.55,
            $albedo->b * 0.40,
        );
        // Lighter skin shows the SSS more strongly because there is less
        // melanin absorbing the scatter. Dark skin still gets SSS but
        // dialled down so the visual contribution stays subtle.
        $strength = 0.4 + $luminance * 0.5;
        MaterialRegistry::register('skin_' . $tone->name, Material::skin(
            albedo: $albedo,
            subsurfaceColor: $sss,
            subsurfaceStrength: $strength,
            roughness: 0.55,
        ));
    }
    foreach (HairColor::cases() as $color) {
        $albedo = Color::hex($color->value);
        MaterialRegistry::register('hair_' . $color->name, new Material(
            albedo: $albedo, roughness: 0.55, metallic: 0.0,
        ));
        // Facial hair shares the hair colour but slightly darker + matte so
        // beard/stubble reads as denser than scalp hair.
        MaterialRegistry::register('facial_hair_' . $color->name, new Material(
            albedo: new Color($albedo->r * 0.70, $albedo->g * 0.70, $albedo->b * 0.70),
            roughness: 0.80,
            metallic: 0.0,
        ));
    }
    foreach (EyeColor::cases() as $color) {
        MaterialRegistry::register('eye_' . $color->name, new Material(
            albedo: Color::hex($color->value), roughness: 0.25, metallic: 0.0,
        ));
    }

    // Accessory materials - shared across all characters.
    MaterialRegistry::register('accessory_metal', new Material(
        albedo: new Color(0.85, 0.78, 0.42), roughness: 0.30, metallic: 0.9,
    ));
    MaterialRegistry::register('accessory_dark', new Material(
        albedo: new Color(0.08, 0.08, 0.10), roughness: 0.40, metallic: 0.1,
    ));
    MaterialRegistry::register('accessory_glass', new Material(
        albedo: new Color(0.12, 0.14, 0.18), roughness: 0.20, metallic: 0.0,
    ));
}

// ---------------------------------------------------------------------------
// Character spawning
// ---------------------------------------------------------------------------

/**
 * @param list<Entity>       $roots
 * @param list<list<Entity>> $parts
 */
function spawnCharacters(World $world, GeneDecoder $decoder, array &$roots, array &$parts): void
{
    $totalWidth = (CHARACTER_COUNT - 1) * CHARACTER_SPACING;
    $startX     = -$totalWidth / 2.0;

    echo "\n== New roster ==\n";

    for ($i = 0; $i < CHARACTER_COUNT; $i++) {
        $dna   = CharacterDNA::random();
        $props = $decoder->decode($dna, PlayerProportions::class);

        $root = $world->createEntity();
        $root->attach(new Transform3D(
            position: new Vec3($startX + $i * CHARACTER_SPACING, 0.0, 0.0),
        ));

        $created = [$root];
        buildCharacter($world, $props, $root, $created);

        $roots[]  = $root;
        $parts[]  = $created;

        printf(
            "  #%d  h=%.2f  skin=%-13s hair=%-10s style=%-11s eyes=%s\n",
            $i + 1,
            $props->bodyHeight,
            $props->skinTone->name,
            $props->hairColor->name,
            $props->hairStyle->name,
            $props->eyeColor->name,
        );
        printf(
            "     beard=%-9s nose=%-8s ears=%.2f  age=%.2f  build=%+.2f  extra=%s\n",
            $props->facialHair->name,
            $props->noseShape->name,
            $props->earSize,
            $props->age,
            $props->buildBias,
            $props->accessory->name,
        );
        printf("     ACGT: %s\n", $dna->toAcgt());
    }
}

/** @param list<list<Entity>> $parts */
function despawnCharacters(array $parts): void
{
    foreach ($parts as $group) {
        foreach ($group as $entity) {
            if ($entity->isAlive()) {
                $entity->destroy();
            }
        }
    }
}

// ---------------------------------------------------------------------------
// Humanoid rig - all dimensions in metres, scaled by bodyHeight
// ---------------------------------------------------------------------------

/** @param list<Entity> $out */
function buildCharacter(World $world, PlayerProportions $p, Entity $root, array &$out): void
{
    $h = $p->bodyHeight;

    // BuildBias in [-1, 1]: -1 lean, 0 neutral, +1 stocky. Modulates limb
    // thickness, torso girth, and waist width. Keep amplitudes small so the
    // silhouette stays recognisable as the same skeleton.
    $bias    = $p->buildBias;
    $thickMul = 1.0 + 0.22 * $bias;     // limb radii
    $girthMul = 1.0 + 0.18 * $bias;     // chest depth, waist depth
    $waistMul = 1.0 + 0.15 * $bias;     // waist width relative to chest

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

    $skinMat = 'skin_' . $p->skinTone->name;

    $base = $root->get(Transform3D::class)->position;

    $hipY     = $base->y + $legH + $hipH * 0.5;
    $neckY    = $base->y + $legH + $hipH + $waistH + $chestH + $neckH * 0.5;
    $headY    = $neckY + $neckH * 0.5 + $headH * 0.5;
    $shldY    = $base->y + $legH + $hipH + $waistH + $chestH - 0.04 * $h;

    // Legs - segmented: thigh + knee + calf + ankle + foot. Splitting at
    // the knee makes the silhouette read as "leg" rather than "pillar"
    // even on a static rig, and the joint sphere bulges out enough to
    // catch a highlight under direct light.
    //
    // Anatomical-ish split: femur ~55 % of leg, tibia ~45 %. Summing to
    // 1.0 means the thigh top reaches the hip line with no gap.
    $legOffset = $hipW * 0.35;
    $thighH = $legH * 0.55;
    $calfH  = $legH * 0.45;
    $kneeY  = $base->y + $calfH;          // knee at top of calf
    $thighR = $legR * 1.05;
    $calfR  = $legR * 0.92;
    $kneeR  = $legR * 1.10;
    $ankleR = $legR * 0.85;
    foreach ([-$legOffset, $legOffset] as $sx) {
        // Thigh (top half)
        $out[] = spawnPart($world, 'unit_cylinder', $skinMat,
            new Vec3($base->x + $sx, $kneeY + $thighH * 0.5, $base->z),
            new Vec3($thighR * 2, $thighH, $thighR * 2),
        );
        // Knee joint
        $out[] = spawnPart($world, 'unit_sphere', $skinMat,
            new Vec3($base->x + $sx, $kneeY, $base->z),
            new Vec3($kneeR * 2, $kneeR * 2, $kneeR * 2),
        );
        // Calf (bottom half)
        $out[] = spawnPart($world, 'unit_cylinder', $skinMat,
            new Vec3($base->x + $sx, $base->y + $calfH * 0.5, $base->z),
            new Vec3($calfR * 2, $calfH, $calfR * 2),
        );
        // Ankle (small joint above the foot)
        $out[] = spawnPart($world, 'unit_sphere', $skinMat,
            new Vec3($base->x + $sx, $base->y + $ankleR, $base->z),
            new Vec3($ankleR * 2, $ankleR * 2, $ankleR * 2),
        );
    }

    // Feet - heel (wider, taller) + toe (narrower, shorter) so the foot
    // reads as a shoe shape rather than a flat tile.
    $footW   = $legR * 2.4;
    $heelL   = $legR * 1.5;
    $toeL    = $legR * 1.9;
    $heelH   = 0.045 * $h;
    $toeH    = 0.030 * $h;
    foreach ([-$legOffset, $legOffset] as $sx) {
        // Heel - centred under the ankle
        $out[] = spawnPart($world, 'unit_box', 'cloth_dark',
            new Vec3($base->x + $sx, $base->y + $heelH * 0.5, $base->z - $heelL * 0.3),
            new Vec3($footW, $heelH, $heelL),
        );
        // Toe - in front of heel, narrower
        $out[] = spawnPart($world, 'unit_box', 'cloth_dark',
            new Vec3($base->x + $sx, $base->y + $toeH * 0.5, $base->z + ($heelL * 0.5) + $toeL * 0.3),
            new Vec3($footW * 0.85, $toeH, $toeL),
        );
    }

    // Hip belt
    $out[] = spawnPart($world, 'unit_box', 'cloth_dark',
        new Vec3($base->x, $hipY, $base->z),
        new Vec3($hipW, $hipH, $waistD * 1.05),
    );

    // Torso - single tapered solid of revolution that covers waist + chest
    // in one mesh. The normalised profile already encodes the hourglass
    // silhouette; scale.x/scale.z modulate breadth/depth from DNA, and
    // scale.y stretches the profile to match waistH + chestH. Bottom pole
    // sinks slightly into the hip belt so the seam is hidden.
    $torsoH       = $waistH + $chestH;
    $torsoBottom  = $base->y + $legH + $hipH - 0.005 * $h;
    $torsoBreadth = max($waistW, $shldW * 0.95);
    $torsoDepth   = max($waistD, $chestD * 0.95);
    $out[] = spawnPart($world, 'torso_lathe', $skinMat,
        new Vec3($base->x, $torsoBottom, $base->z),
        new Vec3($torsoBreadth, $torsoH, $torsoDepth),
    );

    // Shoulder caps (round off the boxy shoulders)
    foreach ([-1, 1] as $side) {
        $out[] = spawnPart($world, 'unit_sphere', $skinMat,
            new Vec3($base->x + $side * $shldW * 0.5, $shldY, $base->z),
            new Vec3($armR * 2.4, $armR * 2.4, $armR * 2.4),
        );
    }

    // Neck - cylinder for the bulk, sphere on top to widen into the jaw
    // line so the head doesn't sit on a perfect pillar.
    $out[] = spawnPart($world, 'unit_cylinder', $skinMat,
        new Vec3($base->x, $neckY, $base->z),
        new Vec3(0.085 * $h, $neckH, 0.085 * $h),
    );
    $out[] = spawnPart($world, 'unit_sphere', $skinMat,
        new Vec3($base->x, $neckY + $neckH * 0.45, $base->z),
        new Vec3(0.11 * $h * $p->skullWidth, 0.04 * $h, 0.10 * $h * $p->skullWidth),
    );

    // Arms - segmented: upper arm + elbow + forearm + wrist + hand.
    // Same logic as legs: the joint sphere catches a highlight and makes
    // the arm read as anatomical rather than a single broomstick.
    //
    // Arms drift outward by ~6 % of the shoulder width between the
    // shoulder and the wrist, so they don't hang strictly vertical -
    // matches a natural relaxed stance and gives the silhouette some
    // bounce.
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

        // Upper arm - between shoulder and elbow X
        $out[] = spawnPart($world, 'unit_cylinder', $skinMat,
            new Vec3(($shoulderX + $elbowX) * 0.5, $shldY - $upperArmH * 0.5, $base->z),
            new Vec3($upperArmR * 2, $upperArmH, $upperArmR * 2),
        );
        // Elbow joint
        $out[] = spawnPart($world, 'unit_sphere', $skinMat,
            new Vec3($elbowX, $elbowY, $base->z),
            new Vec3($elbowR * 2, $elbowR * 2, $elbowR * 2),
        );
        // Forearm - between elbow and wrist X
        $out[] = spawnPart($world, 'unit_cylinder', $skinMat,
            new Vec3(($elbowX + $wristX) * 0.5, $wristY + $forearmH * 0.5, $base->z),
            new Vec3($forearmR * 2, $forearmH, $forearmR * 2),
        );
        // Wrist joint
        $out[] = spawnPart($world, 'unit_sphere', $skinMat,
            new Vec3($wristX, $wristY, $base->z),
            new Vec3($wristR * 2, $wristR * 2, $wristR * 2),
        );
        // Palm - flattened sphere just below wrist
        $palmY = $wristY - $armR * 0.85;
        $palmW = $armR * 2.2;
        $palmH = $armR * 0.9;
        $palmD = $armR * 2.2;
        $out[] = spawnPart($world, 'unit_sphere', $skinMat,
            new Vec3($wristX, $palmY, $base->z),
            new Vec3($palmW, $palmH * 2.0, $palmD),
        );
        // Four finger stubs hanging below the palm. Slight horizontal
        // spread so they read as individual fingers, not one paddle.
        $fingerLen   = $armR * 1.4;
        $fingerR     = $armR * 0.30;
        $fingerTopY  = $palmY - $palmH * 0.7;
        $fingerCtrY  = $fingerTopY - $fingerLen * 0.5;
        for ($f = 0; $f < 4; $f++) {
            $offsetX = (($f - 1.5) / 1.5) * ($palmW * 0.30);
            $out[] = spawnPart($world, 'unit_cylinder', $skinMat,
                new Vec3($wristX + $offsetX, $fingerCtrY, $base->z),
                new Vec3($fingerR * 2, $fingerLen, $fingerR * 2),
            );
        }
        // Thumb - shorter stub on the inside of the palm
        $thumbDir = -$dir; // thumb points toward body centre
        $out[] = spawnPart($world, 'unit_cylinder', $skinMat,
            new Vec3(
                $wristX + $thumbDir * $palmW * 0.45,
                $palmY - $palmH * 0.1,
                $base->z + $palmD * 0.10,
            ),
            new Vec3($fingerR * 2.1, $fingerLen * 0.7, $fingerR * 2.1),
        );
    }

    // Head
    $out[] = spawnPart($world, 'unit_sphere', $skinMat,
        new Vec3($base->x, $headY, $base->z),
        new Vec3($headW, $headH, $headD),
    );

    // Ears - small sphere stubs on each side of the skull at eye-line
    // height. Slightly squashed front-to-back so they read as ears, not
    // skull-mounted balls. earSize scales the whole ear volume around the
    // attachment point so a 1.35 multiplier reads as Spock-grade ears.
    $earScale = $p->earSize;
    $earCenterY = $headY + $headH * 0.05;
    $earCenterX = $headW * 0.52;
    foreach ([-1, 1] as $earSide) {
        $out[] = spawnPart($world, 'unit_sphere', $skinMat,
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

    // Eyebrows - thin horizontal bars in the hair colour, above the eyes.
    // Hair on a bald scalp still has eyebrows, so this isn't gated on
    // hair style. eyebrowThickness scales width + height; eyebrowAngle
    // tilts each bar around the head's forward axis, mirrored per side so
    // a positive angle reads as "inner-up" (concerned) and a negative
    // angle as "outer-up" (skeptical).
    $browMat = 'hair_' . $p->hairColor->name;
    $browOffsetX = $headW * 0.32 * $p->eyeSpacing;
    $browW = $headW * 0.32 * $p->eyebrowThickness;
    $browH = 0.018 * $h * (0.7 + 0.6 * $p->eyebrowThickness);
    $browD = 0.012 * $h;
    $browZ = $base->z + $headD * 0.5 - 0.003 * $h;
    $browY = $headY + $headH * 0.14;
    foreach ([-1, 1] as $bs) {
        $angle = $p->eyebrowAngle * $bs;       // mirror across the centre line
        $rot = Quaternion::fromAxisAngle(new Vec3(0.0, 0.0, 1.0), $angle);
        $out[] = spawnPartRot($world, 'unit_box', $browMat,
            new Vec3($base->x + $bs * $browOffsetX, $browY, $browZ),
            new Vec3($browW, $browH, $browD),
            $rot,
        );
    }

    // Jaw - jawWidth pushes a small wedge below the head front
    $out[] = spawnPart($world, 'unit_sphere', $skinMat,
        new Vec3($base->x, $headY - $headH * 0.35, $base->z + $headD * 0.05),
        new Vec3($headW * (0.55 + 0.35 * $p->jawWidth), $jawDrop, $headD * 0.9),
    );

    // Brow ridge (skin-coloured ridge above the brows, only on prominent
    // brows - keeps it from looking like sunglasses on subtle faces).
    $browProm = $p->browProminence;
    if ($browProm > 0.25) {
        $browZ = $base->z + $headD * 0.5 - 0.005 * $h;
        $out[] = spawnPart($world, 'unit_box', $skinMat,
            new Vec3($base->x, $headY + $headH * 0.20, $browZ),
            new Vec3($headW * 0.7, 0.012 * $h, 0.025 * (0.5 + $browProm) * $h),
        );
    }

    // Nose - shape varies per NoseShape. The first sphere is the bridge
    // bulk; the optional second sphere is the tip (hooked = drooped tip).
    [$noseW, $noseHi, $noseDp, $noseDip] = noseShapeDims($p->noseShape);
    $noseY = $headY - $headH * 0.05;
    $noseZ = $base->z + $headD * 0.5 + 0.015 * $h;
    $out[] = spawnPart($world, 'unit_sphere', $skinMat,
        new Vec3($base->x, $noseY, $noseZ),
        new Vec3($noseW * $h, $noseHi * $h, $noseDp * $h),
    );
    if ($noseDip > 0.0) {
        // Tip droop for the hooked nose - small sphere pulled down + slightly
        // further forward so the silhouette shows a beak.
        $out[] = spawnPart($world, 'unit_sphere', $skinMat,
            new Vec3(
                $base->x,
                $noseY - $noseHi * $h * $noseDip,
                $noseZ + $noseDp * $h * 0.25,
            ),
            new Vec3($noseW * $h * 0.85, $noseHi * $h * 0.55, $noseDp * $h * 0.55),
        );
    }

    // Mouth (thin dark line)
    $out[] = spawnPart($world, 'unit_box', 'mouth',
        new Vec3($base->x, $headY - $headH * 0.28, $base->z + $headD * 0.5 - 0.002 * $h),
        new Vec3($headW * 0.35, 0.012 * $h, 0.005 * $h),
    );

    // Eyes (sclera recessed slightly into the socket so they read as eyes, not goggles)
    $eyeOffsetX = $headW * 0.32 * $p->eyeSpacing;
    $eyeY       = $headY + $headH * 0.05;
    $eyeZ       = $base->z + $headD * 0.5 - 0.005 * $h;
    [$ex, $ey] = eyeShapeScale($p->eyeShape);
    $scleraSize = 0.055 * $h;
    $irisSize   = 0.028 * $h;

    foreach ([-$eyeOffsetX, $eyeOffsetX] as $sx) {
        $out[] = spawnPart($world, 'unit_sphere', 'eye_white',
            new Vec3($base->x + $sx, $eyeY, $eyeZ),
            new Vec3($scleraSize * $ex, $scleraSize * $ey, $scleraSize * 0.6),
        );
        $out[] = spawnPart($world, 'unit_sphere', 'eye_' . $p->eyeColor->name,
            new Vec3($base->x + $sx, $eyeY, $eyeZ + 0.015 * $h),
            new Vec3($irisSize * $ex, $irisSize * $ey, $irisSize * 0.5),
        );
    }

    // Facial hair - rendered against the lower face. Sits in front of the
    // mouth/jaw geometry so jaw outline still reads through.
    buildFacialHair($world, $p, $base, $headY, $headW, $headH, $headD, $out);

    // Age detail - faint dark line under each eye at high age. Cheap
    // "experienced face" cue without rebuilding the skull.
    if ($p->age > 0.65) {
        $wrinkleZ = $base->z + $headD * 0.5 - 0.004 * $h;
        $wrinkleY = $eyeY - $scleraSize * 0.95;
        $wrinkleAlpha = ($p->age - 0.65) / 0.35;   // 0..1 across the band
        foreach ([-$eyeOffsetX, $eyeOffsetX] as $sx) {
            $out[] = spawnPart($world, 'unit_box', 'mouth',
                new Vec3($base->x + $sx, $wrinkleY, $wrinkleZ),
                new Vec3($headW * 0.18, 0.005 * $h, 0.003 * $h * (0.5 + $wrinkleAlpha)),
            );
        }
    }

    // Hair / scalp
    buildHair($world, $p, $base, $headY, $headW, $headH, $headD, $out);

    // Accessories - spawned last so they overlay everything else.
    buildAccessory(
        $world, $p, $base,
        $headY, $headW, $headH, $headD,
        $eyeOffsetX, $eyeY, $eyeZ,
        $earCenterX, $earCenterY,
        $neckY, $neckH, $shldW,
        $out,
    );
}

function spawnPart(World $world, string $mesh, string $material, Vec3 $pos, Vec3 $scale): Entity
{
    $e = $world->createEntity();
    $e->attach(new MeshRenderer($mesh, $material));
    $e->attach(new Transform3D(position: $pos, scale: $scale));
    return $e;
}

function spawnPartRot(World $world, string $mesh, string $material, Vec3 $pos, Vec3 $scale, Quaternion $rot): Entity
{
    $e = $world->createEntity();
    $e->attach(new MeshRenderer($mesh, $material));
    $e->attach(new Transform3D(position: $pos, rotation: $rot, scale: $scale));
    return $e;
}

/**
 * Returns nose dimensions as [width, height, depth, dipFactor] - all in
 * relative-to-bodyHeight units; the caller multiplies by $h. dipFactor > 0
 * spawns a downward "tip droop" sphere; 0.0 disables it.
 *
 * @return array{0: float, 1: float, 2: float, 3: float}
 */
function noseShapeDims(NoseShape $shape): array
{
    return match ($shape) {
        NoseShape::Button   => [0.026, 0.030, 0.038, 0.0],
        NoseShape::Straight => [0.022, 0.055, 0.040, 0.0],
        NoseShape::Wide     => [0.038, 0.034, 0.034, 0.0],
        NoseShape::Pointed  => [0.018, 0.045, 0.058, 0.0],
        NoseShape::Hooked   => [0.024, 0.050, 0.044, 0.55],
    };
}

/** @param list<Entity> $out */
function buildFacialHair(World $world, PlayerProportions $p, Vec3 $base, float $headY, float $headW, float $headH, float $headD, array &$out): void
{
    if ($p->facialHair === FacialHair::None) {
        return;
    }

    $mat = 'facial_hair_' . $p->hairColor->name;
    $faceZ = $base->z + $headD * 0.5 - 0.002 * 1.0;   // skim the face plane
    $mouthY = $headY - $headH * 0.28;

    switch ($p->facialHair) {
        case FacialHair::Stubble:
            // Thin dusting from cheekbones down to chin - one wide flat
            // patch reads as 5-o'clock shadow.
            $out[] = spawnPart($world, 'unit_box', $mat,
                new Vec3($base->x, $mouthY - $headH * 0.04, $faceZ),
                new Vec3($headW * 0.85, $headH * 0.30, 0.004),
            );
            break;

        case FacialHair::Mustache:
            // Short horizontal bar just above the mouth.
            $out[] = spawnPart($world, 'unit_box', $mat,
                new Vec3($base->x, $mouthY + $headH * 0.04, $faceZ),
                new Vec3($headW * 0.42, $headH * 0.07, 0.012),
            );
            break;

        case FacialHair::Goatee:
            // Small patch under the lower lip + a slightly thicker chin tuft.
            $out[] = spawnPart($world, 'unit_box', $mat,
                new Vec3($base->x, $mouthY - $headH * 0.10, $faceZ),
                new Vec3($headW * 0.22, $headH * 0.18, 0.012),
            );
            $out[] = spawnPart($world, 'unit_sphere', $mat,
                new Vec3($base->x, $mouthY - $headH * 0.22, $faceZ),
                new Vec3($headW * 0.20, $headH * 0.14, 0.020),
            );
            break;

        case FacialHair::FullBeard:
            // Wraps the jaw - one wide box spanning ear-to-ear and dropping
            // below the head, plus a sphere on the chin for volume.
            $out[] = spawnPart($world, 'unit_box', $mat,
                new Vec3($base->x, $mouthY - $headH * 0.16, $faceZ - 0.005),
                new Vec3($headW * 0.92, $headH * 0.42, 0.030),
            );
            $out[] = spawnPart($world, 'unit_sphere', $mat,
                new Vec3($base->x, $mouthY - $headH * 0.30, $faceZ + 0.005),
                new Vec3($headW * 0.55, $headH * 0.32, 0.045),
            );
            break;

        case FacialHair::Sideburns:
            // Two vertical strips running down the temples to the jawline.
            foreach ([-1, 1] as $side) {
                $out[] = spawnPart($world, 'unit_box', $mat,
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
function buildAccessory(
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
            // Two thin lens frames in front of the eyes + a bridge bar.
            $lensW = $headW * 0.30;
            $lensH = $headH * 0.18;
            $lensZ = $eyeZ + 0.030;
            foreach ([-$eyeOffsetX, $eyeOffsetX] as $sx) {
                // Lens fill - dark tinted glass
                $out[] = spawnPart($world, 'unit_box', 'accessory_glass',
                    new Vec3($base->x + $sx, $eyeY, $lensZ),
                    new Vec3($lensW * 0.85, $lensH * 0.85, 0.004),
                );
                // Frame ring - slightly larger box behind the glass
                $out[] = spawnPart($world, 'unit_box', 'accessory_dark',
                    new Vec3($base->x + $sx, $eyeY, $lensZ - 0.003),
                    new Vec3($lensW, $lensH, 0.006),
                );
            }
            // Bridge connecting the lenses
            $out[] = spawnPart($world, 'unit_box', 'accessory_dark',
                new Vec3($base->x, $eyeY, $lensZ - 0.003),
                new Vec3($eyeOffsetX * 2.0 - $lensW * 0.95, $lensH * 0.10, 0.006),
            );
            return;

        case Accessory::Earrings:
            // Tiny gold spheres dangling from the lower edge of each ear.
            $earringR = $headW * 0.04 * $p->earSize;
            foreach ([-1, 1] as $side) {
                $out[] = spawnPart($world, 'unit_sphere', 'accessory_metal',
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
            // Thin metallic ring around the forehead - approximated by a
            // wide cylinder sized to wrap the skull.
            $out[] = spawnPart($world, 'unit_cylinder', 'accessory_metal',
                new Vec3($base->x, $headY + $headH * 0.30, $base->z),
                new Vec3($headW * 1.08, $headH * 0.10, $headD * 1.08),
            );
            return;

        case Accessory::Necklace:
            // Arc of small spheres at the base of the neck. Spans the
            // shoulder width so it sits naturally on the collarbone.
            $arcWidth = $shldW * 0.55;
            $arcY = $neckY - $neckH * 0.40;
            $arcRingZ = $base->z + $headD * 0.45;
            $beadCount = 7;
            $beadR = $headW * 0.05;
            for ($i = 0; $i < $beadCount; $i++) {
                $t = $i / ($beadCount - 1);   // 0..1
                $bx = -$arcWidth + 2.0 * $arcWidth * $t;
                // Drape - centre beads sit lower (parabolic)
                $drop = 1.0 - 4.0 * ($t - 0.5) * ($t - 0.5);
                $out[] = spawnPart($world, 'unit_sphere', 'accessory_metal',
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

/** @return array{0: float, 1: float} */
function eyeShapeScale(EyeShape $shape): array
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
function buildHair(World $world, PlayerProportions $p, Vec3 $base, float $headY, float $headW, float $headH, float $headD, array &$out): void
{
    if ($p->hairStyle === HairStyle::Bald) {
        return;
    }

    $mat = 'hair_' . $p->hairColor->name;
    $topY = $headY + $headH * 0.5;

    switch ($p->hairStyle) {
        case HairStyle::BuzzCut:
            $out[] = spawnPart($world, 'unit_sphere', $mat,
                new Vec3($base->x, $headY + $headH * 0.05, $base->z),
                new Vec3($headW * 1.04, $headH * 1.05, $headD * 1.04),
            );
            break;

        case HairStyle::Short:
            $out[] = spawnPart($world, 'unit_sphere', $mat,
                new Vec3($base->x, $headY + $headH * 0.18, $base->z),
                new Vec3($headW * 1.12, $headH * 0.95, $headD * 1.12),
            );
            break;

        case HairStyle::ShortCurly:
            $out[] = spawnPart($world, 'unit_sphere', $mat,
                new Vec3($base->x, $headY + $headH * 0.25, $base->z),
                new Vec3($headW * 1.28, $headH * 1.05, $headD * 1.28),
            );
            // Two curl blobs
            $out[] = spawnPart($world, 'unit_sphere', $mat,
                new Vec3($base->x - $headW * 0.45, $headY + $headH * 0.35, $base->z),
                new Vec3($headW * 0.5, $headH * 0.5, $headD * 0.5),
            );
            $out[] = spawnPart($world, 'unit_sphere', $mat,
                new Vec3($base->x + $headW * 0.45, $headY + $headH * 0.35, $base->z),
                new Vec3($headW * 0.5, $headH * 0.5, $headD * 0.5),
            );
            break;

        case HairStyle::Medium:
            $out[] = spawnPart($world, 'unit_sphere', $mat,
                new Vec3($base->x, $headY + $headH * 0.15, $base->z),
                new Vec3($headW * 1.18, $headH * 1.1, $headD * 1.18),
            );
            $out[] = spawnPart($world, 'unit_box', $mat,
                new Vec3($base->x, $headY - $headH * 0.05, $base->z - $headD * 0.45),
                new Vec3($headW * 1.05, $headH * 0.8, 0.06),
            );
            break;

        case HairStyle::Long:
            $out[] = spawnPart($world, 'unit_sphere', $mat,
                new Vec3($base->x, $headY + $headH * 0.18, $base->z),
                new Vec3($headW * 1.18, $headH * 1.1, $headD * 1.18),
            );
            $out[] = spawnPart($world, 'unit_box', $mat,
                new Vec3($base->x, $headY - $headH * 1.0, $base->z - $headD * 0.35),
                new Vec3($headW * 1.1, $headH * 2.4, 0.08),
            );
            break;

        case HairStyle::Ponytail:
            $out[] = spawnPart($world, 'unit_sphere', $mat,
                new Vec3($base->x, $headY + $headH * 0.12, $base->z),
                new Vec3($headW * 1.05, $headH * 0.9, $headD * 1.05),
            );
            $out[] = spawnPart($world, 'unit_cylinder', $mat,
                new Vec3($base->x, $headY - $headH * 0.3, $base->z - $headD * 0.55),
                new Vec3(0.06, $headH * 1.6, 0.06),
            );
            break;

        case HairStyle::Topknot:
            $out[] = spawnPart($world, 'unit_sphere', $mat,
                new Vec3($base->x, $headY + $headH * 0.05, $base->z),
                new Vec3($headW * 1.02, $headH * 0.85, $headD * 1.02),
            );
            $out[] = spawnPart($world, 'unit_sphere', $mat,
                new Vec3($base->x, $topY + $headH * 0.18, $base->z),
                new Vec3($headW * 0.5, $headH * 0.5, $headD * 0.5),
            );
            break;

        case HairStyle::Mohawk:
            $out[] = spawnPart($world, 'unit_sphere', $mat,
                new Vec3($base->x, $headY + $headH * 0.0, $base->z),
                new Vec3($headW * 1.02, $headH * 0.85, $headD * 1.02),
            );
            $out[] = spawnPart($world, 'unit_box', $mat,
                new Vec3($base->x, $topY + $headH * 0.25, $base->z),
                new Vec3(0.04, $headH * 0.7, $headD * 1.1),
            );
            break;

        case HairStyle::Braided:
            $out[] = spawnPart($world, 'unit_sphere', $mat,
                new Vec3($base->x, $headY + $headH * 0.12, $base->z),
                new Vec3($headW * 1.1, $headH * 1.0, $headD * 1.1),
            );
            for ($i = 0; $i < 3; $i++) {
                $out[] = spawnPart($world, 'unit_sphere', $mat,
                    new Vec3($base->x, $headY - $headH * (0.4 + $i * 0.45), $base->z - $headD * 0.5),
                    new Vec3(0.06, 0.10, 0.06),
                );
            }
            break;

        case HairStyle::Dreadlocks:
            $out[] = spawnPart($world, 'unit_sphere', $mat,
                new Vec3($base->x, $headY + $headH * 0.12, $base->z),
                new Vec3($headW * 1.2, $headH * 1.05, $headD * 1.2),
            );
            for ($i = -2; $i <= 2; $i++) {
                $x = $base->x + $i * 0.04;
                $out[] = spawnPart($world, 'unit_cylinder', $mat,
                    new Vec3($x, $headY - $headH * 0.5, $base->z - 0.04),
                    new Vec3(0.035, $headH * 1.4, 0.035),
                );
            }
            break;

        case HairStyle::Mullet:
            $out[] = spawnPart($world, 'unit_sphere', $mat,
                new Vec3($base->x, $headY + $headH * 0.1, $base->z),
                new Vec3($headW * 1.08, $headH * 0.95, $headD * 1.08),
            );
            $out[] = spawnPart($world, 'unit_box', $mat,
                new Vec3($base->x, $headY - $headH * 0.45, $base->z - $headD * 0.5),
                new Vec3($headW * 1.0, $headH * 1.1, 0.06),
            );
            break;

        default:
            break;
    }
}
