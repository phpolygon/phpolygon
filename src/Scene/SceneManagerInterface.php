<?php

declare(strict_types=1);

namespace PHPolygon\Scene;

use PHPolygon\ECS\SystemInterface;

/**
 * SceneManagerInterface — public contract for scene lifecycle and navigation.
 *
 * Game code (e.g. a MenuSystem that triggers scene transitions) depends on this
 * interface so it stays decoupled from the concrete SceneManager implementation.
 */
interface SceneManagerInterface
{
    /**
     * Register a scene class under a name.
     *
     * @param class-string<Scene> $sceneClass
     */
    public function register(string $name, string $sceneClass): void;

    /**
     * Load (and optionally activate) a scene.
     * In Single mode (default) all currently loaded scenes are unloaded first.
     */
    public function loadScene(string $name, LoadMode $mode = LoadMode::Single): void;

    /** Unload a scene, removing its entities and systems. */
    public function unloadScene(string $name): void;

    /** Returns the currently active Scene instance, or null if none. */
    public function getActiveScene(): ?Scene;

    /** Returns the name of the currently active scene, or null if none. */
    public function getActiveSceneName(): ?string;

    /** @return array<string, Scene> All currently loaded scenes keyed by name. */
    public function getLoadedScenes(): array;

    /** Returns true if the named scene is currently loaded. */
    public function isLoaded(string $name): bool;

    /** Returns true if a scene class is registered under the given name. */
    public function isRegistered(string $name): bool;

    /** Mark an entity as persistent across scene unloads. */
    public function markPersistent(int $entityId): void;

    public function isPersistent(int $entityId): bool;

    /** Add a system that persists across all scene loads/unloads. */
    public function addGlobalSystem(SystemInterface $system): void;

    /**
     * Returns the entity map for a loaded scene (name → entity ID), or null.
     *
     * @return array<string, int>|null
     */
    public function getSceneEntities(string $sceneName): ?array;
}
