<?php

declare(strict_types=1);

namespace PHPolygon;

use PHPolygon\Audio\AudioManager;
use PHPolygon\Audio\GLFWAudioBackend;
use PHPolygon\Audio\VioAudioBackend;
use PHPolygon\Branding\StudioSplashInterface;
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
use PHPolygon\Rendering\Quality\ThermalMonitor;
use PHPolygon\Rendering\Quality\ThermalSourceFrametime;
use PHPolygon\Rendering\Quality\ThermalSourceOs;
use PHPolygon\Rendering\Renderer2D;
use PHPolygon\Rendering\Renderer2DInterface;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\Renderer3DInterface;
use PHPolygon\Rendering\ShaderManager;
use PHPolygon\Rendering\TextureManager;
use PHPolygon\Testing\NullTextureManager;
use PHPolygon\Runtime\Clock;
use PHPolygon\Runtime\DevLogger;
use PHPolygon\Runtime\GameLoop;
use PHPolygon\Runtime\HardwareProfile;
use PHPolygon\Runtime\HardwareProfiler;
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
use PHPolygon\Scene\Transpiler\WorldExporter;
use PHPolygon\Scene\Transpiler\WorldImporter;
use PHPolygon\Support\Facades\Facade;
use PHPolygon\Thread\NullThreadScheduler;
use PHPolygon\Thread\ThreadScheduler;
use PHPolygon\Thread\ThreadSchedulerFactory;
use PHPolygon\UI\PerfOverlay;

class Engine
{
    public readonly World $world;

    // Game-authoritative editor sync (opt-in via enableEditorSync()).
    private ?string $editorSyncPath = null;
    private float $editorSyncInterval = 0.5;
    private float $editorSyncAccum = 0.0;
    private int $editorSyncWorldVersion = 0;
    private int $editorSyncMtime = 0;

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
    public readonly ?ThermalMonitor $thermalMonitor;
    public readonly HardwareProfile $hardware;
    public readonly ?DevLogger $devLogger;
    public readonly ThreadScheduler|NullThreadScheduler $scheduler;

    public readonly ?PerfOverlay $perfOverlay;

    private bool $running = false;
    private bool $headless;
    private bool $useVio;
    private bool $fontsLoaded = false;

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

    /**
     * Fixed-timestep render interpolation factor [0,1] for the current frame:
     * how far the render sits between the previous and latest update tick.
     * Set by the game loop's render callback before world->render() runs, so
     * systems (e.g. Camera3DSystem) can interpolate transforms and stay smooth
     * when the render rate exceeds the update rate (144 fps render / 60 Hz sim).
     */
    public float $renderInterpolation = 1.0;

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

        $this->gameLoop = new GameLoop(
            $config->targetTickRate,
            variableTimestep: $config->variableTimestep,
        );
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

        $this->hardware = (new HardwareProfiler())->detect($this->headless);
        self::log('Hardware: ' . $this->hardware->describe());

        // CLI flag pickup. Two sources are supported so the flag works
        // both in packaged PHAR builds (the stub pre-defines the
        // constants in PharBuilder::generateStub) and in direct
        // `php game.php --dev-monitor` runs during development.
        $cliFlags = self::detectCliDevFlags();
        $effectiveDevMode = $config->devMode
            || defined('PHPOLYGON_CLI_DEV')
            || $cliFlags['dev'];
        $effectiveDevMonitor = $config->devMonitor
            || defined('PHPOLYGON_CLI_DEV_MONITOR')
            || $cliFlags['monitor'];
        $this->devLogger = $effectiveDevMode
            ? new DevLogger($config->devLogPath, alsoStdout: !$this->headless)
            : null;
        $this->devLogger?->logHardwareProfile($this->hardware);

        if ($config->autoThermalManagement && !$this->headless) {
            // Real hardware thermal sensor wherever php-vio can read one:
            // macOS NSProcessInfo, Linux sysfs thermal zones (CPU+GPU), Windows
            // NVIDIA NVAPI GPU temp (with an ACPI-WMI fallback). Gate on a
            // PLAUSIBLE reading, not mere function existence: a present-but-
            // unsupported sensor (driver returns an unparseable/unknown value)
            // must NOT demote the frametime fallback to advisory — that would
            // leave no thermal safety net at all. A known, non-Unknown state
            // means a real sensor is actually reporting; otherwise keep the
            // frametime guard active as the fallback. (Honors PHPOLYGON_THERMAL_FORCE.)
            $hasRealThermalSensor = \PHPolygon\Runtime\ThermalState::fromVio()
                !== \PHPolygon\Runtime\ThermalState::Unknown;
            // The frametime guard is only a THERMAL FALLBACK. When a real sensor
            // exists it runs in ADVISORY mode — it still tracks p95 + logs in dev
            // mode, but does NOT drive thermal throttling, so a heavy-but-cool
            // scene can't raise a false thermal alarm (the real sensor decides).
            $sources = [new ThermalSourceFrametime(
                log: $this->devLogger,
                contributesPressure: !$hasRealThermalSensor,
            )];
            if ($hasRealThermalSensor) {
                $sources[] = new ThermalSourceOs();
            }
            $this->thermalMonitor = new ThermalMonitor(
                engine: $this,
                profile: $this->hardware,
                sources: $sources,
                log: $this->devLogger,
            );
        } else {
            $this->thermalMonitor = null;
        }

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
                $effectiveDevMode,
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

        $this->perfOverlay = $effectiveDevMode ? new PerfOverlay($this, devMonitor: $effectiveDevMonitor) : null;
        if ($this->perfOverlay !== null) {
            self::log('PerfOverlay enabled (F3 to toggle' . ($effectiveDevMonitor ? ', V for monitor' : '') . ')');
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
            $engine->renderer2D = new VioRenderer2D(
                $engine->window->getContext(),
                $config->textMeasureCacheCap,
            );
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
                // The CJK fallback fonts are ~13 MB / ~32k glyphs each; packing
                // their atlas synchronously froze the render thread for 20-25 s
                // the first time a CJK glyph hit the fallback chain. Register
                // them for background loading instead — on the vio backend the
                // atlas is rasterized on a worker thread and the glyphs pop in a
                // few frames after first use, with no stall. On non-vio backends
                // preloadFontAsync() is a synchronous alias for loadFont().
                // Region subsets: SC/TC/JP carry overlapping Han codepoints with
                // different glyph forms. The chain below is a coverage default —
                // a consumer with a known active locale should rebuild the chain
                // (clearFallbackFonts + addFallbackFont) with that locale's face
                // first so Han-unified codepoints pick the right regional form.
                $cjkFaces = [
                    'noto-sans-sc' => 'NotoSansSC-Regular.otf',
                    'noto-sans-tc' => 'NotoSansTC-Regular.otf',
                    'noto-sans-jp' => 'NotoSansJP-Regular.otf',
                    'noto-sans-kr' => 'NotoSansKR-Regular.otf',
                ];
                foreach ($cjkFaces as $faceId => $file) {
                    $path = $cjkDir . DIRECTORY_SEPARATOR . $file;
                    if (!is_file($path)) {
                        continue;
                    }
                    // VRT is headless + single-frame with no splash thread to
                    // protect, so load CJK faces synchronously. The async path
                    // (preloadFontAsync) has resolveFontByName() return null until
                    // a worker finishes, so a one-shot capture would drop the face
                    // from the fallback chain and render tofu. loadFont() builds
                    // the atlas on demand at draw time instead.
                    $engine->renderer2D->loadFont($faceId, $path);
                    $engine->renderer2D->addFallbackFont('regular', $faceId);
                    $engine->renderer2D->addFallbackFont('semibold', $faceId);
                }
            }

            // Arabic + Thai fallbacks (vio shapes/BiDis via HarfBuzz+SheenBidi;
            // it needs a font that actually carries the glyphs in the chain).
            // Each is a small static Regular (~140 KB / ~20 KB) so, unlike the
            // multi-MB CJK faces, loading them synchronously is cheap.
            $arabicFont = $fontDir . DIRECTORY_SEPARATOR . 'noto-sans-arabic'
                . DIRECTORY_SEPARATOR . 'NotoSansArabic-Regular.ttf';
            if (is_file($arabicFont)) {
                $engine->renderer2D->loadFont('noto-sans-arabic', $arabicFont);
                $engine->renderer2D->addFallbackFont('regular', 'noto-sans-arabic');
                $engine->renderer2D->addFallbackFont('semibold', 'noto-sans-arabic');
            }
            $thaiFont = $fontDir . DIRECTORY_SEPARATOR . 'noto-sans-thai'
                . DIRECTORY_SEPARATOR . 'NotoSansThai-Regular.ttf';
            if (is_file($thaiFont)) {
                $engine->renderer2D->loadFont('noto-sans-thai', $thaiFont);
                $engine->renderer2D->addFallbackFont('regular', 'noto-sans-thai');
                $engine->renderer2D->addFallbackFont('semibold', 'noto-sans-thai');
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

    /**
     * Snapshot the live ECS world to an editor-loadable `*.scene.json`. Call
     * from a dev command/hotkey to hand the running game state to the editor.
     *
     * @param  list<class-string>|null  $systems  Override declared systems.
     */
    public function exportWorldSnapshot(string $path, string $name = 'game_world', ?array $systems = null): void
    {
        (new WorldExporter)->toJsonFile($this->world, $path, $name, $systems);
    }

    /**
     * Apply an editor-saved `*.scene.json` snapshot to the live world — the
     * "editor → game" direction. With $replace the world is cleared first
     * (full swap to the edited version); otherwise entities are added.
     *
     * @return array<string, int>  Created entities keyed by name.
     */
    public function importWorldSnapshot(string $path, bool $replace = false): array
    {
        $raw = is_file($path) ? file_get_contents($path) : false;
        $data = $raw !== false ? json_decode($raw, true) : null;
        if (! is_array($data)) {
            throw new \RuntimeException("Invalid world snapshot: {$path}");
        }
        if ($replace) {
            $this->world->clear();
        }

        /** @var array<string, mixed> $data */
        return (new WorldImporter)->apply($this->world, $data);
    }

    /**
     * Enable game-authoritative live sync with the editor via a snapshot file.
     *
     * On enable, the current world is exported (the editor sees it). Each frame
     * (throttled) the engine reconciles: if the world has structurally advanced
     * since the last export, the game re-exports and overwrites an out-of-date
     * editor; only when the world is unchanged is an editor save imported. This
     * keeps code/game authoritative (AI-authoring), with editor tweaks applied
     * only while the editor is current (e.g. while the game is paused/idle).
     */
    public function enableEditorSync(string $path, float $intervalSeconds = 0.5): void
    {
        $this->editorSyncPath = $path;
        $this->editorSyncInterval = max(0.05, $intervalSeconds);
        $this->exportWorldSnapshot($path);
        $this->editorSyncWorldVersion = $this->world->version();
        $this->editorSyncMtime = $this->snapshotMtime($path);
        $this->editorSyncAccum = 0.0;
    }

    public function disableEditorSync(): void
    {
        $this->editorSyncPath = null;
    }

    private function tickEditorSync(float $dt): void
    {
        if ($this->editorSyncPath === null) {
            return;
        }
        $this->editorSyncAccum += $dt;
        if ($this->editorSyncAccum < $this->editorSyncInterval) {
            return;
        }
        $this->editorSyncAccum = 0.0;
        $path = $this->editorSyncPath;

        if ($this->world->version() !== $this->editorSyncWorldVersion) {
            // Game moved on since the last export → authoritative; overwrite the
            // (now outdated) editor snapshot.
            $this->exportWorldSnapshot($path);
            $this->editorSyncWorldVersion = $this->world->version();
            $this->editorSyncMtime = $this->snapshotMtime($path);

            return;
        }

        $mtime = $this->snapshotMtime($path);
        if ($mtime !== $this->editorSyncMtime) {
            // World unchanged and the editor saved → the editor was current; apply.
            $this->importWorldSnapshot($path, replace: true);
            $this->editorSyncWorldVersion = $this->world->version();
            $this->editorSyncMtime = $this->snapshotMtime($path);
        }
    }

    private function snapshotMtime(string $path): int
    {
        clearstatcache(true, $path);

        return is_file($path) ? (int) filemtime($path) : 0;
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
        // Apply the saved display mode NOW — the window exists but no frame has
        // drawn yet, so the studio splash (and everything after) renders in the
        // player's chosen mode instead of briefly flashing windowed.
        self::applyDisplayMode($this->window, $this->config->displayMode);
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
        $this->applyRenderFpsCap($this->graphics->settings());
        $this->events->listen(\PHPolygon\Event\GraphicsSettingsChanged::class, function (\PHPolygon\Event\GraphicsSettingsChanged $event): void {
            $this->window->setVsync($event->current->vsync);
            $this->applyRenderFpsCap($event->current);
            $this->textures->applySettings($event->current);
            $this->syncFieldtracingToSystems($event->current);
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

            $vioRenderer = new VioRenderer2D($vioCtx, $this->config->textMeasureCacheCap);
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

        // When the splash is skipped, nothing else loads the engine fonts, so
        // renderer2D->drawText() would have no font and every HUD / text draw
        // would silently render nothing. Load them here. (The splash path loads
        // them itself, covering the TTF-parse stall behind the splash frame;
        // loadEngineFonts() is idempotent so the two paths never double-load.)
        if (!$this->headless && $this->config->skipSplash) {
            self::log('skipSplash: loading engine fonts up front');
            $this->loadEngineFonts();
        }

        $initFn = $this->onInit;
        if (!$this->headless && !$this->config->skipSplash) {
            // Engine fonts are needed by every renderer-driven splash phase:
            // the optional studio splash (StudioSplashInterface implementations
            // call $renderer2D->setFont(...) on Inter), the engine splash
            // ("Developed with PHPolygon" caption, task checklist, progress
            // label), the first-launch calibration overlay, and the F3 perf
            // HUD. Loading them once here keeps the splash phases unaware of
            // who else might paint text, and means a second renderer-driven
            // overlay added later doesn't need to remember to load its own.
            //
            // Each loadFont call pumps events between, so the WM ping stays
            // answered on slow GPUs even though no frame is rendered until
            // the first splash's fade-in loop below.
            $this->loadEngineFonts();

            if ($this->config->studioSplash !== null) {
                self::log('Showing studio splash...');
                $this->showStudioSplash($this->config->studioSplash);
                self::log('Studio splash done');
            }
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

        // Now that game systems are registered, push the persisted Fieldtracing
        // tier into any Renderer3DSystem so it emits the matching SetFieldtracing
        // command each frame (runtime changes are handled by the listener below).
        $this->syncFieldtracingToSystems($this->graphics->settings());

        // Hardware-aware targetFps ceiling: on known throttle-prone Macs
        // (e.g. 2018/2019 15" MBP i9) lower the calibration target so the
        // auto-tuner picks quality tiers that hold up under sustained load.
        // No-op when the hardware has no recommended ceiling, when a
        // thermalHint is already persisted, or when autoThermalManagement
        // is disabled.
        if ($this->config->autoThermalManagement && !$this->headless) {
            $ceiling = $this->hardware->targetFpsCeiling();
            if ($ceiling !== null) {
                $this->graphics->applyInitialCeiling($ceiling, $this->hardware->thermalProfile);
                if ($this->devLogger !== null) {
                    $this->devLogger->logMessage(sprintf(
                        'Hardware ceiling: targetFps capped at %.0f for profile %s',
                        $ceiling,
                        $this->hardware->thermalProfile->value,
                    ));
                }
            }
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
                    $this->tickEditorSync($dt);
                    PerfProfiler::end();
                },
                render: function (float $interpolation) use ($nativeBackend) {
                    PerfProfiler::begin('engine.render');
                    $this->renderInterpolation = $interpolation;
                    $this->beginFrameStats();

                    // Window has no drawable area (minimised / 0x0). Skip all
                    // render work — including the onRender user callback — but
                    // keep input + events pumping so the game resumes cleanly
                    // when the window is restored. See skipRenderFrame().
                    if ($this->isWindowMinimised()) {
                        $this->skipRenderFrame();
                        return;
                    }

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
                    PerfProfiler::begin('render2d.begin');
                    $this->renderer2D->beginFrame();
                    PerfProfiler::end();

                    $this->world->render();

                    if ($this->onRender !== null) {
                        PerfProfiler::begin('render2d.onRender');
                        ($this->onRender)($this, $interpolation);
                        PerfProfiler::end();
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
                    $this->tickEditorSync($dt);
                    PerfProfiler::end();
                },
                render: function (float $interpolation) use ($nativeBackend) {
                    PerfProfiler::begin('engine.render');
                    $this->renderInterpolation = $interpolation;
                    $this->beginFrameStats();

                    // Window has no drawable area (minimised / 0x0). Skip all
                    // render work — including the onRender user callback — but
                    // keep input + events pumping so the game resumes cleanly
                    // when the window is restored. See skipRenderFrame().
                    if ($this->isWindowMinimised()) {
                        $this->skipRenderFrame();
                        return;
                    }

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
                    PerfProfiler::begin('render2d.begin');
                    $this->renderer2D->beginFrame();
                    PerfProfiler::end();

                    $this->world->render();

                    if ($this->onRender !== null) {
                        PerfProfiler::begin('render2d.onRender');
                        ($this->onRender)($this, $interpolation);
                        PerfProfiler::end();
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

    /**
     * True when the window has no drawable area — i.e. it is minimised or
     * otherwise reports a 0×0 size (common when a fullscreen window loses
     * focus on a multi-monitor setup). Rendering against a 0×0 surface is
     * pointless and causes games to divide by a zero width/height in their
     * own layout/hit-test math, so the render frame is skipped entirely
     * while this holds.
     */
    private function isWindowMinimised(): bool
    {
        return $this->window->getWidth() <= 0 || $this->window->getHeight() <= 0;
    }

    /**
     * Frame body for a minimised / 0×0 window: skip every render call
     * (the onRender user callback, the 2D/3D begin/end-frame pair and the
     * buffer swap) but keep input edges and OS events flowing so the game
     * keeps ticking and resumes cleanly the moment the window is restored.
     */
    private function skipRenderFrame(): void
    {
        $this->input->endFrame();
        $this->window->pollEvents();
        $this->endFrameStats();
        PerfProfiler::end(); // closes the 'engine.render' section
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

        // Run thermal monitoring first so any targetFps adjustment is in
        // place before the adaptive quality controller evaluates against
        // the new budget. No-op when autoThermalManagement is disabled.
        $this->thermalMonitor?->tick($elapsedMs);

        // Drive the adaptive quality controller. It is a no-op unless the
        // player has switched to QualityMode::Adaptive.
        $this->adaptiveQuality?->tick($elapsedMs);

        // Re-apply the render cap each frame so a real thermal throttle — or its
        // recovery — takes effect even when targetFps itself didn't move (e.g.
        // the frametime guard had already lowered it). Cheap: a few comparisons
        // + a setFpsCap. Only runs when thermal management is active.
        if ($this->thermalMonitor !== null) {
            $this->applyRenderFpsCap($this->graphics->settings());
        }

        if ($this->devLogger !== null && $this->thermalMonitor !== null) {
            $frametimeSource = self::findFrametimeSource($this->thermalMonitor->sources());
            if ($frametimeSource !== null && $frametimeSource->sampleCount() >= 60) {
                $p95 = $frametimeSource->lastP95Ms();
                $target = $this->graphics->settings()->targetFps;
                $budget = 1000.0 / max(1.0, $target);
                $this->devLogger->logFrameTime($p95, $budget, $target);
            }
        }
    }

    /**
     * Inspect $_SERVER['argv'] for the engine's dev CLI flags. Mirrors the
     * detection in PharBuilder::generateStub so direct `php game.php` runs
     * get the same behaviour as packaged builds.
     *
     * @return array{dev: bool, monitor: bool}
     */
    private static function detectCliDevFlags(): array
    {
        $argv = $_SERVER['argv'] ?? [];
        if (!is_array($argv)) {
            return ['dev' => false, 'monitor' => false];
        }
        $dev = false;
        $monitor = false;
        foreach ($argv as $arg) {
            if (!is_string($arg)) {
                continue;
            }
            if ($arg === '--dev') {
                $dev = true;
            } elseif ($arg === '--dev-monitor' || $arg === '--dev=monitor') {
                $dev = true;
                $monitor = true;
            }
        }
        return ['dev' => $dev, 'monitor' => $monitor];
    }

    /**
     * @param list<\PHPolygon\Rendering\Quality\ThermalSourceInterface> $sources
     */
    private static function findFrametimeSource(array $sources): ?\PHPolygon\Rendering\Quality\ThermalSourceFrametime
    {
        foreach ($sources as $src) {
            if ($src instanceof \PHPolygon\Rendering\Quality\ThermalSourceFrametime) {
                return $src;
            }
        }
        return null;
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
     * Run a render-style callback into a private off-screen target that is
     * never presented to the screen. Intended for splash-phase warm-up work
     * (font atlas pre-rasterisation, sprite texture uploads) where the game
     * wants real `Renderer2D::beginFrame()`/`endFrame()` semantics — including
     * glyph rasterisation and texture upload — without the warm pixels ever
     * appearing on the swapchain.
     *
     * Contract:
     *  - The active `Renderer2D` is switched into off-screen mode for the
     *    duration of the callback. Calls to `beginFrame()`/`endFrame()` inside
     *    `$renderFn` route into a private target sized to the current
     *    framebuffer.
     *  - The off-screen target is released (GC drops the underlying
     *    `VioRenderTarget`) before this method returns, so the next real
     *    frame is unaffected.
     *  - Safe to call repeatedly — every call allocates and releases its own
     *    target.
     *  - Safe to call from inside a generator-based `onInit` (the
     *    cooperative init pattern). The callback runs synchronously within
     *    the current chunk; no `yield` is required.
     *  - On backends without offscreen support (NanoVG/GLFW fallback,
     *    `NullRenderer2D`, GD test renderer) the callback still runs so glyph
     *    paths are exercised, but the offscreen redirect is a no-op. Callers
     *    on those backends accept a brief flash; vio is the shipping path.
     *
     * @param callable $renderFn fn(): void — draws one or more frames using
     *                            `$engine->renderer2D->beginFrame()/endFrame()`.
     *                            Return value is ignored.
     */
    public function warmRender(callable $renderFn): void
    {
        // Size the offscreen target to match the current framebuffer so glyph
        // atlases warmed here are the same resolution the swapchain will use.
        // Window may not be initialised yet in some test paths; fall back to
        // the renderer's logical size in that case.
        $fbW = $this->window->getFramebufferWidth();
        $fbH = $this->window->getFramebufferHeight();
        if ($fbW <= 0 || $fbH <= 0) {
            $fbW = max(1, $this->renderer2D->getWidth());
            $fbH = max(1, $this->renderer2D->getHeight());
        }

        // Flip the renderer into offscreen-redirect mode. On vio, subsequent
        // beginFrame()/endFrame() calls inside $renderFn route into a private
        // VioRenderTarget that is released in endOffscreenFrame(). On other
        // backends this is a no-op and the callback renders against the
        // default surface (a brief flash on GLFW, no effect headless).
        $renderer = $this->renderer2D;
        $renderer->beginOffscreenFrame($fbW, $fbH);

        try {
            $renderFn();
        } finally {
            $renderer->endOffscreenFrame();
        }
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
    /**
     * Apply the render FPS cap. The effective cap is the most restrictive of:
     *
     *   1. The player's explicit `fpsCap` (30 / 60 / 120 / 144), when > 0.
     *   2. The engine's `targetFps` - the soft target the AdaptiveQualityController
     *      and ThermalMonitor steer towards. When the monitor lowers targetFps in
     *      response to thermal pressure, the render rate drops with it; we don't
     *      want to keep burning frames the user can't see.
     *   3. The fixed `targetTickRate`. Rendering faster than the world updates
     *      only re-presents stale (D3D12 flip-model) backbuffers, which flickers
     *      during movement. Games that add render interpolation can opt into
     *      higher rates by setting an explicit `fpsCap`.
     */
    /**
     * Push the active Fieldtracing tier into every registered Renderer3DSystem so
     * it emits a matching SetFieldtracing command each frame. The 3D backends are
     * the authority (they read GraphicsSettings::$fieldtracing directly too), so
     * this only matters for the canonical command-list path; it is a no-op for
     * 2D-only games (no Renderer3DSystem present).
     */
    private function syncFieldtracingToSystems(\PHPolygon\Rendering\GraphicsSettings $settings): void
    {
        foreach ($this->world->getSystems() as $system) {
            if ($system instanceof \PHPolygon\System\Renderer3DSystem) {
                $system->setFieldtracingMode($settings->fieldtracing);
            }
        }
    }

    /**
     * Apply the configured initial display mode to the window. Called during
     * run() right after the window is initialized and before the studio splash
     * draws, so the very first visible frame (the studio splash) is already in
     * the player's chosen mode instead of flashing windowed first.
     *
     * The window is created windowed, so only fullscreen/borderless need an
     * action; an unknown value is treated as windowed and never throws — a
     * corrupted setting must not break engine startup.
     */
    private static function applyDisplayMode(Window $window, string $mode): void
    {
        if ($mode === 'fullscreen') {
            $window->setFullscreen();
        } elseif ($mode === 'borderless') {
            $window->setBorderless();
        }
    }

    private function applyRenderFpsCap(\PHPolygon\Rendering\GraphicsSettings $settings): void
    {
        if ($settings->fpsCap > 0) {
            $cap = (float) $settings->fpsCap;
        } else {
            // fpsCap == 0 means UNCAPPED. Do NOT clamp the render rate to the sim
            // tick rate: with fixed-timestep + interpolation the render SHOULD run
            // faster than the sim ticks (interpolation smooths between them), so a
            // 30 Hz sim must still allow 60+ fps rendering. (targetFps drives
            // adaptive quality, not a hard render ceiling.)
            $cap = 0.0;
        }
        // Real hardware thermal throttle: under genuine heat (a real OS/GPU
        // sensor, NOT the frametime guard) drop the render rate below the
        // player's cap so the device can cool down — restored on recovery.
        $thermalCeiling = $this->realThermalFpsCeiling();
        if ($thermalCeiling > 0.0 && ($cap <= 0.0 || $thermalCeiling < $cap)) {
            $cap = $thermalCeiling;
        }
        $this->gameLoop->setFpsCap($cap > 0.0 ? (int) round(max(1.0, $cap)) : 0);
    }

    /**
     * Render-rate ceiling imposed by a REAL hardware thermal sensor under
     * genuine heat (Serious / Critical). The frametime guard is deliberately
     * excluded: it fires on any slow frame — not actual heat — so it must never
     * pin the player's chosen FPS (it adapts quality instead). 0.0 = no cap.
     */
    private function realThermalFpsCeiling(): float
    {
        if ($this->thermalMonitor === null) {
            return 0.0;
        }
        foreach ($this->thermalMonitor->sources() as $source) {
            if (!$source instanceof ThermalSourceOs) {
                continue;
            }
            return match ($source->lastState()) {
                \PHPolygon\Runtime\ThermalState::Critical => 30.0,
                \PHPolygon\Runtime\ThermalState::Serious  => 45.0,
                default                                   => 0.0,
            };
        }
        return 0.0;
    }

    private function loadEngineFonts(): void
    {
        // Idempotent: the splash path and the run()-boot path both ask for the
        // fonts, but the TTF parse + atlas warm-up must only happen once.
        if ($this->fontsLoaded) return;

        $fontDir = $this->resolveEngineFontDir();
        if ($fontDir === null || !is_dir($fontDir)) return;

        $this->renderer2D->loadFont('regular',  $fontDir . DIRECTORY_SEPARATOR . 'Inter-Regular.ttf');
        $this->window->pollEvents();
        $this->renderer2D->setFont('regular');
        // Mark as loaded the moment 'regular' is registered and selected: that
        // is the minimum a HUD needs to render. If 'semibold' or the CJK fonts
        // throw further down (bad TTF, vio OOM during atlas build), a later
        // retry must not re-register 'regular' a second time.
        $this->fontsLoaded = true;

        $this->renderer2D->loadFont('semibold', $fontDir . DIRECTORY_SEPARATOR . 'Inter-SemiBold.ttf');
        $this->window->pollEvents();

        // CJK fonts: registered here (cheap - vio just stores the path) but
        // the *chain* (addFallbackFont) is set up later by
        // addEngineFontFallbacks() so the very first splash drawText only
        // parses Inter (~300 KB) instead of also parsing NotoSansSC (8 MB)
        // + NotoSansKR (5 MB) - that lazy parse on the first chain-using
        // drawText cost 2.6 s on the splash's first visible frame.
        $cjkDir = $fontDir . DIRECTORY_SEPARATOR . 'noto-sans-cjk';
        if (!is_dir($cjkDir)) return;

        $cjkFaces = [
            'noto-sans-sc' => 'NotoSansSC-Regular.otf',
            'noto-sans-tc' => 'NotoSansTC-Regular.otf',
            'noto-sans-jp' => 'NotoSansJP-Regular.otf',
            'noto-sans-kr' => 'NotoSansKR-Regular.otf',
        ];
        foreach ($cjkFaces as $faceId => $file) {
            $path = $cjkDir . DIRECTORY_SEPARATOR . $file;
            if (!is_file($path)) {
                continue;
            }
            $this->renderer2D->loadFont($faceId, $path);
            $this->window->pollEvents();
        }

        // Arabic + Thai (small static Regulars) — register the names so a game
        // can chain them as fallbacks. Cheap enough to load inline; unlike CJK
        // there is no multi-MB parse to defer.
        $arabicFont = $fontDir . DIRECTORY_SEPARATOR . 'noto-sans-arabic'
            . DIRECTORY_SEPARATOR . 'NotoSansArabic-Regular.ttf';
        if (is_file($arabicFont)) {
            $this->renderer2D->loadFont('noto-sans-arabic', $arabicFont);
            $this->window->pollEvents();
        }
        $thaiFont = $fontDir . DIRECTORY_SEPARATOR . 'noto-sans-thai'
            . DIRECTORY_SEPARATOR . 'NotoSansThai-Regular.ttf';
        if (is_file($thaiFont)) {
            $this->renderer2D->loadFont('noto-sans-thai', $thaiFont);
            $this->window->pollEvents();
        }
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

        // Engine fonts are loaded once in Engine::run() before any splash
        // phase begins (studio + engine), so we don't reload here.

        // Phase 1: Fade in. Engine fonts intentionally have no CJK fallback
        // chain - vio's drawTextWithChain iterates every fallback on every
        // draw to compute max width, which lazy-parses NotoSansSC (8 MB) +
        // NotoSansKR (5 MB) on first chain traversal. Adding the chain even
        // after the first visible frame just deferred that 3 s cost onto
        // frame #2, breaking the fade-in animation.
        //
        // Engine-rendered splash text is always Latin (logo caption, renderer
        // info string). Game-supplied setSplashTasks() labels render glyphs
        // the primary font has; missing chars render as .notdef. Games whose
        // splash needs CJK can call $engine->renderer2D->addFallbackFont()
        // themselves after the splash exits, or pass ASCII task labels.
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

        // Warm the 3D shaders behind the splash. Their compilation is a
        // multi-second synchronous cost that used to run in the VioRenderer3D
        // constructor — i.e. before the very first splash frame, so the window
        // sat blank for several seconds on launch. Deferring it here means the
        // studio + engine splash are already on screen while the driver
        // compiles. renderSplashFrame() paints the current (compiling) label so
        // the last presented frame is the branded splash, not a blank buffer.
        if (!$this->headless && $this->renderer3D instanceof VioRenderer3D) {
            // Hold the narrowed type in a local: renderSplashFrame() below is a
            // method call, after which PHPStan can no longer assume the
            // $this->renderer3D property is still a VioRenderer3D.
            $renderer3D = $this->renderer3D;
            $this->splashFadeAlpha = 1.0;
            $this->splashProgress = 0.4;
            $this->splashLabel = 'Compiling shaders';
            $this->renderSplashFrame();
            $renderer3D->warmShaders();
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

    /**
     * Render an optional studio-branding splash before the engine splash.
     *
     * The engine drives the frame lifecycle (begin/clear/swap/poll) and
     * the skip-input handling; the StudioSplashInterface implementation
     * only paints into the active frame. Ends when the splash reports
     * `getDuration()` has elapsed, when the window is requested to close,
     * or when the user hits ESC / Enter / Space / left-click while the
     * splash reports `isSkippable()` as true.
     *
     * Mirrors the lifecycle of `showSplashScreen()` so the two splashes
     * feel identical to the player - same poll/swap cadence, same input
     * handling, same window-close response. We do NOT call onInit during
     * this phase: it stays on `showSplashScreen()` so games keep using
     * the engine's task-checklist progress UI for actual init work.
     */
    private function showStudioSplash(StudioSplashInterface $splash): void
    {
        $duration = max(0.0, $splash->getDuration());
        if ($duration <= 0.0) {
            return;
        }

        $black = new Color(0.0, 0.0, 0.0);
        $start = microtime(true);

        // Drop any input edges that fired during loadEngineFonts() (each
        // loadFont pumps events). Without this a player who hits ESC while
        // the window opens would skip the splash before isSkippable() ever
        // gets a chance to gate it.
        $this->input->clearKeyEdges();

        while (!$this->window->shouldClose()) {
            $elapsed = (float)(microtime(true) - $start);
            if ($elapsed >= $duration) {
                break;
            }

            // Skip-input: only honoured once the splash reports it's in a
            // skippable phase. Lets implementations protect a short "sting"
            // at the start of the animation from being killed by a stray
            // keystroke.
            if ($splash->isSkippable($elapsed)) {
                if ($this->input->isKeyPressed(256)   // ESC
                    || $this->input->isKeyPressed(257) // Enter
                    || $this->input->isKeyPressed(32)  // Space
                    || $this->input->isMouseButtonPressed(0)
                ) {
                    break;
                }
            }

            // Mirror the game-loop pattern: snapshot vio scroll before
            // beginFrame so scroll deltas survive the frame swap.
            if ($this->input instanceof \PHPolygon\Runtime\VioInput) {
                $this->input->snapshotScroll();
            }

            $this->renderer2D->beginFrame();
            $this->renderer2D->clear($black);
            $splash->render($this->renderer2D, $elapsed);
            $this->renderer2D->endFrame();

            $this->window->swapBuffers();
            $this->input->endFrame();
            $this->window->pollEvents();
        }
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
                $this->renderer3D instanceof OpenGLRenderer3D => 'OpenGL 3D ('
                    . $this->renderer3D->capabilities()->major . '.'
                    . $this->renderer3D->capabilities()->minor . ')',
                default => null,
            };
        }
        return implode(' · ', array_filter($parts));
    }

    /**
     * Capabilities of the active standalone OpenGL context (version + feature
     * tier), or null when the 3D backend is not the standalone GL renderer
     * (Vio / Vulkan / Metal / headless). Games can use this to gate features
     * that only exist on newer GL contexts.
     */
    public function glFeatureTier(): ?\PHPolygon\Rendering\GlCapabilities
    {
        if ($this->renderer3D instanceof OpenGLRenderer3D) {
            return $this->renderer3D->capabilities();
        }
        return null;
    }

    private function getVioBackendName(): string
    {
        if ($this->window instanceof VioWindow && function_exists('vio_backend_name')) {
            return vio_backend_name($this->window->getContext());
        }
        return 'unknown';
    }

    /**
     * Per-backend rendering conventions (depth range, render-target Y origin,
     * shader source format) for the active GPU backend. Use this instead of
     * hand-rolling `vio_backend_name(...) === 'opengl'` checks so a single place
     * owns each convention. See {@see \PHPolygon\Rendering\BackendConventions}.
     */
    public function backendConventions(): \PHPolygon\Rendering\BackendConventions
    {
        return \PHPolygon\Rendering\BackendConventions::forBackend($this->getVioBackendName());
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
