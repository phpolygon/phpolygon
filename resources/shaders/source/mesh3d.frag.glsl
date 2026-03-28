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
uniform vec3 u_fog_color;
uniform float u_fog_near;
uniform float u_fog_far;

uniform vec3 u_camera_pos;

out vec4 frag_color;

void main() {
    vec3 N = normalize(v_normal);

    // Ambient
    vec3 color = u_ambient_color * u_ambient_intensity * u_albedo;

    // Directional light (Lambert)
    float NdotL = max(dot(N, -normalize(u_dir_light_direction)), 0.0);
    color += u_albedo * u_dir_light_color * u_dir_light_intensity * NdotL;

    // Point lights
    for (int i = 0; i < u_point_light_count; i++) {
        vec3 L = u_point_lights[i].position - v_worldPos;
        float dist = length(L);
        float atten = max(0.0, 1.0 - dist / u_point_lights[i].radius);
        float NdotPL = max(dot(N, normalize(L)), 0.0);
        color += u_albedo * u_point_lights[i].color * u_point_lights[i].intensity * NdotPL * atten;
    }

    // Emission
    color += u_emission;

    // Fog
    float fogDist = length(v_worldPos - u_camera_pos);
    float fogFactor = clamp((fogDist - u_fog_near) / (u_fog_far - u_fog_near), 0.0, 1.0);
    color = mix(color, u_fog_color, fogFactor);

    frag_color = vec4(color, 1.0);
}
