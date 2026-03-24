<?php

declare(strict_types=1);

namespace PHPolygon\Scene;

use PHPolygon\ECS\SystemInterface;
use PHPolygon\ECS\World;
use PHPolygon\Engine;
use PHPolygon\Event\SceneActivated;
use PHPolygon\Event\SceneDeactivated;
use PHPolygon\Event\SceneLoaded;
use PHPolygon\Event\SceneLoading;
use PHPolygon\Event\SceneUnloaded;
use PHPolygon\Event\SceneUnloading;
use RuntimeException;

class SceneManager
{
    /** @var array<string, class-string<Scene>> */
    private array $registry = [];

    /** @var array<string, Scene> */
    private array $loaded = [];

    private ?string $activeScene = null;

    /** @var array<int, bool> */
    private array $persistentEntities = [];

    /** @var array<string, array<string, int>> Maps scene name => declaration name => entity ID */
    private array $sceneEntities = [];

    /** @var array<string, list<SystemInterface>> Maps scene name => systems */
    private array $sceneSystems = [];

    public function __construct(
        private readonly Engine $engine,
    ) {}

    /**
     * @param class-string<Scene> $sceneClass
     */
    public function register(string $name, string $sceneClass): void
    {
        $this->registry[$name] = $sceneClass;
    }

    public function loadScene(string $name, LoadMode $mode = LoadMode::Single): void
    {
        if (!isset($this->registry[$name])) {
            throw new RuntimeException("Scene '{$name}' is not registered");
        }

        $events = $this->engine->events;
        $world = $this->engine->world;

        $events->dispatch(new SceneLoading($name));

        if ($mode === LoadMode::Single) {
            $this->unloadAllScenes();
        }

        // Instantiate scene
        $sceneClass = $this->registry[$name];
        $scene = new $sceneClass();

        // Register scene systems
        $systems = [];
        foreach ($scene->getSystems() as $systemClass) {
            $system = new $systemClass();
            $world->addSystem($system);
            $systems[] = $system;
        }
        $this->sceneSystems[$name] = $systems;

        // Build and materialize entities
        $builder = new SceneBuilder();
        $scene->build($builder);
        $entityMap = $builder->materialize($world);
        $this->sceneEntities[$name] = $entityMap;

        // Track persistent entities
        foreach ($builder->getDeclarations() as $decl) {
            if ($decl->isPersistent() && isset($entityMap[$decl->getName()])) {
                $this->persistentEntities[$entityMap[$decl->getName()]] = true;
            }
        }

        $this->loaded[$name] = $scene;
        $scene->onLoad($this->engine);

        // Activate if single mode or first scene
        if ($mode === LoadMode::Single || $this->activeScene === null) {
            $this->setActiveScene($name);
        }

        $events->dispatch(new SceneLoaded($name, $scene));
    }

    public function unloadScene(string $name): void
    {
        if (!isset($this->loaded[$name])) {
            return;
        }

        $scene = $this->loaded[$name];
        $events = $this->engine->events;
        $world = $this->engine->world;

        $events->dispatch(new SceneUnloading($name, $scene));

        if ($this->activeScene === $name) {
            $scene->onDeactivate($this->engine);
            $events->dispatch(new SceneDeactivated($name, $scene));
            $this->activeScene = null;
        }

        $scene->onUnload($this->engine);

        // Destroy non-persistent entities belonging to this scene
        foreach ($this->sceneEntities[$name] ?? [] as $entityId) {
            if (!isset($this->persistentEntities[$entityId]) && $world->isAlive($entityId)) {
                $world->destroyEntity($entityId);
            }
        }
        unset($this->sceneEntities[$name]);

        // Remove scene systems
        foreach ($this->sceneSystems[$name] ?? [] as $system) {
            $world->removeSystem($system);
        }
        unset($this->sceneSystems[$name]);

        unset($this->loaded[$name]);

        $events->dispatch(new SceneUnloaded($name));

        // Activate next loaded scene if we had the active one
        if ($this->activeScene === null && !empty($this->loaded)) {
            $this->setActiveScene(array_key_first($this->loaded));
        }
    }

    public function getActiveScene(): ?Scene
    {
        if ($this->activeScene === null) {
            return null;
        }
        return $this->loaded[$this->activeScene] ?? null;
    }

    public function getActiveSceneName(): ?string
    {
        return $this->activeScene;
    }

    /** @return array<string, Scene> */
    public function getLoadedScenes(): array
    {
        return $this->loaded;
    }

    public function isLoaded(string $name): bool
    {
        return isset($this->loaded[$name]);
    }

    public function markPersistent(int $entityId): void
    {
        $this->persistentEntities[$entityId] = true;
    }

    public function isPersistent(int $entityId): bool
    {
        return isset($this->persistentEntities[$entityId]);
    }

    public function addGlobalSystem(SystemInterface $system): void
    {
        $this->engine->world->addSystem($system);
    }

    /**
     * @return array<string, int>|null Entity map for given scene
     */
    public function getSceneEntities(string $sceneName): ?array
    {
        return $this->sceneEntities[$sceneName] ?? null;
    }

    private function setActiveScene(string $name): void
    {
        $events = $this->engine->events;

        // Deactivate current
        if ($this->activeScene !== null && isset($this->loaded[$this->activeScene])) {
            $current = $this->loaded[$this->activeScene];
            $current->onDeactivate($this->engine);
            $events->dispatch(new SceneDeactivated($this->activeScene, $current));
        }

        $this->activeScene = $name;
        $scene = $this->loaded[$name];
        $scene->onActivate($this->engine);
        $events->dispatch(new SceneActivated($name, $scene));
    }

    private function unloadAllScenes(): void
    {
        foreach (array_keys($this->loaded) as $name) {
            $this->unloadScene($name);
        }
    }
}
