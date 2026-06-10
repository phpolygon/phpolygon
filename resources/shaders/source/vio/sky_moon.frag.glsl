#version 410 core
// Sky element 3/6: moon disc + soft cool glow (ADDITIVE).
in vec2 v_ndc;
uniform mat4 u_sky_inv_vp;
uniform vec3 u_moon_direction;
uniform vec3 u_moon_color;
uniform float u_moon_intensity;
uniform float u_sun_size;       // moon disc sized relative to the sun disc
uniform float u_sun_glow_size;
// HDR scene path (FP16 target tonemapped on resolve). This is an ADDITIVE pass;
// linearise the additive contribution so the resolve's ACES+gamma maps it back
// to its authored display-referred look — keeping the moon HDR≈LDR (mirrors the
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

void main() {
    vec4 world = u_sky_inv_vp * vec4(v_ndc, 1.0, 1.0);
    vec3 dir = normalize(world.xyz / world.w);

    float angle = acos(clamp(dot(dir, u_moon_direction), -1.0, 1.0));

    vec3 add = vec3(0.0);
    float disc = 1.0 - smoothstep01(u_sun_size * 0.7, u_sun_size * 1.4, angle);
    add += u_moon_color * u_moon_intensity * disc;

    if (angle < u_sun_glow_size * 0.6) {
        float g = 1.0 - angle / (u_sun_glow_size * 0.6);
        g = g * g * 0.35 * u_moon_intensity;
        add += u_moon_color * g;
    }

    // Under HDR, linearise the additive contribution so the resolve ACES maps an
    // isolated moon back to its authored display look (no over-bright/over-spread).
    if (u_linear_output == 1) {
        add = invToneMapInvGamma(add);
    }
    frag_color = vec4(add, 1.0);
}
