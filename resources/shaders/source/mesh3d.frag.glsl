#version 410 core

in vec3 v_normal;
in vec3 v_worldPos;
in vec2 v_uv;

uniform vec3 u_ambient_color;
uniform float u_ambient_intensity;

uniform vec3 u_dir_light_direction;
uniform vec3 u_dir_light_color;
uniform float u_dir_light_intensity;

struct PointLight {
    vec3 position;
    vec3 color;
    float intensity;
    float radius;
};
uniform PointLight u_point_lights[8];
uniform int u_point_light_count;

uniform vec3 u_albedo;
uniform vec3 u_emission;
uniform float u_roughness;
uniform float u_metallic;
uniform float u_alpha;
uniform vec3 u_fog_color;
uniform float u_fog_near;
uniform float u_fog_far;

uniform vec3 u_camera_pos;

out vec4 frag_color;

// ----------------------------------------------------------------
// Helpers
// ----------------------------------------------------------------

// Simple 2D hash noise [0..1] — used for procedural sand/ground variation
float hash21(vec2 p) {
    p = fract(p * vec2(127.1, 311.7));
    p += dot(p, p + 19.19);
    return fract(p.x * p.y);
}

float sandNoise(vec2 p) {
    vec2 i = floor(p);
    vec2 f = fract(p);
    f = f * f * (3.0 - 2.0 * f); // smoothstep
    float a = hash21(i);
    float b = hash21(i + vec2(1.0, 0.0));
    float c = hash21(i + vec2(0.0, 1.0));
    float d = hash21(i + vec2(1.0, 1.0));
    return mix(mix(a, b, f.x), mix(c, d, f.x), f.y);
}

// ----------------------------------------------------------------
// PBR helpers (simplified Blinn-Phong with metallic workflow)
// ----------------------------------------------------------------

// Fresnel-Schlick approximation
vec3 fresnelSchlick(float cosTheta, vec3 F0) {
    return F0 + (1.0 - F0) * pow(clamp(1.0 - cosTheta, 0.0, 1.0), 5.0);
}

void main() {
    vec3 N = normalize(v_normal);
    // Flip back-face normals so double-sided plane water/sand renders correctly
    if (!gl_FrontFacing) N = -N;

    vec3 V = normalize(u_camera_pos - v_worldPos);
    vec3 L = normalize(-u_dir_light_direction);
    vec3 H = normalize(V + L);

    // --- Roughness / shininess conversion ---
    // roughness 0 = mirror, 1 = completely diffuse
    float roughness = clamp(u_roughness, 0.04, 1.0);
    float shininess = exp2(10.0 * (1.0 - roughness) + 1.0); // ~2..2048

    // --- Base colour + procedural sand noise ---
    // Adds subtle variation to large flat surfaces (sand, water planes)
    // Noise scale: 0.4 tiles roughly every 2.5 world units
    float noise = sandNoise(v_worldPos.xz * 0.4);
    // Only apply noise where roughness is high (sand/ground, not glass/water)
    float noiseMask = smoothstep(0.3, 0.9, roughness);
    vec3 albedo = u_albedo * (1.0 + (noise - 0.5) * 0.12 * noiseMask);

    // --- F0 (reflectance at normal incidence) ---
    // Dielectrics: 0.04, metals: full albedo
    vec3 F0 = mix(vec3(0.04), albedo, u_metallic);

    // --- Diffuse (metals absorb diffuse) ---
    float NdotL = max(dot(N, L), 0.0);

    // --- Ambient ---
    vec3 color = u_ambient_color * u_ambient_intensity * albedo * (1.0 - u_metallic * 0.9);

    // --- Directional light: diffuse + specular ---
    if (NdotL > 0.0) {
        // Diffuse (Lambertian, metals get no diffuse)
        color += albedo * u_dir_light_color * u_dir_light_intensity * NdotL * (1.0 - u_metallic);

        // Specular (Blinn-Phong)
        float NdotH = max(dot(N, H), 0.0);
        float spec = pow(NdotH, shininess);
        // Energy-conserving normalisation
        spec *= (shininess + 2.0) / (8.0);
        vec3 F = fresnelSchlick(max(dot(H, V), 0.0), F0);
        color += F * u_dir_light_color * u_dir_light_intensity * spec * NdotL;
    }

    // --- Point lights ---
    for (int i = 0; i < u_point_light_count; i++) {
        vec3 Lp   = u_point_lights[i].position - v_worldPos;
        float dist = length(Lp);
        Lp = normalize(Lp);
        vec3 Hp   = normalize(V + Lp);

        // Inverse-square attenuation with smooth cutoff at radius
        float radius = max(u_point_lights[i].radius, 0.001);
        float atten  = clamp(1.0 - (dist * dist) / (radius * radius), 0.0, 1.0);
        atten       *= atten; // smooth falloff

        float NdotPL = max(dot(N, Lp), 0.0);
        if (NdotPL > 0.0) {
            // Diffuse
            color += albedo * u_point_lights[i].color * u_point_lights[i].intensity
                     * NdotPL * atten * (1.0 - u_metallic);
            // Specular
            float NdotHP = max(dot(N, Hp), 0.0);
            float specP  = pow(NdotHP, shininess) * (shininess + 2.0) / 8.0;
            vec3 FP = fresnelSchlick(max(dot(Hp, V), 0.0), F0);
            color += FP * u_point_lights[i].color * u_point_lights[i].intensity
                     * specP * NdotPL * atten;
        }
    }

    // --- Emission ---
    color += u_emission;

    // --- Fog ---
    float fogDist   = length(v_worldPos - u_camera_pos);
    float fogFactor = clamp((fogDist - u_fog_near) / (u_fog_far - u_fog_near), 0.0, 1.0);
    // Exponential-squared fog for more natural horizon falloff
    fogFactor = 1.0 - exp(-fogFactor * fogFactor * 3.0);
    color = mix(color, u_fog_color, fogFactor);

    // --- Gamma correction (linear → sRGB) ---
    color = pow(max(color, vec3(0.0)), vec3(1.0 / 2.2));

    frag_color = vec4(color, u_alpha);
}
