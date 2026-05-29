<?php

declare(strict_types=1);

namespace PHPolygon\Prototype;

use FilesystemIterator;
use PHPolygon\ECS\Attribute\Serializable;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

/**
 * Discovers concrete #[Serializable] classes under a PSR-4 directory so the
 * prototype export can build its component vocabulary without a hard-coded
 * class list. Maps each `.php` file to its FQN via the namespace prefix,
 * autoloads it, and keeps the ones carrying #[Serializable].
 *
 * Abstract classes and interfaces are skipped - the front-end only ever
 * instantiates concrete components.
 */
final class SerializableScanner
{
    /**
     * @param string  $directory         Absolute path to a PSR-4 root (e.g. src/Component).
     * @param string  $namespacePrefix   Namespace that maps to $directory (e.g. PHPolygon\Component).
     * @param ?string $mustBeSubclassOf  When set, only classes that are subclasses of this
     *                                   FQN are returned (e.g. AbstractComponent to keep a
     *                                   broad project src/ scan to actual components).
     * @return list<class-string>        Sorted, de-duplicated FQNs.
     */
    public static function scan(string $directory, string $namespacePrefix, ?string $mustBeSubclassOf = null): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $directory = rtrim($directory, '/\\');
        $namespacePrefix = trim($namespacePrefix, '\\');

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        );

        $found = [];
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($directory) + 1);
            $relative = preg_replace('/\.php$/', '', $relative) ?? $relative;
            $class = $namespacePrefix . '\\' . str_replace(['/', '\\'], '\\', $relative);

            if (!class_exists($class)) {
                continue;
            }

            $ref = new ReflectionClass($class);
            if ($ref->isAbstract() || $ref->isInterface() || $ref->isEnum()) {
                continue;
            }
            if ($ref->getAttributes(Serializable::class) === []) {
                continue;
            }
            if ($mustBeSubclassOf !== null && !is_subclass_of($class, $mustBeSubclassOf)) {
                continue;
            }
            $found[$class] = true;
        }

        $classes = array_keys($found);
        sort($classes);

        /** @var list<class-string> $classes */
        return $classes;
    }
}
