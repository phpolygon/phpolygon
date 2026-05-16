<?php

/**
 * PHPolygon - Visual Character DNA showcase
 *
 * Lines up 5 procedural humanoids built purely from the DNA system:
 *   CharacterDNA -> CharacterDnaComponent -> CharacterMeshBuilder -> primitive rig
 *
 * No external models, no Blender. All mesh + material registration and rig
 * construction lives in {@see CharacterMeshBuilder}; this script only sets up
 * the scene, scatters the characters, and handles input.
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

use PHPolygon\Character\CharacterMeshBuilder;
use PHPolygon\Character\DNA\CharacterDNA;
use PHPolygon\Component\Camera3DComponent;
use PHPolygon\Component\CharacterDnaComponent;
use PHPolygon\Component\DirectionalLight;
use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\Entity;
use PHPolygon\ECS\World;
use PHPolygon\Engine;
use PHPolygon\EngineConfig;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Geometry\PlaneMesh;
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

const CHARACTER_COUNT   = 5;
const CHARACTER_SPACING = 1.6;

$engine = new Engine(new EngineConfig(
    title:                  'PHPolygon - DNA Characters',
    width:                  1280,
    height:                 720,
    is3D:                   true,
    skipSplash:             true,
    firstLaunchCalibration: false,
));

$characterRoots = [];   // list<Entity>
$characterParts = [];   // list<list<Entity>>
$turntable      = true;
$angle          = 0.0;

$engine->onInit(function () use ($engine, &$characterRoots, &$characterParts): void {
    MeshRegistry::register('ground', PlaneMesh::generate(40.0, 40.0));
    MaterialRegistry::register('stone_floor', new Material(
        albedo: new Color(0.35, 0.34, 0.32), roughness: 0.95, metallic: 0.0,
    ));
    CharacterMeshBuilder::registerDefaults();

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

    spawnCharacters($engine->world, $characterRoots, $characterParts);
});

$engine->onUpdate(function (Engine $engine, float $dt) use (&$characterRoots, &$characterParts, &$turntable, &$angle): void {
    if ($engine->input->isKeyPressed(256)) {
        $engine->stop();
    }
    if ($engine->input->isKeyPressed(32)) {
        despawnCharacters($characterParts);
        $characterRoots = [];
        $characterParts = [];
        spawnCharacters($engine->world, $characterRoots, $characterParts);
    }
    if ($engine->input->isKeyPressed(290)) {
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

/**
 * @param list<Entity>       $roots
 * @param list<list<Entity>> $parts
 */
function spawnCharacters(World $world, array &$roots, array &$parts): void
{
    $totalWidth = (CHARACTER_COUNT - 1) * CHARACTER_SPACING;
    $startX     = -$totalWidth / 2.0;

    echo "\n== New roster ==\n";

    for ($i = 0; $i < CHARACTER_COUNT; $i++) {
        $dna  = CharacterDNA::random();
        $root = $world->createEntity();
        $root->attach(new Transform3D(
            position: new Vec3($startX + $i * CHARACTER_SPACING, 0.0, 0.0),
        ));
        $root->attach(new CharacterDnaComponent($dna));

        $parts[]   = [$root, ...CharacterMeshBuilder::buildOn($world, $root)];
        $roots[]   = $root;

        $props = $root->get(CharacterDnaComponent::class)->proportions();
        printf(
            "  #%d  h=%.2f  skin=%-13s hair=%-10s style=%-11s eyes=%s\n",
            $i + 1, $props->bodyHeight,
            $props->skinTone->name, $props->hairColor->name,
            $props->hairStyle->name, $props->eyeColor->name,
        );
        printf(
            "     beard=%-9s nose=%-8s ears=%.2f  age=%.2f  build=%+.2f  extra=%s\n",
            $props->facialHair->name, $props->noseShape->name,
            $props->earSize, $props->age, $props->buildBias,
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
