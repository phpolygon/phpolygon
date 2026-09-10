<?php

declare(strict_types=1);

namespace PHPolygon\Runtime;

use VioContext;

class VioWindow extends Window
{
    private VioContext $ctx;
    private bool $initialized = false;
    private bool $vioFullscreen = false;
    private bool $vioBorderless = false;
    private string $backend;
    private bool $debug;

    public function __construct(
        int $width,
        int $height,
        string $title,
        bool $vsync = true,
        bool $resizable = true,
        string $backend = 'auto',
        bool $debug = false,
        string $shaderCachePath = '',
        int $frameLatency = 0,
        bool $hdrOutput = false,
        float $hdrPaperWhite = 200.0,
        int $shaderModel = 0,
        string $dxcDir = '',
    ) {
        parent::__construct($width, $height, $title, $vsync, $resizable);
        $this->backend = $backend;
        $this->debug = $debug;
        $this->shaderCachePath = $shaderCachePath;
        $this->frameLatency = $frameLatency;
        $this->hdrOutput = $hdrOutput;
        $this->hdrPaperWhite = $hdrPaperWhite;
        $this->shaderModel = $shaderModel;
        $this->dxcDir = $dxcDir;
    }

    /** vio_create 'shader_model' / 'dxc_dir' (EngineConfig::$vioShaderModel). */
    private int $shaderModel = 0;
    private string $dxcDir = '';

    /** vio_create 'hdr_output' / 'hdr_paper_white' (GraphicsSettings::$hdrOutput). */
    private bool $hdrOutput = false;
    private float $hdrPaperWhite = 200.0;

    /** vio_create 'frame_latency' (0 = driver default; 1 = GraphicsSettings::$lowLatency). */
    private int $frameLatency = 0;

    /** Directory handed to vio_create as 'shader_cache' ('' = no cache). */
    private string $shaderCachePath = '';

    public function initialize(InputInterface $input): void
    {
        \PHPolygon\Engine::log('VioWindow::initialize() backend=' . $this->backend . ' size=' . $this->width . 'x' . $this->height);

        $config = [
            'width'   => $this->width,
            'height'  => $this->height,
            'title'   => $this->title,
            'vsync'   => $this->vsync,
            'samples' => 4,
            // Only request the graphics-API debug/validation layer in dev mode
            // (--dev / --dev-monitor or EngineConfig::$devMode). In shipping
            // builds the D3D12/Vulkan validation layer adds overhead and spams
            // the log with benign hints; Engine threads $effectiveDevMode here.
            'debug'   => $this->debug ? 1 : 0,
        ];
        // On-disk shader / pipeline cache (EngineConfig::$shaderCachePath). A
        // directory that cannot be created just leaves the cache off.
        if ($this->shaderCachePath !== '') {
            if (is_dir($this->shaderCachePath) || @mkdir($this->shaderCachePath, 0755, true)) {
                $config['shader_cache'] = $this->shaderCachePath;
            }
        }
        // Waitable swapchain (php-vio >= 2.15, D3D11 / D3D12): cap the CPU's
        // run-ahead. Backends without the feature ignore the option.
        if ($this->frameLatency > 0) {
            $config['frame_latency'] = $this->frameLatency;
        }
        // HDR10 output (php-vio >= 2.15, D3D11 / D3D12): a 10-bit ST 2084
        // backbuffer when the display is in HDR mode; vio's 2D batch PQ-encodes
        // itself, the 3D resolve reads vio_swapchain_info() and does the same.
        if ($this->hdrOutput) {
            $config['hdr_output'] = 1;
            $config['hdr_paper_white'] = $this->hdrPaperWhite;
        }
        // D3D12 shader model (php-vio >= 2.16): 6 = DXC / DXIL, honest fallback to FXC.
        if ($this->shaderModel > 0) {
            $config['shader_model'] = $this->shaderModel;
            if ($this->dxcDir !== '') {
                $config['dxc_dir'] = $this->dxcDir;
            }
        }

        // Try the requested backend first; on failure, walk a platform-aware
        // fallback list. On Linux a missing Vulkan loader / driver makes the
        // 'auto' picker return false and we'd otherwise leave the user with
        // a hard "Failed to create Vulkan instance" without trying OpenGL.
        $candidates = self::backendCandidates($this->backend);
        $ctx = false;
        $chosen = '';
        foreach ($candidates as $backend) {
            \PHPolygon\Engine::log('VioWindow: trying vio_create backend=' . $backend);
            $ctx = vio_create($backend, $config);
            if ($ctx !== false) {
                $chosen = $backend;
                break;
            }
            \PHPolygon\Engine::log('VioWindow: backend=' . $backend . ' returned false, trying next');
        }

        if ($ctx === false) {
            throw new \RuntimeException(
                'Failed to create VIO context. Tried backends: ' . implode(', ', $candidates)
                . '. Check that your GPU driver supports at least one of them '
                . '(OpenGL 3.3+ or Vulkan 1.1+ on Linux).'
            );
        }

        $this->ctx = $ctx;
        $this->backend = $chosen;
        $this->initialized = true;
        \PHPolygon\Engine::log('VioWindow: actual backend=' . vio_backend_name($ctx));

        if ($input instanceof VioInput) {
            $input->setContext($ctx);
        }
    }

    /**
     * Ordered list of backends to try when initialising. The caller-supplied
     * backend always comes first, followed by platform-appropriate fallbacks
     * that don't share the failure mode of the primary (e.g. OpenGL after a
     * Vulkan-instance creation failure on Linux).
     *
     * @return list<string>
     */
    private static function backendCandidates(string $primary): array
    {
        $fallbacks = match (PHP_OS_FAMILY) {
            'Windows' => ['d3d11', 'opengl'],
            'Darwin'  => ['metal', 'opengl'],
            'Linux'   => ['opengl', 'vulkan'],
            default   => ['opengl'],
        };
        return array_values(array_unique(array_merge([$primary], $fallbacks)));
    }

    public function shouldClose(): bool
    {
        return vio_should_close($this->ctx);
    }

    public function requestClose(): void
    {
        vio_close($this->ctx);
    }

    public function pollEvents(): void
    {
        vio_poll_events($this->ctx);
    }

    public function swapBuffers(): void
    {
        // vio_end() handles present - called by VioRenderer2D::endFrame()
    }

    public function getWidth(): int
    {
        if (!$this->initialized) {
            return $this->width;
        }
        return vio_window_size($this->ctx)[0];
    }

    public function getHeight(): int
    {
        if (!$this->initialized) {
            return $this->height;
        }
        return vio_window_size($this->ctx)[1];
    }

    public function getFramebufferWidth(): int
    {
        if (!$this->initialized) {
            return $this->width;
        }
        return vio_framebuffer_size($this->ctx)[0];
    }

    public function getFramebufferHeight(): int
    {
        if (!$this->initialized) {
            return $this->height;
        }
        return vio_framebuffer_size($this->ctx)[1];
    }

    public function getContentScaleX(): float
    {
        if (!$this->initialized) {
            return 1.0;
        }
        return vio_content_scale($this->ctx)[0];
    }

    public function getContentScaleY(): float
    {
        if (!$this->initialized) {
            return 1.0;
        }
        return vio_content_scale($this->ctx)[1];
    }

    public function getPixelRatio(): float
    {
        if (!$this->initialized) {
            return 1.0;
        }
        return vio_pixel_ratio($this->ctx);
    }

    public function getHandle(): object
    {
        return $this->ctx;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
        if ($this->initialized) {
            vio_set_title($this->ctx, $title);
        }
    }

    public function setFullscreen(): void
    {
        if ($this->vioFullscreen) {
            return;
        }
        vio_set_fullscreen($this->ctx);
        $this->vioFullscreen = true;
        $this->vioBorderless = false;
    }

    public function setBorderless(): void
    {
        if ($this->vioBorderless) {
            return;
        }
        vio_set_borderless($this->ctx);
        $this->vioBorderless = true;
        $this->vioFullscreen = false;
    }

    public function setWindowed(): void
    {
        if (!$this->vioFullscreen && !$this->vioBorderless) {
            return;
        }
        vio_set_windowed($this->ctx);
        $this->vioFullscreen = false;
        $this->vioBorderless = false;
    }

    public function setSize(int $width, int $height): void
    {
        if (!$this->initialized) {
            $this->width = $width;
            $this->height = $height;
            return;
        }
        vio_set_window_size($this->ctx, $width, $height);
    }

    public function toggleFullscreen(): void
    {
        if ($this->vioFullscreen) {
            $this->setWindowed();
        } else {
            $this->setFullscreen();
        }
    }

    public function isFullscreen(): bool
    {
        return $this->vioFullscreen;
    }

    public function isBorderless(): bool
    {
        return $this->vioBorderless;
    }

    public function getContext(): VioContext
    {
        return $this->ctx;
    }

    /**
     * Toggle vsync. Vio exposes vio_set_vsync(); when not present we fall
     * through to the GLFW-based parent implementation, which is a no-op
     * for vio-managed windows.
     */
    public function setVsync(bool $vsync): void
    {
        $this->vsync = $vsync;
        if ($this->initialized && function_exists('vio_set_vsync')) {
            try {
                @vio_set_vsync($this->ctx, $vsync);
            } catch (\Throwable $e) {
                // Older vio builds may lack the helper.
            }
        }
    }

    public function setCursorDisabled(): void
    {
        vio_set_cursor_mode($this->ctx, VIO_CURSOR_DISABLED);
    }

    public function setCursorNormal(): void
    {
        vio_set_cursor_mode($this->ctx, VIO_CURSOR_NORMAL);
    }

    public function destroy(): void
    {
        if ($this->initialized) {
            vio_destroy($this->ctx);
            $this->initialized = false;
        }
    }

    public function __destruct()
    {
        $this->destroy();
    }

}
