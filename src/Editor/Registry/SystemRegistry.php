<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Registry;

use PHPolygon\ECS\SystemInterface;
use ReflectionClass;
use RuntimeException;

class SystemRegistry
{
    /** @var array<class-string, array{class: string, shortName: string}> */
    private array $systems = [];

    /**
     * @param class-string<SystemInterface> $className
     */
    public function register(string $className): void
    {
        $ref = new ReflectionClass($className);
        $this->systems[$className] = [
            'class' => $className,
            'shortName' => $ref->getShortName(),
        ];
    }

    /**
     * Scan a directory for system classes.
     */
    public function scan(string $directory, string $namespace): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relativePath = str_replace($directory . DIRECTORY_SEPARATOR, '', $file->getPathname());
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

            if (!$ref->implementsInterface(SystemInterface::class)) {
                continue;
            }

            $this->register($className);
        }
    }

    public function has(string $className): bool
    {
        return isset($this->systems[$className]);
    }

    /** @return array<class-string, array{class: string, shortName: string}> */
    public function getAll(): array
    {
        return $this->systems;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_values($this->systems);
    }
}
