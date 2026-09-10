<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use PHPolygon\Rendering\VioOffscreenTarget;

/**
 * The render-scale / anti-aliasing offscreen target really multisamples on
 * every vio backend that reports VIO_FEATURE_RENDER_TARGET_MSAA — including
 * D3D12 since php-vio 2.12 (PSO sample variants), where AntiAliasing::Msaa4x
 * used to allocate a target that silently stayed single-sampled.
 *
 * A green triangle whose hypotenuse cuts the target diagonally is drawn into a
 * 4-sample VioOffscreenTarget; after unbind the interior is pure green, the
 * exterior black, and some pixel on the diagonal is a coverage blend — which
 * only a multisampled target can produce.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioOffscreenTargetMsaaTest extends TestCase
{
    private const S = 64;

    public function testMsaaTargetResolvesCoverageBlendedEdges(): void
    {
        $ctx = @vio_create('auto', ['width' => 32, 'height' => 32, 'headless' => true, 'vsync' => false]);
        if ($ctx === false) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        if (!defined('VIO_FEATURE_RENDER_TARGET_MSAA') || !vio_supports_feature($ctx, VIO_FEATURE_RENDER_TARGET_MSAA)
            || !vio_supports_feature($ctx, VIO_FEATURE_3D_PIPELINE)) {
            vio_destroy($ctx);
            $this->markTestSkipped('backend has no multisampled render targets');
        }

        $target = new VioOffscreenTarget($ctx);
        $target->resize(self::S, self::S, 4);
        self::assertTrue($target->isAllocated());
        self::assertSame(4, $target->samples(), 'vio accepted the 4-sample target');
        self::assertTrue($target->msaaSupported() === true);
        $rt = $target->renderTarget();
        self::assertNotNull($rt);

        $raw = vio_backend_name($ctx) === 'opengl';
        $fmt = $raw ? VIO_SHADER_GLSL_RAW : VIO_SHADER_GLSL;
        $vs = "#version 330 core\nlayout(location=0) in vec3 aPos;\nvoid main(){ gl_Position = vec4(aPos, 1.0); }";
        $fs = "#version 330 core\nlayout(location=0) out vec4 o;\nvoid main(){ o = vec4(0.0, 1.0, 0.0, 1.0); }";
        $shader = vio_shader($ctx, ['vertex' => $vs, 'fragment' => $fs, 'format' => $fmt]);
        self::assertNotFalse($shader);
        $pipeline = vio_pipeline($ctx, ['shader' => $shader, 'depth_test' => false, 'cull_mode' => VIO_CULL_NONE]);
        self::assertNotFalse($pipeline);
        // Lower-left half: hypotenuse from top-left to bottom-right.
        $tri = vio_mesh($ctx, ['vertices' => [-1, -1, 0,  1, -1, 0,  -1, 1, 0], 'layout' => [VIO_FLOAT3]]);
        self::assertNotFalse($tri);

        vio_begin($ctx);
        $target->bindForDraw();
        vio_viewport($ctx, 0, 0, self::S, self::S);
        vio_clear($ctx, 0, 0, 0, 1);
        vio_bind_pipeline($ctx, $pipeline);
        vio_draw($ctx, $tri);
        $target->unbind();
        vio_end($ctx);

        $pixels = vio_read_render_target($rt);
        self::assertNotFalse($pixels);
        self::assertSame(self::S * self::S * 4, strlen($pixels));

        $px = static function (int $x, int $y) use ($pixels): array {
            $o = ($y * self::S + $x) * 4;
            return [ord($pixels[$o]), ord($pixels[$o + 1]), ord($pixels[$o + 2])];
        };
        // Read-back is top-down: the triangle covers x + (S-1-y) < S.
        $interior = $px(8, self::S - 8);
        $exterior = $px(self::S - 8, 8);
        self::assertEqualsWithDelta(255, $interior[1], 3, 'interior is green');
        self::assertLessThan(4, $interior[0] + $interior[2], 'interior is pure green');
        self::assertLessThan(4, $exterior[0] + $exterior[1] + $exterior[2], 'exterior is black');

        $blend = false;
        for ($i = 2; $i < self::S - 2; $i++) {
            foreach ([[$i, $i], [$i, $i - 1], [$i - 1, $i]] as [$x, $y]) {
                $g = $px($x, $y)[1];
                if ($g > 20 && $g < 235) {
                    $blend = true;
                    break 2;
                }
            }
        }
        self::assertTrue($blend, 'a pixel on the diagonal must be coverage-blended (the target resolved multiple samples)');

        $target->release();
        vio_destroy($ctx);
    }
}
