<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Editor;

use PHPUnit\Framework\TestCase;
use PHPolygon\Editor\SceneDocument;

class SceneDocumentTest extends TestCase
{
    private function createTestDocument(): SceneDocument
    {
        return new SceneDocument([
            'name' => 'test_scene',
            'entities' => [
                [
                    'name' => 'Camera',
                    'components' => [
                        ['_class' => 'Transform2D', 'position' => ['x' => 0, 'y' => 0]],
                    ],
                ],
                [
                    'name' => 'Player',
                    'components' => [
                        ['_class' => 'Transform2D', 'position' => ['x' => 100, 'y' => 200]],
                        ['_class' => 'SpriteRenderer', 'textureId' => 'player'],
                    ],
                    'children' => [
                        [
                            'name' => 'Weapon',
                            'components' => [
                                ['_class' => 'Transform2D', 'position' => ['x' => 20, 'y' => 0]],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function testGetEntity(): void
    {
        $doc = $this->createTestDocument();

        $camera = $doc->getEntity('Camera');
        $this->assertNotNull($camera);
        $this->assertSame('Camera', $camera['name']);

        // Nested entity
        $weapon = $doc->getEntity('Weapon');
        $this->assertNotNull($weapon);
    }

    public function testAddEntity(): void
    {
        $doc = $this->createTestDocument();
        $doc->addEntity('Enemy');

        $enemy = $doc->getEntity('Enemy');
        $this->assertNotNull($enemy);
        $this->assertCount(3, $doc->getEntities());
    }

    public function testAddEntityAsChild(): void
    {
        $doc = $this->createTestDocument();
        $doc->addEntity('Shield', 'Player');

        $player = $doc->getEntity('Player');
        $this->assertCount(2, $player['children']); // Weapon + Shield
    }

    public function testRemoveEntity(): void
    {
        $doc = $this->createTestDocument();
        $doc->removeEntity('Camera');

        $this->assertNull($doc->getEntity('Camera'));
        $this->assertCount(1, $doc->getEntities());
    }

    public function testRenameEntity(): void
    {
        $doc = $this->createTestDocument();
        $doc->renameEntity('Camera', 'MainCamera');

        $this->assertNull($doc->getEntity('Camera'));
        $this->assertNotNull($doc->getEntity('MainCamera'));
    }

    public function testAddComponent(): void
    {
        $doc = $this->createTestDocument();
        $doc->addComponent('Camera', 'RigidBody2D', ['mass' => 1.0]);

        $camera = $doc->getEntity('Camera');
        $this->assertCount(2, $camera['components']);
        $this->assertSame('RigidBody2D', $camera['components'][1]['_class']);
    }

    public function testRemoveComponent(): void
    {
        $doc = $this->createTestDocument();
        $doc->removeComponent('Player', 'SpriteRenderer');

        $player = $doc->getEntity('Player');
        $this->assertCount(1, $player['components']);
    }

    public function testUpdateProperty(): void
    {
        $doc = $this->createTestDocument();
        $doc->updateProperty('Player', 'SpriteRenderer', 'textureId', 'player_run');

        $player = $doc->getEntity('Player');
        $sprite = null;
        foreach ($player['components'] as $c) {
            if ($c['_class'] === 'SpriteRenderer') {
                $sprite = $c;
            }
        }
        $this->assertSame('player_run', $sprite['textureId']);
    }

    public function testUndo(): void
    {
        $doc = $this->createTestDocument();
        $doc->addEntity('Enemy');
        $this->assertCount(3, $doc->getEntities());

        $doc->undo();
        $this->assertCount(2, $doc->getEntities());
        $this->assertNull($doc->getEntity('Enemy'));
    }

    public function testRedo(): void
    {
        $doc = $this->createTestDocument();
        $doc->addEntity('Enemy');
        $doc->undo();
        $doc->redo();

        $this->assertCount(3, $doc->getEntities());
        $this->assertNotNull($doc->getEntity('Enemy'));
    }

    public function testUndoRedoChain(): void
    {
        $doc = $this->createTestDocument();
        $doc->addEntity('A');
        $doc->addEntity('B');
        $doc->addEntity('C');

        $this->assertCount(5, $doc->getEntities());

        $doc->undo();
        $this->assertCount(4, $doc->getEntities());

        $doc->undo();
        $this->assertCount(3, $doc->getEntities());

        $doc->redo();
        $this->assertCount(4, $doc->getEntities());
    }

    public function testDirtyFlag(): void
    {
        $doc = $this->createTestDocument();
        $this->assertFalse($doc->isDirty());

        $doc->addEntity('X');
        $this->assertTrue($doc->isDirty());

        $doc->markClean();
        $this->assertFalse($doc->isDirty());
    }

    public function testNewActionClearsRedoStack(): void
    {
        $doc = $this->createTestDocument();
        $doc->addEntity('A');
        $doc->undo();
        $this->assertTrue($doc->canRedo());

        $doc->addEntity('B');
        $this->assertFalse($doc->canRedo());
    }

    public function testReparentEntity(): void
    {
        $doc = $this->createTestDocument();
        // Move Camera under Player
        $doc->reparentEntity('Camera', 'Player');

        $this->assertCount(1, $doc->getEntities()); // Only Player at root
        $player = $doc->getEntity('Player');
        $this->assertCount(2, $player['children']); // Weapon + Camera
    }
}
