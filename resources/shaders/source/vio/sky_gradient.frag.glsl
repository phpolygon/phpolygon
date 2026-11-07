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
// Physical sky weight 0..1 (SetSky::$physicalSky).
uniform float u_physical_sky;
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

// Physical sky (shader plan 4.3): single scattering in a planet's atmosphere -
// Rayleigh (blue, scale height 8 km) and Mie (haze, 1.2 km) - integrated along
// the view ray in 24 steps - spaced quadratically, dense near the ground where the
// air is thick - each lit by the sun through 8 such steps of optical depth;
// points in the planet's shadow get no sunlight (twilight from the upper layers).
const float PLANET_R = 6360.0e3;
const float ATMOS_R = 6420.0e3;
const vec3 BETA_R = vec3(5.8e-6, 13.5e-6, 33.1e-6);
const float BETA_M = 21.0e-6;
// Ozone absorbs orange and green (not blue) and is what keeps the dusk sky blue
// instead of grey-green; approximated along the Rayleigh density profile.
const vec3 BETA_OZ = vec3(2.0e-6, 5.0e-6, 0.2e-6);
const float H_R = 7994.0;
const float H_M = 1200.0;

vec2 raySphere(vec3 o, vec3 d, float r) {
    float b = dot(o, d);
    float c = dot(o, o) - r * r;
    float h = b * b - c;
    if (h < 0.0) return vec2(1.0e12, -1.0e12);
    h = sqrt(h);
    return vec2(-b - h, -b + h);
}

vec3 physicalSky(vec3 dir, vec3 sun, vec3 sunColor) {
    vec3 o = vec3(0.0, PLANET_R + 2.0, 0.0);
    float tMax = raySphere(o, dir, ATMOS_R).y;
    vec3 sumR = vec3(0.0);
    vec3 sumM = vec3(0.0);
    float odR = 0.0;
    float odM = 0.0;
    for (int i = 0; i < 24; i++) {
        float t0 = float(i) / 24.0;
        float t1 = float(i + 1) / 24.0;
        float seg = tMax * (t1 * t1 - t0 * t0);
        vec3 p = o + dir * (tMax * 0.5 * (t0 * t0 + t1 * t1));
        float h = length(p) - PLANET_R;
        float hr = exp(-h / H_R) * seg;
        float hm = exp(-h / H_M) * seg;
        odR += hr;
        odM += hm;
        // In the planet's shadow only where the way to the sun really hits it (a miss reports 1e12).
        vec2 shadow = raySphere(p, sun, PLANET_R);
        if (shadow.x > 0.0 && shadow.x < 1.0e11) continue;
        float lt = raySphere(p, sun, ATMOS_R).y;
        float lR = 0.0;
        float lM = 0.0;
        for (int j = 0; j < 8; j++) {
            float s0 = float(j) / 8.0;
            float s1 = float(j + 1) / 8.0;
            float ls = lt * (s1 * s1 - s0 * s0);
            float hq = length(p + sun * (lt * 0.5 * (s0 * s0 + s1 * s1))) - PLANET_R;
            lR += exp(-hq / H_R) * ls;
            lM += exp(-hq / H_M) * ls;
        }
        vec3 att = exp(-((BETA_R + BETA_OZ) * (odR + lR) + BETA_M * 1.1 * (odM + lM)));
        sumR += att * hr;
        sumM += att * hm;
    }
    float mu = dot(dir, sun);
    float phaseR = 3.0 / (16.0 * 3.14159265) * (1.0 + mu * mu);
    const float g = 0.76;
    float phaseM = 3.0 / (8.0 * 3.14159265) * ((1.0 - g * g) * (1.0 + mu * mu)) / ((2.0 + g * g) * pow(1.0 + g * g - 2.0 * g * mu, 1.5));
    // The atmosphere colours the light itself: only the sun's brightness comes in,
    // or a sun the game already reddened at dusk would redden twice.
    float sunPower = max(sunColor.r, max(sunColor.g, sunColor.b));
    return vec3(sunPower * 12.0) * (sumR * BETA_R * phaseR + sumM * BETA_M * phaseM);
}

// Linear radiance -> the display-referred colour the authored gradient is written in
// (the resolve's ACES at exposure 1, then gamma).
vec3 toDisplay(vec3 x) {
    vec3 t = (x * (2.51 * x + 0.03)) / (x * (2.43 * x + 0.59) + 0.14);
    return pow(clamp(t, 0.0, 1.0), vec3(1.0 / 2.2));
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

    // Physical sky: from the sun alone; the authored gradient keeps the night.
    float physical = u_physical_sky * smoothstep01(-0.12, -0.02, u_sun_direction.y);
    if (physical > 0.0) {
        vec3 up = normalize(vec3(dir.x, max(elevation, 0.0), dir.z));
        vec3 phys = toDisplay(physicalSky(up, normalize(u_sun_direction), u_sun_color));
        if (elevation < 0.0) {
            phys = mix(phys, u_ground_color, smoothstep01(0.0, -0.3, elevation));
        }
        color = mix(color, phys, physical);
    }

    // Twilight (shader plan 4.3): with the sun low the sky on its side glows - a
    // wide warm band along the horizon and a forward-scattering halo round the
    // sun - and opposite it the earth's shadow rises as a cool band under a pink
    // belt. All of it fades out once the sun stands higher than ~15 degrees.
    float sunY = u_sun_direction.y;
    float low = smoothstep01(0.26, 0.0, sunY) * smoothstep01(-0.2, -0.02, sunY) * (1.0 - physical);
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
