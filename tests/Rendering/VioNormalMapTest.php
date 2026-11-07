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
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetDirectionalLight;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\ProcModeShaderRegistry;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioRenderer3D;
use PHPolygon\Rendering\VioTextureManager;

/**
 * Tangent-space normal maps (Material::$normalTexture): under a grazing light a flat
 * panel with a step in its normal map turns one half towards the light and the other
 * away - one half of the image clearly brighter than the other (left/right or top/
 * bottom, whichever way the box's UVs run). Without the map the panel stays even.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioNormalMapTest extends TestCase
{
    private const SIZE = 64;

    protected function setUp(): void
    {
        MeshRegistry::clear();
        MaterialRegistry::clear();
        MeshRegistry::register('panel', BoxMesh::generate(4.0, 0.1, 4.0));
    }

    protected function tearDown(): void
    {
        MaterialRegistry::clear();
        MeshRegistry::clear();
        ProcModeShaderRegistry::clear();
    }

    public function testSnippetSamplersAndTheNormalMapShareTheSlotsFairly(): void
    {
        ProcModeShaderRegistry::clear();
        ProcModeShaderRegistry::setTextureUnits(16);
        if (ProcModeShaderRegistry::samplerRegisters() < ProcModeShaderRegistry::SAMPLER_REGISTERS_2_40) {
            $this->markTestSkipped('no normal maps with this php-vio');
        }
        // 16 units: games keep their four snippet samplers; the fourth takes the slot.
        self::assertSame(4, ProcModeShaderRegistry::maxSamplers());
        foreach (['u_tex_a', 'u_tex_b', 'u_tex_c'] as $name) {
            ProcModeShaderRegistry::declareSampler($name);
        }
        self::assertTrue(ProcModeShaderRegistry::normalMapAvailable(), 'three snippet samplers leave it free');
        self::assertSame(ProcModeShaderRegistry::NORMAL_MAP_SLOT, ProcModeShaderRegistry::declareSampler('u_tex_d'));
        self::assertFalse(ProcModeShaderRegistry::normalMapAvailable(), 'the fourth takes it');

        // More units: snippets skip the slot.
        ProcModeShaderRegistry::clear();
        ProcModeShaderRegistry::setTextureUnits(32);
        $slots = [];
        for ($i = 0; $i < ProcModeShaderRegistry::maxSamplers(); $i++) {
            $slots[] = ProcModeShaderRegistry::declareSampler('u_tex_' . $i);
        }
        self::assertNotContains(ProcModeShaderRegistry::NORMAL_MAP_SLOT, $slots);
        self::assertTrue(ProcModeShaderRegistry::normalMapAvailable());
        self::assertLessThan(32, max($slots));
        ProcModeShaderRegistry::setTextureUnits(null);
    }

    public function testTheNormalMapTurnsTheSurfaceTowardsTheLight(): void
    {
        $ctx = VioTestContext::create(self::SIZE, self::SIZE, ['shader_cache' => sys_get_temp_dir() . '/phpolygon-test-shader-cache']);
        if ($ctx === null) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        if (!ProcModeShaderRegistry::normalMapAvailable()) {
            vio_destroy($ctx);
            $this->markTestSkipped('no normal maps with this php-vio');
        }
        try {
            // Normal map: x leans +0.5 on the left half (u < 0.5), -0.5 on the right half.
            $px = '';
            for ($y = 0; $y < 16; $y++) {
                for ($x = 0; $x < 16; $x++) {
                    $nx = $x < 8 ? 0.5 : -0.5;
                    $l = sqrt($nx * $nx + 1.0);
                    $px .= chr((int) round(($nx / $l * 0.5 + 0.5) * 255)) . chr(128) . chr((int) round((1.0 / $l * 0.5 + 0.5) * 255)) . "\xff";
                }
            }
            $textures = new VioTextureManager($ctx);
            $textures->loadRgba('ramp_n', 16, 16, $px);
            $renderer = new VioRenderer3D($ctx, self::SIZE, self::SIZE);
            $renderer->setTextureManager($textures);
            $renderer->applySettings(new GraphicsSettings(shadowQuality: ShadowQuality::Off, antiAliasing: AntiAliasing::Off, bloom: false));
            $shot = function (?string $tex) use ($renderer): string {
                MaterialRegistry::register('panel', new Material(albedo: new Color(0.5, 0.5, 0.5), roughness: 0.9, useEnvironmentMap: false, normalTexture: $tex));
                $list = new RenderCommandList();
                // Straight down onto the panel, +X to the right of the image.
                $list->add(new SetCamera(Mat4::lookAt(new Vec3(0.0, 5.0, 0.001), new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 0.0, -1.0)), Mat4::perspective(deg2rad(40.0), 1.0, 0.1, 50.0)));
                $list->add(new SetAmbientLight(new Color(1.0, 1.0, 1.0), 0.05));
                // Low light from +X and +Z, so a step along either UV axis shows.
                $list->add(new SetDirectionalLight(new Vec3(-0.7, -0.3, -0.7), new Color(1.0, 1.0, 1.0), 1.0));
                $list->add(new DrawMesh('panel', 'panel', Mat4::identity()));
                return $renderer->renderToImage($list, self::SIZE, self::SIZE, new Color(0.0, 0.0, 0.0));
            };
            $plain = $shot(null);
            $mapped = $shot('ramp_n');
            $plainContrast = $this->contrast($plain);
            $mappedContrast = $this->contrast($mapped);
            fprintf(STDERR, "[normal map] half-to-half contrast plain %.1f, mapped %.1f\n", $plainContrast, $mappedContrast);
            self::assertLessThan(3.0, $plainContrast, 'without the map the panel is even');
            self::assertGreaterThan(15.0, $mappedContrast, 'one half leans towards the light, the other away');
        } finally {
            vio_destroy($ctx);
        }
    }

    /** The larger brightness difference between the image halves: left/right or top/bottom. */
    private function contrast(string $img): float
    {
        return max(abs($this->mean($img, 12, 28, 20, 44) - $this->mean($img, 36, 52, 20, 44)),
                   abs($this->mean($img, 20, 44, 12, 28) - $this->mean($img, 20, 44, 36, 52)));
    }

    /** Mean green of columns $x0..$x1, rows $y0..$y1. */
    private function mean(string $img, int $x0, int $x1, int $y0, int $y1): float
    {
        $s = 0;
        $n = 0;
        for ($y = $y0; $y < $y1; $y++) {
            for ($x = $x0; $x < $x1; $x++) {
                $s += ord($img[($y * self::SIZE + $x) * 4 + 1]);
                $n++;
            }
        }
        return $s / $n;
    }
}
