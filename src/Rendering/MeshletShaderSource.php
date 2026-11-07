<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

/**
 * Turns the scene vertex shader into the task and mesh stage of the mesh-shader
 * path (shader plan 10.1, VIO_FEATURE_MESH_SHADER), so both paths run the very
 * same vertex code - animation, normals, motion vectors and every variant define
 * included:
 *
 *  - the vertex attributes become private globals the mesh stage fills from the
 *    storage buffers (binding 0 vertices, 1 meshlets, 2 indices, 3 instance
 *    matrices; see {@see \PHPolygon\Geometry\MeshletBuilder});
 *  - every varying `out T v;` becomes the per-vertex output array `out T v[];`
 *    plus a private `T ms_v;` the vertex code writes, copied into slot i after it
 *    ran (inside the same #if as the declaration);
 *  - gl_Position / gl_VertexID / gl_InstanceID become private globals, main()
 *    becomes ms_vertexMain().
 *
 * The task stage gets the same text without outputs, so both stages declare the
 * identical uniform block (php-vio shares the vertex block between them). It tests
 * 32 meshlets of one instance per workgroup against the view frustum and launches
 * a mesh workgroup per visible one. Group (x, y) = (meshlet chunk, instance -
 * u_ms_params.y); u_ms_params = (meshlet count, instance base, instance count,
 * radius margin for vertex animation).
 */
final class MeshletShaderSource
{
    public const TASK_GROUP = 32;
    public const MESH_GROUP = 64;

    public static function mesh(string $vertexSource): string
    {
        [$src, $copies] = self::transform($vertexSource, true);
        $g = self::MESH_GROUP;
        $v = \PHPolygon\Geometry\MeshletBuilder::MAX_VERTICES;
        $t = \PHPolygon\Geometry\MeshletBuilder::MAX_TRIANGLES;
        return $src . <<<GLSL

            layout(local_size_x = {$g}) in;
            layout(triangles, max_vertices = {$v}, max_primitives = {$t}) out;
            void main() {
                uint mb = ms_payload.meshlets[gl_WorkGroupID.x] * 8u;
                uint vOff = ms_meshlets[mb];
                uint vCnt = ms_meshlets[mb + 1u];
                uint tOff = ms_meshlets[mb + 2u];
                uint tCnt = ms_meshlets[mb + 3u];
                SetMeshOutputsEXT(vCnt, tCnt);
                uint li = gl_LocalInvocationIndex;
                if (li < vCnt) {
                    uint vi = ms_indices[vOff + li];
                    uint vb = vi * 8u;
                    a_position = vec3(ms_vertices[vb], ms_vertices[vb + 1u], ms_vertices[vb + 2u]);
                    a_normal = vec3(ms_vertices[vb + 3u], ms_vertices[vb + 4u], ms_vertices[vb + 5u]);
                    a_uv = vec2(ms_vertices[vb + 6u], ms_vertices[vb + 7u]);
                    uint ib = ms_payload.instance * 16u;
                    a_instance_col0 = vec4(ms_instances[ib], ms_instances[ib + 1u], ms_instances[ib + 2u], ms_instances[ib + 3u]);
                    a_instance_col1 = vec4(ms_instances[ib + 4u], ms_instances[ib + 5u], ms_instances[ib + 6u], ms_instances[ib + 7u]);
                    a_instance_col2 = vec4(ms_instances[ib + 8u], ms_instances[ib + 9u], ms_instances[ib + 10u], ms_instances[ib + 11u]);
                    a_instance_col3 = vec4(ms_instances[ib + 12u], ms_instances[ib + 13u], ms_instances[ib + 14u], ms_instances[ib + 15u]);
                    ms_VertexID = int(vi);
                    ms_InstanceID = int(ms_payload.instance);
                    ms_vertexMain();
                    gl_MeshVerticesEXT[li].gl_Position = ms_Position;
            {$copies}    }
                for (uint t = li; t < tCnt; t += {$g}u) {
                    uint p = ms_indices[tOff + t];
                    gl_PrimitiveTriangleIndicesEXT[t] = uvec3(p & 255u, (p >> 8u) & 255u, (p >> 16u) & 255u);
                }
            }

            GLSL;
    }

    public static function task(string $vertexSource): string
    {
        [$src] = self::transform($vertexSource, false);
        $g = self::TASK_GROUP;
        return $src . <<<GLSL

            layout(local_size_x = {$g}) in;
            shared uint ms_visible;
            void main() {
                uint inst = gl_WorkGroupID.y + uint(u_ms_params.y);
                uint m = gl_WorkGroupID.x * {$g}u + gl_LocalInvocationIndex;
                if (gl_LocalInvocationIndex == 0u) {
                    ms_visible = 0u;
                    ms_payload.instance = inst;
                }
                barrier();
                if (m < uint(u_ms_params.x) && inst < uint(u_ms_params.z)) {
                    uint ib = inst * 16u;
                    mat4 model = mat4(
                        vec4(ms_instances[ib], ms_instances[ib + 1u], ms_instances[ib + 2u], 0.0),
                        vec4(ms_instances[ib + 4u], ms_instances[ib + 5u], ms_instances[ib + 6u], 0.0),
                        vec4(ms_instances[ib + 8u], ms_instances[ib + 9u], ms_instances[ib + 10u], 0.0),
                        vec4(ms_instances[ib + 12u], ms_instances[ib + 13u], ms_instances[ib + 14u], 1.0));
                    uint mb = m * 8u;
                    vec4 c = model * vec4(uintBitsToFloat(ms_meshlets[mb + 4u]), uintBitsToFloat(ms_meshlets[mb + 5u]), uintBitsToFloat(ms_meshlets[mb + 6u]), 1.0);
                    float s = max(length(model[0].xyz), max(length(model[1].xyz), length(model[2].xyz)));
                    float r = (uintBitsToFloat(ms_meshlets[mb + 7u]) + u_ms_params.w) * s;
                    mat4 vp = u_projection * u_view;
                    vec4 r0 = vec4(vp[0][0], vp[1][0], vp[2][0], vp[3][0]);
                    vec4 r1 = vec4(vp[0][1], vp[1][1], vp[2][1], vp[3][1]);
                    vec4 r2 = vec4(vp[0][2], vp[1][2], vp[2][2], vp[3][2]);
                    vec4 r3 = vec4(vp[0][3], vp[1][3], vp[2][3], vp[3][3]);
                    vec4 planes[6] = vec4[6](r3 + r0, r3 - r0, r3 + r1, r3 - r1, r3 + r2, r3 - r2);
                    bool visible = true;
                    for (int i = 0; i < 6; i++) {
                        if (dot(planes[i].xyz, c.xyz) + planes[i].w < -r * length(planes[i].xyz)) {
                            visible = false;
                        }
                    }
                    if (visible) {
                        uint slot = atomicAdd(ms_visible, 1u);
                        ms_payload.meshlets[slot] = m;
                    }
                }
                barrier();
                EmitMeshTasksEXT(ms_visible, 1u, 1u);
            }

            GLSL;
    }

    /**
     * @return array{0: string, 1: string} transformed source, varying copies for the mesh main
     */
    private static function transform(string $vertexSource, bool $mesh): array
    {
        $src = str_replace("\r\n", "\n", $vertexSource);
        if (preg_match('/^\s*#version[^\n]*\n/', $src, $m) !== 1) {
            throw new \RuntimeException('MeshletShaderSource: the vertex source has no #version line');
        }
        $body = substr($src, strlen($m[0]));
        foreach (['a_position', 'a_normal', 'a_uv', 'a_instance_col0', 'a_instance_col3'] as $attr) {
            if (preg_match('/\b' . $attr . '\b/', $body) !== 1) {
                throw new \RuntimeException("MeshletShaderSource: the vertex source does not use {$attr}");
            }
        }

        // Attributes -> private globals.
        $body = (string) preg_replace('/^[ \t]*layout\s*\(\s*location\s*=\s*\d+\s*\)\s*in\s+(\w+)\s+(\w+)\s*;/m', '$1 $2;', $body);

        // Varyings, with the preprocessor conditions they are declared under.
        $outs = [];
        $stack = [];
        foreach (explode("\n", $body) as $line) {
            $trim = trim($line);
            if (preg_match('/^#\s*(if|ifdef|ifndef)\b/', $trim) === 1) {
                $stack[] = $trim;
            } elseif (preg_match('/^#\s*(else|elif)\b/', $trim) === 1) {
                $stack[] = null; // a branch we cannot restate: varyings below it are refused
            } elseif (preg_match('/^#\s*endif\b/', $trim) === 1) {
                while ($stack !== [] && end($stack) === null) {
                    array_pop($stack);
                }
                array_pop($stack);
            } elseif (preg_match('/^((?:flat|smooth|noperspective)\s+)*out\s+(\w+)\s+(\w+)\s*;/', $trim, $o) === 1) {
                if (in_array(null, $stack, true)) {
                    throw new \RuntimeException("MeshletShaderSource: varying {$o[3]} under #else/#elif is not supported");
                }
                $outs[] = ['qual' => trim($o[1]), 'type' => $o[2], 'name' => $o[3], 'cond' => $stack];
            }
        }

        foreach ($outs as $o) {
            $body = (string) preg_replace('/\b' . $o['name'] . '\b/', 'ms_' . $o['name'], $body);
        }
        $body = (string) preg_replace_callback(
            '/^([ \t]*)((?:(?:flat|smooth|noperspective)\s+)*)out\s+(\w+)\s+ms_(\w+)\s*;/m',
            static fn (array $d): string => $mesh
                ? "{$d[1]}{$d[2]}out {$d[3]} {$d[4]}[];\n{$d[1]}{$d[3]} ms_{$d[4]};"
                : "{$d[1]}{$d[3]} ms_{$d[4]};",
            $body,
        );
        $body = (string) preg_replace(['/\bgl_Position\b/', '/\bgl_VertexID\b/', '/\bgl_InstanceID\b/'], ['ms_Position', 'ms_VertexID', 'ms_InstanceID'], $body);
        $body = (string) preg_replace('/\bvoid\s+main\s*\(\s*(void)?\s*\)/', 'void ms_vertexMain()', $body, -1, $mains);
        if ($mains !== 1) {
            throw new \RuntimeException('MeshletShaderSource: expected exactly one main() in the vertex source');
        }

        $head ="#version 450\n#extension GL_EXT_mesh_shader : require\n"
            . "// Meshlet path (shader plan 10.1): generated from the scene vertex shader by MeshletShaderSource.\n"
            . "uniform vec4 u_ms_params; // meshlet count, instance base, instance count, radius margin\n"
            . "layout(std430, binding = 0) readonly buffer MsVertices { float ms_vertices[]; };\n"
            . "layout(std430, binding = 1) readonly buffer MsMeshlets { uint ms_meshlets[]; };\n"
            . "layout(std430, binding = 2) readonly buffer MsIndices { uint ms_indices[]; };\n"
            . "layout(std430, binding = 3) readonly buffer MsInstances { float ms_instances[]; };\n"
            . "struct MsPayload { uint instance; uint meshlets[" . self::TASK_GROUP . "]; };\n"
            . "taskPayloadSharedEXT MsPayload ms_payload;\n"
            . "vec4 ms_Position;\nint ms_VertexID;\nint ms_InstanceID;\n";

        $copies = '';
        foreach ($outs as $o) {
            $line = "        {$o['name']}[li] = ms_{$o['name']};\n";
            foreach (array_reverse($o['cond']) as $cond) {
                $line = "{$cond}\n{$line}#endif\n";
            }
            $copies .= $line;
        }
        return [$head . $body, $copies];
    }
}
