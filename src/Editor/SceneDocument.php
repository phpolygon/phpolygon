<?php

declare(strict_types=1);

namespace PHPolygon\Editor;

use RuntimeException;

class SceneDocument
{
    /** @var array<string, mixed> */
    private array $data;

    /** @var list<string> JSON snapshots for undo */
    private array $undoStack = [];

    /** @var list<string> JSON snapshots for redo */
    private array $redoStack = [];

    private bool $dirty = false;

    private const MAX_UNDO = 100;

    /**
     * @param array<string, mixed> $data Scene JSON structure (from SceneTranspiler::toArray)
     */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function isDirty(): bool
    {
        return $this->dirty;
    }

    public function markClean(): void
    {
        $this->dirty = false;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    public function getName(): string
    {
        return $this->data['name'] ?? '';
    }

    // --- Entity operations ---

    /**
     * @return list<array<string, mixed>>
     */
    public function getEntities(): array
    {
        return $this->data['entities'] ?? [];
    }

    public function getEntity(string $name): ?array
    {
        return $this->findEntity($name, $this->data['entities'] ?? []);
    }

    public function addEntity(string $name, ?string $parentName = null): void
    {
        $this->pushUndo();

        $newEntity = [
            'name' => $name,
            'components' => [],
        ];

        if ($parentName === null) {
            $this->data['entities'][] = $newEntity;
        } else {
            $this->addEntityToParent($name, $parentName, $newEntity, $this->data['entities']);
        }

        $this->dirty = true;
    }

    public function removeEntity(string $name): void
    {
        $this->pushUndo();
        $this->data['entities'] = $this->removeEntityFromList($name, $this->data['entities'] ?? []);
        $this->dirty = true;
    }

    public function renameEntity(string $oldName, string $newName): void
    {
        $this->pushUndo();
        $this->renameEntityInList($oldName, $newName, $this->data['entities']);
        $this->dirty = true;
    }

    public function reparentEntity(string $entityName, ?string $newParentName): void
    {
        $this->pushUndo();

        // Find and remove entity from current position
        $entity = $this->findEntity($entityName, $this->data['entities'] ?? []);
        if ($entity === null) {
            return;
        }

        $this->data['entities'] = $this->removeEntityFromList($entityName, $this->data['entities']);

        if ($newParentName === null) {
            // Move to root
            $this->data['entities'][] = $entity;
        } else {
            $this->addEntityToParent($entityName, $newParentName, $entity, $this->data['entities']);
        }

        $this->dirty = true;
    }

    // --- Component operations ---

    /**
     * @param array<string, mixed> $defaults
     */
    public function addComponent(string $entityName, string $componentClass, array $defaults = []): void
    {
        $this->pushUndo();

        $component = array_merge(['_class' => $componentClass], $defaults);
        $this->modifyEntity($entityName, function (array &$entity) use ($component) {
            $entity['components'][] = $component;
        });

        $this->dirty = true;
    }

    public function removeComponent(string $entityName, string $componentClass): void
    {
        $this->pushUndo();

        $this->modifyEntity($entityName, function (array &$entity) use ($componentClass) {
            $entity['components'] = array_values(array_filter(
                $entity['components'] ?? [],
                fn(array $c) => ($c['_class'] ?? '') !== $componentClass,
            ));
        });

        $this->dirty = true;
    }

    public function updateProperty(string $entityName, string $componentClass, string $property, mixed $value): void
    {
        $this->pushUndo();

        $this->modifyEntity($entityName, function (array &$entity) use ($componentClass, $property, $value) {
            foreach ($entity['components'] as &$component) {
                if (($component['_class'] ?? '') === $componentClass) {
                    $component[$property] = $value;
                    return;
                }
            }
        });

        $this->dirty = true;
    }

    // --- Undo/Redo ---

    public function undo(): void
    {
        if (empty($this->undoStack)) {
            return;
        }

        $this->redoStack[] = json_encode($this->data);
        $snapshot = array_pop($this->undoStack);
        $this->data = json_decode($snapshot, true);
        $this->dirty = true;
    }

    public function redo(): void
    {
        if (empty($this->redoStack)) {
            return;
        }

        $this->undoStack[] = json_encode($this->data);
        $snapshot = array_pop($this->redoStack);
        $this->data = json_decode($snapshot, true);
        $this->dirty = true;
    }

    public function canUndo(): bool
    {
        return !empty($this->undoStack);
    }

    public function canRedo(): bool
    {
        return !empty($this->redoStack);
    }

    // --- Internal ---

    private function pushUndo(): void
    {
        $this->undoStack[] = json_encode($this->data);
        if (count($this->undoStack) > self::MAX_UNDO) {
            array_shift($this->undoStack);
        }
        $this->redoStack = [];
    }

    /**
     * @param list<array<string, mixed>> $entities
     * @return array<string, mixed>|null
     */
    private function findEntity(string $name, array $entities): ?array
    {
        foreach ($entities as $entity) {
            if ($entity['name'] === $name) {
                return $entity;
            }
            if (isset($entity['children'])) {
                $found = $this->findEntity($name, $entity['children']);
                if ($found !== null) {
                    return $found;
                }
            }
        }
        return null;
    }

    /**
     * @param list<array<string, mixed>> $entities
     * @return list<array<string, mixed>>
     */
    private function removeEntityFromList(string $name, array $entities): array
    {
        $result = [];
        foreach ($entities as $entity) {
            if ($entity['name'] === $name) {
                continue;
            }
            if (isset($entity['children'])) {
                $entity['children'] = $this->removeEntityFromList($name, $entity['children']);
                if (empty($entity['children'])) {
                    unset($entity['children']);
                }
            }
            $result[] = $entity;
        }
        return $result;
    }

    /**
     * @param list<array<string, mixed>> $entities
     */
    private function addEntityToParent(string $name, string $parentName, array $newEntity, array &$entities): void
    {
        foreach ($entities as &$entity) {
            if ($entity['name'] === $parentName) {
                $entity['children'] = $entity['children'] ?? [];
                $entity['children'][] = $newEntity;
                return;
            }
            if (isset($entity['children'])) {
                $this->addEntityToParent($name, $parentName, $newEntity, $entity['children']);
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $entities
     */
    private function renameEntityInList(string $oldName, string $newName, array &$entities): void
    {
        foreach ($entities as &$entity) {
            if ($entity['name'] === $oldName) {
                $entity['name'] = $newName;
                return;
            }
            if (isset($entity['children'])) {
                $this->renameEntityInList($oldName, $newName, $entity['children']);
            }
        }
    }

    private function modifyEntity(string $name, callable $modifier): void
    {
        $this->modifyEntityInList($name, $modifier, $this->data['entities']);
    }

    /**
     * @param list<array<string, mixed>> $entities
     */
    private function modifyEntityInList(string $name, callable $modifier, array &$entities): void
    {
        foreach ($entities as &$entity) {
            if ($entity['name'] === $name) {
                $modifier($entity);
                return;
            }
            if (isset($entity['children'])) {
                $this->modifyEntityInList($name, $modifier, $entity['children']);
            }
        }
    }
}
