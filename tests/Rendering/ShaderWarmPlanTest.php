<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\TestCase;
use PHPolygon\Rendering\ProcModeShaderRegistry;
use PHPolygon\Rendering\ShaderWarmPlan;

/**
 * The plan the shader progress counts against names what
 * VioRenderer3D::initShaders() compiles. The plan is a list next to the compile
 * sequence, not derived from it, so this test reads the sequence from the source
 * and fails when a program is added to one and not to the other.
 */
final class ShaderWarmPlanTest extends TestCase
{
    public function testThePlanNamesWhatInitShadersCompiles(): void
    {
        self::assertSame(
            $this->programsInInitShaders(),
            ShaderWarmPlan::programs(outline: true, mrt: true),
            'ShaderWarmPlan and VioRenderer3D::initShaders() list the same programs in the same order',
        );
    }

    public function testOptionalGroupsFollowTheBackend(): void
    {
        $full = ShaderWarmPlan::programs(true, true);
        $forward = ShaderWarmPlan::programs(true, false);
        $bare = ShaderWarmPlan::programs(false, false);

        self::assertContains('outline', $forward);
        self::assertNotContains('outline', $bare);
        self::assertSame([], array_filter($forward, static fn (string $id): bool => str_ends_with($id, '_mrt') || $id === 'composite'));
        self::assertContains('froxel_apply_mrt', $full);
        self::assertSame('default', $bare[0], 'the mesh program is compiled first');
        self::assertSame(array_values(array_intersect($full, $bare)), $bare, 'a smaller plan keeps the order');
    }

    public function testVariantsAddTheirPrograms(): void
    {
        $base = ShaderWarmPlan::programs(true, true);
        $plan = ShaderWarmPlan::programs(true, true, ['water', ProcModeShaderRegistry::BASE_VARIANT, 'profile:far+cutout', 'water']);

        self::assertSame(
            [...$base, 'default@water', 'default_mrt@water', 'default@profile:far+cutout', 'default_mrt@profile:far+cutout'],
            $plan,
        );
        self::assertSame(
            [...ShaderWarmPlan::programs(false, false), 'default@water'],
            ShaderWarmPlan::programs(false, false, ['water']),
            'no MRT twin on the forward path',
        );
    }

    public function testEveryProgramIsListedOnce(): void
    {
        $plan = ShaderWarmPlan::programs(true, true, ['a', 'b', 'a']);
        self::assertSame(array_values(array_unique($plan)), $plan);
    }

    /**
     * The program ids of initShaders() in source order, each once: the literal
     * compileShaderFromFiles() calls, and the '<layer>_mrt' loop over the sky layers.
     *
     * @return list<string>
     */
    private function programsInInitShaders(): array
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Rendering/VioRenderer3D.php');
        if (preg_match('/private function initShaders\(.*?\n    }\r?\n/s', $source, $found) !== 1) {
            self::fail('initShaders() not found in VioRenderer3D.php');
        }
        $body = $found[0];

        $found = preg_match_all(
            '/compileShaderFromFiles\(\'([a-z0-9_]+)\'|foreach \(\[([^\]]+)\] as \$sky\)/',
            $body,
            $matches,
            PREG_SET_ORDER,
        );
        self::assertGreaterThan(20, $found);

        $programs = [];
        $dynamic = 0;
        foreach ($matches as $match) {
            if (($match[2] ?? '') !== '') {
                $dynamic++;
                preg_match_all('/\'([a-z0-9_]+)\'/', $match[2], $layers);
                foreach ($layers[1] as $layer) {
                    $programs[] = $layer . '_mrt';
                }
                continue;
            }
            $programs[] = $match[1] ?? '';
        }
        // Any other compile with a computed id would be invisible to this reading.
        self::assertSame(1, $dynamic, 'one loop over the sky layers');
        self::assertSame(
            $dynamic,
            preg_match_all('/compileShaderFromFiles\((?!\')/', $body),
            'every compile in initShaders() has a literal id, except the sky layer loop',
        );

        return array_values(array_unique($programs));
    }
}
