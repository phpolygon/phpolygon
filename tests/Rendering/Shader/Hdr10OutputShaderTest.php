<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering\Shader;

use PHPolygon\Testing\Shader\HeadlessShaderHarness;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * The present shaders PQ-encode their output for an HDR10 backbuffer
 * (u_output_pq = 1): display white at 200 nits paper white must land at the
 * ST 2084 code value ~0.58 (the same value php-vio's 2D batch produces, so UI
 * and scene agree), black stays black, and with the flag off the image is
 * passed through unchanged.
 */
#[RequiresPhpExtension('vio')]
final class Hdr10OutputShaderTest extends TestCase
{
    private ?HeadlessShaderHarness $h = null;

    protected function setUp(): void
    {
        $this->h = HeadlessShaderHarness::open(16, 16);
        if ($this->h === null) {
            $this->markTestSkipped('No vio OpenGL context available.');
        }
    }

    protected function tearDown(): void
    {
        $this->h?->close();
        $this->h = null;
    }

    public function testPassthroughEncodesWhiteToPqCodeValueOnlyWhenAsked(): void
    {
        $h = $this->h;
        $this->assertNotNull($h);
        $shader = $h->compileShaderFromFiles('vio/postprocess.vert.glsl', 'vio/passthrough_blit.frag.glsl');
        $pipeline = $h->createPipeline($shader);

        $render = function (int $pq) use ($h, $pipeline): array {
            $rgba = $h->renderAndRead($pipeline, $h->fullscreenQuad(), function (HeadlessShaderHarness $h) use ($pq): void {
                $h->bindDummyMaterialSamplers();          // u_source = 1x1 white at unit 0
                $h->setUniform('u_source', 0);
                $h->setUniform('u_bloom_intensity', 0.0);
                $h->setUniform('u_hdr_resolve', 0);
                $h->setUniform('u_exposure', 1.0);
                $h->setUniform('u_grade_lift', [0.0, 0.0, 0.0]);
                $h->setUniform('u_grade_gamma', [1.0, 1.0, 1.0]);
                $h->setUniform('u_grade_gain', [1.0, 1.0, 1.0]);
                $h->setUniform('u_grade_saturation', 1.0);
                $h->setUniform('u_vignette_intensity', 0.0);
                $h->setUniform('u_viewport_size', [16.0, 16.0]);
                $h->setUniform('u_output_pq', $pq);
                $h->setUniform('u_paper_white', 200.0);
            });
            return $h->samplePixel($rgba, 8, 8);
        };

        [$r, $g, $b] = $render(0);
        $this->assertEqualsWithDelta(1.0, $r, 0.01, 'sRGB backbuffer: white passes through');
        $this->assertEqualsWithDelta(1.0, $g, 0.01);
        $this->assertEqualsWithDelta(1.0, $b, 0.01);

        [$r, $g, $b] = $render(1);
        // PQ(200 nits) = ((c1 + c2*y)/(1 + c3*y))^m2 with y = (200/10000)^m1 → 0.5806.
        $this->assertEqualsWithDelta(0.58, $r, 0.02, 'HDR10: white encodes to the 200-nit PQ code value');
        $this->assertEqualsWithDelta($r, $g, 0.01, 'neutral stays neutral');
        $this->assertEqualsWithDelta($r, $b, 0.01);
    }
}
