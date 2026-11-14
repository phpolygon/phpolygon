<?php

declare(strict_types=1);

/**
 * Glass audit (E0): repro scenes for transparent glass on every vio backend.
 *
 *   php examples/glass_audit.php <backend> <outDir> [case,case|all] [--mode=game|image] [--prefix=glass-audit]
 *
 * Renders each case headless and writes <outDir>/<prefix>-<backend>-<case>.png plus
 * a per-run JSON with pixel probes. "game" mode runs the real frame path
 * (beginFrame/render/endFrame with the given GraphicsSettings: HDR, bloom, FXAA,
 * SSAO, SSR on Direct3D) and reads the swapchain; "image" mode uses
 * renderToImage (LDR, no post) as the engine's VRTs do.
 *
 * Audit tooling only – no engine behaviour is changed.
 */

require __DIR__ . '/../vendor/autoload.php';

use PHPolygon\Component\Camera3DComponent;
use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\World;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Geometry\PlaneMesh;
use PHPolygon\Geometry\SphereMesh;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\AddPointLight;
use PHPolygon\Rendering\Command\AddSpotLight;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\DrawMeshInstanced;
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetDirectionalLight;
use PHPolygon\Rendering\Command\SetFog;
use PHPolygon\Rendering\Command\SetSky;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\NullRenderer3D;
use PHPolygon\Rendering\ProcModeRegistry;
use PHPolygon\Rendering\ProcModeShaderRegistry;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ScreenSpaceAO;
use PHPolygon\Rendering\Quality\ScreenSpaceReflections;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioRenderer3D;
use PHPolygon\System\Camera3DSystem;
use PHPolygon\System\Renderer3DSystem;

define('W', (int) (getenv('GLASS_W') ?: 640));
define('H', (int) (getenv('GLASS_H') ?: 360));
// Scene origin off Tutorial-Island's shore: the game's water branch fades the
// ocean radially from the world origin (shore ~98 m), so stay in open water.
const OX = 130.0;

$backendArg = $argv[1] ?? 'auto';
$outDir = rtrim($argv[2] ?? (__DIR__ . '/glass_audit/out'), '/\\');
$filterArg = $argv[3] ?? 'all';
$mode = 'game';
$prefix = 'glass-audit';
foreach ($argv as $a) {
    if (str_starts_with($a, '--mode=')) {
        $mode = substr($a, 7);
    }
    if (str_starts_with($a, '--prefix=')) {
        $prefix = substr($a, 9);
    }
}
$filter = $filterArg === 'all' ? null : explode(',', $filterArg);
@mkdir($outDir, 0777, true);

// ── game-supplied water look (Code Rescue proc_mode 2), copied verbatim ──────
$pm = __DIR__ . '/glass_audit/procmode';
ProcModeRegistry::map('water_', 2);
$opt = static fn(string $f): ?string => is_file($f) ? (string) file_get_contents($f) : null;
ProcModeShaderRegistry::registerGlsl(
    2,
    $opt("$pm/water.helpers.glsl") ?? '',
    // Water is the only arm here: close the ladder like the game's last arm does.
    ($opt("$pm/water.branch.glsl") ?? '') . "\n    } else",
    $opt("$pm/water.gl.helpers.glsl"),
    ($opt("$pm/water.gl.branch.glsl") ?? '') . "\n    } else",
);

// ── meshes ───────────────────────────────────────────────────────────────────
MeshRegistry::register('pane', BoxMesh::generate(2.0, 2.4, 0.03));
MeshRegistry::register('leaf', BoxMesh::generate(1.0, 2.2, 0.03));
MeshRegistry::register('facade', BoxMesh::generate(8.0, 2.6, 0.03));
MeshRegistry::register('sidewall', BoxMesh::generate(0.03, 2.2, 2.0));
MeshRegistry::register('chamber', BoxMesh::generate(2.0, 2.5, 2.0));
MeshRegistry::register('cube', BoxMesh::generate(1.0, 1.0, 1.0));
MeshRegistry::register('deck', BoxMesh::generate(5.0, 0.4, 30.0));
MeshRegistry::register('stripe', BoxMesh::generate(0.5, 3.0, 0.2));
MeshRegistry::register('water_plane', PlaneMesh::generate(400.0, 400.0, 64));
// Single-sided pane: PlaneMesh is horizontal (normal +Y); rotated -90° about X it
// stands upright with its one face (normal +Z) towards +Z.
MeshRegistry::register('pane_quad', PlaneMesh::generate(2.0, 2.4, 1));
MeshRegistry::register('bubble', SphereMesh::generate(0.07, 8, 12));
MeshRegistry::register('frame_bar', BoxMesh::generate(0.08, 2.5, 0.08));
MeshRegistry::register('frame_rail', BoxMesh::generate(2.16, 0.08, 0.08));
MeshRegistry::register('room_floor', BoxMesh::generate(8.0, 0.2, 10.0));
MeshRegistry::register('room_wall_x', BoxMesh::generate(0.2, 3.4, 10.0));
MeshRegistry::register('room_wall_z', BoxMesh::generate(8.0, 3.4, 0.2));

// ── materials ────────────────────────────────────────────────────────────────
$mat = static function (string $id, Material $m): void {
    MaterialRegistry::register($id, $m);
};
$mat('deck', new Material(albedo: new Color(0.42, 0.40, 0.37), roughness: 0.85));
$mat('red', new Material(albedo: new Color(0.80, 0.12, 0.10), roughness: 0.5));
$mat('green', new Material(albedo: new Color(0.15, 0.60, 0.20), roughness: 0.5));
$mat('blue', new Material(albedo: new Color(0.10, 0.25, 0.80), roughness: 0.5));
$mat('yellow', new Material(albedo: new Color(0.90, 0.75, 0.10), roughness: 0.5));
$mat('stripe_w', new Material(albedo: new Color(0.85, 0.85, 0.85), roughness: 0.7));
$mat('stripe_k', new Material(albedo: new Color(0.04, 0.04, 0.04), roughness: 0.7));
$mat('frame', new Material(albedo: new Color(0.35, 0.37, 0.40), roughness: 0.35, metallic: 0.8));
$mat('room', new Material(albedo: new Color(0.22, 0.22, 0.24), roughness: 0.8));
// Code Rescue ocean values (alpha is re-derived by the water branch).
$mat('water_surface', new Material(albedo: new Color(0.10, 0.35, 0.45), roughness: 0.02, metallic: 0.8, alpha: 0.55));
// Sort probe: strongly tinted so a missing layer is obvious.
$mat('glass_probe', new Material(albedo: new Color(1.0, 0.45, 0.05), roughness: 0.3, alpha: 0.45));
$mat('glass_probe_b', new Material(albedo: new Color(0.05, 0.85, 0.35), roughness: 0.3, alpha: 0.45));
// Glass candidates.
$mat('glass_naiv', new Material(albedo: new Color(0.85, 0.92, 0.95), roughness: 0.05, alpha: 0.25));
$mat('glass_dunkel_cc', new Material(albedo: new Color(0.18, 0.26, 0.28), roughness: 0.05, alpha: 0.32, clearcoat: 1.0, clearcoatRoughness: 0.03));
$mat('glass_metall', new Material(albedo: new Color(0.55, 0.68, 0.70), roughness: 0.08, metallic: 0.6, alpha: 0.30, clearcoat: 1.0, clearcoatRoughness: 0.03));
$mat('glass_dicht', new Material(albedo: new Color(0.30, 0.40, 0.42), roughness: 0.04, metallic: 0.3, alpha: 0.45, clearcoat: 1.0, clearcoatRoughness: 0.03));
$mat('glass_noenv', new Material(albedo: new Color(0.18, 0.26, 0.28), roughness: 0.05, alpha: 0.32, clearcoat: 1.0, clearcoatRoughness: 0.03, useEnvironmentMap: false));
$mat('glass_schatten', new Material(albedo: new Color(0.30, 0.40, 0.42), roughness: 0.05, alpha: 0.92));
$mat('bubble', new Material(albedo: new Color(0.80, 0.95, 1.00), roughness: 0.1, alpha: 0.6, emission: new Color(0.15, 0.25, 0.3)));

$GLASS = getenv('GLASS_MAT') ?: 'glass_dunkel_cc';

// ── helpers ──────────────────────────────────────────────────────────────────
function T(float $x, float $y, float $z): Mat4
{
    return Mat4::translation(OX + $x, $y, $z);
}
function TY(float $x, float $y, float $z, float $yawDeg): Mat4
{
    return T($x, $y, $z)->multiply(Mat4::rotationY(deg2rad($yawDeg)));
}
function V(float $x, float $y, float $z): Vec3
{
    return new Vec3(OX + $x, $y, $z);
}
function draw(RenderCommandList $l, string $mesh, string $mat, Mat4 $m): void
{
    $l->add(new DrawMesh($mesh, $mat, $m));
}

/** @param array{fog?: bool, night?: bool, noSky?: bool} $o */
function env(RenderCommandList $l, Vec3 $eye, Vec3 $target, array $o = []): void
{
    $l->add(new SetCamera(
        Mat4::lookAt($eye, $target, new Vec3(0, 1, 0)),
        Mat4::perspective(deg2rad(60.0), W / H, 0.1, 600.0),
    ));
    $night = $o['night'] ?? false;
    $sunTo = (new Vec3(-0.45, 0.75, 0.55))->normalize();
    if (!($o['noSky'] ?? false)) {
        $l->add(new SetSky(
            $sunTo,
            new Color(1.0, 0.95, 0.88), $night ? 0.05 : 1.0,
            $night ? new Color(0.02, 0.03, 0.07) : new Color(0.18, 0.38, 0.75),
            $night ? new Color(0.05, 0.06, 0.10) : new Color(0.70, 0.80, 0.90),
            new Color(0.25, 0.22, 0.20),
        ));
    }
    $l->add(new SetAmbientLight($night ? new Color(0.3, 0.35, 0.45) : new Color(0.45, 0.50, 0.55), $night ? 0.12 : 0.55));
    $l->add(new SetDirectionalLight($sunTo->mul(-1.0), new Color(1.0, 0.95, 0.88), $night ? 0.05 : 1.4));
    if ($o['fog'] ?? false) {
        $l->add(new SetFog(new Color(0.70, 0.76, 0.82), 4.0, 32.0));
    }
}

function backdrop(RenderCommandList $l, float $zShift = 0.0): void
{
    draw($l, 'deck', 'deck', T(0, -0.2, -6 + $zShift));
    draw($l, 'cube', 'red', T(-1.4, 0.5, -3 + $zShift));
    draw($l, 'cube', 'green', T(0.0, 0.5, -3.6 + $zShift));
    draw($l, 'cube', 'blue', T(1.4, 0.5, -3 + $zShift));
    draw($l, 'cube', 'yellow', T(0.0, 1.5, -3.6 + $zShift));
    for ($i = 0; $i < 10; $i++) {
        draw($l, 'stripe', $i % 2 === 0 ? 'stripe_w' : 'stripe_k', T(-2.25 + $i * 0.5, 1.5, -6 + $zShift));
    }
}

function water(RenderCommandList $l): void
{
    draw($l, 'water_plane', 'water_surface', T(0, -0.35, 0));
}

/** Frame (two posts + rails) around a 2.0 x 2.4 pane. */
function frame(RenderCommandList $l, Mat4 $m): void
{
    draw($l, 'frame_bar', 'frame', $m->multiply(Mat4::translation(-1.04, 0.0, 0.0)));
    draw($l, 'frame_bar', 'frame', $m->multiply(Mat4::translation(1.04, 0.0, 0.0)));
    draw($l, 'frame_rail', 'frame', $m->multiply(Mat4::translation(0.0, 1.24, 0.0)));
    draw($l, 'frame_rail', 'frame', $m->multiply(Mat4::translation(0.0, -1.24, 0.0)));
}

/** @return list<Mat4> */
function bubbleCloud(float $cx, float $cy, float $cz, int $n, int $seed): array
{
    mt_srand($seed);
    $out = [];
    for ($i = 0; $i < $n; $i++) {
        $out[] = T($cx + (mt_rand() / mt_getrandmax() - 0.5) * 1.2, $cy + (mt_rand() / mt_getrandmax()) * 1.8, $cz + (mt_rand() / mt_getrandmax() - 0.5) * 0.6);
    }
    return $out;
}

/**
 * Build a frame through the real ECS path (Camera3DSystem + Renderer3DSystem):
 * capture the command list Renderer3DSystem flushes – including its
 * back-to-front transparent sort – for the given camera pose.
 *
 * @param list<array{0: string, 1: string, 2: Vec3, 3: float}> $entities mesh, material, position, yawDeg
 */
function ecsFrame(array $entities, Vec3 $camPos, float $camYawDeg, ?callable $extra = null): RenderCommandList
{
    $world = new World();
    foreach ($entities as [$mesh, $material, $pos, $yaw]) {
        $world->createEntity()
            ->attach(new Transform3D($pos, Quaternion::fromAxisAngle(new Vec3(0, 1, 0), deg2rad($yaw))))
            ->attach(new MeshRenderer(meshId: $mesh, materialId: $material));
    }
    // Code Rescue's player camera: entity at the body, eye 0.78 m above it.
    $world->createEntity()
        ->attach(new Transform3D($camPos, Quaternion::fromAxisAngle(new Vec3(0, 1, 0), deg2rad($camYawDeg))))
        ->attach(new Camera3DComponent(fov: 60.0, near: 0.1, far: 600.0, eyeOffset: new Vec3(0.0, 0.78, 0.0)));

    $list = new RenderCommandList();
    $capture = new class extends NullRenderer3D {
        /** @var list<object> */
        public array $commands = [];
        public function render(RenderCommandList $commandList): void
        {
            $this->commands = $commandList->getCommands();
        }
    };
    $camSys = new Camera3DSystem($list, W, H);
    $rSys = new Renderer3DSystem($capture, $list);
    $camSys->render($world);
    if ($extra !== null) {
        $extra($list);
    }
    $rSys->render($world);

    $out = new RenderCommandList();
    foreach ($capture->commands as $c) {
        $out->add($c);
    }
    return $out;
}

function settingsFor(string $backend, array $over = []): GraphicsSettings
{
    // Code Rescue first-run baseline: SSR Low, SSAO High, FXAA, bloom, HDR.
    $s = new GraphicsSettings(
        antiAliasing: AntiAliasing::Fxaa,
        bloom: true,
        hdr: true,
        ambientOcclusion: ScreenSpaceAO::High,
        ssr: ScreenSpaceReflections::Low,
        vsync: false,
    );
    return $over === [] ? $s : $s->with(...$over);
}

// ── cases ────────────────────────────────────────────────────────────────────
$E0 = V(0, 1.6, 5.0);
$T0 = V(0, 1.2, -3.0);

/** @var array<string, array{0: string, 1: callable(): RenderCommandList, 2?: array<string, mixed>, 3?: int}> $cases */
$cases = [];

foreach (['glass_naiv', 'glass_dunkel_cc', 'glass_metall', 'glass_dicht'] as $gm) {
    $short = str_replace('glass_', '', $gm);
    $cases["glas-vor-opak-$short"] = [
        "Eine Scheibe ($gm) vor farbigen Würfeln und Streifenwand.",
        static function () use ($E0, $T0, $gm): RenderCommandList {
            $l = new RenderCommandList();
            env($l, $E0, $T0);
            backdrop($l);
            frame($l, TY(0, 1.25, 1.5, 0));
            draw($l, 'pane', $gm, TY(0, 1.25, 1.5, 0));
            return $l;
        },
    ];
}

$cases['material-vergleich'] = [
    'Vier Glasmaterialien schräg (30°) nebeneinander: naiv, dunkel+Klarlack, metallisch, dicht.',
    static function (): RenderCommandList {
        $l = new RenderCommandList();
        env($l, V(0, 1.6, 6.5), V(0, 1.2, -3.0));
        backdrop($l);
        $i = 0;
        foreach (['glass_naiv', 'glass_dunkel_cc', 'glass_metall', 'glass_dicht'] as $gm) {
            $x = -3.3 + $i * 2.2;
            frame($l, TY($x, 1.25, 1.0, 30));
            draw($l, 'pane', $gm, TY($x, 1.25, 1.0, 30));
            $i++;
        }
        return $l;
    },
];

$cases['glas-vor-glas-sortiert'] = [
    'Zwei getönte Scheiben hintereinander, hinten zuerst gezeichnet (korrekte Reihenfolge).',
    static function () use ($E0, $T0): RenderCommandList {
        $l = new RenderCommandList();
        env($l, $E0, $T0);
        backdrop($l);
        draw($l, 'pane', 'glass_probe_b', TY(-0.5, 1.25, 0.3, 0));
        draw($l, 'pane', 'glass_probe', TY(0.5, 1.25, 2.0, 0));
        return $l;
    },
];
$cases['glas-vor-glas-falsch'] = [
    'Dieselben zwei Scheiben, vordere zuerst gezeichnet (Sortierfehler).',
    static function () use ($E0, $T0): RenderCommandList {
        $l = new RenderCommandList();
        env($l, $E0, $T0);
        backdrop($l);
        draw($l, 'pane', 'glass_probe', TY(0.5, 1.25, 2.0, 0));
        draw($l, 'pane', 'glass_probe_b', TY(-0.5, 1.25, 0.3, 0));
        return $l;
    },
];

foreach ([0 => 'a', 180 => 'b'] as $yaw => $tag) {
    $cases["kammer-$tag"] = [
        "Glaskammer als EIN Box-Mesh (2 x 2,5 x 2 m), um {$yaw}° gedreht – gleiche Ansicht, andere Dreiecksreihenfolge.",
        static function () use ($yaw): RenderCommandList {
            $l = new RenderCommandList();
            env($l, V(1.2, 1.7, 4.5), V(0, 1.1, -1.0));
            backdrop($l);
            draw($l, 'chamber', 'glass_probe', TY(0, 1.25, 0.5, $yaw));
            return $l;
        },
    ];
}
$cases['kammer-innen'] = [
    'Blick aus der Glaskammer (ein Box-Mesh) nach außen auf Würfel und Wasser.',
    static function () use ($GLASS): RenderCommandList {
        $l = new RenderCommandList();
        env($l, V(0.3, 1.6, 1.0), V(-1.0, 1.0, -3.0));
        backdrop($l);
        water($l);
        draw($l, 'chamber', $GLASS, TY(0, 1.25, 0.5, 0));
        return $l;
    },
];

$harbourEye = V(0, 1.6, 0);
$harbourTarget = V(10, 0.6, -1.5);
$cases['hafen-wasser-dann-glas'] = [
    'Hafenblick: Wasser zuerst, dann Glasscheibe (Reihenfolge, wenn der Wasser-Mittelpunkt weiter weg ist).',
    static function () use ($harbourEye, $harbourTarget, $GLASS): RenderCommandList {
        $l = new RenderCommandList();
        env($l, $harbourEye, $harbourTarget);
        backdrop($l);
        draw($l, 'cube', 'red', T(6, 0.2, -2));
        water($l);
        frame($l, TY(1.6, 1.25, -0.6, 90));
        draw($l, 'pane', $GLASS, TY(1.6, 1.25, -0.6, 90));
        return $l;
    },
];
$cases['hafen-glas-dann-wasser'] = [
    'Hafenblick: Glasscheibe zuerst, dann Wasser (Wasser-Mittelpunkt näher als die Scheibe).',
    static function () use ($harbourEye, $harbourTarget, $GLASS): RenderCommandList {
        $l = new RenderCommandList();
        env($l, $harbourEye, $harbourTarget);
        backdrop($l);
        draw($l, 'cube', 'red', T(6, 0.2, -2));
        frame($l, TY(1.6, 1.25, -0.6, 90));
        draw($l, 'pane', $GLASS, TY(1.6, 1.25, -0.6, 90));
        water($l);
        return $l;
    },
];
$cases['glas-unter-wasser'] = [
    'Bullauge halb unter der Wasserlinie, von oben gesehen; Wasser zuerst gezeichnet, Blasen vor dem Wasser.',
    static function (): RenderCommandList {
        $l = new RenderCommandList();
        env($l, V(0, 2.6, 2.5), V(3.0, -0.6, -0.5));
        draw($l, 'deck', 'deck', T(-1.5, -0.2, -6));
        draw($l, 'cube', 'red', T(3.8, -0.9, -0.5));
        draw($l, 'cube', 'yellow', T(3.8, 0.4, 0.8));
        // game order: ParticleSystem before Renderer3DSystem's sorted draws
        $l->add(new DrawMeshInstanced('bubble', 'bubble', bubbleCloud(3.2, -1.2, 1.6, 40, 7)));
        water($l);
        frame($l, TY(3.0, -0.3, -0.5, 90));
        draw($l, 'pane', 'glass_probe', TY(3.0, -0.3, -0.5, 90));
        return $l;
    },
];

$cases['partikel'] = [
    'Blasen (instanziert, Alpha 0,6) links hinter und rechts vor einer getönten Scheibe; Partikel vor dem Glas gezeichnet wie im Spiel.',
    static function () use ($E0, $T0): RenderCommandList {
        $l = new RenderCommandList();
        env($l, $E0, $T0);
        backdrop($l);
        $l->add(new DrawMeshInstanced('bubble', 'bubble', array_merge(
            bubbleCloud(-0.6, 0.4, 0.4, 50, 3),   // behind the pane
            bubbleCloud(0.6, 0.4, 2.6, 50, 5),    // in front of the pane
        )));
        draw($l, 'pane', 'glass_probe', TY(0, 1.25, 1.5, 0));
        return $l;
    },
];

foreach (['glass_naiv', 'glass_dunkel_cc'] as $gm) {
    $short = str_replace('glass_', '', $gm);
    $cases["fresnel-$short"] = [
        "Fresnel/Kanten: drei Scheiben ($gm) unter 0°, 60° und 80° zur Blickrichtung.",
        static function () use ($gm): RenderCommandList {
            $l = new RenderCommandList();
            env($l, V(0, 1.6, 6.0), V(0, 1.2, -3.0));
            backdrop($l);
            foreach ([[-2.3, 0], [0.0, 60], [2.3, 80]] as [$x, $yaw]) {
                frame($l, TY($x, 1.25, 1.0, $yaw));
                draw($l, 'pane', $gm, TY($x, 1.25, 1.0, $yaw));
            }
            return $l;
        },
    ];
}

foreach (['glass_dunkel_cc' => 'env', 'glass_noenv' => 'ohne-env'] as $gm => $tag) {
    $cases["spiegelung-innen-$tag"] = [
        "Geschlossener dunkler Raum mit Innenlicht, Scheibe $gm – was spiegelt das Glas innen?",
        static function () use ($gm): RenderCommandList {
            $l = new RenderCommandList();
            env($l, V(0, 1.6, 3.5), V(0, 1.3, -3.0));
            // closed room: floor, ceiling, four walls
            draw($l, 'room_floor', 'room', T(0, -0.1, -1));
            draw($l, 'room_floor', 'room', T(0, 3.3, -1));
            draw($l, 'room_wall_x', 'room', T(-4, 1.6, -1));
            draw($l, 'room_wall_x', 'room', T(4, 1.6, -1));
            draw($l, 'room_wall_z', 'room', T(0, 1.6, -6));
            draw($l, 'room_wall_z', 'room', T(0, 1.6, 4));
            draw($l, 'cube', 'red', T(-1.2, 0.5, -3.5));
            draw($l, 'cube', 'blue', T(1.2, 0.5, -3.5));
            $l->add(new AddPointLight(V(0, 2.8, 0), new Color(1.0, 0.9, 0.75), 3.0, 8.0));
            frame($l, TY(0, 1.25, -0.5, 20));
            draw($l, 'pane', $gm, TY(0, 1.25, -0.5, 20));
            return $l;
        },
    ];
}

$cases['licht-hinter-glas'] = [
    'Nacht: Punktlicht (orange) hinter der Scheibe und Spot von hinten durch die Scheibe auf das Deck davor.',
    static function () use ($E0, $GLASS): RenderCommandList {
        $l = new RenderCommandList();
        env($l, $E0, V(0, 0.8, -2.0), ['night' => true]);
        backdrop($l);
        $l->add(new AddPointLight(V(0, 1.4, -0.8), new Color(1.0, 0.55, 0.2), 4.0, 6.0));
        $l->add(new AddSpotLight(V(0, 2.6, -1.5), (new Vec3(0, -0.6, 1.0))->normalize(), new Color(0.6, 0.8, 1.0), 6.0, 12.0, deg2rad(25.0), 0.3));
        frame($l, TY(0, 1.25, 1.0, 0));
        draw($l, 'pane', $GLASS, TY(0, 1.25, 1.0, 0));
        return $l;
    },
];

$cases['schatten'] = [
    'Sonnenschatten: links Glas Alpha 0,32 (kein Schatten), rechts Alpha 0,92 (voller, schwarzer Schatten).',
    static function () use ($GLASS): RenderCommandList {
        $l = new RenderCommandList();
        env($l, V(0, 4.0, 6.0), V(0, 0.0, -1.0));
        backdrop($l);
        frame($l, TY(-2.0, 1.25, 0.5, 0));
        frame($l, TY(0.2, 1.25, 0.5, 0));
        draw($l, 'pane', $GLASS, TY(-2.0, 1.25, 0.5, 0));
        draw($l, 'pane', 'glass_schatten', TY(0.2, 1.25, 0.5, 0));
        return $l;
    },
];

$cases['nebel'] = [
    'Nebel (4–32 m): Scheiben in 3, 11 und 19 m Entfernung, jeweils mit Würfel dahinter.',
    static function () use ($GLASS): RenderCommandList {
        $l = new RenderCommandList();
        env($l, V(0, 1.6, 5.0), V(0, 1.0, -15.0), ['fog' => true]);
        draw($l, 'deck', 'deck', T(0, -0.2, -8));
        foreach ([2.0, -6.0, -14.0] as $i => $z) {
            draw($l, 'cube', ['red', 'green', 'blue'][$i], T(0.6 - $i * 0.6, 0.5, $z - 1.5));
        }
        foreach ([-14.0, -6.0, 2.0] as $z) { // back to front
            frame($l, TY(0.6 - 0.6 * (int) round((2.0 - $z) / 8.0), 1.25, $z, 0));
            draw($l, 'pane', 'glass_probe', TY(0.6 - 0.6 * (int) round((2.0 - $z) / 8.0), 1.25, $z, 0));
        }
        return $l;
    },
];

foreach (['aus' => ScreenSpaceReflections::Off, 'hoch' => ScreenSpaceReflections::High] as $tag => $ssr) {
    $cases["ssr-wasser-$tag"] = [
        "Hafenblick mit SSR {$tag} (D3D): Wasser zuerst, dann getönte Scheibe.",
        static function () use ($harbourEye, $harbourTarget): RenderCommandList {
            $l = new RenderCommandList();
            env($l, $harbourEye, $harbourTarget);
            backdrop($l);
            draw($l, 'cube', 'red', T(6, 0.2, -2));
            draw($l, 'cube', 'yellow', T(5, 1.2, 1.5));
            water($l);
            frame($l, TY(1.6, 1.25, -0.6, 90));
            draw($l, 'pane', 'glass_probe', TY(1.6, 1.25, -0.6, 90));
            return $l;
        },
        ['ssr' => $ssr],
    ];
}

$cases['instanziert'] = [
    'Drei getönte Scheiben als EIN DrawMeshInstanced, Instanzen vorn-nach-hinten (keine Sortierung pro Instanz).',
    static function () use ($E0, $T0): RenderCommandList {
        $l = new RenderCommandList();
        env($l, $E0, $T0);
        backdrop($l);
        $l->add(new DrawMeshInstanced('pane', 'glass_probe', [
            TY(0.8, 1.25, 2.2, 0), TY(0.0, 1.25, 1.0, 0), TY(-0.8, 1.25, -0.2, 0),
        ]));
        return $l;
    },
];

// Real ECS path: an airlock of separate entities + a long facade with a leaf
// behind it whose CENTRE is closer than the facade's centre.
$lock = [
    ['leaf', 'glass_probe', V(0, 1.1, 1.0), 0.0],
    ['leaf', 'glass_probe_b', V(0, 1.1, -1.0), 0.0],
    ['sidewall', 'glass_probe', V(-0.55, 1.1, 0.0), 0.0],
    ['sidewall', 'glass_probe', V(0.55, 1.1, 0.0), 0.0],
];
$cases['ecs-schleuse-aussen'] = [
    'Echter ECS-Pfad (Renderer3DSystem-Sortierung, Augenhöhe +0,78): Schleuse aus Einzel-Entities von außen.',
    static function () use ($lock): RenderCommandList {
        return ecsFrame($lock, V(0.4, 0.82, 4.0), 0.0, static function (RenderCommandList $l): void {
            backdrop($l);
        });
    },
];
$cases['ecs-schleuse-innen'] = [
    'Echter ECS-Pfad nach Kameraschnitt: Blick aus der Schleuse (zwischen den Flügeln) nach außen.',
    static function () use ($lock): RenderCommandList {
        return ecsFrame($lock, V(0.0, 0.82, 0.3), 10.0, static function (RenderCommandList $l): void {
            backdrop($l);
        });
    },
];
$cases['ecs-fassade-tuer'] = [
    'Echter ECS-Pfad: lange Glasfassade (8 m) vor einem Türflügel, dessen Mittelpunkt näher an der Kamera liegt.',
    static function (): RenderCommandList {
        return ecsFrame([
            ['facade', 'glass_probe_b', V(3.0, 1.3, 0.0), 0.0],
            ['leaf', 'glass_probe', V(-0.3, 1.1, -0.8), 0.0],
        ], V(0.0, 0.82, 4.0), 0.0, static function (RenderCommandList $l): void {
            backdrop($l);
        });
    },
];

foreach (['vorn' => [V(0, 1.6, 5.0), V(0, 1.2, -3.0)], 'hinten' => [V(0, 1.7, -2.5), V(0, 1.1, 3.0)]]as $tag => [$eye, $tgt]) {
    $cases["scheibe-quad-$tag"] = [
        "Einseitige Scheibe (ein Quad statt dünner Box, $GLASS) links neben dünner Box rechts, Blick von $tag.",
        static function () use ($eye, $tgt, $GLASS): RenderCommandList {
            $l = new RenderCommandList();
            env($l, $eye, $tgt);
            backdrop($l);
            backdrop($l, -10.0);
            draw($l, 'cube', 'red', T(-1.2, 0.5, 3.0));
            draw($l, 'cube', 'blue', T(1.2, 0.5, 3.0));
            frame($l, TY(-1.15, 1.25, -1.0, 0));
            frame($l, TY(1.15, 1.25, -1.0, 0));
            draw($l, 'pane_quad', $GLASS, TY(-1.15, 1.25, -1.0, 0)->multiply(Mat4::rotationX(deg2rad(90.0))));
            draw($l, 'pane', $GLASS, TY(1.15, 1.25, -1.0, 0));
            return $l;
        },
    ];
}

$cases['ufer-bullauge'] = [
    'Ufernähe (r ≈ 103 m, flaches Wasser): Bullauge halb unter Wasser, Wasser zuerst gezeichnet.',
    static function (): RenderCommandList {
        $l = new RenderCommandList();
        $dx = -27.0; // world x ≈ 103
        env($l, V($dx, 2.4, 2.5), V($dx + 3.0, -0.5, -0.5));
        draw($l, 'cube', 'red', T($dx + 3.8, -0.9, -0.5));
        draw($l, 'cube', 'yellow', T($dx + 3.8, -0.9, 1.0));
        water($l);
        frame($l, TY($dx + 3.0, -0.3, -0.5, 90));
        draw($l, 'pane', 'glass_probe', TY($dx + 3.0, -0.3, -0.5, 90));
        return $l;
    },
];

$cases['taa-schiebetuer'] = [
    'TAA (D3D12): getönter Türflügel gleitet 0,08 m pro Bild nach rechts; Bild 16.',
    static function (int $f = 0): RenderCommandList {
        $l = new RenderCommandList();
        env($l, V(0, 1.6, 5.0), V(0, 1.2, -3.0));
        backdrop($l);
        $m = TY(-0.8 + 0.08 * $f, 1.25, 1.5, 0);
        $prev = TY(-0.8 + 0.08 * max(0, $f - 1), 1.25, 1.5, 0);
        $l->add(new DrawMesh('pane', 'glass_dunkel_cc', $m, false, $f > 0 ? $prev : null));
        $l->add(new DrawMesh('frame_bar', 'frame', $m->multiply(Mat4::translation(-1.04, 0, 0)), false, $f > 0 ? $prev->multiply(Mat4::translation(-1.04, 0, 0)) : null));
        return $l;
    },
    ['antiAliasing' => AntiAliasing::Taa],
    16,
];

// ── perf: ~10 glass panes vs 10 opaque panes vs none (harbour view) ─────────
function perfScene(string $kind): RenderCommandList
{
    $l = new RenderCommandList();
    env($l, V(0, 1.6, 6.0), V(2.0, 1.0, -4.0));
    backdrop($l);
    water($l);
    if ($kind !== 'none') {
        for ($i = 0; $i < 10; $i++) {
            $m = TY(-3.0 + ($i % 5) * 1.6, 1.25, 2.0 - intdiv($i, 5) * 2.5, 15.0 * ($i % 3));
            frame($l, $m);
            draw($l, 'pane', $kind === 'glass' ? 'glass_dunkel_cc' : 'stripe_w', $m);
        }
    }
    return $l;
}

// ── run ──────────────────────────────────────────────────────────────────────
$ctx = vio_create($backendArg, ['width' => W, 'height' => H, 'headless' => true, 'vsync' => false]);
if ($ctx === false) {
    fwrite(STDERR, "vio_create($backendArg) failed\n");
    exit(2);
}
$backend = vio_backend_name($ctx);
fwrite(STDERR, "backend=$backend vio=" . phpversion('vio') . " mode=$mode\n");

function savePng(string $rgba, string $file): void
{
    $img = imagecreatetruecolor(W, H);
    $len = strlen($rgba);
    for ($y = 0; $y < H; $y++) {
        for ($x = 0; $x < W; $x++) {
            $o = ($y * W + $x) * 4;
            if ($o + 2 >= $len) {
                break 2;
            }
            imagesetpixel($img, $x, $y, (ord($rgba[$o]) << 16) | (ord($rgba[$o + 1]) << 8) | ord($rgba[$o + 2]));
        }
    }
    imagepng($img, $file);
}

$results = [];
$renderer = new VioRenderer3D($ctx, W, H);

if (in_array('--perf', $argv, true)) {
    $renderer->applySettings(settingsFor($backend));
    $perf = [];
    foreach (['none', 'opaque', 'glass', 'none', 'opaque', 'glass'] as $round => $kind) {
        $list = perfScene($kind);
        $cpu = [];
        $gpu = [];
        for ($f = 0; $f < 150; $f++) {
            $t = hrtime(true);
            vio_begin($ctx);
            vio_viewport($ctx, 0, 0, W, H);
            $renderer->beginFrame();
            $renderer->render($list);
            $renderer->endFrame();
            vio_end($ctx);
            if ($f >= 30) {
                $cpu[] = (hrtime(true) - $t) / 1e6;
                $g = $renderer->gpuFrameTimeMs();
                if ($g > 0) {
                    $gpu[] = $g;
                }
            }
        }
        sort($cpu);
        sort($gpu);
        $med = static fn(array $a): float => $a === [] ? -1.0 : round($a[intdiv(count($a), 2)], 3);
        $perf[] = ['round' => intdiv($round, 3), 'kind' => $kind, 'cpu_median_ms' => $med($cpu), 'gpu_median_ms' => $med($gpu)];
        fwrite(STDERR, sprintf("perf %-6s cpu %.3f ms gpu %.3f ms\n", $kind, $med($cpu), $med($gpu)));
    }
    file_put_contents("$outDir/$prefix-$backend-perf.json", json_encode(['backend' => $backend, 'size' => [W, H], 'perf' => $perf], JSON_PRETTY_PRINT));
    vio_destroy($ctx);
    exit(0);
}
foreach ($cases as $id => $case) {
    if ($filter !== null && !in_array($id, $filter, true)) {
        continue;
    }
    [$caption, $build] = $case;
    $over = $case[2] ?? [];
    $t0 = microtime(true);
    $renderer->applySettings(settingsFor($backend, $over));
    $frames = $case[3] ?? 4;
    $rgba = '';
    if ($mode === 'image') {
        $rgba = $renderer->renderToImage($build($frames - 1), W, H, new Color(0.1, 0.1, 0.1, 1.0));
    } else {
        for ($f = 0; $f < $frames; $f++) {
            $list = $build($f);
            vio_clear($ctx, 0.1, 0.1, 0.1, 1.0);
            vio_begin($ctx);
            vio_viewport($ctx, 0, 0, W, H);
            $renderer->beginFrame();
            $renderer->render($list);
            $renderer->endFrame();
            if ($f === $frames - 1) {
                $rgba = vio_read_pixels($ctx);
            }
            vio_end($ctx);
        }
    }
    $file = "$outDir/$prefix-$backend-$id.png";
    if (strlen($rgba) >= W * H * 4) {
        savePng($rgba, $file);
        $ok = true;
    } else {
        $ok = false;
    }
    $results[$id] = [
        'file' => basename($file),
        'ok' => $ok,
        'bytes' => strlen($rgba),
        'mrt' => $renderer->mrtActive(),
        'ms' => round((microtime(true) - $t0) * 1000.0, 1),
        'caption' => $caption,
    ];
    fwrite(STDERR, sprintf("%-28s %s mrt=%s %.0f ms\n", $id, $ok ? 'ok' : 'FAIL(' . strlen($rgba) . ')', $renderer->mrtActive() ? 'y' : 'n', $results[$id]['ms']));
}
unset($renderer);
file_put_contents("$outDir/$prefix-$backend-run.json", json_encode(['backend' => $backend, 'vio' => phpversion('vio'), 'mode' => $mode, 'cases' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
fwrite(STDERR, 'destroy ' . date('H:i:s') . "\n");
vio_destroy($ctx);
fwrite(STDERR, 'end ' . date('H:i:s') . "\n");
