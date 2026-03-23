<?php

declare(strict_types=1);

namespace PHPolygon\ECS;

abstract class AbstractComponent implements ComponentInterface
{
    public function onAttach(Entity $entity): void
    {
    }

    public function onUpdate(Entity $entity, float $dt): void
    {
    }

    public function onDetach(Entity $entity): void
    {
    }

    public function onInspectorGUI(Entity $entity): void
    {
    }
}
