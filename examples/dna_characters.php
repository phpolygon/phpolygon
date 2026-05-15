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
use PHPolygon\Character\DNA\Enum\EyeColor;
use PHPolygon\Character\DNA\Enum\EyeShape;
use PHPolygon\Character\DNA\Enum\HairColor;
use PHPolygon\Character\DNA\Enum\HairStyle;
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
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Geometry\PlaneMesh;
use PHPolygon\Geometry\SphereMesh;
use PHPolygon\Math\Quaternion;
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
        MaterialRegistry::register('hair_' . $color->name, new Material(
            albedo: Color::hex($color->value), roughness: 0.55, metallic: 0.0,
        ));
    }
    foreach (EyeColor::cases() as $color) {
        MaterialRegistry::register('eye_' . $color->name, new Material(
            albedo: Color::hex($color->value), roughness: 0.25, metallic: 0.0,
        ));
    }
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
            "  #%d  height=%.2f  skin=%-13s hair=%-10s style=%-11s eyes=%s\n",
            $i + 1,
            $props->bodyHeight,
            $props->skinTone->name,
            $props->hairColor->name,
            $props->hairStyle->name,
            $props->eyeColor->name,
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

    $legH   = 0.82 * $p->limbLength * $h;
    $hipH   = 0.18 * $h;
    $chestH = 0.34 * $p->torsoLength * $h;
    $waistH = 0.20 * $p->torsoLength * $h;
    $neckH  = 0.05 * $h;
    $headH  = 0.26 * $p->skullHeight * $h;

    $hipW    = 0.34 * $p->hipWidth * $h;
    $waistW  = 0.30 * (0.6 + 0.4 * $p->shoulderWidth) * $h;
    $shldW   = 0.50 * $p->shoulderWidth * $h;
    $chestD  = 0.24 * $h;
    $waistD  = 0.20 * $h;
    $headW   = 0.20 * $p->skullWidth * $h;
    $headD   = 0.22 * $p->skullWidth * $h;
    $jawDrop = 0.05 * $p->jawWidth * $h;

    $legR   = 0.085 * (0.4 + 0.6 * $p->limbTaper) * $h;
    $armR   = 0.062 * (0.4 + 0.6 * $p->limbTaper) * $h;
    $armL   = 0.68  * $p->limbLength * $h;

    $skinMat = 'skin_' . $p->skinTone->name;

    $base = $root->get(Transform3D::class)->position;

    $hipY     = $base->y + $legH + $hipH * 0.5;
    $waistY   = $base->y + $legH + $hipH + $waistH * 0.5;
    $chestY   = $base->y + $legH + $hipH + $waistH + $chestH * 0.5;
    $neckY    = $base->y + $legH + $hipH + $waistH + $chestH + $neckH * 0.5;
    $headY    = $neckY + $neckH * 0.5 + $headH * 0.5;
    $shldY    = $base->y + $legH + $hipH + $waistH + $chestH - 0.04 * $h;

    // Legs (cylinders)
    $legOffset = $hipW * 0.35;
    foreach ([-$legOffset, $legOffset] as $sx) {
        $out[] = spawnPart($world, 'unit_cylinder', $skinMat,
            new Vec3($base->x + $sx, $base->y + $legH * 0.5, $base->z),
            new Vec3($legR * 2, $legH, $legR * 2),
        );
    }

    // Feet
    $footW = $legR * 2.4;
    $footL = $legR * 3.2;
    $footH = 0.04 * $h;
    foreach ([-$legOffset, $legOffset] as $sx) {
        $out[] = spawnPart($world, 'unit_box', 'cloth_dark',
            new Vec3($base->x + $sx, $base->y + $footH * 0.5, $base->z + $footL * 0.15),
            new Vec3($footW, $footH, $footL),
        );
    }

    // Hip belt
    $out[] = spawnPart($world, 'unit_box', 'cloth_dark',
        new Vec3($base->x, $hipY, $base->z),
        new Vec3($hipW, $hipH, $waistD * 1.05),
    );

    // Waist (slimmer than chest -> visible torso taper)
    $out[] = spawnPart($world, 'unit_box', $skinMat,
        new Vec3($base->x, $waistY, $base->z),
        new Vec3($waistW, $waistH, $waistD),
    );

    // Chest (broader)
    $out[] = spawnPart($world, 'unit_box', $skinMat,
        new Vec3($base->x, $chestY, $base->z),
        new Vec3($shldW, $chestH, $chestD),
    );

    // Shoulder caps (round off the boxy shoulders)
    foreach ([-1, 1] as $side) {
        $out[] = spawnPart($world, 'unit_sphere', $skinMat,
            new Vec3($base->x + $side * $shldW * 0.5, $shldY, $base->z),
            new Vec3($armR * 2.4, $armR * 2.4, $armR * 2.4),
        );
    }

    // Neck
    $out[] = spawnPart($world, 'unit_cylinder', $skinMat,
        new Vec3($base->x, $neckY, $base->z),
        new Vec3(0.09 * $h, $neckH, 0.09 * $h),
    );

    // Arms
    $armOffset = $shldW * 0.5 + $armR;
    foreach ([-$armOffset, $armOffset] as $sx) {
        $out[] = spawnPart($world, 'unit_cylinder', $skinMat,
            new Vec3($base->x + $sx, $shldY - $armL * 0.5, $base->z),
            new Vec3($armR * 2, $armL, $armR * 2),
        );
        // Hand
        $out[] = spawnPart($world, 'unit_sphere', $skinMat,
            new Vec3($base->x + $sx, $shldY - $armL - $armR * 0.6, $base->z),
            new Vec3($armR * 2.4, $armR * 2.6, $armR * 2.4),
        );
    }

    // Head
    $out[] = spawnPart($world, 'unit_sphere', $skinMat,
        new Vec3($base->x, $headY, $base->z),
        new Vec3($headW, $headH, $headD),
    );

    // Jaw - jawWidth pushes a small wedge below the head front
    $out[] = spawnPart($world, 'unit_sphere', $skinMat,
        new Vec3($base->x, $headY - $headH * 0.35, $base->z + $headD * 0.05),
        new Vec3($headW * (0.55 + 0.35 * $p->jawWidth), $jawDrop, $headD * 0.9),
    );

    // Brow (subtle ridge above eyes - keeps it from looking like sunglasses)
    $browProm = $p->browProminence;
    if ($browProm > 0.25) {
        $browZ = $base->z + $headD * 0.5 - 0.005 * $h;
        $out[] = spawnPart($world, 'unit_box', $skinMat,
            new Vec3($base->x, $headY + $headH * 0.18, $browZ),
            new Vec3($headW * 0.7, 0.012 * $h, 0.025 * (0.5 + $browProm) * $h),
        );
    }

    // Nose (small wedge)
    $out[] = spawnPart($world, 'unit_sphere', $skinMat,
        new Vec3($base->x, $headY - $headH * 0.05, $base->z + $headD * 0.5 + 0.015 * $h),
        new Vec3(0.025 * $h, 0.04 * $h, 0.04 * $h),
    );

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

    // Hair / scalp
    buildHair($world, $p, $base, $headY, $headW, $headH, $headD, $out);
}

function spawnPart(World $world, string $mesh, string $material, Vec3 $pos, Vec3 $scale): Entity
{
    $e = $world->createEntity();
    $e->attach(new MeshRenderer($mesh, $material));
    $e->attach(new Transform3D(position: $pos, scale: $scale));
    return $e;
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
