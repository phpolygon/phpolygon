#version 410 core

// MRT scene composite (engine RFC docs/rfcs/mrt-gbuffer.md).
//
// The opaque + transparent passes wrote the lit scene split into three FP16
// attachments (mesh3d.frag with PHPOLYGON_MRT):
//   u_sun      primary directional light  -> scaled by the SDF soft sun shadow
//   u_local    other lights, IBL, emission, fog colour, volumetrics, sky
//   u_ambient  flat ambient + probe GI     -> scaled by SSAO and SDF-AO
// The AO passes ran on the fourth attachment (the G-buffer) AFTER the geometry was
// drawn, so this is where their result is folded in — with exactly the factors the
// single-target mesh shader applies per fragment:
//   colour = sun * sdfShadow + local + ambient * ssao * sdfAo
// Sky pixels carry sun = ambient = 0 and AO = 1 (the AO passes treat G-buffer depth
// 0 as sky), so they pass through untouched.
//
// Output: linear when the scene target is FP16 (u_linear_output = 1, the resolve
// tonemaps later), otherwise the same ACES + gamma the mesh shader used inline.

in vec2 v_uv;

uniform sampler2D u_sun;
uniform sampler2D u_local;
uniform sampler2D u_ambient;
uniform sampler2D u_ssao_map;      // blurred SSAO (R), half res; white when off
uniform sampler2D u_sdf_ao_map;    // blurred SDF trace (R = AO, G = sun shadow); white when off
uniform int   u_ssao_enabled;
uniform float u_sdf_ao_enabled;    // float: int-in-UBO is unreliable across SPIRV-Cross targets
uniform int   u_linear_output;

// Screen-space contact shadows (shader plan 3.3): a short march from each pixel
// toward the sun through the G-buffer depth catches the small occluders the
// shadow map is too coarse for (a cup on a table, a foot on the floor, the gap
// under a bench). Only the sun term darkens.
uniform sampler2D u_gbuffer;       // rg view normal (octahedral), b reflectivity, a linear view depth
uniform vec4  u_contact;           // xyz: direction to the sun in view space, w: strength (0 = off)
uniform vec4  u_contact_ray;       // x: ray length in m, y: thickness in m
uniform float u_proj00;
uniform float u_proj11;
uniform float u_uv_flip_y;
// Screen-space radiosity (shader plan 10.2, GraphicsSettings::$screenSpaceGi).
uniform vec4  u_ssgi;           // x strength, y gather radius (m), w on
uniform vec3  u_ssgi_ambient;   // the ambient light the ambient attachment was lit with
// Screen-space subsurface scattering (GraphicsSettings::$screenSpaceSss): x on (strength
// scale), y scatter width in metres. Skin pixels carry -strength in the G-buffer's b.
uniform vec4  u_sss;

// Light diffuses under skin: the sun and ambient light of a skin pixel blurred over a
// disc a few millimetres wide in the world (12 taps), only from skin at about the same
// depth; red reaches furthest, blue least.
void subsurfaceBlur(vec2 uv, float strength, float depth, inout vec3 sunRgb, inout vec3 ambientRgb) {
    const vec2 disc[12] = vec2[12](
        vec2(0.2770, 0.1650), vec2(-0.3320, 0.2020), vec2(0.0530, -0.4460), vec2(0.5580, -0.2980),
        vec2(-0.6260, -0.3270), vec2(-0.1170, 0.6880), vec2(0.6950, 0.4720), vec2(-0.8580, 0.1730),
        vec2(0.1720, 0.8960), vec2(0.3630, -0.8440), vec2(-0.4340, -0.8320), vec2(0.9380, -0.0290));
    vec2 radius = vec2(u_sss.y * u_proj00, u_sss.y * u_proj11) * 0.5 / max(depth, 1.0e-3);
    vec3 sunSum = sunRgb;
    vec3 ambSum = ambientRgb;
    float wSum = 1.0;
    for (int i = 0; i < 12; i++) {
        vec2 suv = uv + disc[i] * radius;
        vec4 g = texture(u_gbuffer, suv);
        if (g.b >= 0.0 || g.a <= 0.0) continue;          // only skin scatters into skin
        float dz = abs(g.a - depth) / (0.02 + depth * 0.01);
        float w = exp(-dot(disc[i], disc[i]) * 2.0) * exp(-dz * dz);
        sunSum += texture(u_sun, suv).rgb * w;
        ambSum += texture(u_ambient, suv).rgb * w;
        wSum += w;
    }
    vec3 profile = vec3(1.0, 0.55, 0.3) * clamp(strength, 0.0, 1.0);
    sunRgb = mix(sunRgb, sunSum / wSum, profile);
    ambientRgb = mix(ambientRgb, ambSum / wSum, profile);
}

vec3 contactViewPos(vec2 uv, float d) {
    vec2 ndc = uv * 2.0 - 1.0;
    ndc.y *= u_uv_flip_y;
    return vec3(ndc.x * d / u_proj00, ndc.y * d / u_proj11, -d);
}

vec2 contactUV(vec3 p) {
    vec2 ndc = vec2(p.x * u_proj00, p.y * u_proj11) / max(-p.z, 1e-4);
    ndc.y *= u_uv_flip_y;
    return ndc * 0.5 + 0.5;
}

float contactShadow(vec2 uv) {
    if (u_contact.w <= 0.0) {
        return 1.0;
    }
    float depth = textureLod(u_gbuffer, uv, 0.0).a;
    if (depth <= 0.0 || depth > 60.0) {
        return 1.0; // sky, or too far for a few centimetres to matter
    }
    vec3 L = u_contact.xyz;
    // Start a little toward the sun so the surface does not shadow itself.
    vec3 P = contactViewPos(uv, depth) + L * (0.02 + depth * 0.002);
    float jitter = fract(52.9829189 * fract(dot(gl_FragCoord.xy, vec2(0.06711056, 0.00583715))));
    const int STEPS = 12;
    float thick = u_contact_ray.y * (1.0 + depth * 0.02);
    for (int i = 1; i <= STEPS; i++) {
        float f = (float(i) - jitter) / float(STEPS);
        vec3 S = P + L * (u_contact_ray.x * f);
        vec2 suv = contactUV(S);
        if (suv.x < 0.0 || suv.x > 1.0 || suv.y < 0.0 || suv.y > 1.0 || S.z >= 0.0) {
            break;
        }
        float sd = textureLod(u_gbuffer, suv, 0.0).a;
        float delta = -S.z - sd;
        if (sd > 0.0 && delta > 0.0 && delta < thick) {
            // Fade with distance along the ray so the contact edge stays soft.
            return 1.0 - u_contact.w * (1.0 - f * 0.6);
        }
    }
    return 1.0;
}

// View normal from the G-buffer's octahedral pair (inverse of gbuffer.frag's octEncode).
vec3 decodeViewNormal(vec2 e) {
    vec3 n = vec3(e, 1.0 - abs(e.x) - abs(e.y));
    float t = max(-n.z, 0.0);
    n.x += n.x >= 0.0 ? -t : t;
    n.y += n.y >= 0.0 ? -t : t;
    return normalize(n);
}

// One bounce of the direct light between pixels: neighbours within the gather radius
// that face this point send their sun + local light, weighted by both cosines and
// the distance. Twelve samples on a turning spiral; the receiving albedo comes back
// out of the ambient attachment (ambient light * albedo).
vec3 screenSpaceGi(vec2 uv) {
    vec4 g = textureLod(u_gbuffer, uv, 0.0);
    float depth = g.a;
    if (depth <= 0.0 || depth > 80.0) {
        return vec3(0.0);
    }
    vec3 P = contactViewPos(uv, depth);
    vec3 N = decodeViewNormal(g.rg);
    float spin = 6.2831853 * fract(52.9829189 * fract(dot(gl_FragCoord.xy, vec2(0.06711056, 0.00583715))));
    vec3 acc = vec3(0.0);
    const int SAMPLES = 12;
    for (int i = 0; i < SAMPLES; i++) {
        float f = (float(i) + 0.5) / float(SAMPLES);
        float a = float(i) * 2.39996 + spin;
        vec3 offset = vec3(cos(a), sin(a), 0.0) * (u_ssgi.y * sqrt(f));
        vec2 suv = contactUV(P + offset);
        if (suv.x < 0.0 || suv.x > 1.0 || suv.y < 0.0 || suv.y > 1.0) {
            continue;
        }
        vec4 sg = textureLod(u_gbuffer, suv, 0.0);
        if (sg.a <= 0.0) {
            continue;
        }
        vec3 S = contactViewPos(suv, sg.a);
        vec3 d = S - P;
        float dist = length(d);
        if (dist < 0.02 || dist > u_ssgi.y * 2.0) {
            continue;
        }
        vec3 dir = d / dist;
        // Form factor of the patch a sample stands for: cos * cos / (pi d^2) times its
        // area (the disc pi R^2 shared by all samples), so the sum below is E / pi * pi.
        float w = max(dot(N, dir), 0.0) * max(dot(decodeViewNormal(sg.rg), -dir), 0.0) / max(dist * dist, 0.09);
        acc += (textureLod(u_sun, suv, 0.0).rgb + textureLod(u_local, suv, 0.0).rgb) * w;
    }
    // Uniform over the disc (sqrt spacing): each sample covers pi R^2 / SAMPLES.
    return acc * (u_ssgi.y * u_ssgi.y / float(SAMPLES));
}

out vec4 frag_color;

vec3 toneMapACES(vec3 x) {
    const float a = 2.51;
    const float b = 0.03;
    const float c = 2.43;
    const float d = 0.59;
    const float e = 0.14;
    return clamp((x * (a * x + b)) / (x * (c * x + d) + e), 0.0, 1.0);
}

void main() {
    vec4 sun     = texture(u_sun, v_uv);
    vec4 local   = texture(u_local, v_uv);
    vec3 ambient = texture(u_ambient, v_uv).rgb;
    if (u_sss.x > 0.0) {
        vec4 g0 = texture(u_gbuffer, v_uv);
        if (g0.b < 0.0 && g0.a > 0.0) {
            vec3 sunRgb = sun.rgb;
            subsurfaceBlur(v_uv, -g0.b * u_sss.x, g0.a, sunRgb, ambient);
            sun.rgb = sunRgb;
        }
    }

    float ao = 1.0;
    if (u_ssao_enabled == 1) {
        ao = clamp(texture(u_ssao_map, v_uv).r, 0.0, 1.0);
    }
    float sunShadow = 1.0;
    if (u_sdf_ao_enabled > 0.5) {
        vec2 ftAoSh = texture(u_sdf_ao_map, v_uv).rg;
        ao = min(ao, clamp(ftAoSh.r, 0.0, 1.0));
        sunShadow = clamp(ftAoSh.g, 0.0, 1.0);
    }

    sunShadow *= contactShadow(v_uv);
    vec3 color = max(sun.rgb * sunShadow + local.rgb + ambient * ao, vec3(0.0));
    if (u_ssgi.w > 0.5) {
        vec3 albedo = clamp(ambient / max(u_ssgi_ambient, vec3(1e-4)), 0.0, 1.0);
        color += albedo * screenSpaceGi(v_uv) * u_ssgi.x * ao;
    }

    // The composite is alpha-blended over the scene target so pixels nothing was
    // drawn into (alpha 0) keep the caller's clear colour, exactly like the
    // forward path. A transparent surface over NOTHING left premultiplied colour
    // (c * a over black) with alpha a in the attachments — divide it back out so
    // the blend below yields c * a + clear * (1 - a), not c * a * a.
    float alpha = clamp(local.a, 0.0, 1.0);
    if (alpha > 0.0 && alpha < 1.0) {
        color /= alpha;
    }
    if (u_linear_output == 0) {
        color = toneMapACES(color);
        color = pow(color, vec3(1.0 / 2.2));
    }
    frag_color = vec4(color, alpha);
}
