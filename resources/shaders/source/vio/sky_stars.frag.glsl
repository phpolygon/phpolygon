#version 410 core
// Sky element 4/6: twinkly starfield above the horizon (ADDITIVE, no texture).
in vec2 v_ndc;
uniform mat4 u_sky_inv_vp;
uniform float u_star_brightness;
out vec4 frag_color;

float smoothstep01(float e0, float e1, float x) {
    float t = clamp((x - e0) / (e1 - e0), 0.0, 1.0);
    return t * t * (3.0 - 2.0 * t);
}

float hash31(vec3 p) {
    return fract(sin(dot(p, vec3(443.897, 441.423, 437.195))) * 43758.5453);
}

void main() {
    vec4 world = u_sky_inv_vp * vec4(v_ndc, 1.0, 1.0);
    vec3 dir = normalize(world.xyz / world.w);
    float elevation = dir.y;

    vec3 add = vec3(0.0);
    if (elevation > 0.0) {
        vec3 cell = floor(dir * 200.0);
        float n = hash31(cell);
        if (n > 0.9975) {
            float twinkle = (n - 0.9975) * 400.0;
            float fadeEdge = smoothstep01(0.0, 0.15, elevation); // atmospheric extinction
            add += vec3(twinkle) * u_star_brightness * fadeEdge;
        }
    }
    frag_color = vec4(add, 1.0);
}
