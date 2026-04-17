<?php

declare(strict_types=1);

namespace PHPolygon;

use PHPolygon\Audio\AudioManager;
use PHPolygon\Audio\GLFWAudioBackend;
use PHPolygon\Audio\VioAudioBackend;
use PHPolygon\ECS\World;
use PHPolygon\Event\EventDispatcher;
use PHPolygon\Geometry\MeshCache;
use PHPolygon\Locale\LocaleManager;
use PHPolygon\Rendering\Camera2D;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\NullRenderer2D;
use PHPolygon\Rendering\MetalRenderer3D;
use PHPolygon\Rendering\TextAlign;
use PHPolygon\Rendering\NullRenderer3D;
use PHPolygon\Rendering\OpenGLRenderer3D;
use PHPolygon\Rendering\VulkanRenderer3D;
use PHPolygon\Rendering\VioRenderer2D;
use PHPolygon\Rendering\VioRenderer3D;
use PHPolygon\Rendering\VioTextureManager;
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
use PHPolygon\Runtime\VioInput;
use PHPolygon\Runtime\VioWindow;
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
    public TextureManager $textures;
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
    private bool $useVio;

    /** @var callable|null */
    private $onUpdate = null;

    /** @var callable|null */
    private $onRender = null;

    /** @var callable|null */
    private $onInit = null;

    public function __construct(
        private readonly EngineConfig $config = new EngineConfig(),
    ) {
        self::log('Engine init - PHP ' . PHP_VERSION . ', OS: ' . PHP_OS . ' (' . php_uname('r') . ')');
        self::log('Config: ' . $config->width . 'x' . $config->height . ', vsync=' . ($config->vsync ? 'on' : 'off') . ', 3D=' . ($config->is3D ? $config->renderBackend3D : 'off'));

        $this->headless = $config->headless;
        $this->useVio = !$config->headless && extension_loaded('vio');

        self::log('Mode: ' . ($this->headless ? 'headless' : ($this->useVio ? 'vio' : 'glfw')));
        self::log('Extensions: vio=' . (extension_loaded('vio') ? 'yes' : 'no') . ', glfw=' . (extension_loaded('glfw') ? 'yes' : 'no') . ', opengl=' . (extension_loaded('opengl') ? 'yes' : 'no'));

        $this->world = new World();
        $this->input = $config->input ?? ($this->useVio ? new VioInput() : new Input());
        $this->events = new EventDispatcher();
        $this->clock = new Clock();
        $this->camera2D = new Camera2D($config->width, $config->height);

        // Vio TextureManager needs the VioContext — deferred to run()
        if ($this->headless) {
            $this->textures = new NullTextureManager($config->assetsPath);
        } elseif ($this->useVio) {
            $this->textures = new NullTextureManager($config->assetsPath);
        } else {
            $this->textures = new TextureManager($config->assetsPath);
        }

        self::log('TextureManager: ' . get_class($this->textures));

        $this->gameLoop = new GameLoop($config->targetTickRate);
        $this->scenes = new SceneManager($this);
        $audioBackend = null;
        if (!$this->headless) {
            $audioBackend = $this->useVio ? new VioAudioBackend() : new GLFWAudioBackend();
        }
        $this->audio = new AudioManager($audioBackend);
        self::log('Audio: ' . ($audioBackend !== null ? get_class($audioBackend) : 'none'));

        $this->locale = new LocaleManager($config->defaultLocale, $config->fallbackLocale);
        $this->saves = new SaveManager($config->savePath, $config->maxSaveSlots);
        $this->scheduler = ThreadSchedulerFactory::create($config);
        self::log('Threading: ' . get_class($this->scheduler));

        if ($config->meshCachePath !== '') {
            MeshCache::configure($config->meshCachePath);
        }

        if ($config->is3D) {
            $this->commandList3D = new RenderCommandList();
            if ($this->headless || $config->renderBackend3D === 'null') {
                $this->renderer3D = new NullRenderer3D($config->width, $config->height);
            } else {
                $this->renderer3D = null;
            }
            self::log('3D renderer: ' . $config->renderBackend3D);
        } else {
            $this->commandList3D = null;
            $this->renderer3D = null;
        }

        $this->shaders = new ShaderManager($this->commandList3D);

        if ($this->headless) {
            $this->window = new NullWindow($config->width, $config->height, $config->title);
            $this->renderer2D = new NullRenderer2D($config->width, $config->height);
        } elseif ($this->useVio) {
            $this->window = new VioWindow(
                $config->width,
                $config->height,
                $config->title,
                $config->vsync,
                $config->resizable,
                $config->vioBackend,
            );
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

        self::log('Window: ' . get_class($this->window));

        Facade::setEngine($this);

        self::log('Engine init complete');
    }

    /**
     * Create a fully initialized Engine for visual regression testing.
     * Initializes window, renderer, and fonts — backend-agnostic (VIO or GLFW).
     */
    public static function initVrt(EngineConfig $config): self
    {
        $engine = new self($config);

        if ($engine->useVio && $engine->window instanceof VioWindow) {
            $engine->window->initialize($engine->input);
            $engine->renderer2D = new VioRenderer2D($engine->window->getContext());
        } elseif ($engine->input instanceof Input) {
            $engine->window->initialize($engine->input);
            $engine->renderer2D = new Renderer2D($engine->window);
        }

        // Load engine fonts
        $fontDir = $engine->resolveEngineFontDir();
        if ($fontDir !== null && is_dir($fontDir)) {
            $engine->renderer2D->loadFont('regular', $fontDir . DIRECTORY_SEPARATOR . 'Inter-Regular.ttf');
            $engine->renderer2D->loadFont('semibold', $fontDir . DIRECTORY_SEPARATOR . 'Inter-SemiBold.ttf');
            $engine->renderer2D->setFont('regular');

            $cjkDir = $fontDir . DIRECTORY_SEPARATOR . 'noto-sans-cjk';
            if (is_dir($cjkDir)) {
                $engine->renderer2D->loadFont('noto-sans-sc', $cjkDir . DIRECTORY_SEPARATOR . 'NotoSansSC-Regular.otf');
                $engine->renderer2D->loadFont('noto-sans-kr', $cjkDir . DIRECTORY_SEPARATOR . 'NotoSansKR-Regular.otf');
                $engine->renderer2D->addFallbackFont('regular', 'noto-sans-sc');
                $engine->renderer2D->addFallbackFont('regular', 'noto-sans-kr');
                $engine->renderer2D->addFallbackFont('semibold', 'noto-sans-sc');
                $engine->renderer2D->addFallbackFont('semibold', 'noto-sans-kr');
            }
        }

        return $engine;
    }

    /**
     * Capture the current framebuffer as a GdImage. Backend-agnostic.
     */
    public function captureFramebuffer(): \GdImage
    {
        $fbW = $this->window->getFramebufferWidth();
        $fbH = $this->window->getFramebufferHeight();

        /** @var positive-int $safeFbW */
        $safeFbW = max(1, $fbW);
        /** @var positive-int $safeFbH */
        $safeFbH = max(1, $fbH);

        if ($this->useVio && $this->window instanceof VioWindow) {
            $fullImg = self::captureVio($this->window, $safeFbW, $safeFbH);
        } else {
            $fullImg = self::captureGL($safeFbW, $safeFbH);
        }

        $logicalW = max(1, $this->config->width);
        $logicalH = max(1, $this->config->height);
        if ($fbW !== $logicalW || $fbH !== $logicalH) {
            $img = imagecreatetruecolor($logicalW, $logicalH);
            imagecopyresampled($img, $fullImg, 0, 0, 0, 0, $logicalW, $logicalH, $fbW, $fbH);
            return $img;
        }

        return $fullImg;
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

    /**
     * Capture framebuffer via VIO's vio_read_pixels.
     * @param positive-int $fbW Framebuffer width
     * @param positive-int $fbH Framebuffer height
     */
    private static function captureVio(VioWindow $window, int $fbW, int $fbH): \GdImage
    {
        $pixels = vio_read_pixels($window->getContext());
        $pixelCount = (int)(strlen($pixels) / 4);
        $winW = $window->getWidth();
        $winH = $window->getHeight();
        // vio_read_pixels may return window-size rather than framebuffer-size
        $readW = max(1, ($pixelCount === $winW * $winH) ? $winW : $fbW);
        $readH = max(1, ($pixelCount === $winW * $winH) ? $winH : $fbH);
        $img = imagecreatetruecolor($readW, $readH);
        $len = strlen($pixels);
        for ($y = 0; $y < $readH; $y++) {
            for ($x = 0; $x < $readW; $x++) {
                $idx = ($y * $readW + $x) * 4;
                if ($idx + 2 >= $len) break 2;
                $color = imagecolorallocate($img, ord($pixels[$idx]), ord($pixels[$idx + 1]), ord($pixels[$idx + 2]));
                if ($color !== false) {
                    imagesetpixel($img, $x, $y, $color);
                }
            }
        }
        return $img;
    }

    /**
     * Capture framebuffer via OpenGL glReadPixels.
     * @param positive-int $fbW
     * @param positive-int $fbH
     */
    private static function captureGL(int $fbW, int $fbH): \GdImage
    {
        $size = $fbW * $fbH * 4;
        $buf = new \GL\Buffer\UByteBuffer(array_fill(0, $size, 0));
        glReadPixels(0, 0, $fbW, $fbH, GL_RGBA, GL_UNSIGNED_BYTE, $buf);
        $img = imagecreatetruecolor($fbW, $fbH);
        for ($y = 0; $y < $fbH; $y++) {
            for ($x = 0; $x < $fbW; $x++) {
                $idx = ((($fbH - 1 - $y) * $fbW) + $x) * 4;
                $r = max(0, min(255, $buf[$idx]));
                $g = max(0, min(255, $buf[$idx + 1]));
                $b = max(0, min(255, $buf[$idx + 2]));
                $color = imagecolorallocate($img, $r, $g, $b);
                if ($color !== false) {
                    imagesetpixel($img, $x, $y, $color);
                }
            }
        }
        return $img;
    }

    public function onInit(callable $callback): self
    {
        $this->onInit = $callback;
        return $this;
    }

    public function run(): void
    {
        self::log('Window initializing...');
        $this->window->initialize($this->input);
        self::log('Window initialized, framebuffer: ' . $this->window->getFramebufferWidth() . 'x' . $this->window->getFramebufferHeight());

        $nativeBackend = $this->config->is3D && in_array($this->config->renderBackend3D, ['vulkan', 'metal'], true);

        // For native backends (Metal/Vulkan), pump the event loop once so AppKit
        // completes window layout and sets proper NSView bounds before the renderer
        // attaches its CAMetalLayer / Vulkan surface.
        if (!$this->headless && $nativeBackend) {
            $this->window->pollEvents();
        }

        // Create GPU-backed renderers after window is initialized (need graphics context)
        if (!$this->headless && $this->config->is3D) {
            if ($this->useVio && $this->window instanceof VioWindow) {
                $this->renderer3D = new VioRenderer3D(
                    $this->window->getContext(),
                    $this->window->getFramebufferWidth(),
                    $this->window->getFramebufferHeight(),
                );
            } else {
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
        }

        if (!$this->headless && $this->config->is3D && $this->renderer3D !== null) {
            self::log('Renderer3D: ' . get_class($this->renderer3D));
        }

        // Create Renderer2D after window is initialized (needs GL/vio context)
        if (!$this->headless && $this->useVio && $this->window instanceof VioWindow) {
            $vioCtx = $this->window->getContext();

            $vioRenderer = new VioRenderer2D($vioCtx);
            $this->renderer2D = $vioRenderer;

            $vioTextures = new VioTextureManager($vioCtx, $this->config->assetsPath);
            $vioTextures->setRenderer($vioRenderer);
            $this->textures = $vioTextures;

            if ($this->renderer3D instanceof VioRenderer3D) {
                $this->renderer3D->setTextureManager($vioTextures);
            }

            $fontDir = $this->resolveEngineFontDir();
            if ($fontDir !== null && is_dir($fontDir)) {
                $this->renderer2D->loadFont('regular',  $fontDir . DIRECTORY_SEPARATOR . 'Inter-Regular.ttf');
                $this->renderer2D->loadFont('semibold', $fontDir . DIRECTORY_SEPARATOR . 'Inter-SemiBold.ttf');
                $this->renderer2D->setFont('regular');

                // CJK fallback fonts
                $cjkDir = $fontDir . DIRECTORY_SEPARATOR . 'noto-sans-cjk';
                if (is_dir($cjkDir)) {
                    $this->renderer2D->loadFont('noto-sans-sc', $cjkDir . DIRECTORY_SEPARATOR . 'NotoSansSC-Regular.otf');
                    $this->renderer2D->loadFont('noto-sans-kr', $cjkDir . DIRECTORY_SEPARATOR . 'NotoSansKR-Regular.otf');
                    $this->renderer2D->addFallbackFont('regular', 'noto-sans-sc');
                    $this->renderer2D->addFallbackFont('regular', 'noto-sans-kr');
                    $this->renderer2D->addFallbackFont('semibold', 'noto-sans-sc');
                    $this->renderer2D->addFallbackFont('semibold', 'noto-sans-kr');
                }
            }
        } elseif (!$this->headless && !$nativeBackend) {
            $this->renderer2D = new Renderer2D($this->window);

            $fontDir = $this->resolveEngineFontDir();
            if ($fontDir !== null && is_dir($fontDir)) {
                $this->renderer2D->loadFont('regular',  $fontDir . DIRECTORY_SEPARATOR . 'Inter-Regular.ttf');
                $this->renderer2D->loadFont('semibold', $fontDir . DIRECTORY_SEPARATOR . 'Inter-SemiBold.ttf');
                $this->renderer2D->setFont('regular');

                // CJK fallback fonts
                $cjkDir = $fontDir . DIRECTORY_SEPARATOR . 'noto-sans-cjk';
                if (is_dir($cjkDir)) {
                    $this->renderer2D->loadFont('noto-sans-sc', $cjkDir . DIRECTORY_SEPARATOR . 'NotoSansSC-Regular.otf');
                    $this->renderer2D->loadFont('noto-sans-kr', $cjkDir . DIRECTORY_SEPARATOR . 'NotoSansKR-Regular.otf');
                    $this->renderer2D->addFallbackFont('regular', 'noto-sans-sc');
                    $this->renderer2D->addFallbackFont('regular', 'noto-sans-kr');
                    $this->renderer2D->addFallbackFont('semibold', 'noto-sans-sc');
                    $this->renderer2D->addFallbackFont('semibold', 'noto-sans-kr');
                }
            }
        } elseif (!$this->headless && $nativeBackend) {
            $this->renderer2D = new NullRenderer2D($this->config->width, $this->config->height);
        }

        self::log('Renderer2D: ' . get_class($this->renderer2D));

        $fontDir = $this->resolveEngineFontDir();
        self::log('Font dir: ' . ($fontDir ?? 'not found'));

        if (!$this->headless && !$this->config->skipSplash) {
            self::log('Showing splash screen...');
            $this->showSplashScreen();
            self::log('Splash screen done');
        }

        $initFn = $this->onInit;
        if ($initFn !== null) {
            self::log('Running onInit callback...');
            $initFn($this);
            self::log('onInit callback done');
        }

        $this->scheduler->boot();
        self::log('Scheduler booted, entering game loop');
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
                    if ($this->input instanceof \PHPolygon\Runtime\VioInput) {
                        $this->input->snapshotScroll();
                    }
                    $this->renderer2D->beginFrame();

                    $this->world->render();

                    if ($this->onRender !== null) {
                        ($this->onRender)($this, $interpolation);
                    }

                    if ($this->renderer3D !== null) {
                        $this->renderer3D->endFrame();
                    }
                    $this->renderer2D->endFrame();
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
                    if ($this->input instanceof \PHPolygon\Runtime\VioInput) {
                        $this->input->snapshotScroll();
                    }
                    $this->renderer2D->beginFrame();

                    $this->world->render();

                    if ($this->onRender !== null) {
                        ($this->onRender)($this, $interpolation);
                    }

                    if ($this->renderer3D !== null) {
                        $this->renderer3D->endFrame();
                    }
                    $this->renderer2D->endFrame();
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

        $targetDir = $resourcesDir . DIRECTORY_SEPARATOR . 'fonts';

        // Extract engine fonts if not already present
        if (!is_dir($targetDir . DIRECTORY_SEPARATOR . 'noto-sans-cjk') && is_dir($pharDir)) {
            @mkdir($targetDir, 0755, true);
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($pharDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );
            $pharDirLen = strlen($pharDir);
            /** @var \SplFileInfo $item */
            foreach ($iterator as $item) {
                $relPath = substr($item->getPathname(), $pharDirLen + 1);
                $targetPath = $targetDir . DIRECTORY_SEPARATOR . $relPath;
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

    /**
     * Render a single test frame with an optional input modifier callback.
     * Designed for VRT and interaction tests — handles beginFrame/endFrame/input lifecycle.
     *
     * @param callable      $draw          fn(Engine): void — draw the frame
     * @param callable|null $inputModifier fn(InputInterface): void — inject input events before rendering
     */
    public function renderTestFrame(callable $draw, ?callable $inputModifier = null): void
    {
        if ($inputModifier !== null) {
            $inputModifier($this->input);
        }

        $this->renderer2D->beginFrame();
        $draw($this);
        $this->renderer2D->endFrame();

        $this->input->endFrame();
    }

    /**
     * Render multiple test frames in sequence with per-frame input control.
     * Each frame: apply input → render → advance input state.
     *
     * @param int      $count         Number of frames to render
     * @param callable $draw          fn(Engine, int $frameIndex): void
     * @param callable $inputModifier fn(InputInterface, int $frameIndex): void
     */
    public function renderTestFrames(int $count, callable $draw, callable $inputModifier): void
    {
        for ($i = 0; $i < $count; $i++) {
            $inputModifier($this->input, $i);

            $this->renderer2D->beginFrame();
            $draw($this, $i);
            $this->renderer2D->endFrame();

            $this->input->endFrame();
        }
    }

    private function showSplashScreen(): void
    {
        $w = $this->renderer2D->getWidth();
        $h = $this->renderer2D->getHeight();
        $black = new Color(0.0, 0.0, 0.0);
        $white = new Color(1.0, 1.0, 1.0);
        $duration = $this->config->splashDuration;

        // Try to load the engine logo
        $logo = null;
        $logoPath = __DIR__ . '/../resources/branding/logo.png';
        if (!str_starts_with($logoPath, 'phar://') && file_exists($logoPath)) {
            $logo = $this->textures->load('_engine_splash_logo', $logoPath);
        } elseif (defined('PHPOLYGON_PATH_RESOURCES')) {
            /** @var string $resDir */
            $resDir = PHPOLYGON_PATH_RESOURCES;
            $pharLogo = $resDir . DIRECTORY_SEPARATOR . 'branding' . DIRECTORY_SEPARATOR . 'logo.png';
            if (file_exists($pharLogo)) {
                $logo = $this->textures->load('_engine_splash_logo', $pharLogo);
            }
        }

        $gray = new Color(0.5, 0.5, 0.5);
        $rendererInfo = $this->buildRendererInfo();

        // Start the timer after the first frame is actually presented,
        // so initialization delays (font loading, first drawable acquisition)
        // don't eat into the visible splash duration.
        $startTime = null;

        while (!$this->window->shouldClose()) {
            if ($startTime !== null) {
                $elapsed = microtime(true) - $startTime;
                if ($elapsed >= $duration) {
                    break;
                }
            } else {
                $elapsed = 0.0;
            }

            // Fade: in for first 0.4s, out for last 0.5s, full in between
            $alpha = 1.0;
            $fadeIn = 0.4;
            $fadeOut = 0.5;
            if ($elapsed < $fadeIn) {
                $alpha = $elapsed / $fadeIn;
            } elseif ($elapsed > $duration - $fadeOut) {
                $alpha = ($duration - $elapsed) / $fadeOut;
            }
            $alpha = max(0.0, min(1.0, $alpha));

            $this->renderer2D->beginFrame();
            $this->renderer2D->clear($black);
            $this->renderer2D->setGlobalAlpha((float) $alpha);

            if ($logo !== null) {
                // Scale logo to fit ~60% of window width, maintain aspect ratio
                $maxW = $w * 0.6;
                $scale = $maxW / $logo->width;
                $logoW = $logo->width * $scale;
                $logoH = $logo->height * $scale;
                $logoX = ($w - $logoW) / 2;
                $logoY = ($h - $logoH) / 2;

                $this->renderer2D->drawSprite($logo, null, (float) $logoX, (float) $logoY, (float) $logoW, (float) $logoH);

                // "Developed with" above the logo
                $this->renderer2D->setFont('regular');
                $this->renderer2D->setTextAlign(TextAlign::CENTER | TextAlign::BOTTOM);
                $this->renderer2D->drawText('Developed with', (float) ($w / 2), (float) ($logoY - 12), 18.0, $white);

                // Renderer info below the logo
                if ($rendererInfo !== '') {
                    $this->renderer2D->setTextAlign(TextAlign::CENTER | TextAlign::TOP);
                    $this->renderer2D->drawText($rendererInfo, (float) ($w / 2), (float) ($logoY + $logoH + 16), 14.0, $gray);
                }
            } else {
                // Text-only fallback
                $this->renderer2D->setFont('regular');
                $this->renderer2D->setTextAlign(TextAlign::CENTER | TextAlign::MIDDLE);
                $this->renderer2D->drawText('Developed with', (float) ($w / 2), (float) ($h / 2 - 30), 18.0, $white);
                $this->renderer2D->drawText('PHPolygon', (float) ($w / 2), (float) ($h / 2 + 20), 42.0, $white);

                if ($rendererInfo !== '') {
                    $this->renderer2D->drawText($rendererInfo, (float) ($w / 2), (float) ($h / 2 + 60), 14.0, $gray);
                }
            }

            $this->renderer2D->setGlobalAlpha(1.0);
            $this->renderer2D->setTextAlign(TextAlign::LEFT | TextAlign::TOP);
            $this->renderer2D->endFrame();
            $this->window->swapBuffers();
            $this->window->pollEvents();

            // Start timer after first frame is visible on screen
            if ($startTime === null) {
                $startTime = microtime(true);
            }
        }

        // Clean up splash texture
        $this->textures->unload('_engine_splash_logo');
    }

    public function buildRendererInfo(): string
    {
        $parts = [];
        $parts[] = match (true) {
            $this->renderer2D instanceof VioRenderer2D => 'Vio 2D (' . ucfirst($this->getVioBackendName()) . ')',
            $this->renderer2D instanceof Renderer2D => 'OpenGL 2D',
            default => null,
        };
        if ($this->renderer3D !== null) {
            $parts[] = match (true) {
                $this->renderer3D instanceof VioRenderer3D => 'Vio 3D (' . ucfirst($this->getVioBackendName()) . ')',
                $this->renderer3D instanceof VulkanRenderer3D => 'Vulkan',
                $this->renderer3D instanceof MetalRenderer3D => 'Metal',
                $this->renderer3D instanceof OpenGLRenderer3D => 'OpenGL 3D',
                default => null,
            };
        }
        return implode(' · ', array_filter($parts));
    }

    private function getVioBackendName(): string
    {
        if ($this->window instanceof VioWindow && function_exists('vio_backend_name')) {
            return vio_backend_name($this->window->getContext());
        }
        return 'unknown';
    }

    private function shutdown(): void
    {
        self::log('Shutting down...');
        $this->scheduler->shutdown();
        $this->audio->dispose();
        $this->textures->clear();
        $this->world->clear();
        $this->window->destroy();
        Facade::clearEngine();
        self::log('Shutdown complete');
    }

    private static ?string $logPath = null;

    /**
     * Write a line to game.log. The log file is placed next to the binary
     * (PHAR/micro-SAPI) or in the project root (development).
     */
    public static function log(string $message): void
    {
        if (self::$logPath === null) {
            self::$logPath = self::resolveLogPath();
        }

        $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";
        @file_put_contents(self::$logPath, $line, FILE_APPEND);
    }

    /**
     * Write an error to game.log with ERROR prefix.
     */
    public static function logError(string $message): void
    {
        self::log('ERROR: ' . $message);
    }

    private static function resolveLogPath(): string
    {
        // Use PHPOLYGON_PATH_ROOT if available (set by PHAR stub and game bootstrap)
        if (defined('PHPOLYGON_PATH_ROOT')) {
            /** @var string $root */
            $root = PHPOLYGON_PATH_ROOT;
            return $root . DIRECTORY_SEPARATOR . 'game.log';
        }

        // Development: project root relative to src/
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'game.log';
    }
}
