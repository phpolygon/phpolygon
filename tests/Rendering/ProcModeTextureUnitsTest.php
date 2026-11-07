<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPolygon\Rendering\ProcModeShaderRegistry;
use PHPUnit\Framework\TestCase;

/** The snippet sampler budget follows the context's texture units (php-vio vio_texture_units). */
final class ProcModeTextureUnitsTest extends TestCase
{
    protected function tearDown(): void
    {
        ProcModeShaderRegistry::setTextureUnits(null);
    }

    public function testUnknownUnitsKeepTheVersionBasedBudget(): void
    {
        ProcModeShaderRegistry::setTextureUnits(null);

        self::assertNull(ProcModeShaderRegistry::textureUnits());
        self::assertSame(ProcModeShaderRegistry::MAX_SAMPLERS, ProcModeShaderRegistry::maxSamplers());
    }

    public function testSixteenUnitsMatchTheFormerTwelvePlusFourSplit(): void
    {
        ProcModeShaderRegistry::setTextureUnits(16);

        self::assertSame(ProcModeShaderRegistry::SAMPLER_REGISTERS_2_40, ProcModeShaderRegistry::samplerRegisters());
        self::assertSame(4, ProcModeShaderRegistry::maxSamplers());
    }

    public function testMoreUnitsGiveSnippetsMoreSamplers(): void
    {
        ProcModeShaderRegistry::setTextureUnits(768);

        self::assertSame(768 - ProcModeShaderRegistry::COMPARISON_SAMPLERS, ProcModeShaderRegistry::samplerRegisters());
        // All units above the engine's, less the normal map's slot that snippets skip.
        self::assertSame(768 - ProcModeShaderRegistry::FIRST_SAMPLER_SLOT - 1, ProcModeShaderRegistry::maxSamplers());
        self::assertTrue(ProcModeShaderRegistry::pointShadowAvailable());
    }

    public function testNonPositiveUnitsAreIgnored(): void
    {
        ProcModeShaderRegistry::setTextureUnits(0);

        self::assertNull(ProcModeShaderRegistry::textureUnits());
    }
}
