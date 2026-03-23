<?php

/**
 * PHPolygon — OpenGL 4.1 3D Cube
 *
 * Renders a Phong-lit, spinning 3D cube with depth testing and perspective
 * projection. Uses raw OpenGL calls for geometry and shading, with NanoVG
 * overlay for the HUD.
 *
 * Controls:
 *   WASD      — Orbit camera
 *   Scroll    — Zoom
 *   SPACE     — Toggle rotation
 *   ESC       — Quit
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use PHPolygon\Engine;
use PHPolygon\EngineConfig;
use PHPolygon\Rendering\Color;

// ---------------------------------------------------------------------------
// Shaders
// ---------------------------------------------------------------------------

$vertSrc = <<<'GLSL'
#version 410 core

layout (location = 0) in vec3 aPos;
layout (location = 1) in vec3 aNormal;
layout (location = 2) in vec3 aColor;

uniform mat4 uModel;
uniform mat4 uView;
uniform mat4 uProjection;

out vec3 vNormal;
out vec3 vFragPos;
out vec3 vColor;

void main()
{
    vec4 worldPos = uModel * vec4(aPos, 1.0);
    vFragPos = worldPos.xyz;
    vNormal = mat3(transpose(inverse(uModel))) * aNormal;
    vColor = aColor;
    gl_Position = uProjection * uView * worldPos;
}
GLSL;

$fragSrc = <<<'GLSL'
#version 410 core

in vec3 vNormal;
in vec3 vFragPos;
in vec3 vColor;

out vec4 FragColor;

uniform vec3 uLightDir;
uniform vec3 uViewPos;

void main()
{
    vec3 norm = normalize(vNormal);
    vec3 lightDir = normalize(-uLightDir);

    // Ambient
    float ambient = 0.15;

    // Diffuse
    float diff = max(dot(norm, lightDir), 0.0);

    // Specular (Blinn-Phong)
    vec3 viewDir = normalize(uViewPos - vFragPos);
    vec3 halfDir = normalize(lightDir + viewDir);
    float spec = pow(max(dot(norm, halfDir), 0.0), 64.0);

    vec3 result = (ambient + diff * 0.7 + spec * 0.4) * vColor;
    FragColor = vec4(result, 1.0);
}
GLSL;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function compileShader(int $type, string $source): int
{
    $s = glCreateShader($type);
    glShaderSource($s, $source);
    glCompileShader($s);
    glGetShaderiv($s, GL_COMPILE_STATUS, $ok);
    if (!$ok) { throw new RuntimeException("Shader: " . glGetShaderInfoLog($s)); }
    return $s;
}

function linkProgram(int $v, int $f): int
{
    $p = glCreateProgram();
    glAttachShader($p, $v); glAttachShader($p, $f);
    glLinkProgram($p);
    glGetProgramiv($p, GL_LINK_STATUS, $ok);
    if (!$ok) { throw new RuntimeException("Link: " . glGetProgramInfoLog($p)); }
    glDeleteShader($v); glDeleteShader($f);
    return $p;
}

/** Build a perspective projection matrix (column-major float[16]). */
function perspective(float $fovDeg, float $aspect, float $near, float $far): array
{
    $f = 1.0 / tan(deg2rad($fovDeg) * 0.5);
    $nf = 1.0 / ($near - $far);
    return [
        $f / $aspect, 0,  0,                       0,
        0,            $f, 0,                       0,
        0,            0, ($far + $near) * $nf,     -1,
        0,            0,  2 * $far * $near * $nf,   0,
    ];
}

/** Build a lookAt view matrix (column-major float[16]). */
function lookAt(array $eye, array $center, array $up): array
{
    $fx = $center[0]-$eye[0]; $fy = $center[1]-$eye[1]; $fz = $center[2]-$eye[2];
    $fl = sqrt($fx*$fx+$fy*$fy+$fz*$fz); $fx/=$fl; $fy/=$fl; $fz/=$fl;
    $sx = $fy*$up[2]-$fz*$up[1]; $sy = $fz*$up[0]-$fx*$up[2]; $sz = $fx*$up[1]-$fy*$up[0];
    $sl = sqrt($sx*$sx+$sy*$sy+$sz*$sz); $sx/=$sl; $sy/=$sl; $sz/=$sl;
    $ux = $sy*$fz-$sz*$fy; $uy = $sz*$fx-$sx*$fz; $uz = $sx*$fy-$sy*$fx;
    return [
        $sx, $ux, -$fx, 0,
        $sy, $uy, -$fy, 0,
        $sz, $uz, -$fz, 0,
        -($sx*$eye[0]+$sy*$eye[1]+$sz*$eye[2]),
        -($ux*$eye[0]+$uy*$eye[1]+$uz*$eye[2]),
        $fx*$eye[0]+$fy*$eye[1]+$fz*$eye[2],
        1,
    ];
}

/** Build a Y*X rotation model matrix. */
function rotationYX(float $yaw, float $pitch): array
{
    $cy = cos($yaw); $sy = sin($yaw);
    $cp = cos($pitch); $sp = sin($pitch);
    return [
        $cy,      $sy*$sp,   $sy*$cp,  0,
        0,        $cp,       -$sp,     0,
        -$sy,     $cy*$sp,   $cy*$cp,  0,
        0,        0,         0,        1,
    ];
}

// ---------------------------------------------------------------------------
// Cube geometry (36 verts: 6 faces * 2 tris * 3 verts)
// Each vertex: pos(3) + normal(3) + color(3) = 9 floats
// ---------------------------------------------------------------------------
function cubeVertices(): GL\Buffer\FloatBuffer
{
    $faces = [
        // pos offsets,        normal,           color
        [[ 1, 0, 0], [ 0, 1, 0], [ 0, 0, 1], [0.91, 0.30, 0.24]], // +X red
        [[-1, 0, 0], [ 0,-1, 0], [ 0, 0, 1], [0.20, 0.66, 0.33]], // -X green
        [[ 0, 1, 0], [ 1, 0, 0], [ 0, 0, 1], [0.26, 0.53, 0.96]], // +Y blue
        [[ 0,-1, 0], [ 1, 0, 0], [ 0, 0,-1], [0.95, 0.77, 0.06]], // -Y yellow
        [[ 0, 0, 1], [ 1, 0, 0], [ 0, 1, 0], [0.56, 0.27, 0.68]], // +Z purple
        [[ 0, 0,-1], [-1, 0, 0], [ 0, 1, 0], [0.90, 0.49, 0.13]], // -Z orange
    ];

    $data = [];
    foreach ($faces as [$n, $t, $b, $c]) {
        // Build 4 corner positions for this face
        $corners = [];
        for ($i = -1; $i <= 1; $i += 2) {
            for ($j = -1; $j <= 1; $j += 2) {
                $corners[] = [
                    $n[0] + $t[0]*$i + $b[0]*$j,
                    $n[1] + $t[1]*$i + $b[1]*$j,
                    $n[2] + $t[2]*$i + $b[2]*$j,
                ];
            }
        }
        // Two triangles: 0-1-2, 2-1-3
        foreach ([0,1,2, 2,1,3] as $idx) {
            $p = $corners[$idx];
            array_push($data, (float)$p[0], (float)$p[1], (float)$p[2]); // position
            array_push($data, (float)$n[0], (float)$n[1], (float)$n[2]); // normal
            array_push($data, (float)$c[0], (float)$c[1], (float)$c[2]); // color
        }
    }
    return new GL\Buffer\FloatBuffer($data);
}

// ---------------------------------------------------------------------------
// State
// ---------------------------------------------------------------------------
$program = 0;
$vao = 0;
$yaw = 0.6;
$pitch = 0.4;
$cubeYaw = 0.0;
$cubePitch = 0.0;
$zoom = 4.0;
$rotating = true;

// ---------------------------------------------------------------------------
// Engine
// ---------------------------------------------------------------------------
$engine = new Engine(new EngineConfig(
    title: 'PHPolygon — OpenGL 3D Cube',
    width: 1280,
    height: 720,
));

$engine->onInit(function (Engine $engine) use (&$program, &$vao, $vertSrc, $fragSrc) {
    $v = compileShader(GL_VERTEX_SHADER, $vertSrc);
    $f = compileShader(GL_FRAGMENT_SHADER, $fragSrc);
    $program = linkProgram($v, $f);

    $vertices = cubeVertices();

    glGenVertexArrays(1, $vao);
    glGenBuffers(1, $vbo);
    glBindVertexArray($vao);
    glBindBuffer(GL_ARRAY_BUFFER, $vbo);
    glBufferData(GL_ARRAY_BUFFER, $vertices, GL_STATIC_DRAW);

    $stride = 9 * 4; // 9 floats * 4 bytes
    glEnableVertexAttribArray(0);
    glVertexAttribPointer(0, 3, GL_FLOAT, false, $stride, 0);      // position
    glEnableVertexAttribArray(1);
    glVertexAttribPointer(1, 3, GL_FLOAT, false, $stride, 3 * 4);  // normal
    glEnableVertexAttribArray(2);
    glVertexAttribPointer(2, 3, GL_FLOAT, false, $stride, 6 * 4);  // color

    glBindVertexArray(0);
    glEnable(GL_DEPTH_TEST);
});

$engine->onUpdate(function (Engine $engine, float $dt)
    use (&$yaw, &$pitch, &$cubeYaw, &$cubePitch, &$zoom, &$rotating)
{
    $camSpeed = 2.0 * $dt;
    if ($engine->input->isKeyDown(GLFW_KEY_A)) $yaw -= $camSpeed;
    if ($engine->input->isKeyDown(GLFW_KEY_D)) $yaw += $camSpeed;
    if ($engine->input->isKeyDown(GLFW_KEY_W)) $pitch += $camSpeed;
    if ($engine->input->isKeyDown(GLFW_KEY_S)) $pitch -= $camSpeed;
    $pitch = max(-1.5, min(1.5, $pitch));

    $scroll = $engine->input->getScrollY();
    if ($scroll != 0) {
        $zoom = max(2.0, min(10.0, $zoom - $scroll * 0.5));
    }

    if ($engine->input->isKeyPressed(GLFW_KEY_SPACE)) $rotating = !$rotating;
    if ($rotating) {
        $cubeYaw += 0.8 * $dt;
        $cubePitch += 0.5 * $dt;
    }

    if ($engine->input->isKeyPressed(GLFW_KEY_ESCAPE)) $engine->stop();
});

$engine->onRender(function (Engine $engine, float $interp)
    use (&$program, &$vao, &$yaw, &$pitch, &$cubeYaw, &$cubePitch, &$zoom)
{
    $r = $engine->renderer2D;
    $w = $engine->getConfig()->width;
    $h = $engine->getConfig()->height;

    // End NanoVG so we can issue raw GL
    $r->endFrame();

    glEnable(GL_DEPTH_TEST);
    glClearColor(0.08, 0.08, 0.12, 1.0);
    glClear(GL_COLOR_BUFFER_BIT | GL_DEPTH_BUFFER_BIT);

    glUseProgram($program);

    // Camera orbiting the origin
    $eyeX = sin($yaw) * cos($pitch) * $zoom;
    $eyeY = sin($pitch) * $zoom;
    $eyeZ = cos($yaw) * cos($pitch) * $zoom;

    $proj = perspective(45.0, $w / $h, 0.1, 100.0);
    $view = lookAt([$eyeX, $eyeY, $eyeZ], [0, 0, 0], [0, 1, 0]);
    $model = rotationYX($cubeYaw, $cubePitch);

    $toFB = fn(array $m) => new GL\Buffer\FloatBuffer(array_map('floatval', $m));
    glUniformMatrix4fv(glGetUniformLocation($program, 'uProjection'), false, $toFB($proj));
    glUniformMatrix4fv(glGetUniformLocation($program, 'uView'), false, $toFB($view));
    glUniformMatrix4fv(glGetUniformLocation($program, 'uModel'), false, $toFB($model));
    glUniform3f(glGetUniformLocation($program, 'uLightDir'), -0.5, -1.0, -0.3);
    glUniform3f(glGetUniformLocation($program, 'uViewPos'), $eyeX, $eyeY, $eyeZ);

    glBindVertexArray($vao);
    glDrawArrays(GL_TRIANGLES, 0, 36);
    glBindVertexArray(0);
    glUseProgram(0);

    // Restart NanoVG for HUD (without clearing — preserve the 3D scene)
    glDisable(GL_DEPTH_TEST);
    $vg = $r->getVGContext();
    $vg->beginFrame(
        (float)$engine->window->getWidth(),
        (float)$engine->window->getHeight(),
        $engine->window->getPixelRatio(),
    );

    $r->drawText('PHPolygon — OpenGL 3D Cube', 20, 20, 22.0, Color::white());
    $r->drawText('WASD orbit  |  Scroll zoom  |  SPACE toggle spin  |  ESC quit', 20, 48, 14.0, Color::hex('#999'));

    $fps = $engine->gameLoop->getAverageFps();
    $r->drawText(sprintf('FPS: %.0f', $fps), 20, 70, 14.0, Color::hex('#666'));
});

$engine->run();
