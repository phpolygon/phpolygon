#version 410 core

in vec3 v_worldPos;

uniform vec3 u_albedo;
uniform vec3 u_emission;
uniform float u_alpha;
uniform vec3 u_fog_color;
uniform float u_fog_near;
uniform float u_fog_far;
uniform vec3 u_camera_pos;

out vec4 frag_color;

void main() {
    vec3 color = u_albedo + u_emission;

    // Distance fog
    float dist = length(v_worldPos - u_camera_pos);
    float fogFactor = clamp((dist - u_fog_near) / (u_fog_far - u_fog_near), 0.0, 1.0);
    color = mix(color, u_fog_color, fogFactor);

    frag_color = vec4(color, u_alpha);
}
