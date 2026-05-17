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
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\GraphicsSettingsManager;
use PHPolygon\Rendering\NullRenderer2D;
use PHPolygon\Rendering\MetalRenderer3D;
use PHPolygon\Rendering\TextAlign;
use PHPolygon\Rendering\NullRenderer3D;
use PHPolygon\Rendering\OpenGLRenderer3D;
use PHPolygon\Rendering\VulkanRenderer3D;
use PHPolygon\Rendering\VioRenderer2D;
use PHPolygon\Rendering\VioRenderer3D;
use PHPolygon\Rendering\VioTextureManager;
use PHPolygon\Rendering\Quality\AdaptiveQualityController;
use PHPolygon\Rendering\Quality\QualityMode;
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
use PHPolygon\Runtime\PerfProfiler;
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
use PHPolygon\UI\PerfOverlay;

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
    public readonly GraphicsSettingsManager $graphics;
    public ?AdaptiveQualityController $adaptiveQuality = null;
    public readonly ThreadScheduler|NullThreadScheduler $scheduler;

    public readonly ?PerfOverlay $perfOverlay;

    private bool $running = false;
    private bool $headless;
    private bool $useVio;

    /**
     * Per-frame stats consumed by the F3 dev overlay and benchmark runner.
     * Updated once per render frame; safe to read from PerfOverlay or game code.
     *
     * frameTimesMs is a ring buffer (newest at the end) of the last
     * self::FRAME_HISTORY render frames in milliseconds. lastGcDelta is the
     * delta of `gc_status()` runs / collected since the previous frame.
     *
     * @var list<float>
     */
    public array $frameTimesMs = [];

    /** @var array{runs:int, collected:int} */
    public array $lastGcDelta = ['runs' => 0, 'collected' => 0];

    private const FRAME_HISTORY = 120;
    private int $lastFrameStartNs = 0;

    /** @var callable|null */
    private $onUpdate = null;

    /** @var callable|null */
    private $onRender = null;

    /** @var callable|null */
    private $onInit = null;

    /** Splash screen loading progress (0.0-1.0) and status label. */
    private float $splashProgress = 0.0;
    private string $splashLabel = '';

    /**
     * Splash task checklist — each entry is rendered as a status row during
     * the splash screen, so the player sees granular init progress instead of
     * a static "Init Game" label. Set via setSplashTasks(); advance via
     * advanceSplashTask().
     *
     * @var array<int, array{label: string, status: string}> status ∈ {'pending','active','done'}
     */
    private array $splashTasks = [];

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

        // Graphics settings: load from disk if available, otherwise stay
        // on the engine defaults (which mirror pre-existing behaviour 1:1
        // so games without a graphics.json render unchanged).
        $this->graphics = new GraphicsSettingsManager(
            path: $config->graphicsSettingsPath,
        );
        $this->graphics->bindEngine($this);
        $this->adaptiveQuality = new AdaptiveQualityController($this);

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

        $this->perfOverlay = $config->devMode ? new PerfOverlay($this) : null;
        if ($this->perfOverlay !== null) {
            self::log('PerfOverlay enabled (F3 to toggle)');
        }

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

    /**
     * Register the game-init callback. The callback runs during the splash so
     * the player sees real progress (advanceSplashTask()) instead of a frozen
     * window.
     *
     * Cooperative mode: if the callback is a generator function (uses `yield`),
     * the engine drives it across multiple splash frames — each `yield` is a
     * chunk boundary where the engine renders one splash frame and pumps
     * window events. This keeps the WM ping (_NET_WM_PING) answered even when
     * a single init chunk would otherwise block the main thread for >5 s, so
     * Linux compositors (Mutter/KWin) don't flag the window as "not responding"
     * during heavy startup work like font atlas pre-warming on slow GPUs.
     *
     * Legacy void-returning callbacks still run synchronously as before.
     */
    public function onInit(callable $callback): self
    {
        $this->onInit = $callback;
        return $this;
    }

    /**
     * Update the splash screen progress bar during init.
     * Call from the onInit callback to show loading progress.
     *
     * @param float  $progress  0.0 to 1.0
     * @param string $label     Status text (e.g. "Loading fonts...")
     */
    public function setSplashProgress(float $progress, string $label = ''): void
    {
        $this->splashProgress = max(0.0, min(1.0, $progress));
        $this->splashLabel = $label;
        $this->renderSplashFrame();
    }

    /**
     * Declare a checklist of init tasks shown on the splash screen. Each entry
     * renders as a row with a status icon; advanceSplashTask() walks through
     * the list, marking the current one done and the next one active.
     *
     * Existing setSplashProgress() / splashLabel API keeps working alongside.
     *
     * @param list<string> $taskLabels in display order
     */
    public function setSplashTasks(array $taskLabels): void
    {
        $this->splashTasks = [];
        foreach ($taskLabels as $label) {
            $this->splashTasks[] = ['label' => $label, 'status' => 'pending'];
        }
        $this->splashProgress = 0.0;
        $this->splashLabel = $taskLabels[0] ?? '';
        $this->renderSplashFrame();
    }

    /**
     * Mark the currently-active task as `done` and promote the next pending
     * task to `active`. If `$newLabel` is given, overrides the next task's
     * label (handy when init runs dynamic content like "Loading <lang>").
     *
     * Safe to call past the end of the list — extra calls just no-op.
     */
    public function advanceSplashTask(?string $newLabel = null): void
    {
        if (empty($this->splashTasks)) return;

        $nextActive = -1;
        foreach ($this->splashTasks as $i => $t) {
            if ($t['status'] === 'active') {
                $this->splashTasks[$i] = ['label' => $t['label'], 'status' => 'done'];
            }
            if ($t['status'] === 'pending' && $nextActive < 0) {
                $nextActive = $i;
            }
        }
        if ($nextActive >= 0) {
            $label = $newLabel ?? $this->splashTasks[$nextActive]['label'];
            $this->splashTasks[$nextActive] = ['label' => $label, 'status' => 'active'];
            $this->splashLabel = $label;
        } else {
            $this->splashLabel = '';
        }

        $done = 0;
        foreach ($this->splashTasks as $t) {
            if ($t['status'] === 'done') $done++;
        }
        $this->splashProgress = $done / max(1, count($this->splashTasks));
        $this->renderSplashFrame();
    }

    /**
     * Mark every remaining splash task as done. Use at the end of init so the
     * checklist shows all-green for the final hold-and-fade phase.
     */
    public function completeSplashTasks(): void
    {
        if (empty($this->splashTasks)) return;
        foreach ($this->splashTasks as $i => $t) {
            $this->splashTasks[$i] = ['label' => $t['label'], 'status' => 'done'];
        }
        $this->splashProgress = 1.0;
        $this->splashLabel = '';
        $this->renderSplashFrame();
    }

    public function run(): void
    {
        self::log('Window initializing...');
        $this->window->initialize($this->input);
        self::log('Window initialized, framebuffer: ' . $this->window->getFramebufferWidth() . 'x' . $this->window->getFramebufferHeight());
        if ($this->window instanceof VioWindow) {
            self::log('VIO backend active: ' . vio_backend_name($this->window->getContext()));
        }

        $nativeBackend = $this->config->is3D && in_array($this->config->renderBackend3D, ['vulkan', 'metal'], true);

        // For native backends (Metal/Vulkan), pump the event loop once so AppKit
        // completes window layout and sets proper NSView bounds before the renderer
        // attaches its CAMetalLayer / Vulkan surface.
        if (!$this->headless && $nativeBackend) {
            $this->window->pollEvents();
        }

        // Create GPU-backed renderers after window is initialized (need graphics context)
        if (!$this->headless && $this->config->is3D) {
            $useVioRenderer3D = $this->useVio
                && $this->window instanceof VioWindow
                && !$this->config->useNative3D;

            if ($useVioRenderer3D) {
                $this->renderer3D = new VioRenderer3D(
                    $this->window->getContext(),
                    $this->window->getFramebufferWidth(),
                    $this->window->getFramebufferHeight(),
                );
            } else {
                // Native renderer path. Each backend takes a different
                // shape of native handle — VulkanRenderer3D wraps an opaque
                // object (php-vulkan SurfaceKHR), MetalRenderer3D needs an
                // integer pointer to attach a CAMetalLayer. Compute the
                // handle in the matching arm so the type is narrow.
                $this->renderer3D = match ($this->config->renderBackend3D) {
                    'vulkan' => new VulkanRenderer3D(
                        $this->window->getFramebufferWidth(),
                        $this->window->getFramebufferHeight(),
                        $this->window->getHandle(),
                    ),
                    'metal' => $this->window instanceof VioWindow
                        ? new MetalRenderer3D(
                            $this->window->getFramebufferWidth(),
                            $this->window->getFramebufferHeight(),
                            vio_native_window_handle($this->window->getContext()),
                        )
                        : throw new \RuntimeException(
                            'MetalRenderer3D requires a VioWindow to obtain the native NSWindow* handle'
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

        // Apply persisted graphics settings to the live renderer + window
        // before the splash screen draws its first frame, so the first
        // visible frame already reflects the player's stored preferences.
        $this->graphics->applyToRenderer();
        if ($this->graphics->settings()->vsync !== $this->config->vsync) {
            // GLFW's swap interval was set with the config value; sync to
            // the persisted preference now that the GL context exists.
            $this->window->setVsync($this->graphics->settings()->vsync);
        }
        $this->gameLoop->setFpsCap($this->graphics->settings()->fpsCap);
        $this->events->listen(\PHPolygon\Event\GraphicsSettingsChanged::class, function (\PHPolygon\Event\GraphicsSettingsChanged $event): void {
            $this->window->setVsync($event->current->vsync);
            $this->gameLoop->setFpsCap($event->current->fpsCap);
            $this->textures->applySettings($event->current);
        });
        $this->textures->applySettings($this->graphics->settings());

        // Reset adaptive controller's warm-up window when a scene loads -
        // the new scene's frame times should not be compared against the
        // old scene's history.
        $this->events->listen(\PHPolygon\Event\SceneLoaded::class, function () {
            $this->adaptiveQuality?->resetWarmup();
        });

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

            $this->paintBootFrame();
        } elseif (!$this->headless && !$nativeBackend) {
            $this->renderer2D = new Renderer2D($this->window);

            $this->paintBootFrame();
        } elseif (!$this->headless && $nativeBackend) {
            $this->renderer2D = new NullRenderer2D($this->config->width, $this->config->height);
        }

        self::log('Renderer2D: ' . get_class($this->renderer2D));

        $fontDir = $this->resolveEngineFontDir();
        self::log('Font dir: ' . ($fontDir ?? 'not found'));

        $initFn = $this->onInit;
        if (!$this->headless && !$this->config->skipSplash) {
            self::log('Showing splash screen...');
            $this->showSplashScreen($initFn);
            self::log('Splash screen done');
        } elseif ($initFn !== null) {
            self::log('Running onInit callback...');
            $result = $initFn($this);
            if ($result instanceof \Generator) {
                // Headless / skipSplash path: drain the generator without a
                // splash. Each chunk still runs to completion; we just don't
                // render between yields. Without this, generator-style onInits
                // would be silently constructed-and-discarded.
                while ($result->valid()) {
                    $result->next();
                }
            }
            self::log('onInit callback done');
        }

        // First-launch graphics calibration: runs after onInit so games have
        // already registered their meshes, materials, and shaders. We only
        // run when no graphics.json exists - on subsequent launches the
        // saved settings are simply applied.
        if (!$this->headless
            && $this->config->firstLaunchCalibration
            && !file_exists($this->config->graphicsSettingsPath)
        ) {
            self::log('First-launch calibration starting...');
            $this->runFirstLaunchCalibration();
            self::log('First-launch calibration done');
        }

        if (!$this->scheduler->isBooted()) {
            $this->scheduler->boot();
            self::log('Scheduler booted');
        }
        self::log('Entering game loop');
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
                    PerfProfiler::begin('engine.update');
                    $this->world->updateMainThread($dt);

                    if ($this->onUpdate !== null) {
                        ($this->onUpdate)($this, $dt);
                    }
                    PerfProfiler::end();
                },
                render: function (float $interpolation) use ($nativeBackend) {
                    PerfProfiler::begin('engine.render');
                    $this->beginFrameStats();

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
                    PerfProfiler::begin('render2d.frame');
                    $this->renderer2D->beginFrame();

                    $this->world->render();

                    if ($this->onRender !== null) {
                        ($this->onRender)($this, $interpolation);
                    }

                    if ($this->renderer3D !== null) {
                        PerfProfiler::begin('render3d.flush');
                        $this->renderer3D->endFrame();
                        PerfProfiler::end();
                    }

                    if ($this->perfOverlay !== null) {
                        $this->perfOverlay->tickInput($this->input);
                        $this->perfOverlay->render($this->renderer2D);
                    }

                    $this->renderer2D->endFrame();
                    PerfProfiler::end();
                    if (!$nativeBackend) {
                        $this->window->swapBuffers();
                    }

                    $this->input->endFrame();
                    $this->window->pollEvents();
                    $this->endFrameStats();
                    PerfProfiler::end();
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
                    PerfProfiler::begin('engine.update');
                    $this->world->update($dt);

                    if ($this->onUpdate !== null) {
                        ($this->onUpdate)($this, $dt);
                    }
                    PerfProfiler::end();
                },
                render: function (float $interpolation) use ($nativeBackend) {
                    PerfProfiler::begin('engine.render');
                    $this->beginFrameStats();

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
                    PerfProfiler::begin('render2d.frame');
                    $this->renderer2D->beginFrame();

                    $this->world->render();

                    if ($this->onRender !== null) {
                        ($this->onRender)($this, $interpolation);
                    }

                    if ($this->renderer3D !== null) {
                        PerfProfiler::begin('render3d.flush');
                        $this->renderer3D->endFrame();
                        PerfProfiler::end();
                    }

                    if ($this->perfOverlay !== null) {
                        $this->perfOverlay->tickInput($this->input);
                        $this->perfOverlay->render($this->renderer2D);
                    }

                    $this->renderer2D->endFrame();
                    PerfProfiler::end();
                    if (!$nativeBackend) {
                        $this->window->swapBuffers();
                    }

                    $this->input->endFrame();
                    $this->window->pollEvents();
                    $this->endFrameStats();
                    PerfProfiler::end();
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

    private function beginFrameStats(): void
    {
        $this->lastFrameStartNs = (int) hrtime(true);
    }

    private function endFrameStats(): void
    {
        if ($this->lastFrameStartNs === 0) {
            return;
        }

        $elapsedMs = ((int) hrtime(true) - $this->lastFrameStartNs) / 1_000_000.0;
        $this->frameTimesMs[] = $elapsedMs;
        if (count($this->frameTimesMs) > self::FRAME_HISTORY) {
            \array_shift($this->frameTimesMs);
        }

        $this->lastGcDelta = PerfProfiler::gcDelta();

        // Drive the adaptive quality controller. It is a no-op unless the
        // player has switched to QualityMode::Adaptive.
        $this->adaptiveQuality?->tick($elapsedMs);
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

    /**
     * Paint a single black frame immediately after the renderer exists, before
     * the engine spends seconds loading fonts. Without this, the freshly
     * mapped window sits unanswered while font I/O blocks the main thread —
     * and Linux compositors (Mutter/KWin) pop "not responding" before the
     * splash screen has had a chance to draw its first frame.
     *
     * One swapBuffers + pollEvents is enough: the WM ping is answered, the
     * window has a defined initial pixel state, and any cost on slow GPUs is
     * negligible compared to the loadFont cascade that follows.
     */
    private function paintBootFrame(): void
    {
        $this->renderer2D->beginFrame();
        $this->renderer2D->clear(new Color(0.0, 0.0, 0.0));
        $this->renderer2D->endFrame();
        $this->window->swapBuffers();
        $this->window->pollEvents();
    }

    /**
     * Load the engine's bundled fonts (Inter + Noto Sans CJK fallbacks) into
     * the freshly-created renderer. Calls pollEvents() between fonts so the
     * window stays responsive on slow GPUs (Intel HD 3000 + Mesa): each
     * loadFont can stall for hundreds of ms while the TTF is parsed and the
     * first glyph atlas is allocated; the CJK fonts in particular are 5-10 MB
     * each. Without inter-font pumping a cold cache can push the cumulative
     * stall over the WM's _NET_WM_PING budget.
     */
    private function loadEngineFonts(): void
    {
        $fontDir = $this->resolveEngineFontDir();
        if ($fontDir === null || !is_dir($fontDir)) return;

        $this->renderer2D->loadFont('regular',  $fontDir . DIRECTORY_SEPARATOR . 'Inter-Regular.ttf');
        $this->window->pollEvents();
        $this->renderer2D->loadFont('semibold', $fontDir . DIRECTORY_SEPARATOR . 'Inter-SemiBold.ttf');
        $this->window->pollEvents();
        $this->renderer2D->setFont('regular');

        // CJK fallback fonts - large files, definitely worth pumping events
        // between them.
        $cjkDir = $fontDir . DIRECTORY_SEPARATOR . 'noto-sans-cjk';
        if (!is_dir($cjkDir)) return;

        $this->renderer2D->loadFont('noto-sans-sc', $cjkDir . DIRECTORY_SEPARATOR . 'NotoSansSC-Regular.otf');
        $this->window->pollEvents();
        $this->renderer2D->loadFont('noto-sans-kr', $cjkDir . DIRECTORY_SEPARATOR . 'NotoSansKR-Regular.otf');
        $this->window->pollEvents();
        $this->renderer2D->addFallbackFont('regular', 'noto-sans-sc');
        $this->renderer2D->addFallbackFont('regular', 'noto-sans-kr');
        $this->renderer2D->addFallbackFont('semibold', 'noto-sans-sc');
        $this->renderer2D->addFallbackFont('semibold', 'noto-sans-kr');
    }

    /**
     * @param callable|null $initFn  Game init callback to run during the splash screen.
     *                               Executed after the first frame is visible, so the
     *                               splash stays on screen while the game loads.
     */
    private function showSplashScreen(?callable $initFn = null): void
    {
        $duration = $this->config->splashDuration;

        // Try to load the engine logo
        $logoPath = __DIR__ . '/../resources/branding/logo.png';
        if (!str_starts_with($logoPath, 'phar://') && file_exists($logoPath)) {
            $this->splashLogo = $this->textures->load('_engine_splash_logo', $logoPath);
        } elseif (defined('PHPOLYGON_PATH_RESOURCES')) {
            /** @var string $resDir */
            $resDir = PHPOLYGON_PATH_RESOURCES;
            $pharLogo = $resDir . DIRECTORY_SEPARATOR . 'branding' . DIRECTORY_SEPARATOR . 'logo.png';
            if (file_exists($pharLogo)) {
                $this->splashLogo = $this->textures->load('_engine_splash_logo', $pharLogo);
            }
        }

        $this->splashRendererInfo = $this->buildRendererInfo();

        // Engine init phase: show "Init Engine" progress
        $this->splashProgress = 0.5;
        $this->splashLabel = 'Init Engine';

        $this->splashFadeAlpha = 1.0;

        // Load engine fonts as the last setup step before the visible splash
        // animation begins. Done here (not in Engine::run) so the helper
        // sits next to the only code that actually uses 'regular' / 'semibold'
        // - the splash itself, the calibration overlay, and the F3 perf HUD.
        // Each loadFont call pumps events between, so the WM ping stays
        // answered on slow GPUs even though no frame is rendered until the
        // fade-in loop below.
        $this->loadEngineFonts();

        // Phase 1: Fade in
        $fadeIn = 0.4;
        $fadeInStart = microtime(true);
        while (!$this->window->shouldClose()) {
            $elapsed = microtime(true) - $fadeInStart;
            $this->splashFadeAlpha = min(1.0, (float) ($elapsed / $fadeIn));
            $this->renderSplashFrame();
            if ($elapsed >= $fadeIn) {
                break;
            }
        }

        // Phase 2: Run game init with splash fully visible
        if (!$this->window->shouldClose() && $initFn !== null) {
            $this->splashFadeAlpha = 1.0;
            $this->splashProgress = 0.5;
            $this->splashLabel = 'Init Game';
            $this->renderSplashFrame();

            self::log('Running onInit during splash...');
            $result = $initFn($this);
            if ($result instanceof \Generator) {
                while ($result->valid()) {
                    if ($this->window->shouldClose()) break;
                    $this->renderSplashFrame();
                    $result->next();
                }
            }
            self::log('onInit done');

            $this->splashProgress = 0.98;
            $this->splashLabel = 'Starting...';
            $this->renderSplashFrame();

            $this->scheduler->boot();
            self::log('Scheduler booted during splash');

            $this->splashProgress = 1.0;
            $this->splashLabel = '';
        }

        // Phase 2: Hold + fade out
        $fadeOut = 0.5;
        $holdStart = microtime(true);
        while (!$this->window->shouldClose()) {
            $elapsed = microtime(true) - $holdStart;
            if ($elapsed >= $duration) {
                break;
            }

            if ($elapsed > $duration - $fadeOut) {
                $this->splashFadeAlpha = max(0.0, (float) (($duration - $elapsed) / $fadeOut));
            } else {
                $this->splashFadeAlpha = 1.0;
            }

            $this->renderSplashFrame();
        }

        // Clean up
        $this->textures->unload('_engine_splash_logo');
        $this->splashLogo = null;
        $this->splashRendererInfo = '';
    }

    /** @var \PHPolygon\Rendering\Texture|null */
    private $splashLogo = null;
    private string $splashRendererInfo = '';
    private float $splashFadeAlpha = 1.0;

    /**
     * Render a single splash screen frame. Called from the splash loop
     * and from setSplashProgress() during init.
     */
    private function renderSplashFrame(): void
    {
        $w = $this->renderer2D->getWidth();
        $h = $this->renderer2D->getHeight();
        $black = new Color(0.0, 0.0, 0.0);
        $white = new Color(1.0, 1.0, 1.0);
        $gray = new Color(0.5, 0.5, 0.5);

        $this->renderer2D->beginFrame();
        $this->renderer2D->clear($black);
        $this->renderer2D->setGlobalAlpha($this->splashFadeAlpha);

        $barY = (float) ($h - 60);

        // Reserve vertical room for the task checklist (when present) so the
        // logo/title block lifts up and stays clear of the rows.
        $hasTasks = !empty($this->splashTasks);
        $listRowH = 18.0;
        $listMarginTop = 24.0;   // gap between logo/info and first row
        $listMarginBottom = 32.0;// gap between last row and the progress bar
        $listH = $hasTasks ? (count($this->splashTasks) * $listRowH) : 0.0;
        $listTop = $hasTasks ? ($barY - $listMarginBottom - $listH) : (float) $h;
        $infoBottom = $listTop - $listMarginTop; // logo/info block must fit above this

        if ($this->splashLogo !== null) {
            // Logo is constrained to fit *above* the task list. Use whichever
            // of "60% width / 30% height" is smaller so the layout never spills.
            $maxByWidth = $w * 0.6;
            $maxByHeight = ($hasTasks ? max(40.0, $infoBottom * 0.55) : $h * 0.6);
            $scale = min(
                $maxByWidth / $this->splashLogo->width,
                $maxByHeight / $this->splashLogo->height,
            );
            $logoW = $this->splashLogo->width * $scale;
            $logoH = $this->splashLogo->height * $scale;
            $logoX = ($w - $logoW) / 2;
            // "Developed with" caption + renderer info both attach to the logo,
            // so the whole block has to fit inside infoBottom when tasks are set.
            $captionGap = 12.0;
            $infoGap = 16.0;
            $blockH = 18.0 + $captionGap + $logoH + $infoGap + 14.0;
            $blockTop = $hasTasks
                ? max(20.0, ($infoBottom - $blockH) / 2.0)
                : ($h - $blockH) / 2.0 - 8.0;
            $logoY = $blockTop + 18.0 + $captionGap;

            $this->renderer2D->drawSprite($this->splashLogo, null, (float) $logoX, (float) $logoY, (float) $logoW, (float) $logoH);

            $this->renderer2D->setFont('regular');
            $this->renderer2D->setTextAlign(TextAlign::CENTER | TextAlign::BOTTOM);
            $this->renderer2D->drawText('Developed with', (float) ($w / 2), (float) ($logoY - $captionGap), 18.0, $white);

            if ($this->splashRendererInfo !== '') {
                $this->renderer2D->setTextAlign(TextAlign::CENTER | TextAlign::TOP);
                $this->renderer2D->drawText($this->splashRendererInfo, (float) ($w / 2), (float) ($logoY + $logoH + $infoGap), 14.0, $gray);
            }
        } else {
            // No logo — fall back to a stacked title in the same block.
            $blockTop = $hasTasks
                ? max(20.0, ($infoBottom - 110.0) / 2.0)
                : ($h / 2 - 50);

            $this->renderer2D->setFont('regular');
            $this->renderer2D->setTextAlign(TextAlign::CENTER | TextAlign::TOP);
            $this->renderer2D->drawText('Developed with', (float) ($w / 2), (float) $blockTop, 18.0, $white);
            $this->renderer2D->drawText('PHPolygon', (float) ($w / 2), (float) ($blockTop + 30.0), 42.0, $white);

            if ($this->splashRendererInfo !== '') {
                $this->renderer2D->drawText($this->splashRendererInfo, (float) ($w / 2), (float) ($blockTop + 86.0), 14.0, $gray);
            }
        }

        // Task checklist — sits in the lower middle, anchored above the bar.
        if ($hasTasks) {
            $this->renderSplashTaskList($w, $listTop, $listRowH);
        }

        // Progress bar and label
        if ($this->splashProgress > 0.0 || $this->splashLabel !== '') {
            $barW = 300.0;
            $barH = 4.0;
            $barX = (float) (($w - $barW) / 2);

            // Bar background
            $this->renderer2D->drawRect($barX, $barY, $barW, $barH, new Color(0.2, 0.2, 0.2));
            // Bar fill
            if ($this->splashProgress > 0.0) {
                $this->renderer2D->drawRect($barX, $barY, (float) ($barW * $this->splashProgress), $barH, $white);
            }

            // Label below bar — only when no task list (the list already shows the active label).
            if ($this->splashLabel !== '' && empty($this->splashTasks)) {
                $this->renderer2D->setFont('regular');
                $this->renderer2D->setTextAlign(TextAlign::CENTER | TextAlign::TOP);
                $this->renderer2D->drawText($this->splashLabel, (float) ($w / 2), $barY + $barH + 8, 13.0, $gray);
            }
        }

        $this->renderer2D->setGlobalAlpha(1.0);
        $this->renderer2D->setTextAlign(TextAlign::LEFT | TextAlign::TOP);
        $this->renderer2D->endFrame();
        $this->window->swapBuffers();
        $this->window->pollEvents();
    }

    /**
     * Render the splash task checklist. Centred horizontally in a column wide
     * enough for the longest label; each row shows a coloured dot (done=green,
     * active=pulsing white, pending=dim grey) plus the label text. The caller
     * decides the vertical anchor so the list never overlaps the logo block.
     */
    private function renderSplashTaskList(int $w, float $startY, float $rowH): void
    {
        $r2d = $this->renderer2D;
        $iconW = 14.0;
        $iconGap = 8.0;

        $maxLabelW = 0.0;
        $r2d->setFont('regular');
        foreach ($this->splashTasks as $t) {
            $m = $r2d->measureText($t['label'], 13.0);
            if ($m->width > $maxLabelW) $maxLabelW = $m->width;
        }
        $colW = $iconW + $iconGap + $maxLabelW;
        $colX = (float) (($w - $colW) / 2.0);

        $done    = new Color(0.30, 0.85, 0.50);
        $active  = new Color(1.00, 1.00, 1.00);
        $pending = new Color(0.40, 0.40, 0.40);
        $labelDone    = new Color(0.70, 0.82, 0.75);
        $labelActive  = new Color(1.00, 1.00, 1.00);
        $labelPending = new Color(0.45, 0.45, 0.45);

        // Subtle pulse for the active row so the user sees the renderer is alive.
        $pulse = 0.55 + 0.45 * (float) sin(microtime(true) * 5.5);

        foreach ($this->splashTasks as $i => $task) {
            $rowY = $startY + $i * $rowH;
            $iconCx = $colX + $iconW / 2.0;
            $iconCy = $rowY + $rowH / 2.0;

            switch ($task['status']) {
                case 'done':
                    $r2d->drawRect($iconCx - 4.0, $iconCy - 4.0, 8.0, 8.0, $done);
                    $labelColor = $labelDone;
                    break;
                case 'active':
                    $r2d->setGlobalAlpha($this->splashFadeAlpha * $pulse);
                    $r2d->drawRect($iconCx - 4.0, $iconCy - 4.0, 8.0, 8.0, $active);
                    $r2d->setGlobalAlpha($this->splashFadeAlpha);
                    $labelColor = $labelActive;
                    break;
                default:
                    $r2d->drawRect($iconCx - 3.0, $iconCy - 3.0, 6.0, 6.0, $pending);
                    $labelColor = $labelPending;
            }

            $r2d->setFont('regular');
            $r2d->setTextAlign(TextAlign::LEFT | TextAlign::MIDDLE);
            $r2d->drawText($task['label'], $colX + $iconW + $iconGap, $iconCy, 13.0, $labelColor);
        }
    }

    /**
     * Run first-launch graphics calibration. Renders an overlay with progress
     * text driven by GraphicsCalibrationProgress events while the auto-tuner
     * sweeps tiers, then persists the chosen settings.
     *
     * Skipped silently if the engine is in 3D-disabled mode (no renderer3D
     * to measure against) or if the player closes the window mid-flow.
     */
    private function runFirstLaunchCalibration(): void
    {
        if ($this->renderer3D === null) {
            // Nothing to calibrate without a 3D renderer; just persist defaults.
            $this->graphics->save();
            return;
        }

        $progressLabel = 'Optimizing for your hardware...';
        $progressRatio = 0.0;

        $progressListener = function (\PHPolygon\Event\GraphicsCalibrationProgress $e) use (&$progressLabel, &$progressRatio): void {
            $progressLabel = 'Optimizing for your hardware...';
            $progressRatio = max($progressRatio, $e->ratio);
            $this->renderCalibrationOverlay($progressRatio, $progressLabel, $e->stage);
        };
        $this->events->listen(\PHPolygon\Event\GraphicsCalibrationProgress::class, $progressListener);

        // Show an initial frame before the tuner begins so the player sees
        // a stable overlay rather than a flash of the empty back buffer.
        $this->renderCalibrationOverlay(0.0, $progressLabel, 'Preparing...');

        try {
            $this->graphics->recalibrate();
        } catch (\Throwable $e) {
            self::logError('Graphics calibration failed: ' . $e->getMessage());
        }

        $this->renderCalibrationOverlay(1.0, 'Done', '');
        usleep(250_000); // 250ms pause so the "Done" frame is visible
    }

    private function renderCalibrationOverlay(float $progress, string $label, string $stage): void
    {
        if ($this->window->shouldClose()) {
            return;
        }
        $w = $this->renderer2D->getWidth();
        $h = $this->renderer2D->getHeight();
        $black = new Color(0.0, 0.0, 0.0);
        $white = new Color(1.0, 1.0, 1.0);
        $gray = new Color(0.6, 0.6, 0.6);

        $this->renderer2D->beginFrame();
        $this->renderer2D->clear($black);

        $this->renderer2D->setFont('regular');
        $this->renderer2D->setTextAlign(TextAlign::CENTER | TextAlign::MIDDLE);
        $this->renderer2D->drawText($label, (float)($w / 2), (float)($h / 2 - 30), 22.0, $white);

        if ($stage !== '') {
            $this->renderer2D->drawText($stage, (float)($w / 2), (float)($h / 2 + 8), 12.0, $gray);
        }

        $barW = 320.0;
        $barH = 4.0;
        $barX = (float)(($w - $barW) / 2);
        $barY = (float)($h / 2 + 32);
        $this->renderer2D->drawRect($barX, $barY, $barW, $barH, new Color(0.2, 0.2, 0.2));
        if ($progress > 0.0) {
            $this->renderer2D->drawRect($barX, $barY, $barW * max(0.0, min(1.0, $progress)), $barH, $white);
        }

        $this->renderer2D->setTextAlign(TextAlign::LEFT | TextAlign::TOP);
        $this->renderer2D->endFrame();
        $this->window->swapBuffers();
        $this->window->pollEvents();
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
