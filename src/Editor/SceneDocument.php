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
        return is_string($this->data['name'] ?? null) ? $this->data['name'] : '';
    }

    // --- Entity operations ---

    /**
     * @return list<array<string, mixed>>
     */
    public function getEntities(): array
    {
        $raw = $this->data['entities'] ?? [];
        if (!is_array($raw)) {
            return [];
        }
        /** @var list<array<string, mixed>> $entities */
        $entities = array_values($raw);
        return $entities;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getEntity(string $name): ?array
    {
        return $this->findEntity($name, $this->getEntities());
    }

    public function addEntity(string $name, ?string $parentName = null): void
    {
        $this->pushUndo();

        $newEntity = [
            'name' => $name,
            'components' => [],
        ];

        if ($parentName === null) {
            $entities = $this->getEntities();
            $entities[] = $newEntity;
            $this->data['entities'] = $entities;
        } else {
            $entities = $this->getEntities();
            $this->addEntityToParent($name, $parentName, $newEntity, $entities);
            $this->data['entities'] = $entities;
        }

        $this->dirty = true;
    }

    public function removeEntity(string $name): void
    {
        $this->pushUndo();
        $this->data['entities'] = $this->removeEntityFromList($name, $this->getEntities());
        $this->dirty = true;
    }

    public function renameEntity(string $oldName, string $newName): void
    {
        $this->pushUndo();
        $entities = $this->getEntities();
        $this->renameEntityInList($oldName, $newName, $entities);
        $this->data['entities'] = $entities;
        $this->dirty = true;
    }

    public function reparentEntity(string $entityName, ?string $newParentName): void
    {
        $this->pushUndo();

        // Find and remove entity from current position
        $entity = $this->findEntity($entityName, $this->getEntities());
        if ($entity === null) {
            return;
        }

        $entities = $this->removeEntityFromList($entityName, $this->getEntities());
        $this->data['entities'] = $entities;

        if ($newParentName === null) {
            // Move to root
            $entities = $this->getEntities();
            $entities[] = $entity;
            $this->data['entities'] = $entities;
        } else {
            $entities = $this->getEntities();
            $this->addEntityToParent($entityName, $newParentName, $entity, $entities);
            $this->data['entities'] = $entities;
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
            $components = is_array($entity['components'] ?? null) ? $entity['components'] : [];
            $components[] = $component;
            $entity['components'] = $components;
        });

        $this->dirty = true;
    }

    public function removeComponent(string $entityName, string $componentClass): void
    {
        $this->pushUndo();

        $this->modifyEntity($entityName, function (array &$entity) use ($componentClass) {
            /** @var list<array<string, mixed>> $components */
            $components = is_array($entity['components'] ?? null) ? $entity['components'] : [];
            $entity['components'] = array_values(array_filter(
                $components,
                fn(array $c) => ($c['_class'] ?? '') !== $componentClass,
            ));
        });

        $this->dirty = true;
    }

    public function updateProperty(string $entityName, string $componentClass, string $property, mixed $value): void
    {
        $this->pushUndo();

        $this->modifyEntity($entityName, function (array &$entity) use ($componentClass, $property, $value) {
            if (!is_array($entity['components'] ?? null)) {
                return;
            }
            foreach ($entity['components'] as &$component) {
                if (!is_array($component)) {
                    continue;
                }
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

        $encoded = json_encode($this->data);
        if (is_string($encoded)) {
            $this->redoStack[] = $encoded;
        }
        $snapshot = array_pop($this->undoStack);
        $decoded = json_decode((string) $snapshot, true);
        /** @var array<string, mixed> $data */
        $data = is_array($decoded) ? $decoded : [];
        $this->data = $data;
        $this->dirty = true;
    }

    public function redo(): void
    {
        if (empty($this->redoStack)) {
            return;
        }

        $encoded = json_encode($this->data);
        if (is_string($encoded)) {
            $this->undoStack[] = $encoded;
        }
        $snapshot = array_pop($this->redoStack);
        $decoded = json_decode((string) $snapshot, true);
        /** @var array<string, mixed> $data */
        $data = is_array($decoded) ? $decoded : [];
        $this->data = $data;
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
        $encoded = json_encode($this->data);
        if (is_string($encoded)) {
            $this->undoStack[] = $encoded;
        }
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
            if (($entity['name'] ?? null) === $name) {
                return $entity;
            }
            if (isset($entity['children']) && is_array($entity['children'])) {
                /** @var list<array<string, mixed>> $children */
                $children = $entity['children'];
                $found = $this->findEntity($name, $children);
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
            if (($entity['name'] ?? null) === $name) {
                continue;
            }
            if (isset($entity['children']) && is_array($entity['children'])) {
                /** @var list<array<string, mixed>> $entityChildren */
                $entityChildren = $entity['children'];
                $entity['children'] = $this->removeEntityFromList($name, $entityChildren);
                if (empty($entity['children'])) {
                    unset($entity['children']);
                }
            }
            $result[] = $entity;
        }
        return $result;
    }

    /**
     * @param array<string, mixed> $newEntity
     * @param list<array<string, mixed>> $entities
     */
    private function addEntityToParent(string $name, string $parentName, array $newEntity, array &$entities): void
    {
        foreach ($entities as &$entity) {
            if (($entity['name'] ?? null) === $parentName) {
                $children = isset($entity['children']) && is_array($entity['children']) ? $entity['children'] : [];
                $children[] = $newEntity;
                $entity['children'] = $children;
                return;
            }
            if (isset($entity['children']) && is_array($entity['children'])) {
                /** @var list<array<string, mixed>> $entityChildren */
                $entityChildren = $entity['children'];
                $this->addEntityToParent($name, $parentName, $newEntity, $entityChildren);
                $entity['children'] = $entityChildren;
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $entities
     */
    private function renameEntityInList(string $oldName, string $newName, array &$entities): void
    {
        foreach ($entities as &$entity) {
            if (($entity['name'] ?? null) === $oldName) {
                $entity['name'] = $newName;
                return;
            }
            if (isset($entity['children']) && is_array($entity['children'])) {
                /** @var list<array<string, mixed>> $entityChildren */
                $entityChildren = $entity['children'];
                $this->renameEntityInList($oldName, $newName, $entityChildren);
                $entity['children'] = $entityChildren;
            }
        }
    }

    private function modifyEntity(string $name, callable $modifier): void
    {
        $entities = $this->getEntities();
        $this->modifyEntityInList($name, $modifier, $entities);
        $this->data['entities'] = $entities;
    }

    /**
     * @param list<array<string, mixed>> $entities
     */
    private function modifyEntityInList(string $name, callable $modifier, array &$entities): void
    {
        foreach ($entities as &$entity) {
            if (($entity['name'] ?? null) === $name) {
                $modifier($entity);
                return;
            }
            if (isset($entity['children']) && is_array($entity['children'])) {
                /** @var list<array<string, mixed>> $entityChildren */
                $entityChildren = $entity['children'];
                $this->modifyEntityInList($name, $modifier, $entityChildren);
                $entity['children'] = $entityChildren;
            }
        }
    }
}
