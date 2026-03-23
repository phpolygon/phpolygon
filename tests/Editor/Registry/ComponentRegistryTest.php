<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Editor\Registry;

use PHPUnit\Framework\TestCase;
use PHPolygon\Component\AudioSource;
use PHPolygon\Component\BoxCollider2D;
use PHPolygon\Component\Camera2DComponent;
use PHPolygon\Component\RigidBody2D;
use PHPolygon\Component\SpriteRenderer;
use PHPolygon\Component\Transform2D;
use PHPolygon\Editor\Registry\ComponentRegistry;

class ComponentRegistryTest extends TestCase
{
    public function testManualRegister(): void
    {
        $registry = new ComponentRegistry();
        $registry->register(Transform2D::class);

        $this->assertTrue($registry->has(Transform2D::class));
        $this->assertFalse($registry->has(SpriteRenderer::class));

        $schema = $registry->get(Transform2D::class);
        $this->assertSame('Transform2D', $schema->shortName);
    }

    public function testScanDirectory(): void
    {
        $registry = new ComponentRegistry();
        $componentDir = dirname(__DIR__, 3) . '/src/Component';
        $registry->scan($componentDir, 'PHPolygon\\Component\\');

        $this->assertTrue($registry->has(Transform2D::class));
        $this->assertTrue($registry->has(SpriteRenderer::class));
        $this->assertTrue($registry->has(RigidBody2D::class));
        $this->assertTrue($registry->has(BoxCollider2D::class));
        $this->assertTrue($registry->has(AudioSource::class));
    }

    public function testGetByCategory(): void
    {
        $registry = new ComponentRegistry();
        $registry->register(Transform2D::class);
        $registry->register(SpriteRenderer::class);
        $registry->register(RigidBody2D::class);
        $registry->register(BoxCollider2D::class);
        $registry->register(AudioSource::class);

        $categories = $registry->getByCategory();

        $this->assertArrayHasKey('Core', $categories);
        $this->assertArrayHasKey('Rendering', $categories);
        $this->assertArrayHasKey('Physics', $categories);
        $this->assertArrayHasKey('Audio', $categories);

        $this->assertCount(1, $categories['Core']); // Transform2D
        $this->assertCount(2, $categories['Physics']); // RigidBody2D, BoxCollider2D
    }

    public function testToArray(): void
    {
        $registry = new ComponentRegistry();
        $registry->register(Transform2D::class);

        $arr = $registry->toArray();
        $this->assertArrayHasKey(Transform2D::class, $arr);
        $this->assertSame('Transform2D', $arr[Transform2D::class]['shortName']);
    }

    public function testGetUnknownThrows(): void
    {
        $registry = new ComponentRegistry();
        $this->expectException(\RuntimeException::class);
        $registry->get('NonExistent');
    }
}
