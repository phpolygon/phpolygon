<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Geometry\QuadMesh;
use PHPolygon\Math\Mat4;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\DrawMeshInstanced;
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioRenderer3D;
use PHPolygon\Rendering\VioTextureManager;

/**
 * Bindless materials (shader plan 10.1, GraphicsSettings::$bindlessMaterials): the albedo
 * read from the texture table looks like the bound one; a batch with per-instance albedo
 * textures (DrawMeshInstanced::$albedoTextures) shows each instance's texture - in one
 * draw through the table, and split per texture without it; switching the setting at
 * run time rebuilds the programs.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioBindlessMaterialsTest extends TestCase
{
    private const W = 96;
    private const H = 48;
    private ?\VioContext $ctx = null;

    protected function setUp(): void
    {
        $ctx = VioTestContext::create(self::W, self::H, ['shader_model' => 6, 'shader_cache' => sys_get_temp_dir() . '/phpolygon-test-shader-cache']);
        if ($ctx === null) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        $this->ctx = $ctx;
        MeshRegistry::clear();
        MaterialRegistry::clear();
        MeshRegistry::register('panel', QuadMesh::generate(1.0, 1.0));
        MaterialRegistry::register('tiles', new Material(albedo: new Color(1.0, 1.0, 1.0), albedoTexture: 'blue', useEnvironmentMap: false));
        MaterialRegistry::register('pattern', new Material(albedo: new Color(1.0, 1.0, 1.0), albedoTexture: 'checker', useEnvironmentMap: false));
    }

    protected function tearDown(): void
    {
        MaterialRegistry::clear();
        MeshRegistry::clear();
        if ($this->ctx !== null) {
            vio_destroy($this->ctx);
            $this->ctx = null;
        }
    }

    public function testTheTableAlbedoLooksLikeTheBoundOne(): void
    {
        $this->requireBindless();
        $list = $this->scene([new DrawMesh('panel', 'pattern', Mat4::translation(0.0, 0.0, -2.0)->multiply(Mat4::scaling(1.6, 0.8, 1.0)))]);
        [$bound] = $this->render($list, false);
        [$table, $active] = $this->render($list, true);
        self::assertTrue($active);
        $off = 0;
        for ($i = 0, $n = strlen($bound); $i < $n; $i++) {
            if (($i & 3) !== 3 && abs(ord($bound[$i]) - ord($table[$i])) > 3) {
                $off++;
            }
        }
        self::assertLessThan(self::W * self::H * 3 / 100, $off, "{$off} channels differ by more than 3");
    }

    public function testAClampedTextureStaysClampedThroughTheTable(): void
    {
        // Triplanar projection reads the plate far outside [0, 1]: the bound texture
        // (loadRgba: clamped) smears its edge, the table must take the clamped sampler.
        $this->requireBindless();
        MaterialRegistry::register('projected', new Material(albedo: new Color(1.0, 1.0, 1.0), albedoTexture: 'checker', useEnvironmentMap: false, triplanar: 0.5));
        $list = $this->scene([new DrawMesh('panel', 'projected', Mat4::translation(0.0, 0.0, -2.0)->multiply(Mat4::scaling(3.0, 1.6, 1.0)))]);
        [$bound] = $this->render($list, false);
        [$table] = $this->render($list, true);
        $off = 0;
        for ($i = 0, $n = strlen($bound); $i < $n; $i++) {
            if (($i & 3) !== 3 && abs(ord($bound[$i]) - ord($table[$i])) > 3) {
                $off++;
            }
        }
        self::assertLessThan(self::W * self::H * 3 / 100, $off, "{$off} channels differ by more than 3");
    }

    public function testInstancesShowTheirOwnTexturesThroughTheTable(): void
    {
        $this->requireBindless();
        [$img, $active] = $this->render($this->instances(), true);
        self::assertTrue($active);
        $this->assertColumns($img);
    }

    public function testInstancesShowTheirOwnTexturesWithoutTheTable(): void
    {
        [$img, $active] = $this->render($this->instances(), false);
        self::assertFalse($active);
        $this->assertColumns($img);
    }

    public function testSwitchingTheSettingRebuildsThePrograms(): void
    {
        $this->requireBindless();
        self::assertNotNull($this->ctx);
        $renderer = $this->renderer(false);
        $list = $this->instances();
        $renderer->renderToImage($list, self::W, self::H, new Color(0.0, 0.0, 0.0));
        self::assertFalse($renderer->bindlessMaterialsActive());
        $renderer->applySettings($this->settings(true));
        $renderer->renderToImage($list, self::W, self::H, new Color(0.0, 0.0, 0.0));
        $img = $renderer->renderToImage($list, self::W, self::H, new Color(0.0, 0.0, 0.0));
        self::assertTrue($renderer->bindlessMaterialsActive());
        $this->assertColumns($img);
    }

    public function testInstancesShowTheirOwnTexturesOnTheMeshShaderPath(): void
    {
        $this->requireBindless();
        self::assertNotNull($this->ctx);
        if (!defined('VIO_FEATURE_MESH_SHADER') || !vio_supports_feature($this->ctx, VIO_FEATURE_MESH_SHADER)) {
            $this->markTestSkipped('no mesh shaders on this device');
        }
        $renderer = $this->renderer(true);
        $renderer->applySettings($this->settings(true)->with(meshShaders: true));
        $list = $this->instances();
        $renderer->renderToImage($list, self::W, self::H, new Color(0.0, 0.0, 0.0));
        $img = $renderer->renderToImage($list, self::W, self::H, new Color(0.0, 0.0, 0.0));
        self::assertSame(1, $renderer->meshletDrawCount(), 'the batch went through the mesh-shader path');
        $this->assertColumns($img);
    }

    private function requireBindless(): void
    {
        self::assertNotNull($this->ctx);
        if (!defined('VIO_FEATURE_BINDLESS') || !vio_supports_feature($this->ctx, VIO_FEATURE_BINDLESS)) {
            $this->markTestSkipped('no bindless textures on this backend');
        }
    }

    /** Three panels: own red, own green, none (the material's blue). */
    private function instances(): RenderCommandList
    {
        $m = [];
        foreach ([-1.25, 0.0, 1.25] as $x) {
            $m[] = Mat4::translation($x, 0.0, -2.5)->multiply(Mat4::scaling(1.0, 1.4, 1.0));
        }
        return $this->scene([new DrawMeshInstanced('panel', 'tiles', $m, albedoTextures: ['red', 'green', null])]);
    }

    /** @param list<object> $draws */
    private function scene(array $draws): RenderCommandList
    {
        $list = new RenderCommandList();
        $list->add(new SetCamera(Mat4::identity(), Mat4::perspective(deg2rad(60.0), self::W / self::H, 0.1, 50.0)));
        $list->add(new SetAmbientLight(new Color(1.0, 1.0, 1.0), 0.4));
        foreach ($draws as $d) {
            $list->add($d);
        }
        return $list;
    }

    private function settings(bool $bindless): GraphicsSettings
    {
        return new GraphicsSettings(shadowQuality: ShadowQuality::Off, bloom: false, hdr: false, antiAliasing: AntiAliasing::Off, anisotropy: 16, bindlessMaterials: $bindless);
    }

    private function renderer(bool $bindless): VioRenderer3D
    {
        self::assertNotNull($this->ctx);
        $settings = $this->settings($bindless);
        $textures = new VioTextureManager($this->ctx);
        $textures->applySettings($settings);
        $textures->loadRgba('red', 8, 8, str_repeat("\xE0\x20\x20\xFF", 64));
        $textures->loadRgba('green', 8, 8, str_repeat("\x20\xE0\x20\xFF", 64));
        $textures->loadRgba('blue', 8, 8, str_repeat("\x20\x20\xE0\xFF", 64));
        $checker = '';
        for ($y = 0; $y < 64; $y++) {
            for ($x = 0; $x < 64; $x++) {
                $checker .= ((intdiv($x, 8) + intdiv($y, 8)) & 1) ? "\xF0\xF0\xF0\xFF" : "\x30\x30\x30\xFF";
            }
        }
        $textures->loadRgba('checker', 64, 64, $checker);
        $renderer = new VioRenderer3D($this->ctx, self::W, self::H);
        $renderer->setTextureManager($textures);
        $renderer->applySettings($settings);
        return $renderer;
    }

    /** @return array{0: string, 1: bool} */
    private function render(RenderCommandList $list, bool $bindless): array
    {
        $renderer = $this->renderer($bindless);
        $renderer->renderToImage($list, self::W, self::H, new Color(0.0, 0.0, 0.0));
        $img = $renderer->renderToImage($list, self::W, self::H, new Color(0.0, 0.0, 0.0));
        return [$img, $renderer->bindlessMaterialsActive()];
    }

    private function assertColumns(string $img): void
    {
        $want = ['red', 'green', 'blue'];
        // Panel centres: 1.25 m off-axis at 2.5 m, half the view 2.89 m wide -> 21 px.
        foreach ([self::W / 2 - 21, self::W / 2, self::W / 2 + 21] as $k => $x) {
            $o = ((self::H >> 1) * self::W + (int) $x) * 4;
            $rgb = [ord($img[$o]), ord($img[$o + 1]), ord($img[$o + 2])];
            $max = array_keys($rgb, max($rgb))[0];
            self::assertSame($want[$k], ['red', 'green', 'blue'][$max], "panel {$k}: " . json_encode($rgb));
            self::assertGreaterThan(80, max($rgb) - min($rgb), "panel {$k} is coloured: " . json_encode($rgb));
        }
    }
}
