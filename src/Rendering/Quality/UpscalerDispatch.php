<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Quality;

/**
 * One frame's parameters for a native temporal upscaler (FSR 3, DLSS through
 * php-vio's vio_upscaler_dispatch), translated from the engine's conventions:
 *
 *   jitter   engine: {@see JitteredProjection::$pixelX}/$pixelY in render pixels,
 *            NDC orientation (+Y up). vio: render pixels with x right and y DOWN
 *            (image rows) - so Y is negated, on every backend: the jitter is a
 *            camera offset, not a texture coordinate.
 *   motion   engine: the motion attachment holds prevUv - uv in the scene
 *            target's texture space (v follows the target's row order,
 *            {@see \PHPolygon\Rendering\BackendConventions::flipRenderTargetClipY()}).
 *            vio: previous - current position in render pixels, x right, y down,
 *            times mv_scale. mv_scale is therefore the render size, with the
 *            Y component negated on a target whose rows run bottom-up.
 *            The vectors are unjittered (jittered_motion = false at creation).
 *   camera   near / far / vertical field of view from the UNJITTERED
 *            projection (perspective only; an orthographic camera leaves them
 *            out). The depth is neither inverted nor infinite.
 *
 * Pure data and math, no GPU calls.
 */
final class UpscalerDispatch
{
    /** php-vio VIO_UPSCALER_FSR3 / VIO_UPSCALER_DLSS. */
    public const PROVIDER_FSR3 = 1;
    public const PROVIDER_DLSS = 2;

    /**
     * @param array{0: float, 1: float} $jitter      render pixels, x right / y down
     * @param array{0: float, 1: float} $motionScale texture-space motion -> render pixels
     */
    public function __construct(
        public readonly array $jitter,
        public readonly array $motionScale,
        public readonly bool $reset,
        public readonly float $sharpness,
        public readonly float $frameTimeMs,
        public readonly int $renderWidth,
        public readonly int $renderHeight,
        public readonly ?float $near = null,
        public readonly ?float $far = null,
        public readonly ?float $fovY = null,
    ) {}

    /**
     * @param bool  $renderTargetYDown row 0 of the scene target is the top
     *                                 (D3D, Metal, Vulkan on php-vio)
     * @param float $sharpness         0..1, the provider's own sharpening (FSR; DLSS has none)
     */
    public static function fromFrame(
        TemporalFrame $frame,
        bool $renderTargetYDown,
        float $sharpness,
        float $frameTimeMs,
    ): self {
        $renderW = max(1, $frame->renderWidth);
        $renderH = max(1, $frame->renderHeight);
        [$near, $far, $fovY] = self::cameraData($frame->jitter->unjitteredProjection->toArray());
        return new self(
            jitter: [$frame->jitter->pixelX, -$frame->jitter->pixelY],
            motionScale: [(float) $renderW, $renderTargetYDown ? (float) $renderH : -(float) $renderH],
            reset: !$frame->historyValid,
            sharpness: max(0.0, min(1.0, $sharpness)),
            frameTimeMs: $frameTimeMs > 0.0 ? $frameTimeMs : 1000.0 / 60.0,
            renderWidth: $renderW,
            renderHeight: $renderH,
            near: $near,
            far: $far,
            fovY: $fovY,
        );
    }

    /**
     * The non-image inputs of vio_upscaler_dispatch; the caller adds color,
     * depth, motion, reactive and output.
     *
     * @return array<string, mixed>
     */
    public function toInputs(): array
    {
        $inputs = [
            'jitter' => $this->jitter,
            'mv_scale' => $this->motionScale,
            'reset' => $this->reset,
            'sharpness' => $this->sharpness,
            'frame_time_ms' => $this->frameTimeMs,
            'render_width' => $this->renderWidth,
            'render_height' => $this->renderHeight,
        ];
        if ($this->near !== null && $this->far !== null && $this->fovY !== null) {
            $inputs['near'] = $this->near;
            $inputs['far'] = $this->far;
            $inputs['fov_y'] = $this->fovY;
        }
        return $inputs;
    }

    /** The php-vio provider id of a native upscaler, null for the engine's own ones. */
    public static function providerId(Upscaler $upscaler): ?int
    {
        return match ($upscaler) {
            Upscaler::Fsr3 => self::PROVIDER_FSR3,
            Upscaler::Dlss => self::PROVIDER_DLSS,
            default => null,
        };
    }

    /**
     * The php-vio quality mode (VIO_UPSCALE_NATIVE_AA = 0 .. ULTRA_PERFORMANCE
     * = 4) of a preset; Custom takes the mode whose ratio is nearest the
     * render scale the slider set.
     */
    public static function qualityMode(UpscaleQuality $quality, float $renderScale): int
    {
        $modes = [
            0 => UpscaleQuality::NativeAa,
            1 => UpscaleQuality::Quality,
            2 => UpscaleQuality::Balanced,
            3 => UpscaleQuality::Performance,
            4 => UpscaleQuality::UltraPerformance,
        ];
        $direct = array_search($quality, $modes, true);
        if (is_int($direct)) {
            return $direct;
        }
        $best = 1;
        $bestDistance = INF;
        foreach ($modes as $mode => $preset) {
            $distance = abs((float) $preset->renderScale() - $renderScale);
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $mode;
            }
        }
        return $best;
    }

    /**
     * near, far and vertical FOV of a perspective projection in the engine's
     * layout ({@see \PHPolygon\Math\Mat4::perspective()}: column-major,
     * clip.w = -z_view); nulls for anything else.
     *
     * @param array<int, float> $m
     * @return array{0: ?float, 1: ?float, 2: ?float}
     */
    private static function cameraData(array $m): array
    {
        if (abs($m[11] + 1.0) > 1e-6 || abs($m[15]) > 1e-6 || $m[5] <= 0.0) {
            return [null, null, null];
        }
        $a = $m[10];
        $b = $m[14];
        if (abs($a - 1.0) < 1e-9 || abs($a + 1.0) < 1e-9) {
            return [null, null, null];
        }
        $near = $b / ($a - 1.0);
        $far = $b / ($a + 1.0);
        if (!($near > 0.0) || !($far > $near)) {
            return [null, null, null];
        }
        return [$near, $far, 2.0 * atan(1.0 / $m[5])];
    }
}
