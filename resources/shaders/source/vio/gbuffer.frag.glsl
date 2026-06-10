#version 410 core

// SSAO + SSR G-buffer fragment stage.
//
// Output target is RGBA16F (the gbuffer render target is created with hdr=true,
// and bindGbufferPipeline() requests an hdr-output PSO so the D3D12 RTV format
// matches). Channel layout (chosen so SSAO is NOT regressed while freeing a
// channel for SSR reflectivity):
//
//   rg = VIEW-space normal OCTAHEDRAL-encoded (signed [-1,1] in each channel).
//        Octahedral encoding round-trips a FULL unit sphere through two channels,
//        so it faithfully represents back-facing AND grazing view normals — not
//        just the camera-facing hemisphere. This matters because the G-buffer
//        pipeline draws with VIO_CULL_NONE, so back faces (whose view normal has
//        z >= 0) are rasterised; an earlier hemisphere pack (z = -sqrt(...)) FORCED
//        every normal's z negative, FLIPPING those back/grazing faces, which
//        oriented the SSAO hemisphere INTO the surface and crushed whole walls
//        (e.g. the beach hut) to black. octDecode never flips a normal. Consumers
//        decode with octDecode(rg).
//   b  = REFLECTIVITY in [0,1] — how strongly this surface reflects the scene.
//        Derived from the material (metallic, smoothness, wetness). 0 = matte,
//        the SSR pass skips it; >0 = reflective, SSR ray-marches it.
//   a  = LINEAR view depth (-viewPos.z) in world units, full FP16 precision.
//
// Linear view depth is convention-INDEPENDENT (computed from the view position,
// not NDC z), so it is identical on GL and D3D. The renderer clears this target
// to 0, so a == 0 marks "sky / no geometry"; SSAO and SSR treat depth <= 0 as
// sky and skip it.

in vec3 v_viewNormal;
in vec3 v_viewPos;
in vec2 v_worldXZ;

// Material reflectivity inputs (mirrors the forward shader's material uniforms;
// see applyGbufferMaterial in VioRenderer3D). Combined here into a single 0..1
// reflectivity term stored in the G-buffer's blue channel.
uniform float u_metallic;      // 0 dielectric .. 1 metal
uniform float u_roughness;     // 0 mirror .. 1 fully rough
uniform float u_wetness;       // per-material wetness (water / wet props)
uniform float u_rain_wetness;  // global rain wetness from the weather system
uniform int   u_proc_mode;     // procedural surface mode (2 = ocean; see mesh3d.frag)

out vec4 frag_color;

// Octahedral normal encoding (Cigolle et al., "A Survey of Efficient
// Representations for Independent Unit Vectors"). Maps a unit vector to a 2D
// point in [-1,1]^2 by projecting the sphere onto an octahedron and unfolding
// it. Full-sphere (handles z<0 and z>0), so back/grazing view normals survive
// the round-trip — no hemisphere assumption, no sign flip. Inverse = octDecode
// in ssao.frag / ssr.frag.
vec2 octWrap(vec2 v) {
    return (1.0 - abs(v.yx)) * vec2(v.x >= 0.0 ? 1.0 : -1.0, v.y >= 0.0 ? 1.0 : -1.0);
}
vec2 octEncode(vec3 n) {
    n /= (abs(n.x) + abs(n.y) + abs(n.z));
    n.xy = n.z >= 0.0 ? n.xy : octWrap(n.xy);
    return n.xy;
}

void main() {
    vec3 n = normalize(v_viewNormal);
    float linearDepth = -v_viewPos.z; // view looks down -Z, so > 0 for real geometry

    // Reflectivity: smooth metals and wet/water surfaces reflect; rough matte
    // surfaces do not. smoothness = 1 - roughness gates everything (a rough
    // metal still shouldn't mirror). Wetness (per-material OR global rain, on
    // up-facing fragments) lifts dielectric reflectivity so wet ground / puddles
    // catch the scene. Kept conservative so only genuinely shiny pixels march.
    float smoothness = 1.0 - clamp(u_roughness, 0.0, 1.0);
    float wet = max(u_wetness, u_rain_wetness);
    // Wetness only makes UP-FACING surfaces reflective (puddles lie flat); the
    // view-space up test isn't available cheaply here, so gate by world-up later
    // is overkill — instead let the SSR pass's Fresnel + the wetness magnitude
    // keep it subtle. Combine: metals reflect by metallic, everything reflects a
    // little when smooth, wet surfaces reflect more.
    //
    // smoothness^2 for the wet term kills the near-matte tail: dry sand
    // (roughness ~0.92 → smoothness ~0.08) used to leak smoothness ~0.08 of
    // reflectivity that grazing Fresnel amplified into a visible sheen; squared
    // it drops to ~0.006 (below the SSR matte cutoff of 0.001 once any wetness
    // is < ~0.15). Water (roughness 0.02 → smoothness ~0.98) is essentially
    // unchanged (0.98^2 = 0.96), so genuine mirrors keep reflecting.
    float metalRefl  = u_metallic * smoothness;
    float wetRefl    = wet * smoothness * smoothness;
    float reflectivity = clamp(max(metalRefl, wetRefl), 0.0, 1.0);

    // Ocean shoreline fade (proc_mode 2). The forward ocean shader fades its
    // alpha to ZERO inside r=92m via `shoreEdge = smoothstep(92,100,r)` so the
    // single world-spanning water plane is INVISIBLE over/around the beach. The
    // G-buffer, however, would still write the water's full reflectivity (the
    // 0.85 wetness floor) wherever that invisible plane wins the depth test —
    // including right under the dry sand — so SSR reflected the beach as if it
    // were a mirror (diagonal wave-pattern sheen). Apply the SAME shoreEdge here
    // so invisible ocean contributes ZERO reflectivity; only the genuinely
    // visible water past the shoreline stays reflective. Matches mesh3d.frag's
    // computeWater() exactly.
    if (u_proc_mode == 2) {
        float r = length(v_worldXZ);
        reflectivity *= smoothstep(92.0, 100.0, r);
    }

    frag_color = vec4(octEncode(n), reflectivity, linearDepth);
}
