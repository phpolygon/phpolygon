#version 410 core
// Sky element 4/6: twinkly starfield above the horizon (ADDITIVE, no texture).
in vec2 v_ndc;
uniform mat4 u_sky_inv_vp;
uniform float u_star_brightness;
// Sky time (s) for the shooting stars; high fog dims the stars (shader plan 4.3).
uniform float u_sky_time;
uniform float u_high_fog;
// Aurora 0..1 (SetSky::$aurora).
uniform float u_aurora;
// HDR scene path (FP16 target tonemapped on resolve). This is an ADDITIVE pass;
// linearise the additive contribution so the resolve's ACES+gamma maps it back
// to its authored display-referred look — keeping the stars HDR≈LDR (mirrors the
// blended sky passes: sky_gradient/clouds/haze).
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

// Sine-free hash (multiply, add, fract only): the same on every GPU. The former
// fract(sin(x) * 43758) fed sin() arguments around 1e5, whose precision is up to
// the driver - an Intel UHD drew other stars and other aurora curtains than an RTX.
float hash31(vec3 p) {
    p = fract(p * 0.1031);
    p += dot(p, p.zyx + 31.32);
    return fract((p.x + p.y) * p.z);
}

float vnoise(vec3 p) {
    vec3 i = floor(p);
    vec3 f = fract(p);
    f = f * f * (3.0 - 2.0 * f);
    return mix(mix(mix(hash31(i), hash31(i + vec3(1, 0, 0)), f.x), mix(hash31(i + vec3(0, 1, 0)), hash31(i + vec3(1, 1, 0)), f.x), f.y),
               mix(mix(hash31(i + vec3(0, 0, 1)), hash31(i + vec3(1, 0, 1)), f.x), mix(hash31(i + vec3(0, 1, 1)), hash31(i + vec3(1, 1, 1)), f.x), f.y), f.z);
}

// The milky way (shader plan 4.3): a band round a tilted great circle, patchy
// with dust lanes, and denser in small stars.
const vec3 GALAXY_POLE = vec3(0.42, 0.55, 0.72);
float milkyWay(vec3 dir, out float density) {
    float b = dot(dir, normalize(GALAXY_POLE));
    float band = exp(-b * b / 0.018);
    float clump = vnoise(dir * 7.0) * 0.6 + vnoise(dir * 19.0) * 0.4;
    float lane = smoothstep(0.55, 0.75, vnoise(dir * 11.0 + 3.7)) * exp(-b * b / 0.003);
    density = band;
    return band * (0.35 + 0.65 * clump) * (1.0 - 0.7 * lane);
}

// One shooting star every few seconds: a short streak across a random part of
// the sky, bright at its head, gone in 0.6 s.
float shootingStar(vec3 dir) {
    float period = 7.0;
    float k = floor(u_sky_time / period);
    float t = (u_sky_time - k * period) / 0.6;
    if (t > 1.0) return 0.0;
    vec3 a = normalize(vec3(hash31(vec3(k, 1.0, 0.0)) - 0.5, 0.35 + 0.5 * hash31(vec3(k, 2.0, 0.0)), hash31(vec3(k, 3.0, 0.0)) - 0.5));
    vec3 side = normalize(cross(a, vec3(0.0, 1.0, 0.0)));
    vec3 travel = normalize(side * (hash31(vec3(k, 4.0, 0.0)) > 0.5 ? 1.0 : -1.0) - vec3(0.0, 0.35, 0.0));
    vec3 head = normalize(a + travel * (0.25 * t));
    vec3 tail = normalize(a + travel * (0.25 * t - 0.08));
    vec3 seg = head - tail;
    float h = clamp(dot(dir - tail, seg) / max(dot(seg, seg), 1e-6), 0.0, 1.0);
    float dist = length(dir - (tail + seg * h));
    return exp(-dist / 0.0012) * h * (1.0 - t);
}

// Aurora: curtains hanging in a layer high above, sampled at a few heights along
// the view ray. A curtain follows a folded line across the layer (slow noise), is
// thin across and long along, split into vertical rays, green at the foot and
// violet at the top; the folds drift with time.
vec3 aurora(vec3 dir) {
    if (u_aurora <= 0.0 || dir.y < 0.1) return vec3(0.0);
    vec3 sum = vec3(0.0);
    const int STEPS = 14;
    for (int i = 0; i < STEPS; i++) {
        float h = 1.0 + float(i) / float(STEPS) * 1.4;   // height within the curtain
        vec2 p = dir.xz / dir.y * h * 0.35;
        float t = u_sky_time * 0.03;
        float fold = vnoise(vec3(p.x * 0.6, t, 1.3)) * 2.6 + vnoise(vec3(p.x * 1.7, t * 1.7, 4.1)) * 0.8;
        // Bands repeat every 2.5 units across the layer: several curtains in view.
        float across = abs(fract((p.y - fold) / 2.5) - 0.5) * 2.5;
        float curtain = exp(-across * across * 10.0);
        float rays = 0.45 + 0.55 * vnoise(vec3(p.x * 14.0 + fold * 3.0, t * 4.0, 7.7));
        float k = float(i) / float(STEPS);
        vec3 col = mix(vec3(0.15, 1.0, 0.45), vec3(0.65, 0.25, 0.9), smoothstep01(0.45, 1.0, k));
        sum += col * curtain * rays * (1.0 - k * 0.6);
    }
    // Towards the horizon the bands crowd together: fade them out there.
    float fade = smoothstep01(0.1, 0.45, dir.y) * (1.0 - u_high_fog);
    return sum / float(STEPS) * 2.4 * u_aurora * fade;
}

void main() {
    vec4 world = u_sky_inv_vp * vec4(v_ndc, 1.0, 1.0);
    vec3 dir = normalize(world.xyz / world.w);
    float elevation = dir.y;

    vec3 add = vec3(0.0);
    if (elevation > 0.0) {
        vec3 cell = floor(dir * 200.0);
        float n = hash31(cell);
        float galaxyDensity;
        float glow = milkyWay(dir, galaxyDensity);
        float fadeSky = smoothstep01(0.0, 0.15, elevation) * (1.0 - u_high_fog);
        add += vec3(0.62, 0.62, 0.70) * glow * 0.12 * u_star_brightness * fadeSky;
        // Many faint stars inside the band.
        vec3 fine = floor(dir * 420.0);
        float fn = hash31(fine + 17.0);
        if (fn > 1.0 - 0.012 * galaxyDensity) {
            add += vec3(0.5) * u_star_brightness * fadeSky;
        }
        add += vec3(1.0, 0.96, 0.9) * shootingStar(dir) * 1.6 * u_star_brightness * fadeSky;
        n *= 1.0;
        if (n > 0.9975 && u_high_fog < 0.9) {
            float twinkle = (n - 0.9975) * 400.0;
            float fadeEdge = smoothstep01(0.0, 0.15, elevation); // atmospheric extinction
            add += vec3(twinkle) * u_star_brightness * fadeEdge;
        }
    }
    add += aurora(dir);
    // Under HDR, linearise the additive contribution so the resolve ACES maps an
    // isolated star back to its authored display look (no over-bright/over-spread).
    if (u_linear_output == 1) {
        add = invToneMapInvGamma(add);
    }
    frag_color = vec4(add, 1.0);
}
