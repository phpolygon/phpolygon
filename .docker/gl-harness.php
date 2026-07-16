<?php

declare(strict_types=1);

/**
 * OpenGL version-matrix harness.
 *
 * Boots the standalone (php-glfw) OpenGL 3D backend against whatever GL version
 * the Mesa driver reports — which the runner forces per rung via
 * MESA_GL_VERSION_OVERRIDE — and verifies the engine copes:
 *
 *   - the window's context ladder obtains a context,
 *   - GlCapabilities detects the expected version + GLSL tier,
 *   - every built-in shader compiles at the injected #version,
 *   - a scene with a plain mesh AND an instanced mesh renders without GL error
 *     (exercising the instancing core-vs-CPU-fallback branch).
 *
 * Exit code 0 on success, non-zero on any failure. Prints a one-line summary
 * per rung so the matrix runner can grep results. (Framebuffer read-back to PNG
 * for pixel VRT is a documented follow-up — see .docker/README.md.)
 *
 * Usage (inside the container, under xvfb):
 *   xvfb-run -a env MESA_GL_VERSION_OVERRIDE=3.1 MESA_GLSL_VERSION_OVERRIDE=140 \
 *     php .docker/gl-harness.php
 */

require __DIR__ . '/../vendor/autoload.php';

use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\DrawMeshInstanced;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetDirectionalLight;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\OpenGLRenderer3D;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\ShaderRegistry;
use PHPolygon\Runtime\Input;
use PHPolygon\Runtime\Window;

function fail(string $msg): never
{
    fwrite(STDERR, "[gl-harness] FAIL: {$msg}\n");
    exit(1);
}

// 1. Window + GL context via the engine's version ladder.
$window = new Window(320, 240, 'gl-harness', vsync: false, resizable: false);
$input = new Input();
try {
    $window->initialize($input);
} catch (\Throwable $e) {
    fail('window/context creation: ' . $e->getMessage());
}

// 2. Renderer construction detects capabilities + compiles the default shader.
try {
    $renderer = new OpenGLRenderer3D(320, 240);
} catch (\Throwable $e) {
    fail('OpenGLRenderer3D construction (default shader compile): ' . $e->getMessage());
}
$caps = $renderer->capabilities();

// 3. Force-compile every built-in shader at the injected #version.
$shaderResults = [];
foreach (ShaderRegistry::ids() as $id) {
    try {
        $renderer->warmCompileShader($id);
        $shaderResults[$id] = 'ok';
    } catch (\Throwable $e) {
        $shaderResults[$id] = 'FAIL';
        fwrite(STDERR, "[gl-harness] shader '{$id}': " . $e->getMessage() . "\n");
    }
}
$shaderFailures = array_keys(array_filter($shaderResults, fn(string $r): bool => $r !== 'ok'));

// 4. Render a scene: one plain mesh + one instanced mesh (instancing path).
MeshRegistry::register('box', BoxMesh::generate(1.0, 1.0, 1.0));
MaterialRegistry::register('mat', new Material(albedo: new Color(0.8, 0.4, 0.2)));

$view = Mat4::lookAt(new Vec3(3, 3, 5), new Vec3(0, 0, 0), new Vec3(0, 1, 0));
$proj = Mat4::perspective(deg2rad(60.0), 320.0 / 240.0, 0.1, 100.0);

$list = new RenderCommandList();
$list->add(new SetCamera($view, $proj));
$list->add(new SetDirectionalLight(new Vec3(-0.5, -1.0, -0.3), new Color(1, 1, 1), 1.0));
$list->add(new DrawMesh('box', 'mat', Mat4::translation(-1.5, 0, 0)));
$list->add(new DrawMeshInstanced('box', 'mat', [
    Mat4::translation(1.5, 0, 0),
    Mat4::translation(1.5, 1.5, 0),
]));

try {
    $renderer->beginFrame();
    $renderer->render($list);
    $renderer->endFrame();
    $window->swapBuffers();
} catch (\Throwable $e) {
    fail('render: ' . $e->getMessage());
}

$summary = sprintf(
    "[gl-harness] gl=%d.%d tier=%d glsl='%s' instancing=%s shaders=%s",
    $caps->major,
    $caps->minor,
    $caps->tier(),
    $caps->glslVersionDirective(),
    $caps->hasCoreInstancing() ? 'core' : 'cpu-fallback',
    $shaderFailures === [] ? 'all-ok' : 'FAILED(' . implode(',', $shaderFailures) . ')',
);
echo $summary . "\n";

exit($shaderFailures === [] ? 0 : 1);
