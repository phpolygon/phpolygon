<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

/**
 * Opt-in check for programs that want the newer D3D12 shader compiler (shader model 6, DXC,
 * hundreds of texture units): D3D12 silently falls back to FXC (shader model 5.1, 16 units)
 * when shader_model 6 was not requested or dxcompiler.dll / dxil.dll cannot be loaded. The
 * engine does not enforce anything; a program that depends on SM 6 calls {@see require()} after
 * the window / context exists and fails loudly instead of running with features missing.
 */
final class ShaderModelCheck
{
    /**
     * The shader model the context compiles with: 6 (DXC), 5 (FXC), or null when the backend
     * is not D3D12 or php-vio does not report it.
     */
    public static function detect(\VioContext $ctx): ?int
    {
        if (!function_exists('vio_swapchain_info') || vio_backend_name($ctx) !== 'd3d12') {
            return null;
        }
        $model = vio_swapchain_info($ctx)['shader_model'];

        return $model > 0 ? $model : null;
    }

    /**
     * Throws when a D3D12 context did not get shader model 6. Other backends (and a php-vio that
     * does not report the model) pass: they have no FXC fallback to detect.
     *
     * @throws \RuntimeException
     */
    public static function require(\VioContext $ctx): void
    {
        $model = self::detect($ctx);
        if ($model !== null && $model < 6) {
            throw new \RuntimeException(
                "Direct3D 12 compiles with shader model {$model} (FXC, 16 texture units), not 6: "
                . 'request shader_model 6 (EngineConfig::$vioShaderModel) and put dxcompiler.dll and dxil.dll next to the executable or in dxc_dir.',
            );
        }
    }
}
