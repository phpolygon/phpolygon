<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Scene;

use PHPolygon\Component\NameTag;
use PHPolygon\Component\SpriteRenderer;
use PHPolygon\Component\Transform2D;
use PHPolygon\ECS\World;
use PHPolygon\Math\Vec2;
use PHPolygon\Scene\Transpiler\WorldExporter;
use PHPolygon\Scene\Transpiler\WorldImporter;
use PHPUnit\Framework\TestCase;

class WorldImporterTest extends TestCase
{
    public function test_export_import_round_trip_reconstructs_the_world(): void
    {
        // A live world.
        $a = new World;
        $client = $a->createEntity();
        $client->attach(new NameTag('client_acme'));
        $client->attach(new Transform2D(new Vec2(120.0, 200.0)));
        $client->attach(new SpriteRenderer('desk'));

        // Export → (edit in the editor) → import into a fresh world.
        $data = (new WorldExporter)->toArray($a, 'game_world');
        $b = new World;
        $created = (new WorldImporter)->apply($b, $data);

        $this->assertSame(1, $b->entityCount());
        $this->assertArrayHasKey('client_acme', $created);

        $id = $created['client_acme'];
        $this->assertSame('client_acme', $b->getComponent($id, NameTag::class)->name);

        $t = $b->getComponent($id, Transform2D::class);
        $this->assertInstanceOf(Transform2D::class, $t);
        $this->assertSame(120.0, $t->position->x);
        $this->assertSame(200.0, $t->position->y);

        $this->assertSame('desk', $b->getComponent($id, SpriteRenderer::class)->textureId);
    }

    public function test_applies_editor_edits(): void
    {
        // Simulate an edited snapshot: a moved desk, changed texture.
        $data = [
            '_version' => 1,
            'name' => 'game_world',
            'entities' => [
                ['name' => 'desk', 'components' => [
                    ['_class' => Transform2D::class, 'position' => ['x' => 999, 'y' => 5]],
                    ['_class' => SpriteRenderer::class, 'textureId' => 'fancy_desk'],
                ]],
            ],
        ];

        $world = new World;
        $created = (new WorldImporter)->apply($world, $data);
        $id = $created['desk'];

        $this->assertSame(999.0, $world->getComponent($id, Transform2D::class)->position->x);
        $this->assertSame('fancy_desk', $world->getComponent($id, SpriteRenderer::class)->textureId);
        // A NameTag was synthesised so a re-export keeps the name.
        $this->assertSame('desk', $world->getComponent($id, NameTag::class)->name);
    }
}
