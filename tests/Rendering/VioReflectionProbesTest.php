<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\CubemapRegistry;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\ProceduralCubemap;
use PHPolygon\Rendering\ProcModeRegistry;
use PHPolygon\Rendering\ProcModeShaderRegistry;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ScreenSpaceAO;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\Reflection\ReflectionProbe;
use PHPolygon\Rendering\Reflection\ReflectionProbes;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * Local reflection probes on the GPU: a 40 m square seen from above, a red probe over
 * its west half and a green one over its east half (box blend 1 m), and a snippet that
 * paints `reflectionProbeSample()` with a blue fallback. Each half shows its probe,
 * the strip beyond both boxes the fallback – so both cube samplers are bound to the
 * right slots and the box test reads the right uniforms.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioReflectionProbesTest extends TestCase
{
    private const SIZE = 64;
    private const HALF = 20.0;

    private ?\VioContext $ctx = null;

    protected function setUp(): void
    {
        $ctx = VioTestContext::create(self::SIZE, self::SIZE, ['shader_cache' => sys_get_temp_dir() . '/phpolygon-test-shader-cache']);
        if ($ctx === null) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        $this->ctx = $ctx;
        MeshRegistry::clear();
        MaterialRegistry::clear();
        CubemapRegistry::clear();
        ProcModeRegistry::clear();
        ProcModeShaderRegistry::clear();
        ReflectionProbes::clear();
        MeshRegistry::register('sea', BoxMesh::generate(2.0 * self::HALF, 0.1, 2.0 * self::HALF));
        MaterialRegistry::register('sea', new Material(albedo: new Color(1.0, 1.0, 1.0)));
        ProcModeRegistry::map('sea', 2);
        // Probe GI omitted, as in a water variant: room for both cube samplers.
        ProcModeShaderRegistry::defineVariant('water', [2], [\PHPolygon\Rendering\ShaderPath::ProbeGi]);
        ReflectionProbes::register(['water']);
        ProcModeShaderRegistry::registerGlsl(
            2,
            '',
            "    if (u_proc_mode == 2) {\n"
            . "        vec3 c = reflectionProbeSample(v_worldPos, vec3(0.0, 1.0, 0.0), vec3(0.0, 0.0, 1.0));\n"
            . "        frag_color = outputColor(step(vec3(0.5), c), 1.0);\n"
            . "        return;\n"
            . "    } else",
        );
        CubemapRegistry::registerProcedural('probe_red', ProceduralCubemap::generate(4, static fn (Vec3 $d): Color => new Color(1.0, 0.0, 0.0)));
        CubemapRegistry::registerProcedural('probe_green', ProceduralCubemap::generate(4, static fn (Vec3 $d): Color => new Color(0.0, 1.0, 0.0)));
        // West half x -20..-1, east half 1..14; x > 14 lies in no box.
        ReflectionProbes::set([
            new ReflectionProbe('probe_red', new Vec3(-10.0, 2.0, 0.0), new Vec3(-25.0, -5.0, -25.0), new Vec3(-1.0, 10.0, 25.0), 1.0),
            new ReflectionProbe('probe_green', new Vec3(8.0, 2.0, 0.0), new Vec3(1.0, -5.0, -25.0), new Vec3(14.0, 10.0, 25.0), 1.0),
        ]);
    }

    protected function tearDown(): void
    {
        ReflectionProbes::clear();
        ProcModeRegistry::clear();
        ProcModeShaderRegistry::clear();
        CubemapRegistry::clear();
        MaterialRegistry::clear();
        MeshRegistry::clear();
        if ($this->ctx !== null) {
            vio_destroy($this->ctx);
            $this->ctx = null;
        }
    }

    public function testEachBoxShowsItsProbeAndTheRestTheFallback(): void
    {
        self::assertNotNull($this->ctx);
        $renderer = new VioRenderer3D($this->ctx, self::SIZE, self::SIZE);
        $renderer->applySettings(new GraphicsSettings(
            shadowQuality: ShadowQuality::Off,
            ambientOcclusion: ScreenSpaceAO::Off,
            antiAliasing: AntiAliasing::Off,
            bloom: false,
        ));
        $list = new RenderCommandList();
        // Straight down, screen up = world -z, screen right = world +x.
        $list->add(new SetCamera(
            Mat4::lookAt(new Vec3(0.0, 30.0, 0.0), new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 0.0, -1.0)),
            Mat4::orthographic(-self::HALF, self::HALF, -self::HALF, self::HALF, 1.0, 100.0),
        ));
        foreach (ReflectionProbes::commandsFor(new Vec3(0.0, 30.0, 0.0)) as $command) {
            $list->add($command);
        }
        $list->add(new DrawMesh('sea', 'sea', Mat4::identity()));
        $image = $renderer->renderToImage($list, self::SIZE, self::SIZE);

        self::assertSame([255, 0, 0], self::at($image, -12.0, 0.0), 'west half: red probe');
        self::assertSame([0, 255, 0], self::at($image, 7.0, 0.0), 'east half: green probe');
        self::assertSame([0, 0, 255], self::at($image, 18.0, 0.0), 'beyond both boxes: fallback');
    }

    /**
     * Pixel of world (x, z), thresholded to 0/255.
     *
     * @return list<int>
     */
    private static function at(string $rgba, float $x, float $z): array
    {
        $col = max(0, min(self::SIZE - 1, (int) floor(($x + self::HALF) / (2.0 * self::HALF) * self::SIZE)));
        $row = max(0, min(self::SIZE - 1, (int) floor(($z + self::HALF) / (2.0 * self::HALF) * self::SIZE)));
        $o = ($row * self::SIZE + $col) * 4;

        return [ord($rgba[$o]) > 127 ? 255 : 0, ord($rgba[$o + 1]) > 127 ? 255 : 0, ord($rgba[$o + 2]) > 127 ? 255 : 0];
    }
}
