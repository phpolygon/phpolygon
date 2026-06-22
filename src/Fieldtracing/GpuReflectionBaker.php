<?php

declare(strict_types=1);

namespace PHPolygon\Fieldtracing;

use PHPolygon\Fieldtracing\Volume\SdfVolume;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\CubemapData;

/**
 * GPU offload for the CodeCity reflection-probe cubemap (the water mirror). It
 * reproduces CityReflectionBaker's CPU work — per cube texel, sphere-march the
 * SDF from the probe, shade a building hit or return the sky gradient, then a
 * 3×3 box blur — on the GPU via two compute passes, returning the same
 * {@see CubemapData} (6 faces of RGBA8). Best-effort: returns null on any
 * unavailability/failure so the caller falls back to the CPU path.
 *
 * The marching/shading/encoding math is a faithful port of CityReflectionBaker
 * + SdfVolume::sample + ProceduralCubemap so the optics match (verified by
 * tools/reflection_gpu_verify.php).
 */
final class GpuReflectionBaker
{
    private const LOCAL_SIZE = 64;

    /** Compiled compute pipelines (shade + blur), cached so the two shader
     *  compiles happen once — warmed during the splash, reused by every bake. */
    private static mixed $shadePipeline = null;
    private static mixed $blurPipeline = null;

    /**
     * Pre-compile + cache both compute pipelines (the two shader compiles are the
     * dominant cost — the actual march/blur is ~ms). Call once during the splash
     * so the world-load reflection bake is just bind+dispatch+readback. Safe to
     * call repeatedly; returns false when compute is unavailable.
     */
    public static function warm(\VioContext $ctx): bool
    {
        if (!GpuSdfBaker::isAvailable($ctx)) {
            return false;
        }
        if (self::$shadePipeline === null) {
            $p = vio_compute_pipeline($ctx, ['source' => self::SHADER_SHADE]);
            if ($p === false) {
                return false;
            }
            self::$shadePipeline = $p;
        }
        if (self::$blurPipeline === null) {
            $p = vio_compute_pipeline($ctx, ['source' => self::SHADER_BLUR]);
            if ($p === false) {
                return false;
            }
            self::$blurPipeline = $p;
        }
        return true;
    }

    /** Pass 1: per cube texel, march the SDF and shade → packed RGBA8 uint. */
    private const SHADER_SHADE = <<<'GLSL'
        #version 450
        layout(local_size_x = 64) in;

        layout(std430, binding = 0) readonly  buffer Sdf { float sdf[]; };
        layout(std430, binding = 1) writeonly buffer Out { uint  outc[]; };
        layout(std140, binding = 2) uniform Params {
            ivec4 dims;   // nx, ny, nz, res
            vec4  oc;     // origin.xyz, cell
            vec4  probe;  // probe.xyz, _
        };

        const float HIT_EPS  = 0.6;
        const float MIN_STEP = 0.5;
        const int   MAX_STEPS = 96;

        float distAt(int ix, int iy, int iz) {
            int nx = dims.x, ny = dims.y, nz = dims.z;
            ix = clamp(ix, 0, nx - 1); iy = clamp(iy, 0, ny - 1); iz = clamp(iz, 0, nz - 1);
            return sdf[ix + iy * nx + iz * nx * ny];
        }

        float sampleSdf(vec3 p) {
            float cell = oc.w;
            float gx = (p.x - oc.x) / cell, gy = (p.y - oc.y) / cell, gz = (p.z - oc.z) / cell;
            gx = clamp(gx, 0.0, float(dims.x - 1));
            gy = clamp(gy, 0.0, float(dims.y - 1));
            gz = clamp(gz, 0.0, float(dims.z - 1));
            int x0 = int(floor(gx)), y0 = int(floor(gy)), z0 = int(floor(gz));
            int x1 = min(x0 + 1, dims.x - 1), y1 = min(y0 + 1, dims.y - 1), z1 = min(z0 + 1, dims.z - 1);
            float fx = gx - float(x0), fy = gy - float(y0), fz = gz - float(z0);
            float c000 = distAt(x0,y0,z0), c100 = distAt(x1,y0,z0), c010 = distAt(x0,y1,z0), c110 = distAt(x1,y1,z0);
            float c001 = distAt(x0,y0,z1), c101 = distAt(x1,y0,z1), c011 = distAt(x0,y1,z1), c111 = distAt(x1,y1,z1);
            float c00 = mix(c000,c100,fx), c10 = mix(c010,c110,fx), c01 = mix(c001,c101,fx), c11 = mix(c011,c111,fx);
            float c0 = mix(c00,c10,fy), c1 = mix(c01,c11,fy);
            return mix(c0, c1, fz);
        }

        vec3 sky(vec3 d) {
            float y = clamp(d.y, -1.0, 1.0);
            if (y >= 0.0) return mix(vec3(0.74,0.82,0.92), vec3(0.30,0.52,0.86), y);
            return mix(vec3(0.20,0.24,0.26), vec3(0.58,0.64,0.68), y + 1.0);
        }

        vec3 shade(vec3 p, vec3 mn, vec3 mx) {
            float e = 0.75;
            float nx = sampleSdf(p + vec3(e,0,0)) - sampleSdf(p - vec3(e,0,0));
            float ny = sampleSdf(p + vec3(0,e,0)) - sampleSdf(p - vec3(0,e,0));
            float nz = sampleSdf(p + vec3(0,0,e)) - sampleSdf(p - vec3(0,0,e));
            vec3 n = vec3(nx, ny, nz);
            float nl = length(n);
            if (nl > 1e-6) n /= nl;
            vec3 L = normalize(vec3(0.25, 0.9, 0.15));
            float lambert = max(0.0, dot(n, L));
            float lit = 0.55 + 0.45 * lambert;
            float span = mx.y - mn.y;
            float h = span > 1e-6 ? clamp((p.y - mn.y) / span, 0.0, 1.0) : 0.5;
            vec3 base = mix(vec3(0.34,0.35,0.38), vec3(0.62,0.60,0.58), h);
            return base * lit;
        }

        // OpenGL cubemap face order +X,-X,+Y,-Y,+Z,-Z; basis = {forward, right, up}.
        void faceBasis(int f, out vec3 fwd, out vec3 right, out vec3 up) {
            if      (f == 0) { fwd = vec3( 1, 0, 0); right = vec3(0, 0,-1); up = vec3(0,-1, 0); }
            else if (f == 1) { fwd = vec3(-1, 0, 0); right = vec3(0, 0, 1); up = vec3(0,-1, 0); }
            else if (f == 2) { fwd = vec3( 0, 1, 0); right = vec3(1, 0, 0); up = vec3(0, 0, 1); }
            else if (f == 3) { fwd = vec3( 0,-1, 0); right = vec3(1, 0, 0); up = vec3(0, 0,-1); }
            else if (f == 4) { fwd = vec3( 0, 0, 1); right = vec3(1, 0, 0); up = vec3(0,-1, 0); }
            else             { fwd = vec3( 0, 0,-1); right = vec3(-1,0, 0); up = vec3(0,-1, 0); }
        }

        void main() {
            uint gid = gl_GlobalInvocationID.x;
            int res = dims.w;
            uint total = uint(6 * res * res);
            if (gid >= total) return;

            int face = int(gid) / (res * res);
            int idx  = int(gid) % (res * res);
            int y = idx / res, x = idx % res;

            vec3 fwd, right, up;
            faceBasis(face, fwd, right, up);
            float invRes = 1.0 / float(res);
            float u = 2.0 * (float(x) + 0.5) * invRes - 1.0;
            float v = 1.0 - 2.0 * (float(y) + 0.5) * invRes;
            vec3 dir = normalize(fwd + u * right + v * up);

            vec3 mn = vec3(oc.x, oc.y, oc.z);
            vec3 mx = mn + vec3(float(dims.x - 1), float(dims.y - 1), float(dims.z - 1)) * oc.w;

            vec3 col;
            float t = MIN_STEP;
            bool done = false;
            for (int s = 0; s < MAX_STEPS; s++) {
                vec3 p = probe.xyz + dir * t;
                if (p.x < mn.x || p.x > mx.x || p.y < mn.y || p.y > mx.y || p.z < mn.z || p.z > mx.z) {
                    col = sky(dir); done = true; break;
                }
                float d = sampleSdf(p);
                if (d < HIT_EPS) { col = shade(p, mn, mx); done = true; break; }
                t += d > MIN_STEP ? d : MIN_STEP;
            }
            if (!done) col = sky(dir);

            // packed little-endian RGBA8, a=255 — matches ProceduralCubemap::clampByte (int)(v*255+0.5).
            uint r = uint(clamp(col.r, 0.0, 1.0) * 255.0 + 0.5);
            uint g = uint(clamp(col.g, 0.0, 1.0) * 255.0 + 0.5);
            uint b = uint(clamp(col.b, 0.0, 1.0) * 255.0 + 0.5);
            outc[gid] = r | (g << 8) | (b << 16) | (255u << 24);
        }
        GLSL;

    /** Pass 2: per-face 3×3 box blur over the packed RGBA8 buffer. */
    private const SHADER_BLUR = <<<'GLSL'
        #version 450
        layout(local_size_x = 64) in;

        layout(std430, binding = 0) readonly  buffer In  { uint inc[];  };
        layout(std430, binding = 1) writeonly buffer Out { uint outc[]; };
        layout(std140, binding = 2) uniform Params { ivec4 d; }; // d.x = res

        void main() {
            uint gid = gl_GlobalInvocationID.x;
            int res = d.x;
            uint total = uint(6 * res * res);
            if (gid >= total) return;

            int face = int(gid) / (res * res);
            int idx  = int(gid) % (res * res);
            int y = idx / res, x = idx % res;
            int base = face * res * res;

            uint outv = 0u;
            for (int c = 0; c < 4; c++) {
                int sum = 0, count = 0;
                for (int dy = -1; dy <= 1; dy++) {
                    int yy = y + dy; if (yy < 0 || yy >= res) continue;
                    for (int dx = -1; dx <= 1; dx++) {
                        int xx = x + dx; if (xx < 0 || xx >= res) continue;
                        sum += int((inc[base + yy * res + xx] >> uint(8 * c)) & 0xFFu);
                        count++;
                    }
                }
                uint cv = uint(float(sum) / float(count) + 0.5);
                outv |= (cv & 0xFFu) << uint(8 * c);
            }
            outc[gid] = outv;
        }
        GLSL;

    /**
     * Bake the reflection cubemap on the GPU, or null when unavailable.
     */
    public static function tryBake(\VioContext $ctx, SdfVolume $vol, Vec3 $probe, int $resolution = 128): ?CubemapData
    {
        if (!GpuSdfBaker::isAvailable($ctx)) {
            return null;
        }

        try {
            $res = $resolution;
            $texels = 6 * $res * $res;
            $groups = intdiv($texels + self::LOCAL_SIZE - 1, self::LOCAL_SIZE);

            // SDF grid as a raw float[] SRV (re-uploaded; the bake's GPU copy was read back).
            $sdfBuf = vio_storage_buffer($ctx, ['data' => pack('f*', ...$vol->data), 'stride' => 4]);
            $shadeOut = vio_storage_buffer($ctx, ['size' => $texels * 4, 'stride' => 4]);
            if ($sdfBuf === false || $shadeOut === false) {
                return null;
            }

            // Pass 1 — march + shade (reuse the splash-warmed pipeline).
            $p1 = self::$shadePipeline ?? vio_compute_pipeline($ctx, ['source' => self::SHADER_SHADE]);
            if ($p1 === false) {
                return null;
            }
            self::$shadePipeline = $p1;
            $params1 = pack('l4', $vol->nx, $vol->ny, $vol->nz, $res)
                     . pack('f4', $vol->origin->x, $vol->origin->y, $vol->origin->z, $vol->cellSize)
                     . pack('f4', $probe->x, $probe->y, $probe->z, 0.0);
            vio_compute_set_uniforms($ctx, $p1, $params1);
            vio_compute_bind_buffer($ctx, $p1, $sdfBuf, 0, VIO_COMPUTE_READ);
            vio_compute_bind_buffer($ctx, $p1, $shadeOut, 1, VIO_COMPUTE_WRITE);
            vio_compute_dispatch($ctx, $p1, $groups, 1, 1);
            $shaded = vio_storage_buffer_read($ctx, $shadeOut);
            if ($shaded === false || strlen($shaded) < $texels * 4) {
                return null;
            }

            // Pass 2 — 3×3 box blur. Re-upload pass-1 output as a fresh SRV input
            // (avoids GPU-side UAV→SRV state churn; the blob passes through untouched).
            $blurIn = vio_storage_buffer($ctx, ['data' => $shaded, 'stride' => 4]);
            $blurOut = vio_storage_buffer($ctx, ['size' => $texels * 4, 'stride' => 4]);
            if ($blurIn === false || $blurOut === false) {
                return null;
            }
            $p2 = self::$blurPipeline ?? vio_compute_pipeline($ctx, ['source' => self::SHADER_BLUR]);
            if ($p2 === false) {
                return null;
            }
            self::$blurPipeline = $p2;
            vio_compute_set_uniforms($ctx, $p2, pack('l4', $res, 0, 0, 0));
            vio_compute_bind_buffer($ctx, $p2, $blurIn, 0, VIO_COMPUTE_READ);
            vio_compute_bind_buffer($ctx, $p2, $blurOut, 1, VIO_COMPUTE_WRITE);
            vio_compute_dispatch($ctx, $p2, $groups, 1, 1);
            $blurred = vio_storage_buffer_read($ctx, $blurOut);
            if ($blurred === false || strlen($blurred) < $texels * 4) {
                return null;
            }

            // Split the flat [face][y][x] RGBA8 blob into 6 face byte arrays.
            $faceBytes = $res * $res * 4;
            $faces = [];
            for ($f = 0; $f < 6; $f++) {
                /** @var list<int> $bytes */
                $bytes = array_values(unpack('C*', substr($blurred, $f * $faceBytes, $faceBytes)));
                $faces[] = $bytes;
            }
            return new CubemapData($res, $faces);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
