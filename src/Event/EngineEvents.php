<?php

declare(strict_types=1);

namespace PHPolygon\Event;

use PHPolygon\ECS\Entity;

class EntitySpawned
{
    public function __construct(public readonly Entity $entity) {}
}

class EntityDestroyed
{
    public function __construct(public readonly int $entityId) {}
}

class SceneLoaded
{
    public function __construct(public readonly string $sceneName) {}
}
