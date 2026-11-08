#version 410 core

// =============================================================================
// Baked light lookup (shader plan 3.3)
// =============================================================================
//
// Reads the baked light volume (R = sky visibility, G = sun visibility, traced once
// from the SDF by BakedLightBaker) at each pixel's world position and writes the
// same screen target the live SDF trace writes: R = AO, G = sun shadow. The mesh
// shader / MRT composite fold it in unchanged. One texture fetch per pixel instead
// of a trace.
//
// Inputs: the G-buffer (rg = octahedral VIEW normal, a = linear VIEW depth; the
// encoding of ssao.frag / sdf_ao.frag) and the light volume (vio_texture_3d).

in vec2 v_uv;

uniform sampler3D u_light_volume; // slot 0 (the 3D sampler first, as in sdf_ao.frag)
uniform sampler2D u_gbuffer;      // slot 1

uniform float u_proj00;
uniform float u_proj11;
uniform float u_uv_flip_y;
uniform mat4  u_inv_view;

uniform vec3  u_vol_origin;
uniform vec3  u_vol_size;
// x = sky strength, y = sun strength (already scaled by how well the live sun fits
// the baked one), z = sun fade start, w = sun fade end (view distance; z >= w: none)
uniform vec4  u_bl_params;

out vec4 frag_color;

vec3 decodeViewNormal(vec2 e) {
    vec3 n = vec3(e, 1.0 - abs(e.x) - abs(e.y));
    float t = max(-n.z, 0.0);
    n.x += n.x >= 0.0 ? -t : t;
    n.y += n.y >= 0.0 ? -t : t;
    return normalize(n);
}

vec3 viewPosFromUV(vec2 uv, float linearDepth) {
    vec2 ndc = uv * 2.0 - 1.0;
    ndc.y *= u_uv_flip_y;
    return vec3(ndc.x * linearDepth / u_proj00, ndc.y * linearDepth / u_proj11, -linearDepth);
}

void main() {
    vec4 g = texture(u_gbuffer, v_uv);
    float depth = g.a;
    if (depth <= 0.0) {
        frag_color = vec4(1.0, 1.0, 0.0, 1.0);
        return;
    }
    vec3 worldP = (u_inv_view * vec4(viewPosFromUV(v_uv, depth), 1.0)).xyz;
    vec3 worldN = normalize(mat3(u_inv_view) * decodeViewNormal(g.rg));

    // A surface of the field sits on its zero crossing; sample about a cell out along
    // the normal, where the bake traced from free space.
    vec3 cellv = u_vol_size / vec3(textureSize(u_light_volume, 0) - ivec3(1));
    float bias = max(cellv.x, max(cellv.y, cellv.z)) * 0.9;
    vec3 uvw = (worldP + worldN * bias - u_vol_origin) / u_vol_size;
    if (any(lessThan(uvw, vec3(0.0))) || any(greaterThan(uvw, vec3(1.0)))) {
        frag_color = vec4(1.0, 1.0, 0.0, 1.0);
        return;
    }
    // Texel centres sit on the samples: map 0..1 over the sample span onto them.
    vec3 res = vec3(textureSize(u_light_volume, 0));
    vec2 lv = texture(u_light_volume, (uvw * (res - 1.0) + 0.5) / res).rg;

    // The volume holds no direction: beside a wall half the sky is the wall itself,
    // which a surface facing away from it never sees anyway. Measure the sky against
    // what an open surface of this orientation would see (all of it facing up, about
    // half facing sideways) so only real occluders darken.
    float openSky = clamp(0.5 + 0.5 * worldN.y, 0.35, 1.0);
    lv.r = clamp(lv.r / openSky, 0.0, 1.0);

    float sunW = u_bl_params.y;
    if (u_bl_params.w > u_bl_params.z) {
        sunW *= smoothstep(u_bl_params.z, u_bl_params.w, depth);
    }
    frag_color = vec4(mix(1.0, lv.r, u_bl_params.x), mix(1.0, lv.g, sunW), 0.0, 1.0);
}
