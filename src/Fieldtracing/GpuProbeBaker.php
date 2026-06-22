<?php

declare(strict_types=1);

namespace PHPolygon\Fieldtracing;

use PHPolygon\Fieldtracing\Volume\SdfVolume;

/**
 * GPU offload for a coloured SH-L1 irradiance probe field baked from an SDF
 * volume. One compute thread per probe: march {@see DIRECTIONS} Fibonacci-sphere
 * rays through the SDF, shade a 1-bounce hit (albedo × ambient + sun colour when
 * the hit point sees the sun) or the sky/ground gradient on escape, and
 * accumulate 12 SH-L1 coefficients per probe (4 per RGB channel).
 *
 * Returns a flat float[] (12 per probe, ix + iy*pnx + iz*pnx*pny order,
 * reconstruction constants folded in) — the same layout the CPU baker it stands
 * in for produces, so the caller's packing is unchanged. The marching/sampling/
 * shading math must match that CPU baker exactly. Best-effort: returns null on
 * any unavailability/failure so the caller falls back to the CPU path.
 */
final class GpuProbeBaker
{
    private const LOCAL_SIZE = 64;

    /** Cached compiled pipeline — warmed during the splash, reused per bake. */
    private static mixed $pipeline = null;

    /**
     * GLSL compute shader. Lighting constants (ALBEDO 0.55, AMBIENT_FILL 0.16,
     * SUN_STRENGTH 0.85, sky/ground gradient, sun dir) and the trilinear sampleSdf
     * — (int) truncation + clamp to [0, n-2], NOT SdfVolume::sample's floor/
     * clamp-to-(n-1) — must match the CPU baker this stands in for.
     */
    public const SHADER = <<<'GLSL'
        #version 450
        layout(local_size_x = 64) in;

        layout(std430, binding = 0) readonly  buffer Sdf { float sdf[]; };
        layout(std430, binding = 1) writeonly buffer Out { float coeffs[]; };
        layout(std140, binding = 2) uniform Params {
            ivec4 gi;   // nx, ny, nz, nDir
            ivec4 gp;   // pnx, pny, pnz, _
            vec4  oc;   // origin.xyz, cell
            vec4  pc;   // pcell, _, _, _
        };

        const float PI = 3.141592653589793;
        const float ALBEDO = 0.55;
        const float AMBIENT_FILL = 0.16;
        const float SUN_STRENGTH = 0.85;
        const vec3  SUN = vec3(1.00, 0.90, 0.72);
        const vec3  ZEN = vec3(0.30, 0.52, 0.86);
        const vec3  HOR = vec3(0.74, 0.82, 0.92);
        const vec3  GND = vec3(0.28, 0.27, 0.24);

        float sampleSdf(vec3 s) {
            int nx = gi.x, ny = gi.y, nz = gi.z;
            float cell = oc.w; vec3 o = oc.xyz;
            float gx = (s.x - o.x) / cell, gy = (s.y - o.y) / cell, gz = (s.z - o.z) / cell;
            int x0 = clamp(int(gx), 0, nx - 2);
            int y0 = clamp(int(gy), 0, ny - 2);
            int z0 = clamp(int(gz), 0, nz - 2);
            float fx = clamp(gx - float(x0), 0.0, 1.0);
            float fy = clamp(gy - float(y0), 0.0, 1.0);
            float fz = clamp(gz - float(z0), 0.0, 1.0);
            int nxny = nx * ny;
            int i000 = x0 + y0 * nx + z0 * nxny;
            int i100 = i000 + 1, i010 = i000 + nx, i110 = i010 + 1;
            int i001 = i000 + nxny, i101 = i001 + 1, i011 = i001 + nx, i111 = i011 + 1;
            float c00 = sdf[i000] + (sdf[i100] - sdf[i000]) * fx;
            float c10 = sdf[i010] + (sdf[i110] - sdf[i010]) * fx;
            float c01 = sdf[i001] + (sdf[i101] - sdf[i001]) * fx;
            float c11 = sdf[i011] + (sdf[i111] - sdf[i011]) * fx;
            float a0 = c00 + (c10 - c00) * fy, a1 = c01 + (c11 - c01) * fy;
            return a0 + (a1 - a0) * fz;
        }

        bool escapesToSky(vec3 p, vec3 dir, vec3 mn, vec3 mx, float cell) {
            float hitEps = cell * 0.5, minStep = cell * 0.75, t = cell * 1.5;
            for (int s = 0; s < 48; s++) {
                vec3 q = p + dir * t;
                if (q.x < mn.x || q.x > mx.x || q.y < mn.y || q.y > mx.y || q.z < mn.z || q.z > mx.z) return true;
                float dist = sampleSdf(q);
                if (dist < hitEps) return false;
                t += dist > minStep ? dist : minStep;
            }
            return true;
        }

        void main() {
            uint gid = gl_GlobalInvocationID.x;
            int pnx = gp.x, pny = gp.y, pnz = gp.z;
            uint total = uint(pnx * pny * pnz);
            if (gid >= total) return;

            int nx = gi.x, ny = gi.y, nz = gi.z, nDir = gi.w;
            float cell = oc.w, pcell = pc.x; vec3 o = oc.xyz;
            vec3 mn = o;
            vec3 mx = o + vec3(float(nx - 1), float(ny - 1), float(nz - 1)) * cell;

            int ix = int(gid) % pnx;
            int iy = (int(gid) / pnx) % pny;
            int iz = int(gid) / (pnx * pny);
            vec3 pp = o + vec3(float(ix), float(iy), float(iz)) * pcell;

            float Y0 = 0.282095, Y1 = 0.488603;
            float c0Scale = PI * Y0 * Y0;
            float cdScale = (2.0 * PI / 3.0) * Y1 * Y1;
            float w = 4.0 * PI / float(nDir);
            float ga = PI * (3.0 - sqrt(5.0));

            vec3 L = normalize(vec3(0.25, 0.9, 0.15));
            float hitEps = cell * 0.5, minStep = cell * 0.75, startT = cell * 1.25;

            vec4 R = vec4(0.0), G = vec4(0.0), B = vec4(0.0); // (c0,c1,c2,c3) per channel

            for (int k = 0; k < nDir; k++) {
                float y = 1.0 - (float(k) + 0.5) / float(nDir) * 2.0;
                float r = sqrt(max(0.0, 1.0 - y * y));
                float phi = float(k) * ga;
                vec3 dir = vec3(cos(phi) * r, y, sin(phi) * r);

                float t = startT;
                vec3 rad = vec3(AMBIENT_FILL * ALBEDO);
                bool hit = false; vec3 hp = vec3(0.0);
                for (int s = 0; s < 64; s++) {
                    vec3 q = pp + dir * t;
                    if (q.x < mn.x || q.x > mx.x || q.y < mn.y || q.y > mx.y || q.z < mn.z || q.z > mx.z) {
                        if (dir.y > 0.0) { float f = dir.y * dir.y; rad = HOR + f * (ZEN - HOR); }
                        else { rad = GND; }
                        break;
                    }
                    float dist = sampleSdf(q);
                    if (dist < hitEps) { hit = true; hp = q; break; }
                    t += dist > minStep ? dist : minStep;
                }

                if (hit) {
                    float sunVis = escapesToSky(hp, L, mn, mx, cell) ? 1.0 : 0.0;
                    float sf = ALBEDO * SUN_STRENGTH * sunVis;
                    rad = vec3(ALBEDO * AMBIENT_FILL) + sf * SUN;
                }

                vec4 basis = vec4(1.0, dir.x, dir.y, dir.z) * w;
                R += rad.r * basis;
                G += rad.g * basis;
                B += rad.b * basis;
            }

            uint base = gid * 12u;
            coeffs[base + 0u]  = c0Scale * R.x; coeffs[base + 1u]  = cdScale * R.y;
            coeffs[base + 2u]  = cdScale * R.z; coeffs[base + 3u]  = cdScale * R.w;
            coeffs[base + 4u]  = c0Scale * G.x; coeffs[base + 5u]  = cdScale * G.y;
            coeffs[base + 6u]  = cdScale * G.z; coeffs[base + 7u]  = cdScale * G.w;
            coeffs[base + 8u]  = c0Scale * B.x; coeffs[base + 9u]  = cdScale * B.y;
            coeffs[base + 10u] = cdScale * B.z; coeffs[base + 11u] = cdScale * B.w;
        }
        GLSL;

    /** Pre-compile + cache the pipeline (splash step). */
    public static function warm(\VioContext $ctx): bool
    {
        if (!GpuSdfBaker::isAvailable($ctx)) {
            return false;
        }
        if (self::$pipeline === null) {
            $p = vio_compute_pipeline($ctx, ['source' => self::SHADER]);
            if ($p === false) {
                return false;
            }
            self::$pipeline = $p;
        }
        return true;
    }

    /**
     * Compute the probe field on the GPU, or null when unavailable. Returns the
     * flat 12-per-probe coefficient array (same layout as the CPU baker).
     *
     * @return list<float>|null length pnx*pny*pnz*12
     */
    public static function tryFill(
        \VioContext $ctx,
        SdfVolume $sdf,
        int $pnx, int $pny, int $pnz, float $pcell, int $nDir,
    ): ?array {
        if (!GpuSdfBaker::isAvailable($ctx)) {
            return null;
        }

        try {
            $pipeline = self::$pipeline ?? vio_compute_pipeline($ctx, ['source' => self::SHADER]);
            if ($pipeline === false) {
                return null;
            }
            self::$pipeline = $pipeline;

            $probes = $pnx * $pny * $pnz;
            $sdfBuf = vio_storage_buffer($ctx, ['data' => pack('f*', ...$sdf->data), 'stride' => 4]);
            $outBuf = vio_storage_buffer($ctx, ['size' => $probes * 12 * 4, 'stride' => 4]);
            if ($sdfBuf === false || $outBuf === false) {
                return null;
            }

            $params = pack('l4', $sdf->nx, $sdf->ny, $sdf->nz, $nDir)
                    . pack('l4', $pnx, $pny, $pnz, 0)
                    . pack('f4', $sdf->origin->x, $sdf->origin->y, $sdf->origin->z, $sdf->cellSize)
                    . pack('f4', $pcell, 0.0, 0.0, 0.0);
            vio_compute_set_uniforms($ctx, $pipeline, $params);
            vio_compute_bind_buffer($ctx, $pipeline, $sdfBuf, 0, VIO_COMPUTE_READ);
            vio_compute_bind_buffer($ctx, $pipeline, $outBuf, 1, VIO_COMPUTE_WRITE);

            $groups = intdiv($probes + self::LOCAL_SIZE - 1, self::LOCAL_SIZE);
            vio_compute_dispatch($ctx, $pipeline, $groups, 1, 1);

            $bytes = vio_storage_buffer_read($ctx, $outBuf);
            if ($bytes === false || strlen($bytes) < $probes * 12 * 4) {
                return null;
            }
            /** @var list<float> $data */
            $data = array_values(unpack('f*', substr($bytes, 0, $probes * 12 * 4)));
            return $data;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
