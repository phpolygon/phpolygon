<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Prototype;

use PHPUnit\Framework\TestCase;
use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\Transform3D;
use PHPolygon\Prototype\ComponentSchemaGenerator;
use PHPolygon\Prototype\SerializableScanner;
use stdClass;

class ComponentSchemaGeneratorTest extends TestCase
{
    public function testGeneratesSchemaForKnownComponents(): void
    {
        $schema = (new ComponentSchemaGenerator())->generate([Transform3D::class, MeshRenderer::class]);

        $this->assertSame(ComponentSchemaGenerator::VERSION, $schema['_version']);
        $this->assertArrayHasKey('Transform3D', $schema['components']);
        $this->assertArrayHasKey('MeshRenderer', $schema['components']);

        $t3d = $schema['components']['Transform3D'];
        $this->assertSame(Transform3D::class, $t3d['class']);
        $this->assertSame('Core', $t3d['category']);

        $byName = $this->indexByName($t3d['properties']);
        $this->assertSame('Vec3', $byName['position']['type']);
        $this->assertSame('vec3', $byName['position']['editorHint']);
        $this->assertSame('Quaternion', $byName['rotation']['type']);

        // ?int -> nullable flag present.
        $this->assertSame('int', $byName['parentEntityId']['type']);
        $this->assertTrue($byName['parentEntityId']['nullable']);

        // #[Hidden] worldMatrix must not leak into the schema.
        $this->assertArrayNotHasKey('worldMatrix', $byName);
    }

    public function testReportsEditorHintsAndDefaults(): void
    {
        $schema = (new ComponentSchemaGenerator())->generate([MeshRenderer::class]);
        $mr = $schema['components']['MeshRenderer'];

        $this->assertSame('Rendering', $mr['category']);

        $byName = $this->indexByName($mr['properties']);
        $this->assertSame('asset:mesh', $byName['meshId']['editorHint']);
        $this->assertSame('asset:material', $byName['materialId']['editorHint']);
        $this->assertSame('bool', $byName['castShadows']['type']);

        // No-arg constructible -> engine defaults captured for the front-end.
        $this->assertArrayHasKey('defaults', $mr);
        $this->assertTrue($mr['defaults']['castShadows']);
        $this->assertSame('', $mr['defaults']['meshId']);
    }

    public function testGenerateOneReturnsNullForNonSerializable(): void
    {
        $this->assertNull((new ComponentSchemaGenerator())->generateOne(stdClass::class));
    }

    public function testScannerDiscoversEngineComponents(): void
    {
        $dir = dirname(__DIR__, 2) . '/src/Component';
        $classes = SerializableScanner::scan($dir, 'PHPolygon\\Component');

        $this->assertContains(Transform3D::class, $classes);
        $this->assertContains(MeshRenderer::class, $classes);

        // Everything the scanner returns must produce a valid schema entry.
        $gen = new ComponentSchemaGenerator();
        foreach ($classes as $class) {
            $this->assertNotNull($gen->generateOne($class), "{$class} should produce a schema entry");
        }
    }

    public function testScannerReturnsEmptyForMissingDirectory(): void
    {
        $this->assertSame([], SerializableScanner::scan('/no/such/dir', 'PHPolygon\\Component'));
    }

    /**
     * @param list<array<string, mixed>> $properties
     * @return array<string, array<string, mixed>>
     */
    private function indexByName(array $properties): array
    {
        $out = [];
        foreach ($properties as $p) {
            /** @var string $name */
            $name = $p['name'];
            $out[$name] = $p;
        }
        return $out;
    }
}
