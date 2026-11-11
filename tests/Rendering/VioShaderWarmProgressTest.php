<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * The shader warm-up reports its progress on a real backend: as many calls as the
 * total announced with the first one, done running 1..total, whatever the backend
 * leaves out (outlines, MRT, baked light, froxel fog). The counting itself is
 * pinned without a GPU by ShaderWarmProgressTest and ShaderWarmPlanTest.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioShaderWarmProgressTest extends TestCase
{
    public function testProgressCallsMatchTheTotal(): void
    {
        $ctx = @vio_create('auto', ['width' => 32, 'height' => 32, 'headless' => true, 'vsync' => false]);
        if ($ctx === false) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        if (!vio_supports_feature($ctx, VIO_FEATURE_3D_PIPELINE)) {
            vio_destroy($ctx);
            $this->markTestSkipped('backend has no 3D pipeline');
        }

        try {
            /** @var list<array{0: int, 1: int, 2: string}> $calls */
            $calls = [];
            $renderer = new VioRenderer3D($ctx, 32, 32);
            $renderer->warmShaders(function (int $done, int $total, string $program) use (&$calls): void {
                $calls[] = [$done, $total, $program];
            });

            self::assertNotSame([], $calls);
            $total = $calls[0][1];
            self::assertCount($total, $calls, 'as many calls as the total announced with the first one');
            foreach ($calls as $i => [$done, $reportedTotal]) {
                self::assertSame($i + 1, $done, 'done runs 1..total without a gap');
                self::assertSame($total, $reportedTotal, 'the total never changes');
            }
            self::assertSame('default', $calls[0][2], 'the mesh program is compiled first');
            $programs = array_column($calls, 2);
            self::assertSame(array_values(array_unique($programs)), $programs, 'every program is reported once');

            // Warm already: a second call compiles nothing and reports nothing.
            $reported = count($calls);
            $renderer->warmShaders(function (int $done, int $total, string $program) use (&$calls): void {
                $calls[] = [$done, $total, $program];
            });
            self::assertCount($reported, $calls);
        } finally {
            vio_destroy($ctx);
        }
    }
}
