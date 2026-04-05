<?php

declare(strict_types=1);

namespace PHPolygon;

use PHPolygon\Audio\AudioManager;
use PHPolygon\Audio\GLFWAudioBackend;
use PHPolygon\ECS\World;
use PHPolygon\Event\EventDispatcher;
use PHPolygon\Locale\LocaleManager;
use PHPolygon\Rendering\Camera2D;
use PHPolygon\Rendering\NullRenderer2D;
use PHPolygon\Rendering\MetalRenderer3D;
use PHPolygon\Rendering\NullRenderer3D;
use PHPolygon\Rendering\OpenGLRenderer3D;
use PHPolygon\Rendering\VulkanRenderer3D;
use PHPolygon\Rendering\Renderer2D;
use PHPolygon\Rendering\Renderer2DInterface;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\Renderer3DInterface;
use PHPolygon\Rendering\ShaderManager;
use PHPolygon\Rendering\TextureManager;
use PHPolygon\Testing\NullTextureManager;
use PHPolygon\Runtime\Clock;
use PHPolygon\Runtime\GameLoop;
use PHPolygon\Runtime\Input;
use PHPolygon\Runtime\InputInterface;
use PHPolygon\Runtime\NullWindow;
use PHPolygon\Runtime\Window;
use PHPolygon\SaveGame\SaveManager;
use PHPolygon\Scene\SceneManager;
use PHPolygon\Scene\SceneManagerInterface;
use PHPolygon\Support\Facades\Facade;
use PHPolygon\Thread\NullThreadScheduler;
use PHPolygon\Thread\ThreadScheduler;
use PHPolygon\Thread\ThreadSchedulerFactory;

class Engine
{
    public readonly World $world;
    public readonly Window $window;
    public readonly InputInterface $input;
    public readonly Camera2D $camera2D;
    public readonly TextureManager $textures;
    public readonly EventDispatcher $events;
    public readonly GameLoop $gameLoop;
    public readonly Clock $clock;
    public readonly SceneManagerInterface $scenes;
    public readonly AudioManager $audio;
    public readonly LocaleManager $locale;
    public readonly SaveManager $saves;

    public Renderer2DInterface $renderer2D;
    public ?Renderer3DInterface $renderer3D;
    public readonly ?RenderCommandList $commandList3D;
    public readonly ShaderManager $shaders;
    public readonly ThreadScheduler|NullThreadScheduler $scheduler;

    private bool $running = false;
    private bool $headless;

    /** @var callable|null */
    private $onUpdate = null;

    /** @var callable|null */
    private $onRender = null;

    /** @var callable|null */
    private $onInit = null;

    public function __construct(
        private readonly EngineConfig $config = new EngineConfig(),
    ) {
        $this->headless = $config->headless;
        $this->world = new World();
        $this->input = new Input();
        $this->events = new EventDispatcher();
        $this->clock = new Clock();
        $this->camera2D = new Camera2D($config->width, $config->height);
        $this->textures = $this->headless
            ? new NullTextureManager($config->assetsPath)
            : new TextureManager($config->assetsPath);
        $this->gameLoop = new GameLoop($config->targetTickRate);
        $this->scenes = new SceneManager($this);
        $this->audio = new AudioManager(
            $this->headless ? null : new GLFWAudioBackend(),
        );
        $this->locale = new LocaleManager($config->defaultLocale, $config->fallbackLocale);
        $this->saves = new SaveManager($config->savePath, $config->maxSaveSlots);
        $this->scheduler = ThreadSchedulerFactory::create($config);

        if ($config->is3D) {
            $this->commandList3D = new RenderCommandList();
            // Non-headless GPU renderers require a GL/Vulkan context — initialized in run()
            if ($this->headless || $config->renderBackend3D === 'null') {
                $this->renderer3D = new NullRenderer3D($config->width, $config->height);
            } else {
                $this->renderer3D = null;
            }
        } else {
            $this->commandList3D = null;
            $this->renderer3D = null;
        }

        $this->shaders = new ShaderManager($this->commandList3D);

        if ($this->headless) {
            $this->window = new NullWindow($config->width, $config->height, $config->title);
            $this->renderer2D = new NullRenderer2D($config->width, $config->height);
        } else {
            $noApi = $config->is3D && in_array($config->renderBackend3D, ['vulkan', 'metal'], true);
            $this->window = new Window(
                $config->width,
                $config->height,
                $config->title,
                $config->vsync,
                $config->resizable,
                $noApi,
            );
        }

        Facade::setEngine($this);
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

        $nativeBackend = $this->config->is3D && in_array($this->config->renderBackend3D, ['vulkan', 'metal'], true);

        // For native backends (Metal/Vulkan), pump the event loop once so AppKit
        // completes window layout and sets proper NSView bounds before the renderer
        // attaches its CAMetalLayer / Vulkan surface.
        if (!$this->headless && $nativeBackend) {
            $this->window->pollEvents();
        }

        // Create GPU-backed renderers after window is initialized (need graphics context)
        if (!$this->headless && $this->config->is3D) {
            $this->renderer3D = match ($this->config->renderBackend3D) {
                'vulkan' => new VulkanRenderer3D(
                    $this->window->getFramebufferWidth(),
                    $this->window->getFramebufferHeight(),
                    $this->window->getHandle(),
                ),
                'metal' => new MetalRenderer3D(
                    $this->window->getFramebufferWidth(),
                    $this->window->getFramebufferHeight(),
                    $this->window->getHandle(),
                ),
                default => new OpenGLRenderer3D(
                    $this->window->getFramebufferWidth(),
                    $this->window->getFramebufferHeight(),
                ),
            };
        }

        // Create Renderer2D after window is initialized (needs GL context)
        // Metal/Vulkan backends don't have an OpenGL context — use NullRenderer2D for 2D overlay.
        if (!$this->headless && !$nativeBackend) {
            $this->renderer2D = new Renderer2D($this->window);

            // Auto-load bundled fonts so text renders without manual setup.
            // NanoVG (C library) cannot read phar:// paths, so when running
            // inside a PHAR we extract engine fonts to the filesystem first.
            $fontDir = $this->resolveEngineFontDir();
            if ($fontDir !== null && is_dir($fontDir)) {
                $this->renderer2D->loadFont('regular',  $fontDir . '/Inter-Regular.ttf');
                $this->renderer2D->loadFont('semibold', $fontDir . '/Inter-SemiBold.ttf');
                $this->renderer2D->setFont('regular');

                // CJK fallback fonts for Japanese, Korean, Chinese
                $cjkDir = $fontDir . '/noto-sans-cjk';
                if (is_dir($cjkDir)) {
                    $vg = $this->renderer2D->getVGContext();
                    $this->renderer2D->loadFont('noto-sans-sc', $cjkDir . '/NotoSansSC-Regular.otf');
                    $this->renderer2D->loadFont('noto-sans-kr', $cjkDir . '/NotoSansKR-Regular.otf');
                    $vg->addFallbackFont('regular', 'noto-sans-sc');
                    $vg->addFallbackFont('regular', 'noto-sans-kr');
                    $vg->addFallbackFont('semibold', 'noto-sans-sc');
                    $vg->addFallbackFont('semibold', 'noto-sans-kr');
                }
            }
        } elseif (!$this->headless && $nativeBackend) {
            $this->renderer2D = new NullRenderer2D($this->config->width, $this->config->height);
        }

        if ($this->onInit !== null) {
            ($this->onInit)($this);
        }

        $this->scheduler->boot();
        $this->running = true;

        $isPipelined = $this->scheduler instanceof ThreadScheduler
            && count($this->scheduler->getSubsystems()) > 0;

        if ($isPipelined) {
            $fixedDt = $this->gameLoop->getFixedDeltaTime();
            $this->gameLoop->runPipelined(
                prepareAndSend: function () use ($fixedDt) {
                    $this->scheduler->sendAll($this->world, $fixedDt);
                },
                update: function (float $dt) {
                    $this->world->updateMainThread($dt);

                    if ($this->onUpdate !== null) {
                        ($this->onUpdate)($this, $dt);
                    }
                },
                render: function (float $interpolation) use ($nativeBackend) {
                    // Sync viewport to framebuffer every frame — handles Retina HiDPI and window resize
                    if ($this->renderer3D !== null && !$nativeBackend) {
                        $fbW = $this->window->getFramebufferWidth();
                        $fbH = $this->window->getFramebufferHeight();
                        if ($fbW > 0 && $fbH > 0) {
                            $this->renderer3D->setViewport(0, 0, $fbW, $fbH);
                        }
                    }

                    if ($this->renderer3D !== null) {
                        $this->renderer3D->beginFrame();
                    }
                    $this->renderer2D->beginFrame();

                    $this->world->render();

                    if ($this->onRender !== null) {
                        ($this->onRender)($this, $interpolation);
                    }

                    $this->renderer2D->endFrame();
                    if ($this->renderer3D !== null) {
                        $this->renderer3D->endFrame();
                    }
                    if (!$nativeBackend) {
                        $this->window->swapBuffers();
                    }

                    $this->input->endFrame();
                    $this->window->pollEvents();
                },
                recvAndApply: function () {
                    $this->scheduler->recvAll($this->world);
                    $this->world->updatePostThread($this->gameLoop->getFixedDeltaTime());
                },
                shouldStop: function (): bool {
                    return !$this->running || $this->window->shouldClose();
                },
            );
        } else {
            $this->gameLoop->run(
                update: function (float $dt) {
                    $this->world->update($dt);

                    if ($this->onUpdate !== null) {
                        ($this->onUpdate)($this, $dt);
                    }
                },
                render: function (float $interpolation) use ($nativeBackend) {
                    // Sync viewport to framebuffer every frame — handles Retina HiDPI and window resize
                    if ($this->renderer3D !== null && !$nativeBackend) {
                        $fbW = $this->window->getFramebufferWidth();
                        $fbH = $this->window->getFramebufferHeight();
                        if ($fbW > 0 && $fbH > 0) {
                            $this->renderer3D->setViewport(0, 0, $fbW, $fbH);
                        }
                    }

                    if ($this->renderer3D !== null) {
                        $this->renderer3D->beginFrame();
                    }
                    $this->renderer2D->beginFrame();

                    $this->world->render();

                    if ($this->onRender !== null) {
                        ($this->onRender)($this, $interpolation);
                    }

                    $this->renderer2D->endFrame();
                    if ($this->renderer3D !== null) {
                        $this->renderer3D->endFrame();
                    }
                    if (!$nativeBackend) {
                        $this->window->swapBuffers();
                    }

                    $this->input->endFrame();
                    $this->window->pollEvents();
                },
                shouldStop: function (): bool {
                    return !$this->running || $this->window->shouldClose();
                },
            );
        }

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

    /**
     * Resolve the engine font directory. When running inside a PHAR,
     * fonts are extracted to the filesystem because NanoVG (C library)
     * cannot read phar:// stream paths.
     */
    private function resolveEngineFontDir(): ?string
    {
        $pharDir = __DIR__ . '/../resources/fonts';

        // Development mode: fonts are directly on the filesystem
        if (!str_starts_with($pharDir, 'phar://')) {
            return is_dir($pharDir) ? $pharDir : null;
        }

        // PHAR mode: extract fonts to the resource directory on disk
        if (!defined('PHPOLYGON_PATH_RESOURCES')) {
            return null;
        }

        /** @var string $resourcesDir */
        $resourcesDir = PHPOLYGON_PATH_RESOURCES;

        $targetDir = $resourcesDir .  '/fonts';

        // Extract engine fonts if not already present
        if (!is_dir($targetDir . '/noto-sans-cjk') && is_dir($pharDir)) {
            @mkdir($targetDir, 0755, true);
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($pharDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );
            $pharDirLen = strlen($pharDir);
            /** @var \SplFileInfo $item */
            foreach ($iterator as $item) {
                $relPath = substr($item->getPathname(), $pharDirLen + 1);
                $targetPath = $targetDir . '/' . $relPath;
                if ($item->isDir()) {
                    @mkdir($targetPath, 0755, true);
                } elseif (!file_exists($targetPath)) {
                    @mkdir(dirname($targetPath), 0755, true);
                    copy($item->getPathname(), $targetPath);
                }
            }
        }

        return is_dir($targetDir) ? $targetDir : null;
    }

    private function shutdown(): void
    {
        $this->scheduler->shutdown();
        $this->audio->dispose();
        $this->textures->clear();
        $this->world->clear();
        $this->window->destroy();
        Facade::clearEngine();
    }
}
