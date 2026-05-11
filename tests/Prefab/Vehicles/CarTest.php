<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Prefab\Vehicles;

use PHPUnit\Framework\TestCase;
use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\World;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Vec3;
use PHPolygon\Prefab\Vehicles\Car;
use PHPolygon\Prefab\Vehicles\CarChassis;
use PHPolygon\Prefab\Vehicles\CarRoof;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Scene\EntityDeclaration;
use PHPolygon\Scene\SceneBuilder;

final class CarTest extends TestCase
{
    protected function setUp(): void
    {
        MeshRegistry::clear();
        MaterialRegistry::clear();
    }

    public function testSedanProducesBodyAndFourWheels(): void
    {
        $builder = new SceneBuilder();
        $built = $builder->spawn(new Car())->place(new Vec3(0, 0, 0));

        $names = array_map(fn(EntityDeclaration $c) => $c->getName(), $built->getChildren());
        sort($names);

        // Body + 4 wheels are mandatory; many detail children (windows,
        // bumpers, lights, mirrors, plates) are also expected after the
        // visual-detail pass.
        foreach (['Body', 'Wheel_0', 'Wheel_1', 'Wheel_2', 'Wheel_3'] as $required) {
            $this->assertContains($required, $names, "Missing required child '{$required}'");
        }
    }

    public function testSuvAndSedanProduceDistinctBodyMeshIds(): void
    {
        $b1 = new SceneBuilder();
        $b1->spawn(new Car())->place(new Vec3(0, 0, 0));

        $b2 = new SceneBuilder();
        $b2->spawn(new Car())->suv()->place(new Vec3(0, 0, 0));

        $sedanBodyId = $this->bodyMeshIdOf($b1->getDeclarations()[0]);
        $suvBodyId   = $this->bodyMeshIdOf($b2->getDeclarations()[0]);

        $this->assertNotSame($sedanBodyId, $suvBodyId);
        $this->assertSame('car.sedan.hardtop.body', $sedanBodyId);
        $this->assertSame('car.suv.hardtop.body',   $suvBodyId);
    }

    public function testCabrioBodyMeshIdReflectsRoofVariant(): void
    {
        $builder = new SceneBuilder();
        $car = $builder->spawn(new Car())->cabrio()->place(new Vec3(0, 0, 0));

        $this->assertSame('car.sedan.convertible.body', $this->bodyMeshIdOf($car));
    }

    public function testModifierChainComposesChassisAndRoof(): void
    {
        $builder = new SceneBuilder();
        $car = $builder->spawn(new Car())
            ->suv()
            ->cabrio()
            ->place(new Vec3(0, 0, 0));

        $this->assertSame('car.suv.convertible.body', $this->bodyMeshIdOf($car));
    }

    public function testMeshesAreRegisteredOnceLazily(): void
    {
        $this->assertFalse(MeshRegistry::has('car.sedan.hardtop.body'));

        $b = new SceneBuilder();
        $b->spawn(new Car())->place(new Vec3(0, 0, 0));

        $this->assertTrue(MeshRegistry::has('car.sedan.hardtop.body'));
        $this->assertTrue(MeshRegistry::has('car.sedan.tire'));
        $this->assertTrue(MeshRegistry::has('car.sedan.rim'));
        $this->assertTrue(MeshRegistry::has('car.sedan.hardtop.windshield'));
        $this->assertTrue(MeshRegistry::has('car.headlight'));
        $bodyVersion = MeshRegistry::version('car.sedan.hardtop.body');

        // Spawning a second sedan must not re-register (version stays).
        $b->spawn(new Car())->named('Second')->place(new Vec3(10, 0, 0));
        $this->assertSame($bodyVersion, MeshRegistry::version('car.sedan.hardtop.body'));
    }

    public function testMaterialiseProducesTransform3DHierarchy(): void
    {
        $builder = new SceneBuilder();
        $builder->spawn(new Car())->named('Hero')->place(new Vec3(0, 0, 0));

        $world = new World();
        $map = $builder->materialize($world);

        $this->assertArrayHasKey('Hero', $map);
        $this->assertArrayHasKey('Body', $map);
        $this->assertArrayHasKey('Wheel_0', $map);
        $this->assertArrayHasKey('Windshield', $map);
        $this->assertArrayHasKey('Headlight_L', $map);
        $this->assertArrayHasKey('Rim_0', $map); // child of Wheel_0

        $rootTransform = $world->getComponent($map['Hero'], Transform3D::class);
        $this->assertInstanceOf(Transform3D::class, $rootTransform);
        // 23 direct children: body + 2 angled-glass + 2 side-windows
        // + 2 bumpers + grille + 4 lights + 2 plates + 2 mirrors
        // + 2 door handles + exhaust + 4 wheels. Rims are children of
        // wheels, not direct children of the root.
        $this->assertCount(23, $rootTransform->childEntityIds);

        $bodyTransform = $world->getComponent($map['Body'], Transform3D::class);
        $this->assertInstanceOf(Transform3D::class, $bodyTransform);
        $this->assertSame($map['Hero'], $bodyTransform->parentEntityId);

        // Rim is a child of its wheel, not the root.
        $rimTransform = $world->getComponent($map['Rim_0'], Transform3D::class);
        $this->assertInstanceOf(Transform3D::class, $rimTransform);
        $this->assertSame($map['Wheel_0'], $rimTransform->parentEntityId);
    }

    public function testSubclassCanOverrideHooks(): void
    {
        $builder = new SceneBuilder();
        $built = $builder->spawn(new TestTaxiCar())->place(new Vec3(0, 0, 0));

        // Subclass uses a distinct chassis enum, so the body mesh id changes too.
        $this->assertSame('car.compact.hardtop.body', $this->bodyMeshIdOf($built));
    }

    public function testSedanWheelPlacementMatchesRealisticProportions(): void
    {
        $builder = new SceneBuilder();
        $car = $builder->spawn(new Car())->place(new Vec3(0, 0, 0));

        $positions = $this->wheelPositions($car);
        sort($positions);

        // Mirror-symmetric across X (z signs flip pairwise).
        $this->assertSame(
            [[-1.35, 0.32, -1.0], [-1.35, 0.32, 1.0], [1.35, 0.32, -1.0], [1.35, 0.32, 1.0]],
            array_map(
                fn(array $p) => [round($p[0], 2), round($p[1], 2), round($p[2], 2)],
                $positions,
            ),
        );
    }

    public function testWheelbaseFractionPerChassis(): void
    {
        $cases = [
            [new Car(),               4.5, 0.60], // sedan
            [(new Car())->suv(),      4.7, 0.62],
            [(new Car())->pickup(),   5.4, 0.65],
            [(new Car())->compact(),  3.8, 0.60],
        ];

        foreach ($cases as [$car, $length, $expectedFraction]) {
            $builder = new SceneBuilder();
            $built = $builder->spawn($car)->place(new Vec3(0, 0, 0));
            $positions = $this->wheelPositions($built);
            $this->assertCount(4, $positions);

            usort($positions, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
            $wheelbase = $positions[3][0] - $positions[0][0];
            $ratio = $wheelbase / $length;

            $this->assertEqualsWithDelta(
                $expectedFraction,
                $ratio,
                0.01,
                "Wheelbase ratio mismatch for chassis at length {$length}",
            );
        }
    }

    public function testWheelsSitOnGroundLevel(): void
    {
        $builder = new SceneBuilder();
        $car = $builder->spawn(new Car())->place(new Vec3(0, 0, 0));

        $positions = $this->wheelPositions($car);
        $wheelR = 0.32; // sedan default

        foreach ($positions as $p) {
            // Wheel centre Y = wheel radius -> tire bottom touches y=0.
            $this->assertEqualsWithDelta($wheelR, $p[1], 1e-6);
        }
    }

    public function testPaintedWithUpdatesBodyMaterial(): void
    {
        $builder = new SceneBuilder();
        $built = $builder->spawn(new Car())
            ->paintedWith('car_paint_neon_pink')
            ->place(new Vec3(0, 0, 0));

        $body = $this->childByName($built, 'Body');
        $renderer = $this->meshRendererOf($body);
        $this->assertSame('car_paint_neon_pink', $renderer->materialId);
    }

    public function testGlassMaterialAppliedToAllWindowChildren(): void
    {
        $builder = new SceneBuilder();
        $built = $builder->spawn(new Car())
            ->glassWith('glass_dark_tint')
            ->place(new Vec3(0, 0, 0));

        foreach (['Windshield', 'RearWindow', 'SideWindow_L', 'SideWindow_R'] as $name) {
            $child = $this->childByName($built, $name);
            $this->assertSame('glass_dark_tint', $this->meshRendererOf($child)->materialId);
        }
    }

    public function testEmissiveLightMaterialsApplied(): void
    {
        $builder = new SceneBuilder();
        $built = $builder->spawn(new Car())
            ->headlightsAs('xenon_white')
            ->taillightsAs('led_red')
            ->place(new Vec3(0, 0, 0));

        $this->assertSame('xenon_white', $this->meshRendererOf($this->childByName($built, 'Headlight_L'))->materialId);
        $this->assertSame('xenon_white', $this->meshRendererOf($this->childByName($built, 'Headlight_R'))->materialId);
        $this->assertSame('led_red',     $this->meshRendererOf($this->childByName($built, 'Taillight_L'))->materialId);
        $this->assertSame('led_red',     $this->meshRendererOf($this->childByName($built, 'Taillight_R'))->materialId);
    }

    public function testRimsAreChildrenOfWheelsAndUseRimMaterial(): void
    {
        $builder = new SceneBuilder();
        $built = $builder->spawn(new Car())
            ->rimsOf('chrome_polished')
            ->place(new Vec3(0, 0, 0));

        for ($i = 0; $i < 4; $i++) {
            $wheel = $this->childByName($built, "Wheel_{$i}");
            $rim = $this->childByName($wheel, "Rim_{$i}");
            $this->assertSame('chrome_polished', $this->meshRendererOf($rim)->materialId);
        }
    }

    public function testMirrorsFallBackToBodyPaintByDefault(): void
    {
        $builder = new SceneBuilder();
        $built = $builder->spawn(new Car())
            ->paintedWith('paint_metallic_silver')
            ->place(new Vec3(0, 0, 0));

        $mirrorL = $this->childByName($built, 'Mirror_L');
        $this->assertSame('paint_metallic_silver', $this->meshRendererOf($mirrorL)->materialId);
    }

    public function testMirrorsCanBeOverriddenIndependently(): void
    {
        $builder = new SceneBuilder();
        $built = $builder->spawn(new Car())
            ->paintedWith('paint_red')
            ->mirrorsOf('chrome')
            ->place(new Vec3(0, 0, 0));

        $mirrorL = $this->childByName($built, 'Mirror_L');
        $this->assertSame('chrome', $this->meshRendererOf($mirrorL)->materialId);
    }

    public function testStyleSedanReturnsDefaultConfiguredCar(): void
    {
        $car = Car::styleSedan();
        $this->assertInstanceOf(Car::class, $car);

        $builder = new SceneBuilder();
        $built = $builder->spawn($car)->place(new Vec3(0, 0, 0));
        $this->assertSame('car.sedan.hardtop.body', $this->bodyMeshIdOf($built));
    }

    public function testStyleSuvCabrioComposesChassisAndRoofModifiers(): void
    {
        $builder = new SceneBuilder();
        $built = $builder->spawn(Car::styleSuvCabrio())->place(new Vec3(0, 0, 0));
        $this->assertSame('car.suv.convertible.body', $this->bodyMeshIdOf($built));
    }

    public function testStyleRedPickupAppliesChassisAndPaint(): void
    {
        $builder = new SceneBuilder();
        $built = $builder->spawn(Car::styleRedPickup())->place(new Vec3(0, 0, 0));
        $this->assertSame('car.pickup.hardtop.body', $this->bodyMeshIdOf($built));
        $body = $this->meshRendererOf($this->childByName($built, 'Body'));
        $this->assertSame('car_paint_red', $body->materialId);
    }

    public function testStyleYellowCompactAppliesChassisAndPaint(): void
    {
        $builder = new SceneBuilder();
        $built = $builder->spawn(Car::styleYellowCompact())->place(new Vec3(0, 0, 0));
        $this->assertSame('car.compact.hardtop.body', $this->bodyMeshIdOf($built));
        $body = $this->meshRendererOf($this->childByName($built, 'Body'));
        $this->assertSame('car_paint_yellow', $body->materialId);
    }

    public function testStyleFactoriesReturnSubclassWhenCalledOnSubclass(): void
    {
        // `new static()` inside the factories means a subclass keeps its
        // identity rather than collapsing back to Car.
        $sedan      = TestSubclassedSportsCar::styleSedan();
        $suvCabrio  = TestSubclassedSportsCar::styleSuvCabrio();
        $redPickup  = TestSubclassedSportsCar::styleRedPickup();
        $yellowComp = TestSubclassedSportsCar::styleYellowCompact();

        $this->assertInstanceOf(TestSubclassedSportsCar::class, $sedan);
        $this->assertInstanceOf(TestSubclassedSportsCar::class, $suvCabrio);
        $this->assertInstanceOf(TestSubclassedSportsCar::class, $redPickup);
        $this->assertInstanceOf(TestSubclassedSportsCar::class, $yellowComp);
    }

    public function testStyleFactoriesAreIndependentInstances(): void
    {
        // Each call returns a fresh Car so callers can mutate without
        // contaminating other spawns.
        $a = Car::styleRedPickup();
        $b = Car::styleRedPickup();
        $a->paintedWith('overridden');

        $builder = new SceneBuilder();
        $bSpawned = $builder->spawn($b)->place(new Vec3(0, 0, 0));
        $body = $this->meshRendererOf($this->childByName($bSpawned, 'Body'));
        // Second instance still has the original paint - state did not leak.
        $this->assertSame('car_paint_red', $body->materialId);
    }

    public function testDemoLineupSpawnsFourCarsAtExpectedPositions(): void
    {
        $builder = new SceneBuilder();
        $cars = Car::demoLineup($builder);

        $this->assertCount(4, $cars);
        $names = array_map(static fn(EntityDeclaration $c): string => $c->getName(), $cars);
        $this->assertSame(['Sedan', 'SuvCabrio', 'RedPickup', 'Commuter'], $names);

        // Cars are placed at -1.5s, -0.5s, +0.5s, +1.5s along X with default
        // spacing of 7.5 (x = -11.25, -3.75, +3.75, +11.25).
        $xs = array_map(function (EntityDeclaration $car): float {
            foreach ($car->getComponents() as $component) {
                if ($component instanceof \PHPolygon\Component\Transform3D) {
                    return $component->position->x;
                }
            }
            return PHP_FLOAT_MAX;
        }, $cars);

        $this->assertEqualsWithDelta(-11.25, $xs[0], 1e-6);
        $this->assertEqualsWithDelta(-3.75,  $xs[1], 1e-6);
        $this->assertEqualsWithDelta(3.75,   $xs[2], 1e-6);
        $this->assertEqualsWithDelta(11.25,  $xs[3], 1e-6);
    }

    public function testDemoLineupAppliesChassisAndPaintModifiers(): void
    {
        $builder = new SceneBuilder();
        $cars = Car::demoLineup($builder);

        // SuvCabrio reaches the convertible body mesh.
        $this->assertSame('car.suv.convertible.body', $this->bodyMeshIdOf($cars[1]));
        // RedPickup uses the pickup chassis and red paint.
        $this->assertSame('car.pickup.hardtop.body',   $this->bodyMeshIdOf($cars[2]));
        $bodyRenderer = $this->meshRendererOf($this->childByName($cars[2], 'Body'));
        $this->assertSame('car_paint_red', $bodyRenderer->materialId);
        // Commuter is a yellow compact.
        $this->assertSame('car.compact.hardtop.body',  $this->bodyMeshIdOf($cars[3]));
        $commuterBody = $this->meshRendererOf($this->childByName($cars[3], 'Body'));
        $this->assertSame('car_paint_yellow', $commuterBody->materialId);
    }

    public function testRegisterDefaultMaterialsIsIdempotentAndPreservesGameOverrides(): void
    {
        $custom = new \PHPolygon\Rendering\Material(
            albedo: new \PHPolygon\Rendering\Color(0.0, 1.0, 0.0),
            roughness: 0.42, metallic: 0.13,
        );
        MaterialRegistry::register('car_paint_default', $custom);

        Car::registerDefaultMaterials();

        // Game-registered material must not be clobbered by the engine defaults.
        $stored = MaterialRegistry::get('car_paint_default');
        $this->assertSame($custom, $stored);

        // Defaults that were not pre-registered are filled in.
        $this->assertNotNull(MaterialRegistry::get('car_glass_default'));
        $this->assertNotNull(MaterialRegistry::get('car_rim_default'));
        $this->assertNotNull(MaterialRegistry::get('car_headlight_default'));

        // Calling twice is safe (no duplicate registrations / errors).
        Car::registerDefaultMaterials();
        $this->assertSame($custom, MaterialRegistry::get('car_paint_default'));
    }

    public function testNewDetailEntitiesArePresent(): void
    {
        $builder = new SceneBuilder();
        $builder->spawn(new Car())->named('Hero')->place(new Vec3(0, 0, 0));

        $world = new \PHPolygon\ECS\World();
        $map = $builder->materialize($world);

        // Visual-detail children added in the spoked-rim / grille / exhaust
        // / door-handle pass.
        foreach (['Grille', 'DoorHandle_L', 'DoorHandle_R', 'Exhaust'] as $name) {
            $this->assertArrayHasKey($name, $map, "Missing detail entity '{$name}'");
        }
    }

    public function testWheelHasSeparateTireAndRimChildren(): void
    {
        $builder = new SceneBuilder();
        $builder->spawn(new Car())->named('Hero')->place(new Vec3(0, 0, 0));

        $world = new \PHPolygon\ECS\World();
        $map = $builder->materialize($world);

        foreach (['Wheel_0', 'Tire_0', 'Rim_0'] as $name) {
            $this->assertArrayHasKey($name, $map, "Missing wheel-component '{$name}'");
        }

        $tire = $world->getComponent($map['Tire_0'], Transform3D::class);
        $rim  = $world->getComponent($map['Rim_0'], Transform3D::class);
        $this->assertInstanceOf(Transform3D::class, $tire);
        $this->assertInstanceOf(Transform3D::class, $rim);
        // Both must hang under the same Wheel container.
        $this->assertSame($map['Wheel_0'], $tire->parentEntityId);
        $this->assertSame($map['Wheel_0'], $rim->parentEntityId);
    }

    public function testRimMeshUsesSpokedRimGenerator(): void
    {
        $builder = new SceneBuilder();
        $builder->spawn(new Car())->place(new Vec3(0, 0, 0));

        $rimMesh = MeshRegistry::get('car.sedan.rim');
        $this->assertNotNull($rimMesh);
        // Spoked rims have substantially more triangles than a plain
        // CylinderMesh (which would generate ~24 triangles for a
        // 12-segment cylinder + caps). The new rim has outer ring +
        // inner ring + face disc + hub + 5 boxed spokes ≈ 100+ tris.
        $this->assertGreaterThan(60, $rimMesh->triangleCount());
    }

    public function testPickupHasMoreSpokesThanSedan(): void
    {
        // Indirect check via mesh-id stability: pickups regenerate the rim
        // mesh under their own chassis-scoped id, with rimSpokeCount=6.
        // The vertex count for a 6-spoke rim is greater than a 5-spoke
        // rim of the same dimensions (each spoke adds 8 vertices).
        $b1 = new SceneBuilder();
        $b1->spawn(new Car())->place(new Vec3(0, 0, 0));
        $sedanRim = MeshRegistry::get('car.sedan.rim');

        $b2 = new SceneBuilder();
        $b2->spawn(new Car())->pickup()->place(new Vec3(0, 0, 0));
        $pickupRim = MeshRegistry::get('car.pickup.rim');

        $this->assertNotNull($sedanRim);
        $this->assertNotNull($pickupRim);
        $this->assertGreaterThan(
            $sedanRim->vertexCount(),
            $pickupRim->vertexCount(),
            'Pickup rim (6 spokes) must have more vertices than sedan rim (5 spokes)',
        );
    }

    public function testGrilleMaterialIsRegisteredByDefault(): void
    {
        Car::registerDefaultMaterials();
        $grille = MaterialRegistry::get('car_grille_default');
        $this->assertNotNull($grille);
        // Grille is matte black plastic, not metallic chrome.
        $this->assertLessThan(0.1, $grille->albedo->r);
        $this->assertLessThan(0.5, $grille->metallic);
    }

    public function testCarPaintDefaultsAreCarpaintMaterials(): void
    {
        Car::registerDefaultMaterials();

        foreach (['car_paint_default', 'car_paint_red', 'car_paint_yellow'] as $id) {
            $mat = MaterialRegistry::get($id);
            $this->assertNotNull($mat, "Material '{$id}' must be registered");
            $this->assertGreaterThan(0.0, $mat->clearcoat, "Material '{$id}' must enable clearcoat");
            $this->assertGreaterThan(0.0, $mat->flakes, "Material '{$id}' must enable flakes");
            $this->assertTrue($mat->useEnvironmentMap, "Material '{$id}' must enable IBL reflection");
        }
    }

    public function testGlassUsesClearcoatLobe(): void
    {
        Car::registerDefaultMaterials();
        $glass = MaterialRegistry::get('car_glass_default');
        $this->assertNotNull($glass);
        $this->assertGreaterThan(0.5, $glass->clearcoat);
        $this->assertLessThan(0.1, $glass->clearcoatRoughness);
    }

    public function testNonReflectiveLightsDisableEnvironmentMap(): void
    {
        Car::registerDefaultMaterials();
        // Headlight / taillight emission already drives the look; sampling
        // the environment would fight the emissive component.
        $this->assertFalse(MaterialRegistry::get('car_headlight_default')->useEnvironmentMap);
        $this->assertFalse(MaterialRegistry::get('car_taillight_default')->useEnvironmentMap);
    }

    public function testDemoLineupRespectsCustomOriginAndSpacing(): void
    {
        $builder = new SceneBuilder();
        $cars = Car::demoLineup($builder, new Vec3(100, 0, 50), spacing: 10.0);

        $sedanPos = null;
        foreach ($cars[0]->getComponents() as $component) {
            if ($component instanceof \PHPolygon\Component\Transform3D) {
                $sedanPos = $component->position;
                break;
            }
        }
        $this->assertNotNull($sedanPos);
        // Sedan sits at origin.x - 1.5 * spacing = 100 - 15 = 85.
        $this->assertEqualsWithDelta(85.0, $sedanPos->x, 1e-6);
        $this->assertEqualsWithDelta(50.0, $sedanPos->z, 1e-6);
    }

    public function testWindshieldMeshIdReflectsChassisAndRoof(): void
    {
        $a = new SceneBuilder();
        $a->spawn(new Car())->place(new Vec3(0, 0, 0));
        $b = new SceneBuilder();
        $b->spawn(new Car())->cabrio()->place(new Vec3(0, 0, 0));

        $sedanWindshield  = $this->meshRendererOf($this->childByName($a->getDeclarations()[0], 'Windshield'))->meshId;
        $cabrioWindshield = $this->meshRendererOf($this->childByName($b->getDeclarations()[0], 'Windshield'))->meshId;

        $this->assertSame('car.sedan.hardtop.windshield',     $sedanWindshield);
        $this->assertSame('car.sedan.convertible.windshield', $cabrioWindshield);
    }

    /** @return list<array{0: float, 1: float, 2: float}> */
    private function wheelPositions(EntityDeclaration $car): array
    {
        $positions = [];
        foreach ($car->getChildren() as $child) {
            if (!str_starts_with($child->getName(), 'Wheel_')) {
                continue;
            }
            foreach ($child->getComponents() as $component) {
                if ($component instanceof Transform3D) {
                    $positions[] = [$component->position->x, $component->position->y, $component->position->z];
                }
            }
        }
        return $positions;
    }

    private function bodyMeshIdOf(EntityDeclaration $car): string
    {
        $body = $this->childByName($car, 'Body');
        return $this->meshRendererOf($body)->meshId;
    }

    private function childByName(EntityDeclaration $parent, string $name): EntityDeclaration
    {
        foreach ($parent->getChildren() as $child) {
            if ($child->getName() === $name) {
                return $child;
            }
        }
        $this->fail("Child '{$name}' not found");
    }

    private function meshRendererOf(EntityDeclaration $decl): MeshRenderer
    {
        foreach ($decl->getComponents() as $component) {
            if ($component instanceof MeshRenderer) {
                return $component;
            }
        }
        $this->fail('No MeshRenderer attached');
    }
}

/**
 * Demonstrates the Car-as-base-class pattern: a subclass changes the
 * default chassis without re-implementing build().
 *
 * @internal
 */
final class TestTaxiCar extends Car
{
    protected CarChassis $chassis = CarChassis::Compact;
    protected CarRoof    $roof    = CarRoof::Hardtop;
}

/**
 * Subclass used to verify that style factories return the subclass type
 * (covariant `static` return) rather than collapsing back to Car.
 *
 * @internal
 */
final class TestSubclassedSportsCar extends Car
{
}
