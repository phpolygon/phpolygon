<?php

declare(strict_types=1);

namespace PHPolygon\Scene\Transpiler;

use PHPolygon\ECS\Serializer\AttributeSerializer;
use PHPolygon\Scene\EntityDeclaration;
use PHPolygon\Scene\Scene;
use PHPolygon\Scene\SceneBuilder;
use PHPolygon\Scene\SceneConfig;
use RuntimeException;

class SceneTranspiler
{
    private AttributeSerializer $serializer;

    public function __construct(?AttributeSerializer $serializer = null)
    {
        $this->serializer = $serializer ?? new AttributeSerializer();
    }

    /**
     * Convert a Scene class to a JSON-compatible array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Scene $scene): array
    {
        $builder = new SceneBuilder();
        $scene->build($builder);

        return [
            '_version' => JsonSceneFormat::VERSION,
            '_scene' => get_class($scene),
            'name' => $scene->getName(),
            'config' => $this->serializer->toArray($scene->getConfig()),
            'systems' => $scene->getSystems(),
            'entities' => array_map(
                fn(EntityDeclaration $decl) => $this->declarationToArray($decl),
                $builder->getDeclarations(),
            ),
        ];
    }

    /**
     * Convert a Scene class to a JSON string.
     */
    public function toJson(Scene $scene, int $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES): string
    {
        $json = json_encode($this->toArray($scene), $flags);
        if ($json === false) {
            throw new RuntimeException('Failed to encode scene to JSON: ' . json_last_error_msg());
        }
        return $json;
    }

    /**
     * Write a Scene to a JSON file.
     */
    public function toJsonFile(Scene $scene, string $path): void
    {
        file_put_contents($path, $this->toJson($scene));
    }

    /**
     * Convert a JSON array back to PHP Scene source code.
     *
     * @param array<string, mixed> $data
     */
    public function fromArray(array $data): string
    {
        JsonSceneFormat::validate($data);

        $generator = new PhpCodeGenerator();
        return $generator->generate($data);
    }

    /**
     * Convert a JSON string to PHP Scene source code.
     */
    public function fromJson(string $json): string
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new RuntimeException('Invalid JSON: ' . json_last_error_msg());
        }
        /** @var array<string, mixed> $data */
        return $this->fromArray($data);
    }

    /**
     * Read a JSON file and produce PHP Scene source code.
     */
    public function fromJsonFile(string $path): string
    {
        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException("Cannot read file: {$path}");
        }
        return $this->fromJson($content);
    }

    /**
     * @return array<string, mixed>
     */
    private function declarationToArray(EntityDeclaration $decl): array
    {
        $components = [];
        foreach ($decl->getComponents() as $component) {
            $components[] = $this->serializer->toArray($component);
        }

        $children = [];
        foreach ($decl->getChildren() as $child) {
            $children[] = $this->declarationToArray($child);
        }

        return array_filter([
            'name' => $decl->getName(),
            'persistent' => $decl->isPersistent() ?: null,
            'prefab' => $decl->getPrefabSource(),
            'tags' => $decl->getTags() ?: null,
            'components' => $components,
            'children' => $children ?: null,
        ], fn($v) => $v !== null);
    }
}
