<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Registry;

use PHPolygon\ECS\SystemInterface;
use ReflectionClass;
use RuntimeException;
use SplFileInfo;

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

            if (!$ref->implementsInterface(SystemInterface::class)) {
                continue;
            }

            /** @var class-string<SystemInterface> $className */
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

    /** @return list<array{class: string, shortName: string}> */
    public function toArray(): array
    {
        return array_values($this->systems);
    }
}
