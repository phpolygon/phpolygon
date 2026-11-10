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

    public function testDefaultShaderIsDefault(): void
    {
        $m = Material::default();
        $this->assertSame('default', $m->shader);
    }

    public function testCustomShaderProperty(): void
    {
        $m = new Material(shader: 'unlit');
        $this->assertSame('unlit', $m->shader);
    }

    public function testShaderPropertyWithOtherParams(): void
    {
        $m = new Material(
            albedo: Color::red(),
            roughness: 0.3,
            shader: 'normals',
        );
        $this->assertSame('normals', $m->shader);
        $this->assertEqualsWithDelta(1.0, $m->albedo->r, 1e-6);
        $this->assertEqualsWithDelta(0.3, $m->roughness, 1e-6);
    }

    public function testFactoryMethodsUseDefaultShader(): void
    {
        $this->assertSame('default', Material::color(Color::white())->shader);
        $this->assertSame('default', Material::emissive(Color::white(), Color::white())->shader);
    }

    public function testCarpaintFactoryDefaults(): void
    {
        $m = Material::carpaint(new Color(0.2, 0.5, 0.85));
        $this->assertEqualsWithDelta(0.6, $m->metallic, 1e-6);
        $this->assertEqualsWithDelta(0.32, $m->roughness, 1e-6);
        $this->assertEqualsWithDelta(0.7, $m->clearcoat, 1e-6);
        $this->assertEqualsWithDelta(0.4, $m->flakes, 1e-6);
        $this->assertTrue($m->useEnvironmentMap);
    }

    public function testCarpaintFactoryAcceptsOverrides(): void
    {
        $m = Material::carpaint(
            albedo: new Color(1.0, 0.0, 0.0),
            metallic: 0.9,
            roughness: 0.1,
            clearcoat: 0.5,
            flakes: 0.8,
        );
        $this->assertEqualsWithDelta(0.9, $m->metallic, 1e-6);
        $this->assertEqualsWithDelta(0.1, $m->roughness, 1e-6);
        $this->assertEqualsWithDelta(0.5, $m->clearcoat, 1e-6);
        $this->assertEqualsWithDelta(0.8, $m->flakes, 1e-6);
    }

    public function testStandardMaterialDoesNotEnableCarpaintFeatures(): void
    {
        $m = Material::default();
        $this->assertEqualsWithDelta(0.0, $m->clearcoat, 1e-6);
        $this->assertEqualsWithDelta(0.0, $m->flakes, 1e-6);
        $this->assertEqualsWithDelta(1.0, $m->normalIntensity, 1e-6);
        $this->assertTrue($m->useEnvironmentMap);
    }

    public function testEnvironmentMapCanBeDisabledForFlatMaterials(): void
    {
        $m = new Material(useEnvironmentMap: false);
        $this->assertFalse($m->useEnvironmentMap);
    }

    public function testCarpaintFactoryRoughnessClearcoatRelationship(): void
    {
        // Clearcoat lobe should be sharper than the base lobe so the
        // characteristic "wet paint" highlight reads clearly.
        $m = Material::carpaint(new Color(0.5, 0.5, 0.5));
        $this->assertLessThan($m->roughness, $m->clearcoatRoughness);
    }

    public function testNormalPatternDefaultsToNone(): void
    {
        $m = Material::default();
        $this->assertNull($m->normalPattern);
        $this->assertEqualsWithDelta(1.0, $m->normalScale, 1e-6);
    }

    public function testNormalPatternAcceptsId(): void
    {
        $m = new Material(
            albedo: Color::white(),
            normalPattern: \PHPolygon\Rendering\NormalPattern::BRICKS,
            normalScale: 4.0,
        );
        $this->assertSame('bricks', $m->normalPattern);
        $this->assertEqualsWithDelta(4.0, $m->normalScale, 1e-6);
    }

    public function testWithWetnessKeepsEverythingElse(): void
    {
        $m = new Material(albedo: new Color(0.3, 0.4, 0.5), roughness: 0.8, sheen: 0.4, rimLight: 0.45, shadowDapple: 0.2);
        $wet = $m->withWetness(0.7);
        $this->assertEqualsWithDelta(0.7, $wet->wetness, 1e-9);
        $this->assertSame(0.0, $m->wetness, 'the original stays dry');
        $this->assertEqualsWithDelta(0.8 + (0.28 - 0.8) * 0.7, $wet->roughness, 1e-9, 'the film smooths it');
        $this->assertEqualsWithDelta(0.3 * (1.0 - 0.5 * 0.7), $wet->albedo->r, 1e-9, 'porous surfaces darken');
        $this->assertSame($m->albedo->r, $m->withWetness(0.0)->albedo->r, 'dry = unchanged');
        $this->assertSame(0.6, (new Material(albedo: new Color(0.6, 0.6, 0.6), metallic: 1.0))->withWetness(1.0)->albedo->r, 'metal does not darken');
        $this->assertEqualsWithDelta(0.4 * (1.0 - 0.8 * 0.7), $wet->sheen, 1e-9, 'the fuzz lies flat');
        $this->assertEqualsWithDelta(0.45, $wet->rimLight, 1e-9);
        $this->assertEqualsWithDelta(0.2, $wet->shadowDapple, 1e-9);
        $this->assertSame(1.0, $m->withWetness(3.0)->wetness);
        $this->assertSame(0.0, $m->withWetness(-1.0)->wetness);
    }

    public function testEmissionBehindTextureAlphaSurvivesEveryDerivedCopy(): void
    {
        $this->assertFalse(Material::default()->emissionByTextureAlpha);
        $m = Material::emissive(Color::white(), new Color(2.0, 2.0, 2.0))->withEmissionByTextureAlpha();
        $this->assertTrue($m->emissionByTextureAlpha);
        $this->assertTrue($m->withEmission(new Color(0.0, 0.0, 0.0))->emissionByTextureAlpha, 'dimming keeps the mask');
        $this->assertTrue($m->withAlbedoTexture('t')->emissionByTextureAlpha);
        $this->assertTrue($m->withWetness(0.5)->emissionByTextureAlpha);
        $this->assertFalse($m->withEmissionByTextureAlpha(false)->emissionByTextureAlpha);
    }
}
