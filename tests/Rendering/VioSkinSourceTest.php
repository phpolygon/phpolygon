<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPolygon\Rendering\VioRenderer3D;
use PHPUnit\Framework\TestCase;

/** The skinned programs are text-derived from the engine's vertex shaders: the anchors must keep matching. */
final class VioSkinSourceTest extends TestCase
{
    private function source(string $file): string
    {
        $path = dirname(__DIR__, 2) . '/resources/shaders/source/vio/' . $file;
        $src = file_get_contents($path);
        self::assertIsString($src);

        return $src;
    }

    public function testMeshVertexSourceIsSkinned(): void
    {
        $out = VioRenderer3D::skinVertexSource($this->source('mesh3d.vert.glsl'));
        self::assertStringContainsString('#version 450', $out);
        self::assertStringContainsString('layout(location = 7) in vec4 a_joints;', $out);
        self::assertStringContainsString('skinData[skinBase + int(a_joints.w + 0.5)] * a_weights.w', $out);
        self::assertStringContainsString('vec3 localNormal = skinNrm;', $out);
        self::assertStringNotContainsString('a_instance_col0', $out);
        // The normal and motion paths read the skinned position, not the bind pose.
        self::assertStringContainsString('animatePosition(skinPos, prevModel', $out);
    }

    public function testShadowVertexSourceIsSkinned(): void
    {
        $out = VioRenderer3D::skinShadowVertexSource($this->source('shadow.vert.glsl'));
        self::assertStringContainsString('vec3 pos = (skinM * vec4(a_position, 1.0)).xyz;', $out);
        self::assertStringContainsString('uniform int u_skin_inst;', $out);
    }

    public function testDriftedSourceIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        VioRenderer3D::skinVertexSource("#version 410 core\nvoid main() {}\n");
    }
}
