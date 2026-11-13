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
use PHPolygon\Rendering\Command\SetImageLook;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\PostProcess\ImageLook;
use PHPolygon\Rendering\PostProcess\VioImageChain;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioRenderer3D;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The rings round a bright light: the halo of a bloomed lamp in the dark is a long slow gradient, which 8 bits
 * quantise into wide bands unless the finish pass dithers by about a step. A request of 0.5 used to leave
 * them; a positive deband is now at least 1.5 steps.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioGlowBandingTest extends TestCase
{
    private const SIZE = 512;

    public function testTheAmplitudeIsRaisedOnlyWhenADitherIsAsked(): void
    {
        self::assertSame(0.0, VioImageChain::debandAmplitude(0.0));
        self::assertSame(0.0001, VioImageChain::debandAmplitude(0.0001));
        self::assertSame(1.5, VioImageChain::debandAmplitude(0.5));
        self::assertSame(2.0, VioImageChain::debandAmplitude(2.0));
    }

    public function testTheHaloOfAGlowHasNoBands(): void
    {
        $ctx = VioTestContext::create(self::SIZE, self::SIZE, ['shader_model' => 6, 'shader_cache' => sys_get_temp_dir() . '/phpolygon-test-shader-cache']);
        if ($ctx === null) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        MeshRegistry::clear();
        MaterialRegistry::clear();
        MeshRegistry::register('wall', BoxMesh::generate(60.0, 60.0, 0.1));
        MeshRegistry::register('lamp', BoxMesh::generate(0.3, 0.3, 0.1));
        MaterialRegistry::register('grey', new Material(albedo: new Color(0.05, 0.05, 0.06), roughness: 1.0));
        MaterialRegistry::register('glow', new Material(albedo: new Color(0.0, 0.0, 0.0), emission: new Color(60.0, 30.0, 20.0), roughness: 1.0));
        try {
            $renderer = new VioRenderer3D($ctx, self::SIZE, self::SIZE);
            $renderer->applySettings(new GraphicsSettings(shadowQuality: ShadowQuality::Off, antiAliasing: AntiAliasing::Fxaa, bloom: true, hdr: true));
            $longest = function (float $deband) use ($ctx, $renderer): array {
                for ($i = 0; $i < 4; $i++) {
                    $l = new RenderCommandList();
                    $l->add(new SetCamera(Mat4::translation(0.0, 0.0, -3.0), Mat4::perspective(deg2rad(60.0), 1.0, 0.1, 100.0), cut: $i === 0));
                    $l->add(new SetAmbientLight(new Color(1.0, 1.0, 1.0), 0.1));
                    $l->add(new SetDirectionalLight(new Vec3(0.0, 0.0, -1.0), new Color(1.0, 1.0, 1.0), 0.0));
                    $l->add(new DrawMesh('wall', 'grey', Mat4::identity()));
                    $l->add(new DrawMesh('lamp', 'glow', Mat4::translation(0.0, 0.0, 0.2)));
                    $l->add(new SetImageLook(new ImageLook(deband: $deband, bloomLevels: 7)));
                    vio_begin($ctx);
                    $renderer->beginFrame();
                    $renderer->clear(new Color(0.0, 0.0, 0.0, 1.0));
                    $renderer->render($l);
                    $renderer->endFrame();
                    vio_end($ctx);
                }
                $px = (string) vio_read_pixels($ctx);
                $y = self::SIZE / 2;
                $runs = [];
                $prev = null;
                $run = 0;
                for ($x = self::SIZE / 2 + 30; $x < self::SIZE - 4; $x++) {
                    $o = ($y * self::SIZE + $x) * 4;
                    $v = ord($px[$o]) * 65536 + ord($px[$o + 1]) * 256 + ord($px[$o + 2]);
                    if ($v === $prev) {
                        $run++;
                    } else {
                        if ($prev !== null) {
                            $runs[] = $run;
                        }
                        $prev = $v;
                        $run = 1;
                    }
                }
                $runs[] = $run;

                return [max($runs), count(array_filter($runs, static fn (int $n): bool => $n >= 5))];
            };
            [$noDither] = $longest(0.0001);
            [$longHalf, $manyHalf] = $longest(0.5);
            fprintf(STDERR, "[banding] longest run of one value along the halo: no dither %d px, deband 0.5 %d px (%d runs >= 5 px)\n", $noDither, $longHalf, $manyHalf);
            self::assertGreaterThan(20, $noDither, 'without dither the halo bands (the premise)');
            self::assertLessThanOrEqual(6, $longHalf, 'a deband of 0.5 now breaks the bands');
            self::assertLessThanOrEqual(2, $manyHalf);
        } finally {
            vio_destroy($ctx);
        }
    }
}
