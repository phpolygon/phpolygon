<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

/**
 * A 3D renderer that accepts all commands but produces no output.
 * Used for headless mode: testing, validation, CI pipelines.
 * Stores the last command list for test assertions.
 */
class NullRenderer3D implements Renderer3DInterface
{
    private int $width;
    private int $height;
    private ?RenderCommandList $lastCommandList = null;

    public function __construct(int $width = 1280, int $height = 720)
    {
        $this->width = $width;
        $this->height = $height;
    }

    public function beginFrame(): void {}
    public function endFrame(): void {}
    public function clear(Color $color): void {}

    public function setViewport(int $x, int $y, int $width, int $height): void
    {
        $this->width = $width;
        $this->height = $height;
    }

    public function getWidth(): int { return $this->width; }
    public function getHeight(): int { return $this->height; }

    public function render(RenderCommandList $commandList): void
    {
        // Store a snapshot so test assertions survive the post-render clear()
        $snapshot = new RenderCommandList();
        foreach ($commandList->getCommands() as $cmd) {
            $snapshot->add($cmd);
        }
        $this->lastCommandList = $snapshot;
    }

    public function getLastCommandList(): ?RenderCommandList
    {
        return $this->lastCommandList;
    }

    private ?GraphicsSettings $lastAppliedSettings = null;
    private int $applySettingsCallCount = 0;
    private int $offscreenResizeCount = 0;
    private int $offscreenWidth = 0;
    private int $offscreenHeight = 0;
    private int $offscreenSamples = 1;

    public function applySettings(GraphicsSettings $settings): void
    {
        $this->lastAppliedSettings = $settings;
        $this->applySettingsCallCount++;

        // Mirror the OpenGL backend's offscreen-target sizing logic so tests
        // can verify Phase 1.5 wiring without a real GPU. The math here matches
        // OpenGLRenderer3D::beginOffscreenIfRequired().
        $needsOffscreen = $settings->renderScale !== 1.0
            || $settings->antiAliasing !== \PHPolygon\Rendering\Quality\AntiAliasing::Off;

        if ($needsOffscreen) {
            $newW = max(1, (int)round($this->width  * $settings->renderScale));
            $newH = max(1, (int)round($this->height * $settings->renderScale));
            $newSamples = max(1, $settings->antiAliasing->sampleCount());

            if ($newW !== $this->offscreenWidth
                || $newH !== $this->offscreenHeight
                || $newSamples !== $this->offscreenSamples) {
                $this->offscreenResizeCount++;
                $this->offscreenWidth   = $newW;
                $this->offscreenHeight  = $newH;
                $this->offscreenSamples = $newSamples;
            }
        }
    }

    public function getLastAppliedSettings(): ?GraphicsSettings
    {
        return $this->lastAppliedSettings;
    }

    public function getApplySettingsCallCount(): int
    {
        return $this->applySettingsCallCount;
    }

    /** Number of times applySettings() triggered an off-screen target rebuild. */
    public function getOffscreenResizeCount(): int
    {
        return $this->offscreenResizeCount;
    }

    /** @return array{0: int, 1: int, 2: int} [width, height, samples] of last offscreen alloc, or [0, 0, 1] when never allocated. */
    public function getOffscreenSize(): array
    {
        return [$this->offscreenWidth, $this->offscreenHeight, $this->offscreenSamples];
    }
}
