<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\PostProcess;

use PHPolygon\Rendering\Quality\UpscalerDispatch;
use VioContext;
use VioRenderTarget;
use VioTexture;

/**
 * A native temporal upscaler (FSR 3.1 / DLSS through php-vio's vio_upscaler_*)
 * and its display-size output target.
 *
 * {@see sync()} runs at the frame boundary: it keeps the upscaler while the
 * provider, quality, render / display size and colour format stay, otherwise
 * destroys it (vio waits for the GPU work that still uses it) and creates the
 * new one. {@see dispatch()} records the upscale on the open frame. Both report
 * a failure through {@see lastError()} instead of warning; the renderer decides
 * what to do about it.
 *
 * PHPOLYGON_VIO_UPSCALER_FAIL=create|dispatch makes the respective call fail
 * (tests and diagnostics of the fallback).
 */
final class VioNativeUpscalerPass
{
    private ?\VioUpscaler $upscaler = null;
    private ?VioRenderTarget $output = null;
    private ?VioTexture $outputTexture = null;
    private string $key = '';
    private int $provider = 0;
    private int $jitterPhases = 0;
    private string $lastError = '';

    public function __construct(private readonly VioContext $ctx)
    {
    }

    /**
     * Make the upscaler match. Returns false (see lastError()) when it cannot
     * be created; nothing is kept then.
     */
    public function sync(
        int $provider,
        int $quality,
        int $renderW,
        int $renderH,
        int $displayW,
        int $displayH,
        bool $hdr,
    ): bool {
        $key = "$provider:$quality:{$renderW}x{$renderH}:{$displayW}x{$displayH}:" . ($hdr ? 'hdr' : 'ldr');
        if ($key === $this->key && $this->upscaler !== null) {
            return true;
        }
        $this->release();
        $this->lastError = '';

        if (getenv('PHPOLYGON_VIO_UPSCALER_FAIL') === 'create') {
            $this->lastError = 'vio_upscaler_create failed (PHPOLYGON_VIO_UPSCALER_FAIL=create)';
            return false;
        }
        $upscaler = $this->quietly(fn() => vio_upscaler_create($this->ctx, [
            'provider' => $provider,
            'quality' => $quality,
            'display_width' => $displayW,
            'display_height' => $displayH,
            'render_width' => $renderW,
            'render_height' => $renderH,
            'hdr' => $hdr,
            // The engine's depth: 0 = near, finite far plane; its motion
            // vectors are unjittered.
            'depth_inverted' => false,
            'depth_infinite' => false,
            'jittered_motion' => false,
            'auto_exposure' => $hdr,
        ]));
        if (!$upscaler instanceof \VioUpscaler) {
            return false;
        }
        // Display-size colour the provider writes as a storage image.
        $output = $this->quietly(fn() => vio_render_target($this->ctx, [
            'width' => $displayW,
            'height' => $displayH,
            'attachments' => [VIO_FORMAT_RGBA16F],
            'storage' => true,
        ]));
        if (!$output instanceof VioRenderTarget) {
            vio_upscaler_destroy($upscaler);
            $this->lastError = $this->lastError !== '' ? $this->lastError : 'the storage output target could not be created';
            return false;
        }
        $info = vio_upscaler_info($this->ctx, $upscaler);
        $this->upscaler = $upscaler;
        $this->output = $output;
        $this->outputTexture = vio_render_target_texture($output);
        $this->key = $key;
        $this->provider = $provider;
        $phases = $info['jitter_phases'] ?? 0;
        $this->jitterPhases = max(1, is_int($phases) ? $phases : 0);
        return true;
    }

    /**
     * Upscale this frame into the output target; null on failure (lastError()).
     * $scene is the render-size colour, $mrt the scene's MRT target whose depth,
     * motion and reactive attachments go along. Leaves the bound target and
     * pipeline as they were.
     */
    public function dispatch(
        VioRenderTarget $scene,
        VioRenderTarget $mrt,
        int $motionAttachment,
        int $reactiveAttachment,
        UpscalerDispatch $frame,
    ): ?VioTexture {
        $upscaler = $this->upscaler;
        if ($upscaler === null || $this->output === null) {
            $this->lastError = 'no upscaler';
            return null;
        }
        $this->lastError = '';
        if (getenv('PHPOLYGON_VIO_UPSCALER_FAIL') === 'dispatch') {
            $this->lastError = 'vio_upscaler_dispatch failed (PHPOLYGON_VIO_UPSCALER_FAIL=dispatch)';
            return null;
        }
        $inputs = $frame->toInputs() + [
            'color' => $scene,
            'depth' => [$mrt, (int) constant('VIO_RT_DEPTH')],
            'motion' => [$mrt, $motionAttachment],
            'reactive' => [$mrt, $reactiveAttachment],
            'output' => $this->output,
        ];
        $ok = $this->quietly(fn() => vio_upscaler_dispatch($this->ctx, $upscaler, $inputs));
        if ($ok !== true) {
            $this->lastError = $this->lastError !== '' ? $this->lastError : 'vio_upscaler_dispatch returned false';
            return null;
        }
        return $this->outputTexture;
    }

    public function active(): bool
    {
        return $this->upscaler !== null;
    }

    /** The provider id of the live upscaler, 0 without one. */
    public function provider(): int
    {
        return $this->upscaler !== null ? $this->provider : 0;
    }

    /** The provider's jitter cycle length, null without an upscaler. */
    public function jitterPhases(): ?int
    {
        return $this->upscaler !== null ? $this->jitterPhases : null;
    }

    public function outputTarget(): ?VioRenderTarget
    {
        return $this->output;
    }

    public function lastError(): string
    {
        return $this->lastError;
    }

    /** Destroy the upscaler now (php-vio waits for the GPU work that uses it). */
    public function release(): void
    {
        if ($this->upscaler !== null) {
            vio_upscaler_destroy($this->upscaler);
        }
        $this->upscaler = null;
        $this->output = null;
        $this->outputTexture = null;
        $this->key = '';
        $this->jitterPhases = 0;
    }

    /**
     * Run a vio call that warns on failure; the warning becomes lastError().
     *
     * @phpstan-impure
     * @template T
     * @param callable(): T $call
     * @return T
     */
    private function quietly(callable $call): mixed
    {
        set_error_handler(function (int $errno, string $message): bool {
            $this->lastError = $message;
            return true;
        });
        try {
            return $call();
        } finally {
            restore_error_handler();
        }
    }
}
