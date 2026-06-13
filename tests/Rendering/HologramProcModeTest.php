<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPolygon\Rendering\MetalRenderer3D;
use PHPolygon\Rendering\OpenGLRenderer3D;
use PHPolygon\Rendering\VioRenderer3D;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Locks the "unlit hologram" material -> proc_mode mapping and its presence
 * in every backend shader source.
 *
 * Code Rescue's learning holograms (HologramBoardPrefab) bake their text into
 * an albedo texture. Rendered through the normal PBR path the panel is lit by
 * the scene sun/ambient and the text contrast washes out in bright daylight.
 * proc_mode 12 renders the panel UNLIT (texture/albedo emitted directly, no
 * lighting/fog), so the text reads identically day and night.
 *
 * The mapping is keyed by the material-id prefix 'hologram_text' and is
 * duplicated across the three backend renderers' private resolveProcMode().
 * This test asserts all three agree, and that the shader branch exists in all
 * three sources, so the backends can't silently drift apart.
 */
final class HologramProcModeTest extends TestCase
{
    private const VIO_FRAG   = __DIR__ . '/../../resources/shaders/source/vio/mesh3d.frag.glsl';
    private const GLSL_FRAG  = __DIR__ . '/../../resources/shaders/source/mesh3d.frag.glsl';
    private const METAL_FRAG = __DIR__ . '/../../resources/shaders/source/mesh3d.metal';

    private const HOLOGRAM_PROC_MODE = 12;

    /**
     * @return array<string, class-string>
     */
    public static function rendererProvider(): array
    {
        return [
            'vio (D3D12/Vulkan/Metal active path)' => [VioRenderer3D::class],
            'opengl'                               => [OpenGLRenderer3D::class],
            'metal'                                => [MetalRenderer3D::class],
        ];
    }

    /**
     * @dataProvider rendererProvider
     * @param class-string $rendererClass
     */
    public function testHologramTextMaterialResolvesToUnlitProcMode(string $rendererClass): void
    {
        // resolveProcMode() is pure string logic + a static cache; it does not
        // touch the (vio/GL/Metal) context, so we can call it on an instance
        // created without running the backend constructor.
        $renderer = (new \ReflectionClass($rendererClass))->newInstanceWithoutConstructor();
        $resolve  = new ReflectionMethod($rendererClass, 'resolveProcMode');
        $resolve->setAccessible(true);

        // Real ids produced by HologramBoardPrefab::ensureTextMaterial():
        //   'hologram_text_<topic>_<locale>'
        foreach ([
            'hologram_text_variable_de',
            'hologram_text_loop_en',
            'hologram_text_function_de',
        ] as $materialId) {
            self::assertSame(
                self::HOLOGRAM_PROC_MODE,
                $resolve->invoke($renderer, $materialId),
                "{$rendererClass}: '{$materialId}' must map to the unlit hologram proc_mode"
            );
        }

        // The plain text-less fallback pane must NOT be unlit (it keeps its
        // emissive PBR look — proc_mode 0).
        self::assertSame(
            0,
            $resolve->invoke($renderer, 'hologram_panel_cyan'),
            "{$rendererClass}: the text-less fallback pane must stay standard (proc_mode 0)"
        );
    }

    public function testAllBackendShadersImplementTheUnlitBranch(): void
    {
        $vio   = (string) file_get_contents(self::VIO_FRAG);
        $glsl  = (string) file_get_contents(self::GLSL_FRAG);
        $metal = (string) file_get_contents(self::METAL_FRAG);

        // GLSL backends compare u_proc_mode; Metal copies it into a local `proc`.
        self::assertMatchesRegularExpression('/u_proc_mode\s*==\s*12/', $vio,
            'vio frag shader is missing the proc_mode 12 (unlit hologram) branch');
        self::assertMatchesRegularExpression('/u_proc_mode\s*==\s*12/', $glsl,
            'OpenGL frag shader is missing the proc_mode 12 (unlit hologram) branch');
        self::assertMatchesRegularExpression('/proc\s*==\s*12/', $metal,
            'Metal frag shader is missing the proc_mode 12 (unlit hologram) branch');
    }
}
