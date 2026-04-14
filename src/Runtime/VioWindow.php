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

    public function __construct(
        int $width,
        int $height,
        string $title,
        bool $vsync = true,
        bool $resizable = true,
        string $backend = 'auto',
    ) {
        parent::__construct($width, $height, $title, $vsync, $resizable);
        $this->backend = $backend;
    }

    public function initialize(InputInterface $input): void
    {
        $ctx = vio_create($this->backend, [
            'width' => $this->width,
            'height' => $this->height,
            'title' => $this->title,
            'vsync' => true,
            'samples' => 4,
        ]);

        if ($ctx === false) {
            throw new \RuntimeException('Failed to create VIO context');
        }

        $this->ctx = $ctx;
        $this->initialized = true;

        if ($input instanceof VioInput) {
            $input->setContext($ctx);
        }
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

    public function setCursorDisabled(): void
    {
        // No cursor API in vio yet — no-op
    }

    public function setCursorNormal(): void
    {
        // No cursor API in vio yet — no-op
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
