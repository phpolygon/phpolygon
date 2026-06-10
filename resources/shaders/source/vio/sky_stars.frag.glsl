#version 410 core
// Sky element 4/6: twinkly starfield above the horizon (ADDITIVE, no texture).
in vec2 v_ndc;
uniform mat4 u_sky_inv_vp;
uniform float u_star_brightness;
// HDR scene path (FP16 target tonemapped on resolve). This is an ADDITIVE pass;
// linearise the additive contribution so the resolve's ACES+gamma maps it back
// to its authored display-referred look — keeping the stars HDR≈LDR (mirrors the
// blended sky passes: sky_gradient/clouds/haze).
uniform int u_linear_output;
out vec4 frag_color;

float smoothstep01(float e0, float e1, float x) {
    float t = clamp((x - e0) / (e1 - e0), 0.0, 1.0);
    return t * t * (3.0 - 2.0 * t);
}

// Inverse of pow(ACES(x), 1/2.2): positive root of (cy-a)x²+(dy-b)x+ey=0,
// y = display^2.2. Matches the resolve tonemap (exposure 1.0). See sky_gradient.frag.
vec3 invToneMapInvGamma(vec3 displayColor) {
    vec3 y = pow(clamp(displayColor, 0.0, 0.9965), vec3(2.2));
    const float a = 2.51, b = 0.03, c = 2.43, d = 0.59, e = 0.14;
    vec3 A = c * y - a;
    vec3 B = d * y - b;
    vec3 C = e * y;
    vec3 sq = sqrt(max(B * B - 4.0 * A * C, 0.0));
    return max((-B - sq) / (2.0 * A), 0.0);
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
    // Under HDR, linearise the additive contribution so the resolve ACES maps an
    // isolated star back to its authored display look (no over-bright/over-spread).
    if (u_linear_output == 1) {
        add = invToneMapInvGamma(add);
    }
    frag_color = vec4(add, 1.0);
}
