<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPolygon\Rendering\ProcModeShaderRegistry;
use PHPolygon\Rendering\Water\WaterShading;
use PHPUnit\Framework\TestCase;

/** Helper libraries several modes share: once per program, before the modes' helpers. */
final class ShaderLibraryRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        ProcModeShaderRegistry::clear();
    }

    protected function tearDown(): void
    {
        ProcModeShaderRegistry::clear();
    }

    public function testWithoutALibraryTheHelpersAreTheModesAlone(): void
    {
        ProcModeShaderRegistry::registerGlsl(2, "float a() { return 1.0; }\n", '');

        self::assertSame(
            "float a() { return 1.0; }\n",
            ProcModeShaderRegistry::spliceGlsl(ProcModeShaderRegistry::HELPERS_SENTINEL),
        );
    }

    public function testALibraryComesOnceBeforeTheModesThatCallIt(): void
    {
        ProcModeShaderRegistry::registerLibrary('shared', "float lib() { return 2.0; }\n");
        ProcModeShaderRegistry::registerGlsl(2, "float a() { return lib(); }\n", '');
        ProcModeShaderRegistry::registerGlsl(4, "float b() { return lib(); }\n", '');

        self::assertSame(
            "float lib() { return 2.0; }\nfloat a() { return lib(); }\nfloat b() { return lib(); }\n",
            ProcModeShaderRegistry::spliceGlsl(ProcModeShaderRegistry::HELPERS_SENTINEL),
        );
    }

    public function testTheGlFamilyGetsOnlyALibraryWithGlText(): void
    {
        ProcModeShaderRegistry::registerLibrary('sampling', "float s() { return 1.0; }\n");
        ProcModeShaderRegistry::registerLibrary('plain', "float p() { return 1.0; }\n", "float p() { return 0.5; }\n");

        self::assertSame(
            "float p() { return 0.5; }\n",
            ProcModeShaderRegistry::spliceGlsl(ProcModeShaderRegistry::HELPERS_SENTINEL, ProcModeShaderRegistry::FAMILY_GL),
        );
    }

    public function testALibraryLandsOnlyInItsPrograms(): void
    {
        ProcModeShaderRegistry::defineVariant('lean', [12]);
        ProcModeShaderRegistry::registerLibrary('base_only', "float l() { return 1.0; }\n", null, [ProcModeShaderRegistry::BASE_VARIANT]);

        $base = ProcModeShaderRegistry::spliceGlsl(ProcModeShaderRegistry::HELPERS_SENTINEL, ProcModeShaderRegistry::FAMILY_VIO, ProcModeShaderRegistry::BASE_VARIANT);
        $lean = ProcModeShaderRegistry::spliceGlsl(ProcModeShaderRegistry::HELPERS_SENTINEL, ProcModeShaderRegistry::FAMILY_VIO, 'lean');

        self::assertStringContainsString('float l()', $base);
        self::assertSame('', $lean);
    }

    public function testRegisteringANameAgainReplacesIt(): void
    {
        ProcModeShaderRegistry::registerLibrary('x', "float v() { return 1.0; }\n");
        ProcModeShaderRegistry::registerLibrary('x', "float v() { return 2.0; }\n");

        self::assertSame("float v() { return 2.0; }\n", ProcModeShaderRegistry::libraries());
    }

    public function testWaterShadingDeclaresWhatItsFunctionsRead(): void
    {
        WaterShading::register(programs: [ProcModeShaderRegistry::BASE_VARIANT]);
        $library = ProcModeShaderRegistry::libraries();
        $declarations = ProcModeShaderRegistry::uniformDeclarations(ProcModeShaderRegistry::FAMILY_VIO, ProcModeShaderRegistry::BASE_VARIANT);

        foreach (['vec3 waterCoast(', 'float waterShoreFoam(', 'float waterShoreWetness(', 'vec2 waterDetailSlope(', 'float waterCrestFoam(', 'vec3 waterWake(', 'vec3 waterRipples('] as $function) {
            self::assertStringContainsString($function, $library);
        }
        preg_match_all('/\bu_water_[a-z0-9_]+/', $library, $read);
        foreach (array_unique($read[0]) as $uniform) {
            self::assertMatchesRegularExpression('/uniform \w+ ' . $uniform . ';/', $declarations, "{$uniform} is read but not declared");
        }
        self::assertSame(1, count(ProcModeShaderRegistry::samplers()));
        ProcModeShaderRegistry::assertSamplerBudget();
    }

    public function testWaterShadingWithoutCoastTakesNoSampler(): void
    {
        WaterShading::register(coast: false);

        self::assertSame([], ProcModeShaderRegistry::samplers());
        self::assertStringNotContainsString('waterCoast', ProcModeShaderRegistry::libraries());
        self::assertStringContainsString('waterWake', ProcModeShaderRegistry::libraries());
    }
}
