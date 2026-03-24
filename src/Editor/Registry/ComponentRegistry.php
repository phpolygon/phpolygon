<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Registry;

use PHPolygon\ECS\Attribute\Serializable;
use PHPolygon\ECS\ComponentInterface;
use PHPolygon\Editor\Inspector\ComponentSchema;
use PHPolygon\Editor\Inspector\InspectorMetadataExtractor;
use ReflectionClass;
use RuntimeException;
use SplFileInfo;

class ComponentRegistry
{
    /** @var array<class-string, ComponentSchema> */
    private array $components = [];

    private InspectorMetadataExtractor $extractor;

    public function __construct(?InspectorMetadataExtractor $extractor = null)
    {
        $this->extractor = $extractor ?? new InspectorMetadataExtractor();
    }

    /**
     * @param class-string<ComponentInterface> $className
     */
    public function register(string $className): void
    {
        $this->components[$className] = $this->extractor->extract($className);
    }

    /**
     * Scan a directory for component classes.
     *
     * @param string $directory Absolute path to scan
     * @param string $namespace PSR-4 namespace prefix for this directory
     */
    public function scan(string $directory, string $namespace): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $pathname = $file->getPathname();
            $relativePath = str_replace($directory . DIRECTORY_SEPARATOR, '', $pathname);
            $className = $namespace . str_replace(
                [DIRECTORY_SEPARATOR, '.php'],
                ['\\', ''],
                $relativePath
            );

            if (!class_exists($className)) {
                continue;
            }

            $ref = new ReflectionClass($className);
            if ($ref->isAbstract() || $ref->isInterface()) {
                continue;
            }

            if (!$ref->implementsInterface(ComponentInterface::class)) {
                continue;
            }

            if (empty($ref->getAttributes(Serializable::class))) {
                continue;
            }

            /** @var class-string<ComponentInterface> $className */
            $this->register($className);
        }
    }

    public function get(string $className): ComponentSchema
    {
        if (!isset($this->components[$className])) {
            throw new RuntimeException("Component '{$className}' not registered");
        }
        return $this->components[$className];
    }

    public function has(string $className): bool
    {
        return isset($this->components[$className]);
    }

    /** @return array<class-string, ComponentSchema> */
    public function getAll(): array
    {
        return $this->components;
    }

    /** @return array<string, list<ComponentSchema>> */
    public function getByCategory(): array
    {
        $categories = [];
        foreach ($this->components as $schema) {
            $cat = $schema->category ?? 'Uncategorized';
            $categories[$cat][] = $schema;
        }
        ksort($categories);
        return $categories;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $result = [];
        foreach ($this->components as $class => $schema) {
            $result[$class] = $schema->toArray();
        }
        return $result;
    }
}
