<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Scene\Transpiler;

use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\NameTag;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\World;
use PHPolygon\Math\Vec3;
use PHPolygon\Scene\Transpiler\PrefabExporter;
use PHPolygon\Scene\Transpiler\PrefabInstantiator;
use PHPUnit\Framework\TestCase;

class PrefabRoundTripTest extends TestCase
{
    /**
     * Build a two-node prefab (a lantern with a bulb child) directly in a world.
     *
     * @return array{world: World, root: int, child: int}
     */
    private function buildLantern(): array
    {
        $world = new World();

        $root = $world->createEntity();
        $rootTf = new Transform3D(new Vec3(1.0, 0.0, 2.0));
        $root->attach($rootTf)
            ->attach(new MeshRenderer(meshId: 'lantern_pole', materialId: 'iron'))
            ->attach(new NameTag('Lantern'));

        $child = $world->createEntity();
        $childTf = new Transform3D(new Vec3(0.0, 3.0, 0.0));
        $child->attach($childTf)
            ->attach(new MeshRenderer(meshId: 'lantern_bulb', materialId: 'glass'))
            ->attach(new NameTag('Bulb'));

        $rootTf->addChild($childTf, $child->id, $root->id);

        return ['world' => $world, 'root' => $root->id, 'child' => $child->id];
    }

    public function testExportProducesNestedDocumentWithoutRuntimeIds(): void
    {
        ['world' => $world, 'root' => $rootId] = $this->buildLantern();

        $doc = (new PrefabExporter())->export($world, $rootId);

        $this->assertSame(1, $doc['_version']);
        $this->assertSame('Lantern', $doc['name']);
        $this->assertSame('Lantern', $doc['root']['name']);
        $this->assertCount(1, $doc['root']['children']);
        $this->assertSame('Bulb', $doc['root']['children'][0]['name']);

        // Transform3D must be present but carry no runtime parent/child ids.
        $transformData = $this->componentOf($doc['root'], Transform3D::class);
        $this->assertNotNull($transformData);
        $this->assertArrayNotHasKey('parentEntityId', $transformData);
        $this->assertArrayNotHasKey('childEntityIds', $transformData);

        // NameTag is captured as the node name, not duplicated as a component.
        $this->assertNull($this->componentOf($doc['root'], NameTag::class));
    }

    public function testInstantiateRebuildsHierarchyWithFreshIds(): void
    {
        ['world' => $source, 'root' => $srcRoot] = $this->buildLantern();
        $doc = (new PrefabExporter())->export($source, $srcRoot);

        $target = new World();
        $rootId = (new PrefabInstantiator())->instantiate($target, $doc);

        $rootTf = $target->getComponent($rootId, Transform3D::class);
        $this->assertInstanceOf(Transform3D::class, $rootTf);
        $this->assertCount(1, $rootTf->childEntityIds);

        $childId = $rootTf->childEntityIds[0];
        $childTf = $target->getComponent($childId, Transform3D::class);
        $this->assertSame($rootId, $childTf->parentEntityId);

        // Local transforms round-tripped.
        $this->assertEqualsWithDelta(3.0, $childTf->position->y, 1e-6);

        // Names round-tripped.
        $this->assertSame('Lantern', $target->getComponent($rootId, NameTag::class)->name);
        $this->assertSame('Bulb', $target->getComponent($childId, NameTag::class)->name);
    }

    public function testMaterialAssignmentRoundTripsAndCanBeReassigned(): void
    {
        ['world' => $source, 'root' => $srcRoot] = $this->buildLantern();
        $doc = (new PrefabExporter())->export($source, $srcRoot);

        // Reassign the bulb material in the document (what the editor's material
        // panel would write).
        foreach ($doc['root']['children'][0]['components'] as $i => $component) {
            if ($component['_class'] === MeshRenderer::class) {
                $doc['root']['children'][0]['components'][$i]['materialId'] = 'neon';
            }
        }

        $target = new World();
        $rootId = (new PrefabInstantiator())->instantiate($target, $doc);
        $childId = $target->getComponent($rootId, Transform3D::class)->childEntityIds[0];

        $this->assertSame('iron', $target->getComponent($rootId, MeshRenderer::class)->materialId);
        $this->assertSame('neon', $target->getComponent($childId, MeshRenderer::class)->materialId);
    }

    public function testNamePrefixKeepsCopiesDistinct(): void
    {
        ['world' => $source, 'root' => $srcRoot] = $this->buildLantern();
        $doc = (new PrefabExporter())->export($source, $srcRoot);

        $target = new World();
        $instantiator = new PrefabInstantiator();
        $a = $instantiator->instantiate($target, $doc, 'A_');
        $b = $instantiator->instantiate($target, $doc, 'B_');

        $this->assertSame('A_Lantern', $target->getComponent($a, NameTag::class)->name);
        $this->assertSame('B_Lantern', $target->getComponent($b, NameTag::class)->name);
        $this->assertNotSame($a, $b);
    }

    /**
     * @param array<string, mixed> $node
     * @param class-string $class
     * @return array<string, mixed>|null
     */
    private function componentOf(array $node, string $class): ?array
    {
        foreach ($node['components'] as $component) {
            if (($component['_class'] ?? null) === $class) {
                return $component;
            }
        }
        return null;
    }
}
