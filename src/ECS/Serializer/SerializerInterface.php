<?php

declare(strict_types=1);

namespace PHPolygon\ECS\Serializer;

interface SerializerInterface
{
    /**
     * Serialize an object to a plain array (JSON-encodable).
     *
     * @return array<string, mixed>
     */
    public function toArray(object $object): array;

    /**
     * Deserialize an array back into an object.
     *
     * @param array<string, mixed> $data
     * @param class-string $className
     */
    public function fromArray(array $data, string $className): object;
}
