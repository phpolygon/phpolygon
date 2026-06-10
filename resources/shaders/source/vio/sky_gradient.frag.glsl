#version 410 core
// Sky element 1/6: base gradient (OPAQUE — written first, fills the sky).
// horizon→zenith above the horizon, horizon→ground below it.
in vec2 v_ndc;
uniform mat4 u_sky_inv_vp;
uniform vec3 u_zenith_color;
uniform vec3 u_horizon_color;
uniform vec3 u_ground_color;
// HDR scene path (FP16 target tonemapped on resolve). The gradient colours are
// authored display-referred, so under HDR emit the LINEAR value that the
// resolve's ACES+gamma maps back to them — keeping the base sky pixel-identical.
uniform int u_linear_output;
out vec4 frag_color;

float smoothstep01(float e0, float e1, float x) {
    float t = clamp((x - e0) / (e1 - e0), 0.0, 1.0);
    return t * t * (3.0 - 2.0 * t);
}

// Inverse of pow(ACES(x), 1/2.2): positive root of (cy-a)x²+(dy-b)x+ey=0,
// y = display^2.2. Matches the resolve tonemap (exposure 1.0). See unlit.frag.
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
    float elevation = dir.y;

    vec3 color;
    if (elevation >= 0.0) {
        color = mix(u_horizon_color, u_zenith_color, smoothstep01(0.0, 1.0, elevation));
    } else {
        color = mix(u_horizon_color, u_ground_color, smoothstep01(0.0, -0.3, elevation));
    }
    if (u_linear_output == 1) {
        color = invToneMapInvGamma(color);
    }
    frag_color = vec4(color, 1.0);
}
