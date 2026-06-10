#version 410 core
// Sky element 6/6: horizon haze / humidity fog (ALPHA blend toward horizon tint).
// Peaks at the horizon, fades toward zenith and toward the ground.
in vec2 v_ndc;
uniform mat4 u_sky_inv_vp;
uniform vec3 u_horizon_color;
uniform float u_fog_density;
out vec4 frag_color;

float smoothstep01(float e0, float e1, float x) {
    float t = clamp((x - e0) / (e1 - e0), 0.0, 1.0);
    return t * t * (3.0 - 2.0 * t);
}

void main() {
    vec4 world = u_sky_inv_vp * vec4(v_ndc, 1.0, 1.0);
    vec3 dir = normalize(world.xyz / world.w);

    float hazeBand = 1.0 - smoothstep01(0.0, 0.35, abs(dir.y));
    frag_color = vec4(u_horizon_color, hazeBand * u_fog_density);
}
