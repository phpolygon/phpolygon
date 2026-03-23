<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use PHPolygon\Component\Camera2DComponent;
use PHPolygon\Component\NameTag;
use PHPolygon\Component\Transform2D;
use PHPolygon\Engine;
use PHPolygon\EngineConfig;
use PHPolygon\Math\Vec2;
use PHPolygon\Rendering\Color;
use PHPolygon\System\Camera2DSystem;

$engine = new Engine(new EngineConfig(
    title: 'PHPolygon — Hello World',
    width: 1280,
    height: 720,
));

$engine->onInit(function (Engine $engine) {
    // Register systems
    $engine->world->addSystem(new Camera2DSystem($engine->camera2D));

    // Create a camera entity
    $camera = $engine->world->createEntity();
    $camera->attach(new Transform2D(position: Vec2::zero()));
    $camera->attach(new Camera2DComponent(zoom: 1.0));

    // Create some entities (no textures yet, we'll draw them procedurally)
    $player = $engine->world->createEntity();
    $player->attach(new NameTag('Player'));
    $player->attach(new Transform2D(position: new Vec2(0, 0)));
});

// Movement speed in pixels per second
$speed = 200.0;

$engine->onUpdate(function (Engine $engine, float $dt) use ($speed) {
    // Move "Player" entity with arrow keys
    foreach ($engine->world->query(NameTag::class, Transform2D::class) as $entity) {
        $tag = $entity->get(NameTag::class);
        if ($tag->name !== 'Player') {
            continue;
        }

        $transform = $entity->get(Transform2D::class);
        $dx = 0.0;
        $dy = 0.0;

        if ($engine->input->isKeyDown(GLFW_KEY_RIGHT) || $engine->input->isKeyDown(GLFW_KEY_D)) $dx += 1.0;
        if ($engine->input->isKeyDown(GLFW_KEY_LEFT) || $engine->input->isKeyDown(GLFW_KEY_A)) $dx -= 1.0;
        if ($engine->input->isKeyDown(GLFW_KEY_DOWN) || $engine->input->isKeyDown(GLFW_KEY_S)) $dy += 1.0;
        if ($engine->input->isKeyDown(GLFW_KEY_UP) || $engine->input->isKeyDown(GLFW_KEY_W)) $dy -= 1.0;

        $transform->position = $transform->position->add(new Vec2($dx * $speed * $dt, $dy * $speed * $dt));
        $transform->rotation = fmod($transform->rotation + 45.0 * $dt, 360.0);
    }

    // ESC to quit
    if ($engine->input->isKeyPressed(GLFW_KEY_ESCAPE)) {
        $engine->stop();
    }
});

$engine->onRender(function (Engine $engine, float $interpolation) {
    $r = $engine->renderer2D;
    $cam = $engine->camera2D;

    // Draw background grid
    $gridColor = Color::hex('#1a1a2e');
    $r->drawRect(0, 0, (float)$engine->getConfig()->width, (float)$engine->getConfig()->height, $gridColor);

    // Apply camera transform for world-space drawing
    $viewMatrix = $cam->getViewMatrix();
    $r->pushTransform($viewMatrix);

    // Draw ground plane grid lines
    $lineColor = Color::hex('#16213e');
    for ($i = -10; $i <= 10; $i++) {
        $x = $i * 80.0;
        $r->drawLine(
            new Vec2($x, -800.0),
            new Vec2($x, 800.0),
            $lineColor,
            1.0
        );
        $r->drawLine(
            new Vec2(-800.0, $x),
            new Vec2(800.0, $x),
            $lineColor,
            1.0
        );
    }

    // Draw player entity
    foreach ($engine->world->query(NameTag::class, Transform2D::class) as $entity) {
        $tag = $entity->get(NameTag::class);
        $transform = $entity->get(Transform2D::class);

        if ($tag->name === 'Player') {
            // Draw player as a rotating colored rectangle
            $matrix = $transform->getLocalMatrix();
            $r->pushTransform($matrix);

            // Body
            $r->drawRoundedRect(-30, -30, 60, 60, 8.0, Color::hex('#e94560'));

            // Inner highlight
            $r->drawRoundedRect(-20, -20, 40, 40, 4.0, Color::hex('#f77f8a'));

            // Direction indicator (small circle at top)
            $r->drawCircle(0, -25, 5.0, Color::white());

            $r->popTransform();

            // Label (screen-aligned, not rotated)
            $screenPos = $cam->worldToScreen($transform->position);
        }
    }

    $r->popTransform(); // camera

    // Draw HUD (screen-space, no camera transform)
    $r->drawText('PHPolygon Engine', 20, 20, 24.0, Color::hex('#e94560'));
    $r->drawText('WASD / Arrow Keys to move  |  ESC to quit', 20, 50, 16.0, Color::hex('#888888'));

    $fps = $engine->gameLoop->getAverageFps();
    $r->drawText(sprintf('FPS: %.0f  |  Entities: %d', $fps, $engine->world->entityCount()), 20, 74, 14.0, Color::hex('#666666'));
});

$engine->run();
