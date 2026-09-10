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

    vec3 color = max(sun.rgb * sunShadow + local.rgb + ambient * ao, vec3(0.0));

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
