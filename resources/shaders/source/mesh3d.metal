#include <metal_stdlib>
using namespace metal;

// ── Vertex layout (matches MetalRenderer3D::uploadMesh, 32 bytes per vertex) ──
struct VertexIn {
    float3 position [[attribute(0)]];
    float3 normal   [[attribute(1)]];
    float2 uv       [[attribute(2)]];
};

// ── Buffer slot 0: model matrix (via setVertexBytes, pushed inline per draw) ──
struct PushConstants {
    float4x4 model;
};

// ── Buffer slot 1: FrameUBO ───────────────────────────────────────────────────
struct FrameUBO {
    float4x4 view;
    float4x4 projection; // Z-corrected by CPU before upload
};

// ── Buffer slot 2: LightingUBO ───────────────────────────────────────────────
struct PointLight {
    float3 position;
    float  intensity;
    float3 color;
    float  radius;
};

struct LightingUBO {
    float3 ambient_color;
    float  ambient_intensity;

    float3 dir_light_direction;
    float  dir_light_intensity;
    float3 dir_light_color;
    float  _pad0;

    float3 albedo;
    float  roughness;

    float3 emission;
    float  metallic;

    float3 fog_color;
    float  fog_near;

    float3 camera_pos;
    float  fog_far;

    int    point_light_count;
    float  _pad2;
    float  _pad3;
    float  _pad4;

    PointLight point_lights[8];
};

// ── Interpolants ──────────────────────────────────────────────────────────────
struct VertexOut {
    float4 position [[position]];
    float3 normal;
    float3 world_pos;
    float2 uv;
};

// ── Helpers ───────────────────────────────────────────────────────────────────

float hash21(float2 p) {
    p = fract(p * float2(127.1, 311.7));
    p += dot(p, p + 19.19);
    return fract(p.x * p.y);
}

float sandNoise(float2 p) {
    float2 i = floor(p);
    float2 f = fract(p);
    f = f * f * (3.0 - 2.0 * f);
    float a = hash21(i);
    float b = hash21(i + float2(1.0, 0.0));
    float c = hash21(i + float2(0.0, 1.0));
    float d = hash21(i + float2(1.0, 1.0));
    return mix(mix(a, b, f.x), mix(c, d, f.x), f.y);
}

float3 fresnelSchlick(float cosTheta, float3 F0) {
    return F0 + (1.0 - F0) * pow(clamp(1.0 - cosTheta, 0.0, 1.0), 5.0);
}

// ── Vertex shader ─────────────────────────────────────────────────────────────

vertex VertexOut vertex_mesh3d(
    VertexIn         in      [[stage_in]],
    constant PushConstants& push   [[buffer(0)]],
    constant FrameUBO&      frame  [[buffer(1)]]
) {
    float4 world_pos = push.model * float4(in.position, 1.0);

    // Normal matrix: transpose(inverse(model)) — computed on CPU for accuracy
    // Approximation: use upper-left 3×3 of model (valid for uniform scale)
    float3x3 normalMatrix = float3x3(
        push.model[0].xyz,
        push.model[1].xyz,
        push.model[2].xyz
    );

    VertexOut out;
    out.position  = frame.projection * frame.view * world_pos;
    out.normal    = normalize(normalMatrix * in.normal);
    out.world_pos = world_pos.xyz;
    out.uv        = in.uv;
    return out;
}

// ── Fragment shader ───────────────────────────────────────────────────────────

fragment float4 fragment_mesh3d(
    VertexOut          in      [[stage_in]],
    constant LightingUBO& light [[buffer(2)]],
    bool is_front_face          [[front_facing]]
) {
    float3 N = normalize(is_front_face ? in.normal : -in.normal);
    float3 V = normalize(light.camera_pos - in.world_pos);
    float3 L = normalize(-light.dir_light_direction);
    float3 H = normalize(V + L);

    float roughness = clamp(light.roughness, 0.04, 1.0);
    float shininess = exp2(10.0 * (1.0 - roughness) + 1.0);

    float noise     = sandNoise(in.world_pos.xz * 0.4);
    float noiseMask = smoothstep(0.3, 0.9, roughness);
    float3 albedo   = light.albedo * (1.0 + (noise - 0.5) * 0.12 * noiseMask);

    float3 F0   = mix(float3(0.04), albedo, light.metallic);
    float NdotL = max(dot(N, L), 0.0);

    // Ambient
    float3 color = light.ambient_color * light.ambient_intensity * albedo
                   * (1.0 - light.metallic * 0.9);

    // Directional light
    if (NdotL > 0.0) {
        color += albedo * light.dir_light_color * light.dir_light_intensity
                 * NdotL * (1.0 - light.metallic);
        float NdotH = max(dot(N, H), 0.0);
        float spec  = pow(NdotH, shininess) * (shininess + 2.0) / 8.0;
        float3 F    = fresnelSchlick(max(dot(H, V), 0.0), F0);
        color += F * light.dir_light_color * light.dir_light_intensity * spec * NdotL;
    }

    // Point lights
    for (int i = 0; i < light.point_light_count; i++) {
        float3 Lp    = light.point_lights[i].position - in.world_pos;
        float  dist  = length(Lp);
        Lp = normalize(Lp);
        float3 Hp    = normalize(V + Lp);
        float  radius = max(light.point_lights[i].radius, 0.001);
        float  atten  = clamp(1.0 - (dist * dist) / (radius * radius), 0.0, 1.0);
        atten *= atten;
        float NdotPL = max(dot(N, Lp), 0.0);
        if (NdotPL > 0.0) {
            color += albedo * light.point_lights[i].color * light.point_lights[i].intensity
                     * NdotPL * atten * (1.0 - light.metallic);
            float specP = pow(max(dot(N, Hp), 0.0), shininess) * (shininess + 2.0) / 8.0;
            float3 FP   = fresnelSchlick(max(dot(Hp, V), 0.0), F0);
            color += FP * light.point_lights[i].color * light.point_lights[i].intensity
                     * specP * NdotPL * atten;
        }
    }

    // Emission
    color += light.emission;

    // Fog (exponential-squared)
    float fog_dist   = length(in.world_pos - light.camera_pos);
    float fog_factor = clamp((fog_dist - light.fog_near) / (light.fog_far - light.fog_near), 0.0, 1.0);
    fog_factor = 1.0 - exp(-fog_factor * fog_factor * 3.0);
    color = mix(color, light.fog_color, fog_factor);

    // Gamma correction
    color = pow(max(color, float3(0.0)), float3(1.0 / 2.2));

    return float4(color, 1.0);
}
