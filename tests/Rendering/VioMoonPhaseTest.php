<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetSky;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ScreenSpaceAO;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * Moon phases on the GPU: the camera looks straight at a large moon. Full moon lights
 * both halves of the disc alike; at the first quarter one half is lit and the other
 * dark; at new moon the disc keeps only a faint earthshine.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioMoonPhaseTest extends TestCase
{
    private const SIZE = 64;

    private ?\VioContext $ctx = null;

    protected function setUp(): void
    {
        $ctx = VioTestContext::create(self::SIZE, self::SIZE, ['shader_cache' => sys_get_temp_dir() . '/phpolygon-test-shader-cache']);
        if ($ctx === null) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        $this->ctx = $ctx;
    }

    protected function tearDown(): void
    {
        if ($this->ctx !== null) {
            vio_destroy($this->ctx);
            $this->ctx = null;
        }
    }

    public function testPhasesLightTheDisc(): void
    {
        [$fl, $fr] = $this->halves(0.5);
        [$ql, $qr] = $this->halves(0.25);
        [$nl, $nr] = $this->halves(0.0);

        self::assertGreaterThan(60, min($fl, $fr), "full moon lit on both sides ({$fl}, {$fr})");
        self::assertGreaterThan(min($ql, $qr) + 40, max($ql, $qr), "quarter moon lit on one side ({$ql}, {$qr})");
        self::assertLessThan(0.4 * min($fl, $fr), max($nl, $nr), "new moon nearly dark ({$nl}, {$nr})");
    }

    /** @return array{0: int, 1: int} red a little left and right of the disc centre */
    private function halves(float $phase): array
    {
        self::assertNotNull($this->ctx);
        $renderer = new VioRenderer3D($this->ctx, self::SIZE, self::SIZE);
        $renderer->applySettings(new GraphicsSettings(
            shadowQuality: ShadowQuality::Off,
            ambientOcclusion: ScreenSpaceAO::Off,
            antiAliasing: AntiAliasing::Off,
            bloom: false,
        ));
        $moon = (new Vec3(0.0, 0.5, -1.0))->normalize();
        $list = new RenderCommandList();
        $list->add(new SetCamera(
            Mat4::lookAt(new Vec3(0.0, 0.0, 0.0), $moon, new Vec3(0.0, 1.0, 0.0)),
            Mat4::perspective(0.5, 1.0, 0.1, 100.0),
        ));
        $list->add(new SetSky(
            sunDirection: new Vec3(0.0, -1.0, 0.0),
            sunColor: new Color(1.0, 1.0, 1.0),
            sunIntensity: 0.0,
            zenithColor: new Color(0.0, 0.0, 0.0),
            horizonColor: new Color(0.0, 0.0, 0.0),
            groundColor: new Color(0.0, 0.0, 0.0),
            sunSize: 0.15,
            sunGlowSize: 0.0,
            moonDirection: $moon,
            moonColor: new Color(1.0, 1.0, 1.0),
            moonIntensity: 1.0,
            moonPhase: $phase,
        ));
        $image = $renderer->renderToImage($list, self::SIZE, self::SIZE);
        $row = self::SIZE / 2;
        $at = static fn (int $x): int => ord($image[($row * self::SIZE + $x) * 4]);

        return [$at(self::SIZE / 2 - 6), $at(self::SIZE / 2 + 6)];
    }
}
