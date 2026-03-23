<?php

/**
 * PHPolygon — OpenGL 4.1 Example
 *
 * Renders a rotating, color-interpolated triangle using custom vertex and fragment
 * shaders. Demonstrates the raw OpenGL pipeline: shader compilation, VAO/VBO setup,
 * uniform passing, and the engine's fixed-timestep game loop.
 *
 * Controls:
 *   SPACE  — Toggle rotation
 *   UP/DOWN — Change rotation speed
 *   ESC    — Quit
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use PHPolygon\Engine;
use PHPolygon\EngineConfig;
use PHPolygon\Rendering\Color;
use PHPolygon\Math\Vec2;

// ---------------------------------------------------------------------------
// Shader sources (GLSL 410 core)
// ---------------------------------------------------------------------------

$vertexShaderSource = <<<'GLSL'
#version 410 core

layout (location = 0) in vec2 aPos;
layout (location = 1) in vec3 aColor;

uniform float uTime;
uniform float uScale;

out vec3 vColor;

void main()
{
    float angle = uTime;
    mat2 rot = mat2(cos(angle), -sin(angle),
                    sin(angle),  cos(angle));

    vec2 pos = rot * aPos * uScale;

    gl_Position = vec4(pos, 0.0, 1.0);
    vColor = aColor;
}
GLSL;

$fragmentShaderSource = <<<'GLSL'
#version 410 core

in vec3 vColor;
out vec4 FragColor;

uniform float uTime;

void main()
{
    // Pulse the brightness slightly with time
    float pulse = 0.9 + 0.1 * sin(uTime * 3.0);
    FragColor = vec4(vColor * pulse, 1.0);
}
GLSL;

// ---------------------------------------------------------------------------
// Helper: compile a single shader stage
// ---------------------------------------------------------------------------
function compileShader(int $type, string $source): int
{
    $shader = glCreateShader($type);
    glShaderSource($shader, $source);
    glCompileShader($shader);

    glGetShaderiv($shader, GL_COMPILE_STATUS, $success);
    if (!$success) {
        $log = glGetShaderInfoLog($shader);
        throw new RuntimeException("Shader compile error: {$log}");
    }

    return $shader;
}

// ---------------------------------------------------------------------------
// Helper: link a program from vertex + fragment shaders
// ---------------------------------------------------------------------------
function linkProgram(int $vert, int $frag): int
{
    $program = glCreateProgram();
    glAttachShader($program, $vert);
    glAttachShader($program, $frag);
    glLinkProgram($program);

    glGetProgramiv($program, GL_LINK_STATUS, $success);
    if (!$success) {
        $log = glGetProgramInfoLog($program);
        throw new RuntimeException("Program link error: {$log}");
    }

    glDeleteShader($vert);
    glDeleteShader($frag);

    return $program;
}

// ---------------------------------------------------------------------------
// State
// ---------------------------------------------------------------------------
$angle      = 0.0;
$speed      = 1.5;   // radians per second
$rotating   = true;
$scale      = 0.8;
$program    = 0;
$vao        = 0;

// ---------------------------------------------------------------------------
// Engine setup
// ---------------------------------------------------------------------------
$engine = new Engine(new EngineConfig(
    title: 'PHPolygon — OpenGL Triangle',
    width: 1024,
    height: 768,
));

$engine->onInit(function (Engine $engine) use (&$program, &$vao) {
    // ----- Compile shaders -----
    global $vertexShaderSource, $fragmentShaderSource;

    $vert = compileShader(GL_VERTEX_SHADER, $vertexShaderSource);
    $frag = compileShader(GL_FRAGMENT_SHADER, $fragmentShaderSource);
    $program = linkProgram($vert, $frag);

    // ----- Triangle geometry -----
    //
    //        (0, 0.75)          red
    //       /        \
    //      /          \
    //  (-0.65,-0.5) — (0.65,-0.5)
    //    green           blue

    $vertices = new GL\Buffer\FloatBuffer([
        // x,     y,     r,    g,    b
         0.00,  0.75,  1.0,  0.2,  0.3,   // top       — red-ish
        -0.65, -0.50,  0.2,  1.0,  0.3,   // left      — green-ish
         0.65, -0.50,  0.3,  0.2,  1.0,   // right     — blue-ish
    ]);

    // ----- VAO / VBO -----
    glGenVertexArrays(1, $vao);
    glGenBuffers(1, $vbo);

    glBindVertexArray($vao);
    glBindBuffer(GL_ARRAY_BUFFER, $vbo);
    glBufferData(GL_ARRAY_BUFFER, $vertices, GL_STATIC_DRAW);

    // Position attribute (location = 0)
    glEnableVertexAttribArray(0);
    glVertexAttribPointer(0, 2, GL_FLOAT, false, 5 * 4, 0);

    // Color attribute (location = 1)
    glEnableVertexAttribArray(1);
    glVertexAttribPointer(1, 3, GL_FLOAT, false, 5 * 4, 2 * 4);

    glBindVertexArray(0);
});

$engine->onUpdate(function (Engine $engine, float $dt) use (&$angle, &$speed, &$rotating) {
    if ($rotating) {
        $angle += $speed * $dt;
    }

    // Toggle rotation
    if ($engine->input->isKeyPressed(GLFW_KEY_SPACE)) {
        $rotating = !$rotating;
    }

    // Change speed
    if ($engine->input->isKeyDown(GLFW_KEY_UP)) {
        $speed = min($speed + 2.0 * $dt, 10.0);
    }
    if ($engine->input->isKeyDown(GLFW_KEY_DOWN)) {
        $speed = max($speed - 2.0 * $dt, 0.1);
    }

    if ($engine->input->isKeyPressed(GLFW_KEY_ESCAPE)) {
        $engine->stop();
    }
});

$engine->onRender(function (Engine $engine, float $interpolation) use (&$program, &$vao, &$angle, &$scale) {
    $r = $engine->renderer2D;

    // --- Raw OpenGL pass: draw the triangle ---
    // End the NanoVG frame temporarily so we can issue raw GL calls
    $r->endFrame();

    glEnable(GL_BLEND);
    glBlendFunc(GL_SRC_ALPHA, GL_ONE_MINUS_SRC_ALPHA);

    glUseProgram($program);
    glUniform1f(glGetUniformLocation($program, 'uTime'), $angle);
    glUniform1f(glGetUniformLocation($program, 'uScale'), $scale);

    glBindVertexArray($vao);
    glDrawArrays(GL_TRIANGLES, 0, 3);
    glBindVertexArray(0);

    glUseProgram(0);

    // --- Restart NanoVG for HUD overlay ---
    $r->beginFrame();

    // HUD text
    $r->drawText('PHPolygon — OpenGL 4.1 Triangle', 20, 20, 22.0, Color::white());
    $r->drawText('SPACE toggle rotation  |  UP/DOWN speed  |  ESC quit', 20, 48, 14.0, Color::hex('#999999'));

    $fps = $engine->gameLoop->getAverageFps();
    $r->drawText(sprintf('FPS: %.0f  |  Speed: %.1f rad/s', $fps, $GLOBALS['speed']), 20, 70, 14.0, Color::hex('#666666'));
});

$engine->run();
