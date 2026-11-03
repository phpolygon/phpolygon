<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Geometry\QuadMesh;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\CubemapRegistry;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\ProcModeRegistry;
use PHPolygon\Rendering\ProcModeShaderRegistry;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioRenderer3D;
use PHPolygon\Rendering\VioTextureManager;

/**
 * A text panel read from far away and from the side. Material::$mipBias picks a sharper
 * mip at 30 m, Material::$anisotropy keeps the strokes at a grazing angle. Measured
 * against the same view rendered at 4x4 the resolution and box-filtered, i.e. what a
 * pixel ideally shows: stroke contrast (legibility) and correlation with it.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioMaterialTextureFilterTest extends TestCase
{
    private const W = 192;
    private const H = 128;
    private const SS = 4;

    protected function setUp(): void
    {
        MeshRegistry::clear();
        MaterialRegistry::clear();
        CubemapRegistry::clear();
        ProcModeRegistry::clear();
        ProcModeShaderRegistry::clear();
        MeshRegistry::register('panel', QuadMesh::generate(1.0, 1.0));
    }

    protected function tearDown(): void
    {
        MaterialRegistry::clear();
        MeshRegistry::clear();
    }

    public function testANegativeMipBiasKeepsAFarPanelSharper(): void
    {
        $view = self::far();
        $plain = $this->measure($view, new Material(albedo: new Color(1.0, 1.0, 1.0), albedoTexture: 'text', useEnvironmentMap: false));
        $sharp = $this->measure($view, (new Material(albedo: new Color(1.0, 1.0, 1.0), albedoTexture: 'text', useEnvironmentMap: false))->withTextureFilter(mipBias: -1.0));
        fprintf(STDERR, "[texture filter %s] 30 m: correlation with 16x reference %.3f -> %.3f, stroke contrast %.1f -> %.1f of %.1f\n",
            $plain['backend'], $plain['corr'], $sharp['corr'], $plain['contrast'], $sharp['contrast'], $plain['refContrast']);
        // The strokes come back up to what the ideal pixel shows, not beyond it. The
        // correlation pays for it without temporal AA: a finer mip aliases a little
        // (what the docblock of Material::$mipBias warns about).
        self::assertGreaterThan($plain['contrast'] * 1.5, $sharp['contrast']);
        self::assertLessThan($plain['refContrast'] * 1.1, $sharp['contrast']);
        self::assertGreaterThan(0.7, $sharp['corr']);
    }

    public function testMaterialAnisotropyKeepsAGrazingPanelLegible(): void
    {
        $view = self::grazing();
        foreach ([1, 4] as $setting) {
            $plain = $this->measure($view, new Material(albedo: new Color(1.0, 1.0, 1.0), albedoTexture: 'text', useEnvironmentMap: false), $setting);
            $aniso = $this->measure($view, (new Material(albedo: new Color(1.0, 1.0, 1.0), albedoTexture: 'text', useEnvironmentMap: false))->withTextureFilter(anisotropy: 16), $setting);
            fprintf(STDERR, "[texture filter %s] 80 deg, setting %dx: correlation %.3f -> %.3f, stroke contrast %.1f -> %.1f of %.1f\n",
                $plain['backend'], $setting, $plain['corr'], $aniso['corr'], $plain['contrast'], $aniso['contrast'], $plain['refContrast']);
            self::assertGreaterThan($plain['contrast'], $aniso['contrast'], "setting {$setting}x");
            self::assertGreaterThan($plain['corr'], $aniso['corr'], "setting {$setting}x");
        }
    }

    public function testAMaterialAtTheSettingDrawsLikeOneWithout(): void
    {
        $view = self::grazing();
        $plain = $this->measure($view, new Material(albedo: new Color(1.0, 1.0, 1.0), albedoTexture: 'text', useEnvironmentMap: false), 8);
        $same = $this->measure($view, (new Material(albedo: new Color(1.0, 1.0, 1.0), albedoTexture: 'text', useEnvironmentMap: false))->withTextureFilter(mipBias: 0.0, anisotropy: 8), 8);
        self::assertSame($plain['md5'], $same['md5'], 'anisotropy at the setting and bias 0 take the one-tap path');
    }

    /** @return array{0: Mat4, 1: Mat4} panel 3 x 0.75 m, 30 m ahead, 8 degree lens (a zoom, so the panel spans ~140 px, 3.7 texels each) */
    private static function far(): array
    {
        return [
            Mat4::translation(0.0, 0.0, -30.0)->multiply(Mat4::scaling(3.0, 0.75, 1.0)),
            Mat4::perspective(deg2rad(8.0), self::W / self::H, 0.5, 200.0),
        ];
    }

    /** @return array{0: Mat4, 1: Mat4} panel 6 x 1.5 m, 10 m ahead, turned 80 degrees */
    private static function grazing(): array
    {
        return [
            Mat4::translation(0.0, 0.0, -10.0)->multiply(Mat4::rotationY(deg2rad(80.0)))->multiply(Mat4::scaling(6.0, 1.5, 1.0)),
            Mat4::perspective(deg2rad(20.0), self::W / self::H, 0.1, 100.0),
        ];
    }

    /**
     * @param array{0: Mat4, 1: Mat4} $view
     * @return array{corr: float, contrast: float, refContrast: float, md5: string, backend: string}
     */
    private function measure(array $view, Material $material, int $anisotropySetting = 4): array
    {
        MaterialRegistry::register('panel', $material);
        $reference = $this->render($view, self::W * self::SS, self::H * self::SS, $anisotropySetting);
        $image = $this->render($view, self::W, self::H, $anisotropySetting);
        $ref = [];
        $row = self::W * self::SS * 4;
        for ($y = 0; $y < self::H; $y++) {
            for ($x = 0; $x < self::W; $x++) {
                $sum = 0.0;
                for ($j = 0; $j < self::SS; $j++) {
                    $o = ($y * self::SS + $j) * $row + $x * self::SS * 4;
                    for ($i = 0; $i < self::SS; $i++) {
                        $sum += self::lumaAt($reference['rgba'], $o + $i * 4);
                    }
                }
                $ref[] = $sum / (self::SS * self::SS);
            }
        }
        $img = [];
        for ($i = 0, $n = self::W * self::H; $i < $n; $i++) {
            $img[] = self::lumaAt($image['rgba'], $i * 4);
        }
        // The panel's inside: pixels whose neighbourhood (2 px) its bright plate covers in the
        // reference, so the silhouette edge, where a sub-pixel shift decides, stays out.
        $pixels = [];
        for ($y = 2; $y < self::H - 2; $y++) {
            for ($x = 2; $x < self::W - 2; $x++) {
                $inside = true;
                foreach ([[-2, 0], [2, 0], [0, -2], [0, 2], [0, 0]] as [$dx, $dy]) {
                    $inside = $inside && $ref[($y + $dy) * self::W + $x + $dx] > 20.0;
                }
                if ($inside) {
                    $pixels[] = $y * self::W + $x;
                }
            }
        }
        self::assertGreaterThan(200, count($pixels), 'the panel covers enough pixels');
        return [
            'corr' => self::correlation($img, $ref, $pixels),
            'contrast' => self::deviation($img, $pixels),
            'refContrast' => self::deviation($ref, $pixels),
            'md5' => md5($image['rgba']),
            'backend' => $image['backend'],
        ];
    }

    /**
     * @param array{0: Mat4, 1: Mat4} $view
     * @return array{rgba: string, backend: string}
     */
    private function render(array $view, int $w, int $h, int $anisotropySetting): array
    {
        $ctx = VioTestContext::create($w, $h, ['shader_cache' => sys_get_temp_dir() . '/phpolygon-test-shader-cache']);
        if ($ctx === null) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        try {
            $settings = new GraphicsSettings(shadowQuality: ShadowQuality::Off, bloom: false, hdr: false, antiAliasing: AntiAliasing::Off, anisotropy: $anisotropySetting);
            $textures = new VioTextureManager($ctx);
            $textures->applySettings($settings);
            $textures->loadRgba('text', 512, 128, self::textPixels());
            $renderer = new VioRenderer3D($ctx, $w, $h);
            $renderer->setTextureManager($textures);
            $renderer->applySettings($settings);
            $list = new RenderCommandList();
            $list->add(new SetCamera(Mat4::identity(), $view[1]));
            $list->add(new SetAmbientLight(new Color(0.2, 0.2, 0.2), 1.0));
            $list->add(new DrawMesh('panel', 'panel', $view[0]));
            $renderer->renderToImage($list, $w, $h, new Color(0.0, 0.0, 0.0, 1.0));
            $rgba = $renderer->renderToImage($list, $w, $h, new Color(0.0, 0.0, 0.0, 1.0));
            return ['rgba' => $rgba, 'backend' => vio_backend_name($ctx)];
        } finally {
            vio_destroy($ctx);
        }
    }

    /** Dark strokes 2 px wide on a light plate, lines of glyph-like cells, deterministic. */
    private static function textPixels(): string
    {
        $w = 512;
        $h = 128;
        $px = '';
        for ($y = 0; $y < $h; $y++) {
            $line = intdiv($y, 32);
            $cy = $y % 32;
            for ($x = 0; $x < $w; $x++) {
                $cell = intdiv($x, 16);
                $cx = $x % 16;
                $seed = crc32($line . ':' . $cell);
                $ink = false;
                if ($cy >= 6 && $cy < 26 && $cx >= 2 && $cx < 14) {
                    $ink = ($cx < 4 && ($seed & 1))
                        || ($cx >= 12 && ($seed & 2))
                        || ($cy < 8 && ($seed & 4))
                        || ($cy >= 15 && $cy < 17 && ($seed & 8))
                        || ($cy >= 24 && ($seed & 16));
                }
                $px .= $ink ? "\x18\x18\x20\xff" : "\xf2\xec\xd8\xff";
            }
        }
        return $px;
    }

    private static function lumaAt(string $rgba, int $o): float
    {
        return 0.2126 * ord($rgba[$o]) + 0.7152 * ord($rgba[$o + 1]) + 0.0722 * ord($rgba[$o + 2]);
    }

    /**
     * @param list<float> $a
     * @param list<int> $pixels
     */
    private static function deviation(array $a, array $pixels): float
    {
        $mean = 0.0;
        foreach ($pixels as $i) {
            $mean += $a[$i];
        }
        $mean /= count($pixels);
        $var = 0.0;
        foreach ($pixels as $i) {
            $var += ($a[$i] - $mean) ** 2;
        }
        return sqrt($var / count($pixels));
    }

    /**
     * @param list<float> $a
     * @param list<float> $b
     * @param list<int> $pixels
     */
    private static function correlation(array $a, array $b, array $pixels): float
    {
        $n = count($pixels);
        $ma = 0.0;
        $mb = 0.0;
        foreach ($pixels as $i) {
            $ma += $a[$i];
            $mb += $b[$i];
        }
        $ma /= $n;
        $mb /= $n;
        $cov = 0.0;
        $va = 0.0;
        $vb = 0.0;
        foreach ($pixels as $i) {
            $cov += ($a[$i] - $ma) * ($b[$i] - $mb);
            $va += ($a[$i] - $ma) ** 2;
            $vb += ($b[$i] - $mb) ** 2;
        }
        return $cov / max(1e-9, sqrt($va * $vb));
    }
}
