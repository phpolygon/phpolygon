<?php

declare(strict_types=1);

namespace PHPolygon\Fieldtracing;

/**
 * Ray-traced probe update (shader plan 10.1): the SH-L1 irradiance probe field of
 * GpuProbeBaker, but every probe ray goes through the scene's acceleration structure
 * (inline ray queries in a compute kernel) instead of marching an SDF volume. A hit
 * takes the instance's albedo (4 floats per instance in TLAS order, uploaded as a
 * float array: a vec4 array in a 16-byte-stride buffer reads wrong in D3D12
 * compute), lit by the fill
 * and the sun (a second ray); an escape takes the sky / ground gradient. Same output
 * layout: 12 floats per probe (ix + iy*pnx + iz*pnx*pny), so the caller's packing
 * and upload stay as they are. Needs VIO_FEATURE_RAY_QUERY; null otherwise.
 */
final class RtProbeBaker
{
    public const SHADER = <<<'GLSL'
        #version 460
        #extension GL_EXT_ray_query : require
        layout(local_size_x = 64) in;
        layout(binding = 0) uniform accelerationStructureEXT u_tlas;
        layout(std430, binding = 1) writeonly buffer Out { float coeffs[]; };
        layout(std430, binding = 2) readonly buffer Albedo { float albedo[]; };   // rgb + emission per instance
        layout(std140, binding = 3) uniform Params {
            ivec4 gp;    // pnx, pny, pnz, nDir
            vec4  o;     // origin.xyz, pcell
            vec4  sun;   // direction to the sun, strength
        };
        const float PI = 3.141592653589793;
        const float AMBIENT_FILL = 0.16;
        const vec3  SUN = vec3(1.00, 0.90, 0.72);
        const vec3  ZEN = vec3(0.30, 0.52, 0.86);
        const vec3  HOR = vec3(0.74, 0.82, 0.92);
        const vec3  GND = vec3(0.28, 0.27, 0.24);

        void main() {
            uint gid = gl_GlobalInvocationID.x;
            int pnx = gp.x, pny = gp.y, pnz = gp.z, nDir = gp.w;
            if (gid >= uint(pnx * pny * pnz)) return;
            int ix = int(gid) % pnx, iy = (int(gid) / pnx) % pny, iz = int(gid) / (pnx * pny);
            vec3 pp = o.xyz + vec3(float(ix), float(iy), float(iz)) * o.w;
            float Y0 = 0.282095, Y1 = 0.488603;
            float c0Scale = PI * Y0 * Y0, cdScale = (2.0 * PI / 3.0) * Y1 * Y1;
            float w = 4.0 * PI / float(nDir), ga = PI * (3.0 - sqrt(5.0));
            vec3 L = normalize(sun.xyz);
            vec4 R = vec4(0.0), G = vec4(0.0), B = vec4(0.0);
            for (int k = 0; k < nDir; k++) {
                float y = 1.0 - (float(k) + 0.5) / float(nDir) * 2.0;
                float r = sqrt(max(0.0, 1.0 - y * y));
                float phi = float(k) * ga;
                vec3 dir = vec3(cos(phi) * r, y, sin(phi) * r);
                vec3 rad;
                rayQueryEXT q;
                rayQueryInitializeEXT(q, u_tlas, gl_RayFlagsOpaqueEXT, 0xFF, pp, 0.05, dir, 500.0);
                while (rayQueryProceedEXT(q)) {}
                if (rayQueryGetIntersectionTypeEXT(q, true) != gl_RayQueryCommittedIntersectionNoneEXT) {
                    int id = rayQueryGetIntersectionInstanceIdEXT(q, true);
                    vec3 hp = pp + dir * rayQueryGetIntersectionTEXT(q, true);
                    vec3 alb = vec3(albedo[id * 4], albedo[id * 4 + 1], albedo[id * 4 + 2]);
                    rayQueryEXT s;
                    rayQueryInitializeEXT(s, u_tlas, gl_RayFlagsTerminateOnFirstHitEXT | gl_RayFlagsOpaqueEXT, 0xFF, hp - dir * 0.05, 0.02, L, 2000.0);
                    while (rayQueryProceedEXT(s)) {}
                    float vis = rayQueryGetIntersectionTypeEXT(s, true) == gl_RayQueryCommittedIntersectionNoneEXT ? 1.0 : 0.0;
                    rad = alb * (AMBIENT_FILL + sun.w * vis * SUN) + albedo[id * 4 + 3] * alb;
                } else if (dir.y > 0.0) {
                    float f = dir.y * dir.y;
                    rad = HOR + f * (ZEN - HOR);
                } else {
                    rad = GND;
                }
                vec4 basis = vec4(1.0, dir.x, dir.y, dir.z) * w;
                R += rad.r * basis; G += rad.g * basis; B += rad.b * basis;
            }
            uint base = gid * 12u;
            coeffs[base + 0u] = c0Scale * R.x; coeffs[base + 1u] = cdScale * R.y; coeffs[base + 2u] = cdScale * R.z; coeffs[base + 3u] = cdScale * R.w;
            coeffs[base + 4u] = c0Scale * G.x; coeffs[base + 5u] = cdScale * G.y; coeffs[base + 6u] = cdScale * G.z; coeffs[base + 7u] = cdScale * G.w;
            coeffs[base + 8u] = c0Scale * B.x; coeffs[base + 9u] = cdScale * B.y; coeffs[base + 10u] = cdScale * B.z; coeffs[base + 11u] = cdScale * B.w;
        }
        GLSL;

    /**
     * One colour channel's 4 coefficients per probe as RGBA8 (R=c0 .. A=c3), signed
     * over [-range, +range] - the SetFieldtracingProbes encoding. $offset 0/4/8 = R/G/B.
     *
     * @param list<float> $coeffs 12 per probe
     */
    public static function packChannel(array $coeffs, int $offset, float $range): string
    {
        $range = max($range, 1e-6);
        $bytes = '';
        $n = count($coeffs);
        for ($i = $offset; $i < $n; $i += 12) {
            for ($k = 0; $k < 4; $k++) {
                $b = (int) round((($coeffs[$i + $k] / $range) * 0.5 + 0.5) * 255.0);
                $bytes .= chr($b < 0 ? 0 : ($b > 255 ? 255 : $b));
            }
        }
        return $bytes;
    }

    /** @var array<int, \VioComputePipeline|false> */
    private static array $pipelines = [];

    /**
     * @param array{0: float, 1: float, 2: float} $origin
     * @param array{0: float, 1: float, 2: float} $sunDir direction towards the sun
     * @return list<float>|null 12 floats per probe
     */
    public static function tryFill(\VioContext $ctx, \VioAccelerationStructure $accel, string $albedo,
                                   array $origin, int $pnx, int $pny, int $pnz, float $pcell, int $nDir,
                                   array $sunDir, float $sunStrength = 0.85): ?array
    {
        if (!defined('VIO_FEATURE_RAY_QUERY') || !vio_supports_feature($ctx, VIO_FEATURE_RAY_QUERY)) {
            return null;
        }
        $id = spl_object_id($ctx);
        self::$pipelines[$id] ??= vio_compute_pipeline($ctx, ['source' => self::SHADER]);
        $p = self::$pipelines[$id];
        if ($p === false) {
            return null;
        }
        $probes = $pnx * $pny * $pnz;
        $out = vio_storage_buffer($ctx, ['size' => $probes * 48, 'stride' => 4]);
        $alb = $albedo !== '' ? vio_storage_buffer($ctx, ['data' => $albedo, 'stride' => 4]) : false;
        if ($out === false || $alb === false) {
            return null;
        }
        vio_bind_acceleration_structure($ctx, $accel, 0);
        vio_compute_bind_buffer($ctx, $p, $out, 1, VIO_COMPUTE_WRITE);
        vio_compute_bind_buffer($ctx, $p, $alb, 2, VIO_COMPUTE_READ);
        vio_compute_set_uniforms($ctx, $p, pack('l4', $pnx, $pny, $pnz, $nDir)
            . pack('f4', $origin[0], $origin[1], $origin[2], $pcell)
            . pack('f4', $sunDir[0], $sunDir[1], $sunDir[2], $sunStrength));
        vio_compute_dispatch($ctx, $p, (int) ceil($probes / 64), 1, 1);
        $bytes = vio_storage_buffer_read($ctx, $out);
        if ($bytes === false || strlen($bytes) < $probes * 48) {
            return null;
        }
        $data = unpack('f*', substr($bytes, 0, $probes * 48));
        return $data === false ? null : array_values(array_map(static fn (mixed $v): float => is_float($v) ? $v : 0.0, $data));
    }
}
