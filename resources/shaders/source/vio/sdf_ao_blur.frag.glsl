#version 410 core

// Fieldtracing SDF-AO blur — bilateral, depth-aware box blur over the raw
// SDF-AO target.
//
// The baked SDF volume is COARSE (metres per voxel). Where a flat surface stands
// near baked geometry, the cone-AO + soft sun-shadow that sdf_ao.frag reads from
// that field carry quantised, voxel-grid-aligned noise — a mottle that survives
// onto bright lit surfaces because the raw target was sampled straight by the
// mesh pass with no smoothing (unlike the SSAO chain, which has a blur pass).
//
// This pass averages that mottle out with a box blur the size of the noise's
// spatial scale, but keeps it BILATERAL so it does NOT bleed contact AO/shadow
// across a geometry silhouette: a neighbour only contributes when its surface is
// at the SAME linear view depth as the centre (read from the G-buffer alpha,
// the exact depth sdf_ao.frag/ssao.frag reconstruct from — linear view metres,
// convention-independent: identical on OpenGL [-1,1] and D3D12 [0,1], no NDC).
//
// Channels: source R = AO, G = sun-shadow. They are accumulated and divided
// INDEPENDENTLY (one shared bilateral weight, but separate sums), so AO never
// contaminates shadow or vice versa — the mesh pass still reads (AO, shadow) in
// (R, G). sdf_ao.frag already returns (1,1) for sky / out-of-volume / free
// space, so those texels blur as harmless no-ops. Indexed by v_uv / normalized
// coords like ssao_blur; u_uv_flip_y conventions are untouched (the G-buffer was
// written upright and is sampled upright here).

in vec2 v_uv;

uniform sampler2D u_gbuffer; // a = LINEAR view depth (world metres), 0 = sky
uniform sampler2D u_source;  // r = SDF AO, g = SDF sun-shadow
uniform vec2 u_texel;        // 1.0 / SDF-AO-target resolution

out vec4 frag_color;

void main() {
    float centreDepth = texture(u_gbuffer, v_uv).a;
    vec2  centre      = texture(u_source, v_uv).rg; // r=AO, g=shadow

    // Sky / no geometry: nothing to occlude or shadow — pass through unblurred
    // (sdf_ao.frag wrote (1,1) here anyway).
    if (centreDepth <= 0.0) {
        frag_color = vec4(1.0, 1.0, 0.0, 1.0);
        return;
    }

    // Depth band for "same surface", scaled with distance: at range, a fixed
    // metric tolerance would reject the whole footprint (perspective spreads
    // depth across neighbours), so we widen it proportionally. Keeps near-camera
    // contact edges crisp while still averaging far flat areas.
    float depthTol = max(0.25, centreDepth * 0.04);

    vec2  sumAoSh = vec2(0.0); // x = AO sum, y = shadow sum (independent)
    float wsum    = 0.0;
    // 5x5 footprint (-2..+2) — a touch wider than the 4x4 SSAO blur because the
    // SDF mottle is full-res and lower-frequency than the SSAO rotation noise.
    for (int x = -2; x <= 2; x++) {
        for (int y = -2; y <= 2; y++) {
            vec2 off = vec2(float(x), float(y)) * u_texel;
            vec2 suv = v_uv + off;
            float d = texture(u_gbuffer, suv).a;
            // Reject sky (d<=0) and neighbours across a depth discontinuity.
            float w = step(0.0001, d) * step(abs(d - centreDepth), depthTol);
            vec2 s = texture(u_source, suv).rg;
            sumAoSh += s * w;
            wsum    += w;
        }
    }

    // The centre always passes (w=1), so wsum >= 1; if everything else is
    // rejected (an isolated sliver) we fall back to the centre value.
    vec2 res = sumAoSh / max(wsum, 1.0);
    frag_color = vec4(res.r, res.g, 0.0, 1.0);
}
