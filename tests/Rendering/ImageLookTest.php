<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\TestCase;
use PHPolygon\Rendering\Accessibility\AccessibilitySettings;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\PostProcess\AutoExposure;
use PHPolygon\Rendering\PostProcess\ColorGrade;
use PHPolygon\Rendering\PostProcess\ColorLut;
use PHPolygon\Rendering\PostProcess\ColorLutRegistry;
use PHPolygon\Rendering\PostProcess\FullscreenPass;
use PHPolygon\Rendering\PostProcess\FullscreenPassRegistry;
use PHPolygon\Rendering\PostProcess\ImageLook;
use PHPolygon\Rendering\PostProcess\PostStage;
use PHPolygon\Rendering\PostProcess\Tonemapper;
use PHPolygon\Rendering\PostProcess\VioImageChain;

/** The image look as data: defaults, blending, the comfort gates, colour tables. No GPU. */
final class ImageLookTest extends TestCase
{
    protected function tearDown(): void
    {
        ColorLutRegistry::clear();
        FullscreenPassRegistry::clear();
    }

    public function testTheDefaultLookIsTheV097Chain(): void
    {
        self::assertTrue((new ImageLook())->isDefault());
        self::assertFalse((new ImageLook(deband: 1.0))->isDefault());
        self::assertFalse((new ImageLook(bloomLevels: 5))->isDefault());
        self::assertFalse((new ImageLook(autoExposure: new AutoExposure()))->isDefault());
        self::assertTrue((new ImageLook(grain: 0.2))->with(['grain' => 0.0])->isDefault());
    }

    public function testWithRefusesUnknownFields(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ImageLook())->with(['bloomRadius' => 1.0]);
    }

    public function testLerpBlendsNumbersAndSwitchesDiscreteFieldsAtTheMiddle(): void
    {
        $a = new ImageLook(exposureEv: 0.0, bloomLevels: 1, tonemapper: Tonemapper::Aces, grain: 0.0);
        $b = new ImageLook(exposureEv: 1.0, bloomLevels: 6, tonemapper: Tonemapper::Sigmoid, grain: 0.04);
        $q = ImageLook::lerp($a, $b, 0.25);
        self::assertEqualsWithDelta(0.25, $q->exposureEv, 1e-9);
        self::assertEqualsWithDelta(0.01, $q->grain, 1e-9);
        self::assertSame(1, $q->bloomLevels);
        self::assertSame(Tonemapper::Aces, $q->tonemapper);
        $h = ImageLook::lerp($a, $b, 0.75);
        self::assertSame(6, $h->bloomLevels);
        self::assertSame(Tonemapper::Sigmoid, $h->tonemapper);
        self::assertSame($a, ImageLook::lerp($a, $b, 0.0));
        self::assertSame($b, ImageLook::lerp($a, $b, 1.0));
    }

    public function testLerpBetweenTwoTablesBlendsTheFirstIntoTheSecond(): void
    {
        $a = new ImageLook(lut: 'harbour', lutStrength: 1.0);
        $b = new ImageLook(lut: 'forge', lutStrength: 0.6);
        $m = ImageLook::lerp($a, $b, 0.5);
        self::assertSame('harbour', $m->lut);
        self::assertSame('forge', $m->lutBlend);
        self::assertEqualsWithDelta(0.5, $m->lutBlendWeight, 1e-9);
        self::assertEqualsWithDelta(0.8, $m->lutStrength, 1e-9);

        // Into a look without a table: the table fades out by its strength.
        $n = ImageLook::lerp($a, new ImageLook(), 0.25);
        self::assertSame('harbour', $n->lut);
        self::assertEqualsWithDelta(0.75, $n->lutStrength, 1e-9);
    }

    public function testComfortOptionsWinOverTheLook(): void
    {
        $look = new ImageLook(saturationPulse: 0.5, grain: 0.05, chromaticAberration: 0.004);
        $plain = VioImageChain::finishUniforms($look, new AccessibilitySettings(), true, 0.4, false, 12.5);
        self::assertSame(0.5, $plain['u_saturation_boost']);
        self::assertSame(0.05, $plain['u_grain']);
        self::assertSame(0.004, $plain['u_ca']);
        self::assertSame(12.5, $plain['u_time']);

        // Less world saturation: no pulse on top of it.
        $desaturated = VioImageChain::finishUniforms($look, new AccessibilitySettings(worldSaturation: 0.5), true, 0.4, false, 12.5);
        self::assertSame(0.0, $desaturated['u_saturation_boost']);

        // Reduced motion: no pulse, the grain stands still.
        $calm = VioImageChain::finishUniforms($look, new AccessibilitySettings(reducedMotion: true), true, 0.4, false, 12.5);
        self::assertSame(0.0, $calm['u_saturation_boost']);
        self::assertSame(0.0, $calm['u_time']);
        self::assertSame(0.05, $calm['u_grain']);

        // Contrast mode: its outlines would trace grain and colour fringes.
        $contrast = VioImageChain::finishUniforms($look, new AccessibilitySettings(contrast: 0.5), true, 0.4, false, 12.5);
        self::assertSame(0.0, $contrast['u_grain']);
        self::assertSame(0.0, $contrast['u_ca']);
        self::assertSame(0.5, $contrast['u_saturation_boost']);
    }

    public function testExposureAndTonemapOnlyOnTheLinearScene(): void
    {
        $look = new ImageLook(exposureEv: 1.0, tonemapper: Tonemapper::Sigmoid);
        $hdr = VioImageChain::finishUniforms($look, new AccessibilitySettings(), true, 0.0, true, 0.0);
        self::assertSame(2.0, $hdr['u_exposure']);
        self::assertSame(1.0, $hdr['u_auto_exposure']);
        self::assertSame(1.0, $hdr['u_tonemapper']);
        $ldr = VioImageChain::finishUniforms($look, new AccessibilitySettings(), false, 0.0, true, 0.0);
        self::assertSame(0.0, $ldr['u_hdr']);
        self::assertSame(0.0, $ldr['u_auto_exposure']);
    }

    public function testBloomChainSizesAndNormalisation(): void
    {
        self::assertSame([[960, 540], [480, 270], [240, 135]], VioImageChain::bloomSizes(1920, 1080, 3));
        // Stops before a level narrower than 4 texels.
        self::assertCount(4, VioImageChain::bloomSizes(64, 64, 7));
        self::assertEqualsWithDelta(1.0 / (1.0 + 0.5 + 0.25), VioImageChain::bloomNormalisation(3, 0.5), 1e-12);
    }

    public function testAutoExposureKeepsExposureOneAtTheReference(): void
    {
        $auto = new AutoExposure(reference: 0.25, strength: 0.5, minEv: -1.0, maxEv: 1.5);
        self::assertEqualsWithDelta(1.0, $auto->exposureFor(0.25), 1e-12);
        // Two stops darker, half strength: one stop up.
        self::assertEqualsWithDelta(2.0, $auto->exposureFor(0.0625), 1e-9);
        // Clamped at maxEv.
        self::assertEqualsWithDelta(2.0 ** 1.5, $auto->exposureFor(0.0001), 1e-9);
        self::assertEqualsWithDelta(0.5, $auto->exposureFor(100.0), 1e-9);
    }

    public function testAnIdentityGradeBakesAnIdentityTable(): void
    {
        self::assertTrue((new ColorGrade())->isIdentity());
        $lut = ColorLut::fromGrade(new ColorGrade(), 17);
        self::assertSame(17 ** 3 * 4, strlen($lut->data));
        foreach ([[0.0, 0.0, 0.0], [1.0, 1.0, 1.0], [0.2, 0.55, 0.9], [0.731, 0.12, 0.4]] as $c) {
            $out = $lut->sample(...$c);
            for ($i = 0; $i < 3; $i++) {
                self::assertEqualsWithDelta($c[$i], $out[$i], 0.6 / 255, 'identity within the 8-bit rounding of the entries');
            }
        }
    }

    public function testGradeOperationsMoveColoursTheWayTheyAreNamed(): void
    {
        $grey = [0.5, 0.5, 0.5];
        $warm = (new ColorGrade(temperature: 1.0))->apply($grey);
        self::assertGreaterThan($warm[2], $warm[0], 'warmer = more red than blue');
        $cool = (new ColorGrade(temperature: -1.0))->apply($grey);
        self::assertGreaterThan($cool[0], $cool[2]);

        $red = [0.8, 0.3, 0.3];
        $flat = (new ColorGrade(saturation: 0.0))->apply($red);
        self::assertEqualsWithDelta($flat[0], $flat[1], 1e-9, 'saturation 0 = grey');
        self::assertEqualsWithDelta($flat[1], $flat[2], 1e-9);

        $dark = [0.2, 0.2, 0.2];
        $bright = [0.8, 0.8, 0.8];
        $contrast = new ColorGrade(contrast: 1.3);
        self::assertLessThan(0.2, $contrast->apply($dark)[0]);
        self::assertGreaterThan(0.8, $contrast->apply($bright)[0]);
    }

    public function testCubeTextIsReadWithRedFastest(): void
    {
        $rows = [];
        for ($b = 0; $b < 2; $b++) {
            for ($g = 0; $g < 2; $g++) {
                for ($r = 0; $r < 2; $r++) {
                    $rows[] = sprintf('%d %d %d', 1 - $r, 1 - $g, 1 - $b); // inverted
                }
            }
        }
        $lut = ColorLut::fromCube("TITLE \"invert\"\n# comment\nLUT_3D_SIZE 2\n" . implode("\n", $rows) . "\n");
        self::assertSame(2, $lut->size);
        $out = $lut->sample(1.0, 0.0, 0.25);
        self::assertEqualsWithDelta(0.0, $out[0], 1e-9);
        self::assertEqualsWithDelta(1.0, $out[1], 1e-9);
        self::assertEqualsWithDelta(0.75, $out[2], 1e-9);
    }

    public function testCubeTextWithTheWrongRowCountIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ColorLut::fromCube("LUT_3D_SIZE 2\n0 0 0\n1 1 1\n");
    }

    public function testRegistryVersionsChangeOnEveryRegistration(): void
    {
        self::assertSame(0, ColorLutRegistry::version('x'));
        ColorLutRegistry::register('x', ColorLut::identity(2));
        $v = ColorLutRegistry::version('x');
        ColorLutRegistry::register('x', ColorLut::identity(2));
        self::assertGreaterThan($v, ColorLutRegistry::version('x'));
    }

    public function testRunTimeSwitchOfAFullscreenPassLeavesTheRegistryVersion(): void
    {
        FullscreenPassRegistry::register(new FullscreenPass('visor', PostStage::Final, '/dev/null'));
        $settings = new GraphicsSettings();
        $pass = FullscreenPassRegistry::get('visor');
        self::assertNotNull($pass);
        $version = FullscreenPassRegistry::version();
        self::assertTrue($pass->enabledBy($settings));

        FullscreenPassRegistry::setActive('visor', false);
        self::assertFalse($pass->enabledBy($settings));
        self::assertSame($version, FullscreenPassRegistry::version(), 'nothing to rebuild');

        FullscreenPassRegistry::setActive('visor', true);
        self::assertTrue($pass->enabledBy($settings));

        // The player's setting decides first: the switch cannot turn on what the player turned off.
        self::assertFalse($pass->enabledBy($settings->with(effects: ['visor' => false])));
    }
}
