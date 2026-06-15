#version 410 core

// =============================================================================
// Fieldtracing — screen-space SDF ambient-occlusion + soft sun-shadow pass
// =============================================================================
//
// The separate trace pass the design mandates (PHPOLYGON_FIELDTRACING.md §7,
// anti-pattern #1): instead of marching the SDF in the mesh shader, this runs
// ONCE per pixel over the resolved G-buffer and writes occlusion + shadow into a
// screen target the mesh shader then merely samples.
//
// Inputs: the SSAO G-buffer (RGBA16F; rg = octahedral VIEW normal, a = linear
// VIEW depth — identical encoding to ssao.frag.glsl) and the baked SDF volume
// (vio_texture_3d, world-space, decoded as in fieldtrace_volume.frag.glsl).
//
// We reconstruct VIEW position/normal exactly as ssao.frag does, lift them to
// WORLD space via u_inv_view, then sample the SDF volume for cone-traced AO and
// a Quilez soft shadow toward the sun.
//
// Output: R = AO (1 = unoccluded), G = sun shadow (1 = lit). Sky => (1,1).

in vec2 v_uv;

uniform sampler3D u_sdf_volume;  // slot 0 (declared first so its register is t0)
uniform sampler2D u_gbuffer;     // slot 1

uniform float u_proj00;     // projection[0][0]
uniform float u_proj11;     // projection[1][1]
uniform float u_uv_flip_y;  // +1 GL / -1 D3D
uniform mat4  u_inv_view;   // view -> world

uniform vec3  u_sun_dir;    // world-space direction TOWARD the sun (normalized)
uniform vec3  u_vol_origin; // world min corner of the SDF volume
uniform vec3  u_vol_size;   // world extent of the SDF volume
uniform float u_vol_range;  // distance normalisation used by SdfVolume::toRgba8()
uniform float u_ao_radius;  // AO reach in world units

out vec4 frag_color;

// --- G-buffer decode (mirror of ssao.frag.glsl) ------------------------------
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
    return vec3(ndc.x * linearDepth / u_proj00,
                ndc.y * linearDepth / u_proj11,
                -linearDepth);
}

// --- SDF volume sampling (mirror of fieldtrace_volume.frag.glsl) -------------
float mapVolume(vec3 p) {
    vec3 uvw = clamp((p - u_vol_origin) / u_vol_size, 0.0, 1.0);
    float s = texture(u_sdf_volume, uvw).r;
    return (s * 2.0 - 1.0) * u_vol_range;
}

float ambientOcclusion(vec3 p, vec3 n) {
    float occ = 0.0;
    float sca = 1.0;
    for (int i = 0; i < 5; i++) {
        float hr = 0.02 + u_ao_radius * float(i) / 4.0;
        float d = mapVolume(p + n * hr);
        occ += (hr - d) * sca;
        sca *= 0.6;
    }
    return clamp(1.0 - 2.5 * occ, 0.0, 1.0);
}

float softShadow(vec3 ro, vec3 rd) {
    float res = 1.0;
    float t = 0.05;
    for (int i = 0; i < 40; i++) {
        float h = mapVolume(ro + rd * t);
        if (h < 0.0015) return 0.0;
        res = min(res, 8.0 * h / t);
        t += clamp(h, 0.03, 0.4);
        if (t > 16.0) break;
    }
    return clamp(res, 0.0, 1.0);
}

void main() {
    vec4 g = texture(u_gbuffer, v_uv);
    float depth = g.a;
    if (depth <= 0.0) {            // sky / no geometry
        frag_color = vec4(1.0, 1.0, 0.0, 1.0);
        return;
    }

    vec3 viewN = decodeViewNormal(g.rg);
    vec3 viewP = viewPosFromUV(v_uv, depth);

    // Lift to world space. (View basis is orthonormal, so the rotation part of
    // u_inv_view transforms the normal correctly.)
    vec3 worldP = (u_inv_view * vec4(viewP, 1.0)).xyz;
    vec3 worldN = normalize(mat3(u_inv_view) * viewN);

    float ao = ambientOcclusion(worldP + worldN * 0.02, worldN);
    float sh = softShadow(worldP + worldN * 0.04, normalize(u_sun_dir));

    frag_color = vec4(ao, sh, 0.0, 1.0);
}
