#version 410 core
// Sky element 6/6: horizon haze / humidity fog (ALPHA blend toward horizon tint).
// Peaks at the horizon, fades toward zenith and toward the ground.
in vec2 v_ndc;
uniform mat4 u_sky_inv_vp;
uniform vec3 u_horizon_color;
uniform float u_fog_density;
// HDR scene path: alpha-blend over the (already linear) sky, so the haze colour
// must be linearised too or it would tint toward an un-tonemapped value.
uniform int u_linear_output;
out vec4 frag_color;

float smoothstep01(float e0, float e1, float x) {
    float t = clamp((x - e0) / (e1 - e0), 0.0, 1.0);
    return t * t * (3.0 - 2.0 * t);
}

vec3 invToneMapInvGamma(vec3 displayColor) {
    vec3 y = pow(clamp(displayColor, 0.0, 0.9965), vec3(2.2));
    const float a = 2.51, b = 0.03, c = 2.43, d = 0.59, e = 0.14;
    vec3 A = c * y - a;
    vec3 B = d * y - b;
    vec3 C = e * y;
    vec3 sq = sqrt(max(B * B - 4.0 * A * C, 0.0));
    return max((-B - sq) / (2.0 * A), 0.0);
}

void main() {
    vec4 world = u_sky_inv_vp * vec4(v_ndc, 1.0, 1.0);
    vec3 dir = normalize(world.xyz / world.w);

    float hazeBand = 1.0 - smoothstep01(0.0, 0.35, abs(dir.y));
    vec3 color = u_horizon_color;
    if (u_linear_output == 1) {
        color = invToneMapInvGamma(color);
    }
    frag_color = vec4(color, hazeBand * u_fog_density);
}
