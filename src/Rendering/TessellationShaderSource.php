<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

/**
 * Tessellated draws (shader plan 10.2, Material::$tessellation): turns the scene vertex
 * shader into a tessellation pipeline that runs the same vertex code on every
 * generated vertex, so a surface whose shape comes from the vertex stage - the water
 * waves, the ocean spectrum - gains detail near the camera:
 *
 *  - vertex stage: passes the attributes through;
 *  - control stage: one patch per triangle, each edge split by its distance to the
 *    camera (u_tess = max level, full detail up to this distance, level 1 from this
 *    distance, on);
 *  - evaluation stage: interpolates the attributes at the generated point and runs
 *    the original main() (renamed) on them; its varyings leave unchanged, so the
 *    fragment stage is the program's own.
 *
 * Only the evaluation stage carries the vertex stage's uniforms: php-vio routes a
 * uniform name to one stage, so the control stage reads its own (u_tess, u_tess_eye
 * = camera position, u_tess_model = the draw's model matrix, u_tess_instanced) and
 * the pass-through vertex stage none.
 */
final class TessellationShaderSource
{
    private const ATTRS = [
        ['vec3', 'a_position'], ['vec3', 'a_normal'], ['vec2', 'a_uv'],
        ['vec4', 'a_instance_col0'], ['vec4', 'a_instance_col1'], ['vec4', 'a_instance_col2'], ['vec4', 'a_instance_col3'],
    ];

    private const UNIFORMS = "uniform vec4 u_tess;          // max level, full-detail distance, level-1 distance, on\n"
        . "uniform vec3 u_tess_eye;      // camera position\n"
        . "uniform mat4 u_tess_model;    // model matrix of a single draw\n"
        . "uniform int  u_tess_instanced; // 1: the model matrix rides in the instance attributes\n";

    public static function vertex(): string
    {
        $src = "#version 450\n// Tessellated draws (shader plan 10.2): attributes straight to the control stage.\n";
        foreach (self::ATTRS as $i => [$type, $name]) {
            $src .= "layout(location = {$i}) in {$type} {$name};\n";
        }
        foreach (self::ATTRS as $i => [$type, $name]) {
            $src .= "layout(location = {$i}) out {$type} tc_" . substr($name, 2) . ";\n";
        }
        $src .= "void main() {\n";
        foreach (self::ATTRS as [, $name]) {
            $src .= "    tc_" . substr($name, 2) . " = {$name};\n";
        }
        return $src . "    gl_Position = vec4(a_position, 1.0);\n}\n";
    }

    public static function control(): string
    {
        $src = "#version 450\nlayout(vertices = 3) out;\n" . self::UNIFORMS;
        // Explicit locations: the HLSL tessellation path of php-vio needs them.
        foreach (self::ATTRS as $i => [$type, $name]) {
            $short = substr($name, 2);
            $src .= "layout(location = {$i}) in {$type} tc_{$short}[];\nlayout(location = {$i}) out {$type} te_{$short}[];\n";
        }
        $src .= self::lib('control_head.glsl');
        foreach (self::ATTRS as [, $name]) {
            $short = substr($name, 2);
            $src .= "    te_{$short}[gl_InvocationID] = tc_{$short}[gl_InvocationID];\n";
        }
        $src .= self::lib('control_tail.glsl');
        return $src;
    }

    /**
     * The control stage as a Direct3D hull shader, for php-vio's HLSL stage override
     * (VIO_FEATURE_HLSL_STAGE_OVERRIDE): the hull shader php-vio transpiles from
     * {@see control()} makes the Intel D3D12 shader compiler abort the process.
     */
    public static function controlHlsl(): string
    {
        return self::lib('control.hlsl');
    }

    /** A fragment of the control stage from resources/shaders/lib/tessellation/. */
    private static function lib(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/resources/shaders/lib/tessellation/' . $name);
    }

    public static function evaluation(string $vertexSource): string
    {
        $head = "#version 450\nlayout(triangles, fractional_odd_spacing, ccw) in;\n"
            . "// Tessellated draws (shader plan 10.2): generated from the scene vertex shader by TessellationShaderSource.\n";
        foreach (self::ATTRS as $i => [$type, $name]) {
            $head .= "layout(location = {$i}) in {$type} te_" . substr($name, 2) . "[];\n";
        }
        $main = "\nvoid main() {\n    vec3 b = gl_TessCoord;\n";
        foreach (self::ATTRS as [, $name]) {
            $short = substr($name, 2);
            $main .= str_starts_with($short, 'instance')
                ? "    {$name} = te_{$short}[0];\n"
                : "    {$name} = te_{$short}[0] * b.x + te_{$short}[1] * b.y + te_{$short}[2] * b.z;\n";
        }
        $main .= "    a_normal = normalize(a_normal);\n    te_vertexMain();\n    gl_Position = te_Position;\n}\n";
        return $head . self::body($vertexSource, false, true) . $main;
    }

    /**
     * The vertex source without its #version line: main() renamed to te_vertexMain(),
     * gl_Position written to te_Position, gl_VertexID / gl_InstanceID read as 0, the
     * attributes private globals the evaluation stage fills.
     */
    private static function body(string $vertexSource, bool $keepAttributes, bool $keepOutputs): string
    {
        $src = str_replace("\r\n", "\n", $vertexSource);
        if (preg_match('/^\s*#version[^\n]*\n/', $src, $m) !== 1) {
            throw new \RuntimeException('TessellationShaderSource: the vertex source has no #version line');
        }
        $body = substr($src, strlen($m[0]));
        if (!$keepAttributes) {
            $body = (string) preg_replace('/^[ \t]*layout\s*\(\s*location\s*=\s*\d+\s*\)\s*in\s+(\w+)\s+(\w+)\s*;/m', '$1 $2;', $body);
        }
        if (!$keepOutputs) {
            $body = (string) preg_replace('/^([ \t]*)(?:(?:flat|smooth|noperspective)\s+)*out\s+(\w+)\s+(\w+)\s*;/m', '$1$2 $3;', $body);
        }
        $body = (string) preg_replace(['/\bgl_Position\b/', '/\bgl_VertexID\b/', '/\bgl_InstanceID\b/'], ['te_Position', '0', '0'], $body);
        $body = (string) preg_replace('/\bvoid\s+main\s*\(\s*(void)?\s*\)/', 'void te_vertexMain()', $body, -1, $mains);
        if ($mains !== 1) {
            throw new \RuntimeException('TessellationShaderSource: expected exactly one main() in the vertex source');
        }
        return "vec4 te_Position;\n" . $body;
    }
}
