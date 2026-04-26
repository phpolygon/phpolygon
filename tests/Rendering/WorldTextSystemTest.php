<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\TestCase;
use PHPolygon\Component\Transform3D;
use PHPolygon\Component\WorldText;
use PHPolygon\ECS\World;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\DrawWorldText;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\TextAlign;
use PHPolygon\System\WorldTextSystem;

class WorldTextSystemTest extends TestCase
{
    private function createSystemWithCamera(Vec3 $cameraPos): array
    {
        $commandList = new RenderCommandList();

        $view = Mat4::lookAt($cameraPos, Vec3::zero(), new Vec3(0.0, 1.0, 0.0));
        $proj = Mat4::perspective(deg2rad(60.0), 800.0 / 600.0, 0.1, 100.0);
        $commandList->add(new SetCamera($view, $proj));

        $system = new WorldTextSystem($commandList, 800, 600);
        return [$system, $commandList];
    }

    public function testWorldTextDefaults(): void
    {
        $text = new WorldText();
        $this->assertSame('', $text->text);
        $this->assertEqualsWithDelta(16.0, $text->fontSize, 1e-6);
        $this->assertEqualsWithDelta(0.0, $text->maxDistance, 1e-6);
        $this->assertTrue($text->scaleWithDistance);
    }

    public function testEmitsDrawWorldText(): void
    {
        [$system, $commandList] = $this->createSystemWithCamera(new Vec3(0.0, 5.0, 10.0));
        $world = new World();

        $entity = $world->createEntity();
        $entity->attach(new Transform3D(position: Vec3::zero()));
        $entity->attach(new WorldText(text: 'Hello World', fontSize: 24.0));

        $system->render($world);

        $texts = $commandList->ofType(DrawWorldText::class);
        $this->assertCount(1, $texts);
        $this->assertSame('Hello World', $texts[0]->text);
    }

    public function testEmptyTextSkipped(): void
    {
        [$system, $commandList] = $this->createSystemWithCamera(new Vec3(0.0, 5.0, 10.0));
        $world = new World();

        $entity = $world->createEntity();
        $entity->attach(new Transform3D());
        $entity->attach(new WorldText(text: ''));

        $system->render($world);

        $texts = $commandList->ofType(DrawWorldText::class);
        $this->assertCount(0, $texts);
    }

    public function testNoCameraNoRender(): void
    {
        $commandList = new RenderCommandList();
        $system = new WorldTextSystem($commandList, 800, 600);
        $world = new World();

        $entity = $world->createEntity();
        $entity->attach(new Transform3D());
        $entity->attach(new WorldText(text: 'Test'));

        $system->render($world);

        $texts = $commandList->ofType(DrawWorldText::class);
        $this->assertCount(0, $texts);
    }

    public function testDistanceCulling(): void
    {
        [$system, $commandList] = $this->createSystemWithCamera(new Vec3(0.0, 5.0, 10.0));
        $world = new World();

        // Entity very far away
        $entity = $world->createEntity();
        $entity->attach(new Transform3D(position: new Vec3(500.0, 0.0, 500.0)));
        $entity->attach(new WorldText(text: 'Far away', maxDistance: 20.0));

        $system->render($world);

        $texts = $commandList->ofType(DrawWorldText::class);
        $this->assertCount(0, $texts);
    }

    public function testNoDistanceLimitRendersAll(): void
    {
        [$system, $commandList] = $this->createSystemWithCamera(new Vec3(0.0, 5.0, 10.0));
        $world = new World();

        $entity = $world->createEntity();
        $entity->attach(new Transform3D(position: Vec3::zero()));
        $entity->attach(new WorldText(text: 'Always visible', maxDistance: 0.0));

        $system->render($world);

        $texts = $commandList->ofType(DrawWorldText::class);
        $this->assertCount(1, $texts);
    }

    public function testColorAndFontPassedThrough(): void
    {
        [$system, $commandList] = $this->createSystemWithCamera(new Vec3(0.0, 5.0, 10.0));
        $world = new World();

        $red = new Color(1.0, 0.0, 0.0);
        $entity = $world->createEntity();
        $entity->attach(new Transform3D(position: Vec3::zero()));
        $entity->attach(new WorldText(
            text: 'Colored',
            fontSize: 32.0,
            color: $red,
            fontId: 'custom-font',
        ));

        $system->render($world);

        $texts = $commandList->ofType(DrawWorldText::class);
        $this->assertCount(1, $texts);
        $this->assertSame('custom-font', $texts[0]->fontId);
        $this->assertEqualsWithDelta(1.0, $texts[0]->color->r, 1e-6);
        $this->assertEqualsWithDelta(0.0, $texts[0]->color->g, 1e-6);
    }

    public function testMultipleTexts(): void
    {
        [$system, $commandList] = $this->createSystemWithCamera(new Vec3(0.0, 5.0, 10.0));
        $world = new World();

        for ($i = 0; $i < 3; $i++) {
            $entity = $world->createEntity();
            $entity->attach(new Transform3D(position: new Vec3((float)$i, 0.0, 0.0)));
            $entity->attach(new WorldText(text: "Label {$i}"));
        }

        $system->render($world);

        $texts = $commandList->ofType(DrawWorldText::class);
        $this->assertCount(3, $texts);
    }
}
