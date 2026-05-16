<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Character;

use PHPolygon\Character\CharacterMeshBuilder;
use PHPolygon\Character\DNA\CharacterDNA;
use PHPolygon\Character\DNA\Enum\Accessory;
use PHPolygon\Character\DNA\Enum\FacialHair;
use PHPolygon\Character\DNA\Enum\HairStyle;
use PHPolygon\Character\DNA\PlayerProportions;
use PHPolygon\Component\CharacterDnaComponent;
use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\World;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\MaterialRegistry;
use PHPUnit\Framework\TestCase;

class CharacterMeshBuilderTest extends TestCase
{
    public function testRegisterDefaultsPopulatesRegistries(): void
    {
        CharacterMeshBuilder::resetDefaults();
        CharacterMeshBuilder::registerDefaults();

        $this->assertNotNull(MeshRegistry::get(CharacterMeshBuilder::MESH_BOX));
        $this->assertNotNull(MeshRegistry::get(CharacterMeshBuilder::MESH_SPHERE));
        $this->assertNotNull(MeshRegistry::get(CharacterMeshBuilder::MESH_CYLINDER));
        $this->assertNotNull(MeshRegistry::get(CharacterMeshBuilder::MESH_SKULL));
        $this->assertNotNull(MeshRegistry::get(CharacterMeshBuilder::MESH_TORSO));

        $this->assertTrue(MaterialRegistry::has(CharacterMeshBuilder::MAT_EYE_WHITE));
        $this->assertTrue(MaterialRegistry::has(CharacterMeshBuilder::MAT_MOUTH));
        $this->assertTrue(MaterialRegistry::has(CharacterMeshBuilder::MAT_CLOTH_DARK));
        $this->assertTrue(MaterialRegistry::has(CharacterMeshBuilder::MAT_ACCESSORY_METAL));
    }

    public function testRegisterDefaultsIsIdempotent(): void
    {
        CharacterMeshBuilder::resetDefaults();
        CharacterMeshBuilder::registerDefaults();
        $first = MeshRegistry::get(CharacterMeshBuilder::MESH_BOX);

        // Second call should not throw, should not replace already-registered objects
        CharacterMeshBuilder::registerDefaults();
        $second = MeshRegistry::get(CharacterMeshBuilder::MESH_BOX);

        $this->assertSame($first, $second);
    }

    public function testBuildOnRequiresProportionsOrComponent(): void
    {
        $world = new World();
        $root = $world->createEntity();
        $root->attach(new Transform3D(position: Vec3::zero()));

        $this->expectException(\LogicException::class);
        CharacterMeshBuilder::buildOn($world, $root);
    }

    public function testBuildOnSpawnsRigPartsForDecodedComponent(): void
    {
        $world = new World();
        $root = $world->createEntity();
        $root->attach(new Transform3D(position: new Vec3(0.0, 0.0, 0.0)));
        $root->attach(new CharacterDnaComponent(CharacterDNA::random()));

        $parts = CharacterMeshBuilder::buildOn($world, $root);

        $this->assertGreaterThan(40, count($parts), 'A humanoid rig should consist of dozens of parts');
        foreach ($parts as $part) {
            $this->assertTrue($part->has(MeshRenderer::class));
            $this->assertTrue($part->has(Transform3D::class));
        }
    }

    public function testBuildOnAcceptsExplicitProportions(): void
    {
        $world = new World();
        $root = $world->createEntity();
        $root->attach(new Transform3D(position: new Vec3(0.0, 0.0, 0.0)));

        $props = (new \PHPolygon\Character\DNA\GeneDecoder())
            ->decode(CharacterDNA::random(), PlayerProportions::class);

        $parts = CharacterMeshBuilder::buildOn($world, $root, $props);
        $this->assertGreaterThan(40, count($parts));
    }

    public function testBuildOnRespectsRootPosition(): void
    {
        $world = new World();
        $root = $world->createEntity();
        $rootPos = new Vec3(7.0, 0.0, -3.0);
        $root->attach(new Transform3D(position: $rootPos));
        $root->attach(new CharacterDnaComponent(CharacterDNA::random()));

        $parts = CharacterMeshBuilder::buildOn($world, $root);

        // Every part should sit somewhere in the neighbourhood of the root's xz column
        foreach ($parts as $part) {
            $pos = $part->get(Transform3D::class)->position;
            $this->assertEqualsWithDelta($rootPos->x, $pos->x, 1.5);
            $this->assertEqualsWithDelta($rootPos->z, $pos->z, 1.5);
        }
    }

    public function testBaldStyleSpawnsNoHairCards(): void
    {
        $world = new World();
        $root = $world->createEntity();
        $root->attach(new Transform3D(position: Vec3::zero()));

        // Pick an ACGT strand that puts HairStyle at Bald (locus 13).
        // Bald is the first case (codon 0 mod 12 = 0). Three bases that
        // decode to codon 0 = 'AAA' at base positions 39, 40, 41.
        $acgt = str_repeat('A', CharacterDNA::STRAND_BASES);
        $dna = CharacterDNA::fromAcgt($acgt);
        $component = new CharacterDnaComponent($dna);
        $root->attach($component);

        $this->assertSame(HairStyle::Bald, $component->proportions()->hairStyle);
        $baldParts = count(CharacterMeshBuilder::buildOn($world, $root));

        // Compare against a known non-bald build to confirm the difference.
        $world2 = new World();
        $root2 = $world2->createEntity();
        $root2->attach(new Transform3D(position: Vec3::zero()));
        $component2 = new CharacterDnaComponent(CharacterDNA::fromAcgt(str_repeat('T', CharacterDNA::STRAND_BASES)));
        $root2->attach($component2);
        $hairParts = count(CharacterMeshBuilder::buildOn($world2, $root2));

        $this->assertLessThan($hairParts, $baldParts);
    }

    public function testFacialHairAndAccessoryNoneSkipExtraParts(): void
    {
        $world = new World();
        $root = $world->createEntity();
        $root->attach(new Transform3D(position: Vec3::zero()));
        $component = new CharacterDnaComponent(CharacterDNA::fromAcgt(str_repeat('A', CharacterDNA::STRAND_BASES)));
        $root->attach($component);

        // All-A strand → first enum case for FacialHair/Accessory, which is None.
        $this->assertSame(FacialHair::None, $component->proportions()->facialHair);
        $this->assertSame(Accessory::None, $component->proportions()->accessory);

        // Just verify build completes successfully; specific count is not pinned
        // because hair style + other branches still apply.
        $parts = CharacterMeshBuilder::buildOn($world, $root);
        $this->assertNotEmpty($parts);
    }
}
