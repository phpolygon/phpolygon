#include <metal_stdlib>
using namespace metal;

// ─── Sky / atmospheric fragment shader ──────────────────────────────────────
//
// Direct port of VioRenderer3D::ATMOSPHERE_FRAG (GLSL). Renders a fullscreen
// triangle whose fragments evaluate sky colour, sun disc, moon disc, stars,
// clouds, and horizon haze analytically per pixel — no skybox texture.
//
// Vertex shader emits a fullscreen triangle from a single attribute-less
// vertex_id (no vertex buffer needed). Fragment shader reconstructs the
// world-space view direction from the inverse(projection * rotation_view)
// matrix and the NDC coordinate.

struct SkyUBO {
    float4x4 inv_vp;            // inverse(projection * view_without_translation)
    packed_float3 camera_pos;
    float  time;
    packed_float3 sun_direction;
    float  sun_intensity;
    packed_float3 sun_color;
    float  sun_size;
    packed_float3 zenith_color;
    float  sun_glow_size;
    packed_float3 horizon_color;
    float  sun_glow_intensity;
    packed_float3 ground_color;
    float  star_brightness;
    packed_float3 moon_direction;
    float  moon_intensity;
    packed_float3 moon_color;
    float  cloud_cover;
    float  cloud_altitude;
    float  cloud_density;
    float  cloud_wind_speed;
    float  fog_density;
    packed_float2 cloud_wind_dir;
    float  _pad0;
    float  _pad1;
};

struct SkyVertexOut {
    float4 position [[position]];
    float2 ndc;
};

// Fullscreen triangle (covers entire screen with one triangle, more efficient
// than a quad — no diagonal seam, no overdraw on the diagonal). Uses Metal's
// vertex_id to synthesize positions: ids 0,1,2 produce (-1,-1)(3,-1)(-1,3).
vertex SkyVertexOut vertex_sky(uint vid [[vertex_id]])
{
    SkyVertexOut o;
    float2 p;
    p.x = (vid == 1) ? 3.0 : -1.0;
    p.y = (vid == 2) ? 3.0 : -1.0;
    o.position = float4(p, 1.0, 1.0); // z = 1 → far plane
    o.ndc = p;
    return o;
}

static float smoothstep01(float e0, float e1, float x)
{
    float t = clamp((x - e0) / (e1 - e0), 0.0, 1.0);
    return t * t * (3.0 - 2.0 * t);
}

static float hash31(float3 p)
{
    return fract(sin(dot(p, float3(443.897, 441.423, 437.195))) * 43758.5453);
}

static float hash21(float2 p)
{
    return fract(sin(dot(p, float2(127.1, 311.7))) * 43758.5453);
}

static float noise2d(float2 p)
{
    float2 i = floor(p);
    float2 f = fract(p);
    float2 u = f * f * (3.0 - 2.0 * f);
    return mix(
        mix(hash21(i),                  hash21(i + float2(1.0, 0.0)), u.x),
        mix(hash21(i + float2(0.0, 1.0)), hash21(i + float2(1.0, 1.0)), u.x),
        u.y
    );
}

static float fbm2(float2 p)
{
    float total = 0.0;
    float amp   = 0.5;
    for (int i = 0; i < 4; i++) {
        total += noise2d(p) * amp;
        p     *= 2.07;
        amp   *= 0.5;
    }
    return total;
}

fragment float4 fragment_sky(SkyVertexOut in [[stage_in]],
                              constant SkyUBO &sky [[buffer(0)]])
{
    float4 world = sky.inv_vp * float4(in.ndc, 1.0, 1.0);
    float3 dir   = normalize(world.xyz / world.w);

    float elevation = dir.y;

    // Base gradient: horizon → zenith above, horizon → ground below.
    float3 color;
    if (elevation >= 0.0) {
        float t = smoothstep01(0.0, 1.0, elevation);
        color = mix(float3(sky.horizon_color), float3(sky.zenith_color), t);
    } else {
        float t = smoothstep01(0.0, -0.3, elevation);
        color = mix(float3(sky.horizon_color), float3(sky.ground_color), t);
    }

    // Sun disc + glow + horizon scatter.
    if (sky.sun_intensity > 0.0) {
        float cosA  = dot(dir, float3(sky.sun_direction));
        float angle = acos(clamp(cosA, -1.0, 1.0));

        float disc = 1.0 - smoothstep01(sky.sun_size * 0.5, sky.sun_size, angle);
        color = mix(color, float3(sky.sun_color) * sky.sun_intensity, disc);

        if (angle < sky.sun_glow_size) {
            float g = 1.0 - angle / sky.sun_glow_size;
            g = g * g * sky.sun_glow_intensity;
            color += float3(sky.sun_color) * sky.sun_intensity * g;
        }

        if (elevation > -0.05 && elevation < 0.25) {
            float band = 1.0 - abs(elevation - 0.05) / 0.20;
            band = max(0.0, band);
            float s = max(0.0, cosA) * band * 0.35 * sky.sun_intensity;
            color += float3(sky.sun_color) * s;
        }
    }

    // Moon (faint disc + cool glow).
    if (sky.moon_intensity > 0.0) {
        float cosM  = dot(dir, float3(sky.moon_direction));
        float angle = acos(clamp(cosM, -1.0, 1.0));
        float disc  = 1.0 - smoothstep01(sky.sun_size * 0.7, sky.sun_size * 1.4, angle);
        color = mix(color, float3(sky.moon_color) * sky.moon_intensity, disc);
        if (angle < sky.sun_glow_size * 0.6) {
            float g = 1.0 - angle / (sky.sun_glow_size * 0.6);
            g = g * g * 0.35 * sky.moon_intensity;
            color += float3(sky.moon_color) * g;
        }
    }

    // Stars — only above horizon, only when bright enough.
    if (sky.star_brightness > 0.0 && elevation > 0.0) {
        float3 cell = floor(dir * 200.0);
        float  n    = hash31(cell);
        if (n > 0.9975) {
            float twinkle  = (n - 0.9975) * 400.0;
            float fadeEdge = smoothstep01(0.0, 0.15, elevation);
            color += float3(twinkle) * sky.star_brightness * fadeEdge;
        }
    }

    // Cloud layer — project ray onto a horizontal plane at cloud altitude
    // and sample 2D fBm. Only when looking up.
    if (sky.cloud_cover > 0.0 && elevation > 0.001) {
        float t = (sky.cloud_altitude - sky.camera_pos.y) / elevation;
        if (t > 0.0) {
            float2 cloudPos = (float2(sky.camera_pos.x, sky.camera_pos.z)
                               + float2(dir.x, dir.z) * t) * 0.003;
            cloudPos += float2(sky.cloud_wind_dir) * (sky.time * sky.cloud_wind_speed * 0.003);
            float n = fbm2(cloudPos);

            float thresh    = 1.0 - sky.cloud_cover * 0.95;
            float edge      = 0.12;
            float cloudMask = smoothstep01(thresh - edge, thresh + edge, n);

            float sunAlign = max(0.0, dot(dir, float3(sky.sun_direction)));
            float3 cloudLit    = mix(float3(0.78, 0.80, 0.86),
                                      float3(sky.sun_color),
                                      0.4 * sky.sun_intensity * sunAlign);
            float3 cloudShadow = float3(0.50, 0.52, 0.58);
            float3 cloudColor  = mix(cloudShadow, cloudLit,
                                      clamp(sky.sun_intensity, 0.0, 1.0));

            float perspFade = smoothstep01(0.0, 0.15, elevation);
            float alpha     = cloudMask * sky.cloud_density * perspFade;
            color = mix(color, cloudColor, alpha);
        }
    }

    // Horizon haze (humidity / fog).
    if (sky.fog_density > 0.0) {
        float hazeBand = 1.0 - smoothstep01(0.0, 0.35, abs(elevation));
        color = mix(color, float3(sky.horizon_color), hazeBand * sky.fog_density);
    }

    return float4(color, 1.0);
}
