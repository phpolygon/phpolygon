<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\TestCase;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;

class MaterialTest extends TestCase
{
    protected function setUp(): void
    {
        MaterialRegistry::clear();
    }

    public function testDefaultMaterial(): void
    {
        $m = Material::default();
        $this->assertEqualsWithDelta(0.8, $m->albedo->r, 1e-6);
        $this->assertEqualsWithDelta(0.5, $m->roughness, 1e-6);
        $this->assertEqualsWithDelta(0.0, $m->metallic, 1e-6);
        $this->assertEqualsWithDelta(0.0, $m->emission->r, 1e-6);
    }

    public function testColorFactory(): void
    {
        $m = Material::color(Color::red());
        $this->assertEqualsWithDelta(1.0, $m->albedo->r, 1e-6);
        $this->assertEqualsWithDelta(0.0, $m->albedo->g, 1e-6);
    }

    public function testEmissiveFactory(): void
    {
        $m = Material::emissive(Color::white(), new Color(0.5, 0.3, 0.1));
        $this->assertEqualsWithDelta(0.5, $m->emission->r, 1e-6);
        $this->assertEqualsWithDelta(0.3, $m->emission->g, 1e-6);
    }

    public function testMaterialRegistryRegisterAndGet(): void
    {
        $stone = Material::color(new Color(0.5, 0.5, 0.5));
        MaterialRegistry::register('stone', $stone);

        $this->assertTrue(MaterialRegistry::has('stone'));
        $this->assertSame($stone, MaterialRegistry::get('stone'));
        $this->assertNull(MaterialRegistry::get('nonexistent'));
    }

    public function testMaterialRegistryClear(): void
    {
        MaterialRegistry::register('a', Material::default());
        MaterialRegistry::register('b', Material::default());
        $this->assertCount(2, MaterialRegistry::ids());

        MaterialRegistry::clear();
        $this->assertCount(0, MaterialRegistry::ids());
    }

    public function testMaterialRegistryIds(): void
    {
        MaterialRegistry::register('brick', Material::default());
        MaterialRegistry::register('metal', Material::default());

        $ids = MaterialRegistry::ids();
        $this->assertContains('brick', $ids);
        $this->assertContains('metal', $ids);
    }
}
