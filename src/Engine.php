<?php

declare(strict_types=1);

namespace PHPolygon;

use PHPolygon\Audio\AudioManager;
use PHPolygon\ECS\World;
use PHPolygon\Event\EventDispatcher;
use PHPolygon\Locale\LocaleManager;
use PHPolygon\Rendering\Camera2D;
use PHPolygon\Rendering\Renderer2D;
use PHPolygon\Rendering\TextureManager;
use PHPolygon\Runtime\Clock;
use PHPolygon\Runtime\GameLoop;
use PHPolygon\Runtime\Input;
use PHPolygon\Runtime\Window;
use PHPolygon\SaveGame\SaveManager;
use PHPolygon\Scene\SceneManager;

class Engine
{
    public readonly World $world;
    public readonly Window $window;
    public readonly Input $input;
    public readonly Camera2D $camera2D;
    public readonly TextureManager $textures;
    public readonly EventDispatcher $events;
    public readonly GameLoop $gameLoop;
    public readonly Clock $clock;
    public readonly SceneManager $scenes;
    public readonly AudioManager $audio;
    public readonly LocaleManager $locale;
    public readonly SaveManager $saves;

    public Renderer2D $renderer2D;

    private bool $running = false;

    /** @var callable|null */
    private $onUpdate = null;

    /** @var callable|null */
    private $onRender = null;

    /** @var callable|null */
    private $onInit = null;

    public function __construct(
        private readonly EngineConfig $config = new EngineConfig(),
    ) {
        $this->world = new World();
        $this->input = new Input();
        $this->events = new EventDispatcher();
        $this->clock = new Clock();
        $this->camera2D = new Camera2D($config->width, $config->height);
        $this->textures = new TextureManager($config->assetsPath);
        $this->gameLoop = new GameLoop($config->targetTickRate);
        $this->scenes = new SceneManager($this);
        $this->audio = new AudioManager();
        $this->locale = new LocaleManager($config->defaultLocale, $config->fallbackLocale);
        $this->saves = new SaveManager($config->savePath, $config->maxSaveSlots);

        $this->window = new Window(
            $config->width,
            $config->height,
            $config->title,
            $config->vsync,
            $config->resizable,
        );
    }

    public function onUpdate(callable $callback): self
    {
        $this->onUpdate = $callback;
        return $this;
    }

    public function onRender(callable $callback): self
    {
        $this->onRender = $callback;
        return $this;
    }

    public function onInit(callable $callback): self
    {
        $this->onInit = $callback;
        return $this;
    }

    public function run(): void
    {
        $this->window->initialize($this->input);

        // Create Renderer2D after window is initialized (needs GL context)
        $this->renderer2D = new Renderer2D($this->window);

        if ($this->onInit !== null) {
            ($this->onInit)($this);
        }

        $this->running = true;

        $this->gameLoop->run(
            update: function (float $dt) {
                $this->world->update($dt);

                if ($this->onUpdate !== null) {
                    ($this->onUpdate)($this, $dt);
                }
            },
            render: function (float $interpolation) {
                $this->renderer2D->beginFrame();

                $this->world->render();

                if ($this->onRender !== null) {
                    ($this->onRender)($this, $interpolation);
                }

                $this->renderer2D->endFrame();
                $this->window->swapBuffers();

                // endFrame must happen after render so UI widgets can read
                // pressed/released states during the draw phase
                $this->input->endFrame();

                $this->window->pollEvents();
            },
            shouldStop: function (): bool {
                return !$this->running || $this->window->shouldClose();
            },
        );

        $this->shutdown();
    }

    public function stop(): void
    {
        $this->running = false;
    }

    public function getConfig(): EngineConfig
    {
        return $this->config;
    }

    private function shutdown(): void
    {
        $this->audio->dispose();
        $this->textures->clear();
        $this->world->clear();
        $this->window->destroy();
    }
}
