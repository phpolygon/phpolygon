#version 450

layout(location = 0) in vec3 v_normal;
layout(location = 1) in vec3 v_worldPos;
layout(location = 2) in vec2 v_uv;

struct PointLight {
    vec3  position;
    float intensity;
    vec3  color;
    float radius;
};

// Per-frame lighting uniforms
layout(binding = 1) uniform LightingUBO {
    vec3  u_ambient_color;
    float u_ambient_intensity;

    vec3  u_dir_light_direction;
    float u_dir_light_intensity;
    vec3  u_dir_light_color;
    float _pad0;

    vec3  u_albedo;
    float u_roughness;

    vec3  u_emission;
    float u_metallic;

    vec3  u_fog_color;
    float u_fog_near;

    vec3  u_camera_pos;
    float u_fog_far;

    int   u_point_light_count;
    float _pad2;
    float _pad3;
    float _pad4;

    PointLight u_point_lights[8];
};

layout(location = 0) out vec4 frag_color;

// --- Helpers (mirrored from OpenGL variant) ---

float hash21(vec2 p) {
    p = fract(p * vec2(127.1, 311.7));
    p += dot(p, p + 19.19);
    return fract(p.x * p.y);
}

float sandNoise(vec2 p) {
    vec2 i = floor(p);
    vec2 f = fract(p);
    f = f * f * (3.0 - 2.0 * f);
    float a = hash21(i);
    float b = hash21(i + vec2(1.0, 0.0));
    float c = hash21(i + vec2(0.0, 1.0));
    float d = hash21(i + vec2(1.0, 1.0));
    return mix(mix(a, b, f.x), mix(c, d, f.x), f.y);
}

vec3 fresnelSchlick(float cosTheta, vec3 F0) {
    return F0 + (1.0 - F0) * pow(clamp(1.0 - cosTheta, 0.0, 1.0), 5.0);
}

void main() {
    vec3 N = gl_FrontFacing ? normalize(v_normal) : -normalize(v_normal);

    vec3 V = normalize(u_camera_pos - v_worldPos);
    vec3 L = normalize(-u_dir_light_direction);
    vec3 H = normalize(V + L);

    float roughness = clamp(u_roughness, 0.04, 1.0);
    float shininess = exp2(10.0 * (1.0 - roughness) + 1.0);

    float noise = sandNoise(v_worldPos.xz * 0.4);
    float noiseMask = smoothstep(0.3, 0.9, roughness);
    vec3 albedo = u_albedo * (1.0 + (noise - 0.5) * 0.12 * noiseMask);

    vec3 F0 = mix(vec3(0.04), albedo, u_metallic);
    float NdotL = max(dot(N, L), 0.0);

    // Ambient
    vec3 color = u_ambient_color * u_ambient_intensity * albedo * (1.0 - u_metallic * 0.9);

    // Directional light
    if (NdotL > 0.0) {
        color += albedo * u_dir_light_color * u_dir_light_intensity * NdotL * (1.0 - u_metallic);
        float NdotH = max(dot(N, H), 0.0);
        float spec = pow(NdotH, shininess) * (shininess + 2.0) / 8.0;
        vec3 F = fresnelSchlick(max(dot(H, V), 0.0), F0);
        color += F * u_dir_light_color * u_dir_light_intensity * spec * NdotL;
    }

    // Point lights
    for (int i = 0; i < u_point_light_count; i++) {
        vec3 Lp   = u_point_lights[i].position - v_worldPos;
        float dist = length(Lp);
        Lp = normalize(Lp);
        vec3 Hp = normalize(V + Lp);
        float radius = max(u_point_lights[i].radius, 0.001);
        float atten  = clamp(1.0 - (dist * dist) / (radius * radius), 0.0, 1.0);
        atten *= atten;
        float NdotPL = max(dot(N, Lp), 0.0);
        if (NdotPL > 0.0) {
            color += albedo * u_point_lights[i].color * u_point_lights[i].intensity
                     * NdotPL * atten * (1.0 - u_metallic);
            float specP = pow(max(dot(N, Hp), 0.0), shininess) * (shininess + 2.0) / 8.0;
            vec3 FP = fresnelSchlick(max(dot(Hp, V), 0.0), F0);
            color += FP * u_point_lights[i].color * u_point_lights[i].intensity
                     * specP * NdotPL * atten;
        }
    }

    // Emission
    color += u_emission;

    // Fog (exponential-squared)
    float fogDist   = length(v_worldPos - u_camera_pos);
    float fogFactor = clamp((fogDist - u_fog_near) / (u_fog_far - u_fog_near), 0.0, 1.0);
    fogFactor = 1.0 - exp(-fogFactor * fogFactor * 3.0);
    color = mix(color, u_fog_color, fogFactor);

    // Gamma correction
    color = pow(max(color, vec3(0.0)), vec3(1.0 / 2.2));

    frag_color = vec4(color, 1.0);
}
