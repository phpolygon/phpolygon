<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Scene;

use PHPolygon\Component\NameTag;
use PHPolygon\Component\SpriteRenderer;
use PHPolygon\Component\Transform2D;
use PHPolygon\ECS\World;
use PHPolygon\Math\Vec2;
use PHPolygon\Scene\Transpiler\WorldExporter;
use PHPUnit\Framework\TestCase;

class WorldExporterTest extends TestCase
{
    public function test_exports_live_world_to_scene_json(): void
    {
        $world = new World;

        $client = $world->createEntity();
        $client->attach(new NameTag('client_acme'));
        $client->attach(new Transform2D(new Vec2(120.0, 200.0)));
        $client->attach(new SpriteRenderer('desk'));

        $anon = $world->createEntity();
        $anon->attach(new Transform2D(new Vec2(0.0, 0.0)));

        $data = (new WorldExporter)->toArray($world, 'game_world');

        $this->assertSame(1, $data['_version']);
        $this->assertSame('game_world', $data['name']);
        $this->assertCount(2, $data['entities']);

        // Named entity carries its components (flat {_class, ...} form).
        $named = null;
        foreach ($data['entities'] as $e) {
            if ($e['name'] === 'client_acme') {
                $named = $e;
            }
        }
        $this->assertNotNull($named);
        $classes = array_column($named['components'], '_class');
        $this->assertContains(Transform2D::class, $classes);
        $this->assertContains(SpriteRenderer::class, $classes);

        // The un-tagged entity gets a synthetic name.
        $names = array_column($data['entities'], 'name');
        $this->assertNotEmpty(array_filter($names, fn ($n) => preg_match('/^entity_\d+$/', $n) === 1));
    }

    public function test_entities_carry_their_id(): void
    {
        // Transform3D references its parent BY id, so a consumer without ids
        // cannot rebuild the hierarchy — and a child positioned by its
        // parent-relative transform without that link lands in the wrong place.
        $world = new World;
        $parent = $world->createEntity();
        $parent->attach(new NameTag('parent'));
        $child = $world->createEntity();
        $child->attach(new NameTag('child'));

        $data = (new WorldExporter)->toArray($world);

        $byName = array_column($data['entities'], 'id', 'name');
        $this->assertSame($parent->id, $byName['parent']);
        $this->assertSame($child->id, $byName['child']);
    }

    public function test_ids_stay_distinct_when_names_repeat(): void
    {
        // A live world has no name uniqueness: two spawned copies share a
        // NameTag, and only the id tells them apart.
        $world = new World;
        $world->createEntity()->attach(new NameTag('enemy'));
        $world->createEntity()->attach(new NameTag('enemy'));

        $data = (new WorldExporter)->toArray($world);

        $ids = array_column($data['entities'], 'id');
        $this->assertCount(2, array_unique($ids));
    }

    public function test_json_is_valid_and_editor_shaped(): void
    {
        $world = new World;
        $world->createEntity()->attach(new NameTag('x'));

        $json = (new WorldExporter)->toJson($world, 'w');
        $decoded = json_decode($json, true);

        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('entities', $decoded);
        $this->assertArrayHasKey('systems', $decoded);
        $this->assertSame('x', $decoded['entities'][0]['name']);
    }
}
