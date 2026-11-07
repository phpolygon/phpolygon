#version 410 core
// Sky element 1/6: base gradient (OPAQUE — written first, fills the sky).
// horizon→zenith above the horizon, horizon→ground below it.
in vec2 v_ndc;
uniform mat4 u_sky_inv_vp;
uniform vec3 u_zenith_color;
uniform vec3 u_horizon_color;
uniform vec3 u_ground_color;
// Twilight scattering (shader plan 4.3): the sun's direction and colour.
uniform vec3 u_sun_direction;
uniform vec3 u_sun_color;
// High fog layer 0..1 (SetSky::$highFog).
uniform float u_high_fog;
// HDR scene path (FP16 target tonemapped on resolve). The gradient colours are
// authored display-referred, so under HDR emit the LINEAR value that the
// resolve's ACES+gamma maps back to them — keeping the base sky pixel-identical.
uniform int u_linear_output;
#ifdef PHPOLYGON_MRT
// Drawn into the MRT scene target: sky is local (unlit) light in attachment 1; the
// renderer's pipeline masks the sun / ambient / G-buffer attachments off, so the
// G-buffer keeps its cleared 0 = "sky" for the AO and reflection passes.
layout(location = 0) out vec4 o_sun;
layout(location = 1) out vec4 o_local;
layout(location = 2) out vec4 o_ambient;
layout(location = 3) out vec4 o_gbuffer;
#define frag_color o_local
#else
out vec4 frag_color;
#endif

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

    // Twilight (shader plan 4.3): with the sun low the sky on its side glows - a
    // wide warm band along the horizon and a forward-scattering halo round the
    // sun - and opposite it the earth's shadow rises as a cool band under a pink
    // belt. All of it fades out once the sun stands higher than ~15 degrees.
    float sunY = u_sun_direction.y;
    float low = smoothstep01(0.26, 0.0, sunY) * smoothstep01(-0.2, -0.02, sunY);
    if (low > 0.0 && elevation > -0.05) {
        vec2 hs = normalize(u_sun_direction.xz + vec2(1e-5));
        vec2 hd = normalize(dir.xz + vec2(1e-5));
        float side = dot(hs, hd) * 0.5 + 0.5;
        float band = exp(-max(elevation, 0.0) * 7.0);
        float mu = max(dot(dir, u_sun_direction), 0.0);
        vec3 warm = u_sun_color * vec3(1.0, 0.62, 0.32);
        color += warm * band * pow(side, 3.0) * 0.35 * low;
        color += u_sun_color * pow(mu, 24.0) * 0.45 * low;
        float anti = pow(1.0 - side, 2.0);
        float shadowBand = exp(-max(elevation, 0.0) * 14.0);
        float belt = exp(-pow((elevation - 0.12) / 0.06, 2.0));
        color = mix(color, color * vec3(0.78, 0.82, 0.95), shadowBand * anti * 0.5 * low);
        color += vec3(0.30, 0.14, 0.18) * belt * anti * 0.5 * low;
    }

    // High fog: a grey stratus veil, the sky loses its depth and blue.
    if (u_high_fog > 0.0 && elevation > -0.02) {
        float grey = dot(color, vec3(0.299, 0.587, 0.114));
        vec3 veil = vec3(grey * 1.05 + 0.04);
        color = mix(color, veil, u_high_fog * (0.55 + 0.35 * smoothstep01(0.0, 0.6, elevation)));
    }
    if (u_linear_output == 1) {
        color = invToneMapInvGamma(color);
    }
    frag_color = vec4(color, 1.0);
}
