<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\TestCase;
use PHPolygon\Rendering\TessellationShaderSource;

/**
 * The Direct3D hull shader (controlHlsl) stands in for the GLSL control stage: php-vio
 * keeps the GLSL stage's uniform layout, so the HLSL constant buffer has to declare the
 * same uniforms in the same order, and the tessellator settings have to match the
 * evaluation stage's layout.
 */
final class TessellationShaderSourceTest extends TestCase
{
    public function testTheHullShaderDeclaresTheControlStageUniformsInOrder(): void
    {
        preg_match_all('/^uniform\s+\w+\s+(\w+)\s*;/m', TessellationShaderSource::control(), $glsl);
        $hlsl = TessellationShaderSource::controlHlsl();
        self::assertSame(1, preg_match('/cbuffer\s+\w+\s*:\s*register\(b0\)\s*\{(.*?)\};/s', $hlsl, $block));
        preg_match_all('/(\w+)\s*:\s*packoffset/', $block[1], $names);

        self::assertNotEmpty($glsl[1]);
        self::assertSame($glsl[1], $names[1]);
    }

    public function testTheHullShaderTessellatesLikeTheEvaluationStage(): void
    {
        $evaluation = TessellationShaderSource::evaluation("#version 450\nvoid main() { gl_Position = vec4(0.0); }\n");
        $hlsl = TessellationShaderSource::controlHlsl();

        self::assertStringContainsString('layout(triangles, fractional_odd_spacing, ccw) in;', $evaluation);
        self::assertStringContainsString('[domain("tri")]', $hlsl);
        self::assertStringContainsString('[partitioning("fractional_odd")]', $hlsl);
        // php-vio turns the GLSL ccw winding into triangle_cw (D3D's y-down clip space).
        self::assertStringContainsString('[outputtopology("triangle_cw")]', $hlsl);
        self::assertStringContainsString('[outputcontrolpoints(3)]', $hlsl);
    }
}
