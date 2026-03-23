<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Serializer;

use PHPUnit\Framework\TestCase;
use PHPolygon\Component\Camera2DComponent;
use PHPolygon\Component\SpriteRenderer;
use PHPolygon\Component\Transform2D;
use PHPolygon\ECS\Serializer\AttributeSerializer;
use PHPolygon\Math\Rect;
use PHPolygon\Math\Vec2;
use PHPolygon\Rendering\Color;

class AttributeSerializerTest extends TestCase
{
    private AttributeSerializer $serializer;

    protected function setUp(): void
    {
        $this->serializer = new AttributeSerializer();
    }

    public function testRoundTripTransform2D(): void
    {
        $original = new Transform2D(
            position: new Vec2(100.5, 200.3),
            rotation: 45.0,
            scale: new Vec2(2.0, 3.0),
        );

        $array = $this->serializer->toArray($original);
        $this->assertArrayHasKey('_class', $array);
        $this->assertArrayHasKey('position', $array);
        $this->assertArrayHasKey('rotation', $array);
        $this->assertArrayHasKey('scale', $array);
        // parentEntityId should NOT be in output (it's #[Hidden])
        $this->assertArrayNotHasKey('parentEntityId', $array);

        $restored = $this->serializer->fromArray($array, Transform2D::class);
        $this->assertInstanceOf(Transform2D::class, $restored);
        /** @var Transform2D $restored */
        $this->assertTrue($original->position->equals($restored->position));
        $this->assertEquals($original->rotation, $restored->rotation);
        $this->assertTrue($original->scale->equals($restored->scale));
    }

    public function testRoundTripSpriteRenderer(): void
    {
        $original = new SpriteRenderer(
            textureId: 'player.png',
            region: new Rect(0, 0, 32, 32),
            color: Color::red(),
            layer: 5,
            flipX: true,
            opacity: 0.8,
            width: 64,
            height: 64,
        );

        $array = $this->serializer->toArray($original);
        $restored = $this->serializer->fromArray($array, SpriteRenderer::class);

        $this->assertInstanceOf(SpriteRenderer::class, $restored);
        /** @var SpriteRenderer $restored */
        $this->assertEquals('player.png', $restored->textureId);
        $this->assertTrue($original->region->equals($restored->region));
        $this->assertEquals($original->layer, $restored->layer);
        $this->assertTrue($restored->flipX);
        $this->assertFalse($restored->flipY);
        $this->assertEquals(0.8, $restored->opacity);
    }

    public function testRoundTripCamera2D(): void
    {
        $original = new Camera2DComponent(
            zoom: 2.5,
            bounds: new Rect(-100, -100, 200, 200),
            active: false,
        );

        $array = $this->serializer->toArray($original);
        $restored = $this->serializer->fromArray($array, Camera2DComponent::class);

        $this->assertInstanceOf(Camera2DComponent::class, $restored);
        /** @var Camera2DComponent $restored */
        $this->assertEquals(2.5, $restored->zoom);
        $this->assertNotNull($restored->bounds);
        $this->assertTrue($original->bounds->equals($restored->bounds));
        $this->assertFalse($restored->active);
    }

    public function testSerializesToJsonEncodable(): void
    {
        $transform = new Transform2D(
            position: new Vec2(1.0, 2.0),
            rotation: 90.0,
        );

        $array = $this->serializer->toArray($transform);
        $json = json_encode($array);
        $this->assertIsString($json);
        $this->assertNotFalse($json);

        $decoded = json_decode($json, true);
        $restored = $this->serializer->fromArray($decoded, Transform2D::class);
        $this->assertInstanceOf(Transform2D::class, $restored);
    }
}
