<?php

declare(strict_types=1);

namespace PHPolygon\Scene\Transpiler;

use PHPolygon\Component\NameTag;
use PHPolygon\ECS\Attribute\Serializable;
use PHPolygon\ECS\Serializer\AttributeSerializer;
use PHPolygon\ECS\Serializer\SerializerInterface;
use PHPolygon\ECS\World;
use ReflectionClass;
use RuntimeException;

/**
 * Snapshots a live ECS {@see World} into the editor scene-JSON format.
 *
 * Games unify their runtime state in the World (entities + components). This
 * exports that live state to a `*.scene.json` the editor can load — the generic,
 * game-agnostic bridge "live World → editor". It complements the reverse path
 * ({@see SceneTranspiler}, which turns an authored Scene into JSON/PHP):
 *
 *   (new WorldExporter())->toJsonFile($engine->world, 'saves/game_world.scene.json', 'game_world');
 *
 * Each live entity becomes a scene entity. Its name comes from a {@see NameTag}
 * component if present, else `entity_<id>`. Only `#[Serializable]` components are
 * emitted (flat form, matching the transpiler). Entities are exported flat;
 * parent/child relationships remain captured inside Transform components.
 */
final class WorldExporter
{
    private SerializerInterface $serializer;

    public function __construct(?SerializerInterface $serializer = null)
    {
        $this->serializer = $serializer ?? new AttributeSerializer;
    }

    /**
     * @param  list<class-string>|null  $systems  Override the declared systems;
     *                                            defaults to the World's own systems.
     * @return array<string, mixed>
     */
    public function toArray(World $world, string $name = 'world', ?array $systems = null): array
    {
        $entities = [];
        foreach ($world->aliveEntityIds() as $id) {
            $components = [];
            foreach ($world->getEntityComponents($id) as $component) {
                if ($this->isSerializable($component)) {
                    $components[] = $this->serializer->toArray($component);
                }
            }
            $entities[] = ['name' => $this->nameOf($world, $id), 'components' => $components];
        }

        $declaredSystems = $systems ?? array_map(
            static fn (object $s): string => $s::class,
            $world->getSystems(),
        );

        return [
            '_version' => JsonSceneFormat::VERSION,
            'name' => $name,
            'systems' => $declaredSystems,
            'entities' => $entities,
        ];
    }

    /**
     * @param  list<class-string>|null  $systems
     */
    public function toJson(World $world, string $name = 'world', ?array $systems = null): string
    {
        $json = json_encode($this->toArray($world, $name, $systems), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Failed to encode world snapshot: '.json_last_error_msg());
        }

        return $json;
    }

    /**
     * @param  list<class-string>|null  $systems
     */
    public function toJsonFile(World $world, string $path, string $name = 'world', ?array $systems = null): void
    {
        file_put_contents($path, $this->toJson($world, $name, $systems));
    }

    private function isSerializable(object $component): bool
    {
        return (new ReflectionClass($component))->getAttributes(Serializable::class) !== [];
    }

    private function nameOf(World $world, int $id): string
    {
        $tag = $world->tryGetComponent($id, NameTag::class);
        if ($tag instanceof NameTag && $tag->name !== '') {
            return $tag->name;
        }

        return 'entity_'.$id;
    }
}
