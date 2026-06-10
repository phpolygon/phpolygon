#version 410 core
// Sky element 2/6: sun disc + glow halo + warm horizon scatter band (ADDITIVE).
in vec2 v_ndc;
uniform mat4 u_sky_inv_vp;
uniform vec3 u_sun_direction;
uniform vec3 u_sun_color;
uniform float u_sun_intensity;
uniform float u_sun_size;
uniform float u_sun_glow_size;
uniform float u_sun_glow_intensity;
// HDR scene path (FP16 target tonemapped on resolve). This is an ADDITIVE pass;
// linearise the additive contribution so the resolve's ACES+gamma maps it back
// to its authored display-referred look — keeping the sun HDR≈LDR (mirrors the
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
    float elevation = dir.y;

    float cosA = dot(dir, u_sun_direction);
    float angle = acos(clamp(cosA, -1.0, 1.0));

    vec3 add = vec3(0.0);

    // Soft sun disc.
    float disc = 1.0 - smoothstep01(u_sun_size * 0.5, u_sun_size, angle);
    add += u_sun_color * u_sun_intensity * disc;

    // Glow halo.
    if (angle < u_sun_glow_size) {
        float g = 1.0 - angle / u_sun_glow_size;
        g = g * g * u_sun_glow_intensity;
        add += u_sun_color * u_sun_intensity * g;
    }

    // Warm horizon scattering near the sun direction (sunset band).
    if (elevation > -0.05 && elevation < 0.25) {
        float band = max(0.0, 1.0 - abs(elevation - 0.05) / 0.20);
        add += u_sun_color * (max(0.0, cosA) * band * 0.35 * u_sun_intensity);
    }

    // Additive blend (SrcBlend=SRC_ALPHA, DestBlend=ONE): alpha=1 → adds add.rgb.
    // Under HDR, linearise the additive contribution so the resolve ACES maps an
    // isolated sun back to its authored display look (no over-bright/over-spread).
    if (u_linear_output == 1) {
        add = invToneMapInvGamma(add);
    }
    frag_color = vec4(add, 1.0);
}
