#version 410 core
// Sky element 1/6: base gradient (OPAQUE — written first, fills the sky).
// horizon→zenith above the horizon, horizon→ground below it.
in vec2 v_ndc;
uniform mat4 u_sky_inv_vp;
uniform vec3 u_zenith_color;
uniform vec3 u_horizon_color;
uniform vec3 u_ground_color;
out vec4 frag_color;

float smoothstep01(float e0, float e1, float x) {
    float t = clamp((x - e0) / (e1 - e0), 0.0, 1.0);
    return t * t * (3.0 - 2.0 * t);
}

void main() {
    vec4 world = u_sky_inv_vp * vec4(v_ndc, 1.0, 1.0);
    vec3 dir = normalize(world.xyz / world.w);
    float elevation = dir.y;

    vec3 color;
    if (elevation >= 0.0) {
        color = mix(u_horizon_color, u_zenith_color, smoothstep01(0.0, 1.0, elevation));
    } else {
        color = mix(u_horizon_color, u_ground_color, smoothstep01(0.0, -0.3, elevation));
    }
    frag_color = vec4(color, 1.0);
}
