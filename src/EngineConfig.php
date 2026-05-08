<?php

declare(strict_types=1);

namespace PHPolygon;

use PHPolygon\Runtime\InputInterface;
use PHPolygon\Thread\ThreadingMode;

class EngineConfig
{
    public function __construct(
        public readonly string $title = 'PHPolygon',
        public readonly int $width = 1280,
        public readonly int $height = 720,
        public readonly bool $vsync = true,
        public readonly bool $resizable = true,
        public readonly float $targetTickRate = 60.0,
        public readonly string $assetsPath = '',
        public readonly string $defaultLocale = 'en',
        public readonly string $fallbackLocale = 'en',
        public readonly string $savePath = 'saves',
        public readonly int $maxSaveSlots = 10,
        public readonly bool $headless = false,
        public readonly bool $is3D = false,
        public readonly string $renderBackend3D = 'opengl',
        public readonly ?ThreadingMode $threadingMode = null,
        public readonly ?InputInterface $input = null,
        public readonly string $meshCachePath = '',
        public readonly bool $skipSplash = false,
        public readonly float $splashDuration = 2.5,
        public readonly string $vioBackend = 'auto',
        /**
         * When true and ext-vio is loaded, the 3D pipeline still runs through
         * a native renderer (MetalRenderer3D / VulkanRenderer3D / OpenGLRenderer3D)
         * keyed by $renderBackend3D, while vio continues to own the window and
         * 2D pipeline. Useful on macOS where vio's Metal 3D pipeline isn't
         * complete yet but php-metal's native renderer is.
         */
        public readonly bool $useNative3D = false,
        /**
         * Enables developer-only features such as the F3 performance overlay
         * and PerfProfiler hook points. Must be false for shipping builds.
         * Independent from the SPX/Excimer extensions, which are activated
         * via env vars and do not require this flag.
         */
        public readonly bool $devMode = false,
        /**
         * When true and no graphics.json is found at $graphicsSettingsPath
         * the engine runs the GraphicsAutoTuner against $benchmarkScene before
         * the first user frame and writes the chosen settings to disk.
         */
        public readonly bool $firstLaunchCalibration = true,
        /**
         * Filesystem path used by GraphicsSettingsManager to persist player
         * graphics preferences. Relative paths are resolved against the CWD
         * at engine startup; absolute paths are honoured verbatim.
         */
        public readonly string $graphicsSettingsPath = 'saves/graphics.json',
        /**
         * Fully-qualified class name of the Scene used by the auto-tuner for
         * first-launch calibration and the recalibrate-now button. When null
         * the engine falls back to its built-in BenchmarkScene.
         *
         * @var class-string<\PHPolygon\Scene\Scene>|null
         */
        public readonly ?string $benchmarkScene = null,
    ) {}
}
