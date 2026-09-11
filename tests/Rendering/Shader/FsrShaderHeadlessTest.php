<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering\Shader;

use PHPolygon\Testing\Shader\HeadlessShaderHarness;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * The AMD FSR 1 passes on a real vio context: EASU upscales a 32x32 image to the
 * 64x64 harness framebuffer, RCAS sharpens at 64x64. The images are split into a
 * dark left and a bright right half, so the checks do not depend on the vertical
 * orientation of the read-back.
 */
#[RequiresPhpExtension('vio')]
final class FsrShaderHeadlessTest extends TestCase
{
    private const float DARK = 0.2;
    private const float BRIGHT = 0.8;

    public function testEasuKeepsFlatAreasAndDoesNotRingAtEdges(): void
    {
        $row = $this->renderRow('fsr_easu', 32, static function (HeadlessShaderHarness $h): void {
            $h->setUniform('u_input_size', [32.0, 32.0]);
        });

        $this->assertEqualsWithDelta(self::DARK, $row[4], 0.01, 'flat dark side unchanged');
        $this->assertEqualsWithDelta(self::BRIGHT, $row[59], 0.01, 'flat bright side unchanged');
        foreach ($row as $x => $value) {
            $this->assertGreaterThanOrEqual(self::DARK - 0.01, $value, "no undershoot at x={$x}");
            $this->assertLessThanOrEqual(self::BRIGHT + 0.01, $value, "no overshoot at x={$x}");
        }
        for ($x = 1; $x < 64; $x++) {
            $this->assertGreaterThanOrEqual($row[$x - 1] - 0.01, $row[$x], "monotonic across the edge at x={$x}");
        }
        $this->assertLessThan(0.5, $row[28], 'the edge stays at the middle');
        $this->assertGreaterThan(0.5, $row[35], 'the edge stays at the middle');
    }

    public function testRcasKeepsFlatAreasAndStaysInRange(): void
    {
        $row = $this->renderRow('fsr_rcas', 64, static function (HeadlessShaderHarness $h): void {
            $h->setUniform('u_size', [64.0, 64.0]);
            $h->setUniform('u_sharpness', 0.0);
            $h->setUniform('u_output_pq', 0);
            $h->setUniform('u_paper_white', 200.0);
        });

        $this->assertEqualsWithDelta(self::DARK, $row[8], 0.01, 'flat dark side unchanged');
        $this->assertEqualsWithDelta(self::BRIGHT, $row[56], 0.01, 'flat bright side unchanged');
        foreach ($row as $x => $value) {
            $this->assertGreaterThanOrEqual(0.0, $value, "in range at x={$x}");
            $this->assertLessThanOrEqual(1.0, $value, "in range at x={$x}");
        }
        $this->assertLessThanOrEqual(self::DARK + 0.01, $row[31], 'the dark edge pixel is not brightened');
        $this->assertGreaterThanOrEqual(self::BRIGHT - 0.01, $row[32], 'the bright edge pixel is not darkened');
    }

    /**
     * Render $pass over a $size x $size source (left half DARK, right half BRIGHT)
     * and return the grey values of framebuffer row 16.
     *
     * @param callable(HeadlessShaderHarness): void $uniforms
     * @return array<int, float>
     */
    private function renderRow(string $pass, int $size, callable $uniforms): array
    {
        $h = HeadlessShaderHarness::open(64, 64);
        if ($h === null) {
            $this->markTestSkipped('No vio OpenGL context available.');
        }
        try {
            $dark = (int) round(self::DARK * 255);
            $bright = (int) round(self::BRIGHT * 255);
            $pixels = '';
            for ($y = 0; $y < $size; $y++) {
                for ($x = 0; $x < $size; $x++) {
                    $v = $x < $size / 2 ? $dark : $bright;
                    $pixels .= pack('C4', $v, $v, $v, 255);
                }
            }

            $shader = $h->compileShaderFromFiles('vio/postprocess.vert.glsl', "vio/{$pass}.frag.glsl");
            $pipeline = $h->createPipeline($shader);
            $rgba = $h->renderAndRead(
                $pipeline,
                $h->postProcessQuad(),
                function (HeadlessShaderHarness $h) use ($pixels, $size, $uniforms): void {
                    $h->bindPixelTexture($pixels, $size, $size, 0, 'u_source');
                    $uniforms($h);
                },
            );

            $row = [];
            for ($x = 0; $x < 64; $x++) {
                [$r] = $h->samplePixel($rgba, $x, 16);
                $row[$x] = $r;
            }
            return $row;
        } finally {
            $h->close();
        }
    }
}
