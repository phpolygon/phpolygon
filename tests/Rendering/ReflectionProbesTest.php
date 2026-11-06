<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\TestCase;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Command\SetShaderParameter;
use PHPolygon\Rendering\Command\SetShaderTexture;
use PHPolygon\Rendering\ProcModeShaderRegistry;
use PHPolygon\Rendering\Reflection\ReflectionProbe;
use PHPolygon\Rendering\Reflection\ReflectionProbes;

final class ReflectionProbesTest extends TestCase
{
    protected function setUp(): void
    {
        ProcModeShaderRegistry::clear();
        ReflectionProbes::clear();
    }

    protected function tearDown(): void
    {
        ProcModeShaderRegistry::clear();
        ReflectionProbes::clear();
    }

    public function testRegisterDeclaresCubeSamplersOnlyInTheListedPrograms(): void
    {
        ProcModeShaderRegistry::defineVariant('water', [2]);
        ReflectionProbes::register(['water']);

        self::assertTrue(ReflectionProbes::isRegistered());
        self::assertTrue(ProcModeShaderRegistry::isCubeSampler(ReflectionProbes::samplerName(0)));
        $water = ProcModeShaderRegistry::uniformDeclarations(ProcModeShaderRegistry::FAMILY_VIO, 'water');
        self::assertStringContainsString('uniform samplerCube u_refl_probe_0;', $water);
        self::assertStringContainsString('uniform samplerCube u_refl_probe_1;', $water);
        self::assertStringContainsString('uniform vec4 u_refl_probe_min_0;', $water);
        $base = ProcModeShaderRegistry::uniformDeclarations(ProcModeShaderRegistry::FAMILY_VIO, ProcModeShaderRegistry::BASE_VARIANT);
        self::assertStringNotContainsString('samplerCube', $base);
        self::assertStringContainsString('reflectionProbeSample', ProcModeShaderRegistry::libraries(ProcModeShaderRegistry::FAMILY_VIO, 'water'));
        self::assertStringNotContainsString('reflectionProbeSample', ProcModeShaderRegistry::libraries(ProcModeShaderRegistry::FAMILY_VIO, ProcModeShaderRegistry::BASE_VARIANT));
    }

    public function testAPlainSamplerStaysASampler2D(): void
    {
        ProcModeShaderRegistry::declareSampler('u_plain');

        self::assertFalse(ProcModeShaderRegistry::isCubeSampler('u_plain'));
        self::assertStringContainsString('uniform sampler2D u_plain;', ProcModeShaderRegistry::uniformDeclarations());
    }

    public function testNearestRanksByDistanceThenBySmallerBox(): void
    {
        $big = ReflectionProbe::around('big', new Vec3(0.0, 0.0, 0.0), new Vec3(100.0, 50.0, 100.0));
        $small = ReflectionProbe::around('small', new Vec3(10.0, 0.0, 0.0), new Vec3(20.0, 20.0, 20.0));
        $far = ReflectionProbe::around('far', new Vec3(500.0, 0.0, 0.0), new Vec3(20.0, 20.0, 20.0));
        ReflectionProbes::set([$far, $big, $small]);

        // Inside both: the smaller box first.
        self::assertSame(['small', 'big'], self::ids(ReflectionProbes::nearest(new Vec3(5.0, 0.0, 0.0))));
        // Inside only the big one: then the nearer outside box.
        self::assertSame(['big', 'small'], self::ids(ReflectionProbes::nearest(new Vec3(-60.0, 0.0, 0.0))));
        // Next to the far one.
        self::assertSame(['far', 'big'], self::ids(ReflectionProbes::nearest(new Vec3(530.0, 0.0, 0.0))));
    }

    public function testCommandsBindTheChosenProbesAndClearUnusedSlots(): void
    {
        ReflectionProbes::set([ReflectionProbe::around('only', new Vec3(1.0, 2.0, 3.0), new Vec3(4.0, 5.0, 6.0), 2.5)]);

        $commands = ReflectionProbes::commandsFor(new Vec3(0.0, 0.0, 0.0));
        $textures = [];
        $params = [];
        foreach ($commands as $c) {
            if ($c instanceof SetShaderTexture) {
                $textures[$c->name] = $c->textureId;
            } elseif ($c instanceof SetShaderParameter) {
                $params[$c->name] = $c->value;
            }
        }
        self::assertSame(['u_refl_probe_0' => 'only', 'u_refl_probe_1' => null], $textures);
        self::assertSame([-3.0, -3.0, -3.0, 1.0], $params['u_refl_probe_min_0']);
        self::assertSame([5.0, 7.0, 9.0, 2.5], $params['u_refl_probe_max_0']);
        self::assertSame([1.0, 2.0, 3.0, 0.0], $params['u_refl_probe_pos_0']);
        self::assertSame(0.0, $params['u_refl_probe_min_1'][3]);
    }

    public function testAnEmptyBoxIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ReflectionProbe('flat', new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 0.0, 0.0), new Vec3(1.0, 0.0, 1.0));
    }

    /**
     * @param list<ReflectionProbe> $probes
     * @return list<string>
     */
    private static function ids(array $probes): array
    {
        return array_map(static fn (ReflectionProbe $p): string => $p->cubemapId, $probes);
    }
}
