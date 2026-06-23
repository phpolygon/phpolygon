#version 410 core

// Screen-space ambient occlusion — depth + normal hemisphere SSAO.
//
// Samples the SSAO G-buffer (RGBA16F; see gbuffer.frag.glsl):
//   rg = VIEW-space normal, OCTAHEDRAL-encoded (full sphere, decodeViewNormal).
//        Octahedral round-trips any unit vector — including the back/grazing
//        normals VIO_CULL_NONE rasterises — so the decoded normal is never
//        flipped. This frees the blue channel for SSR reflectivity.
//   b   = SSR reflectivity (unused here)
//   a   = LINEAR view depth in world units (0 == sky / no geometry)
//
// We reconstruct each pixel's VIEW-space position from its UV and the
// linear depth using the perspective focal lengths (u_proj00 = projection[0][0],
// u_proj11 = projection[1][1]). This is convention-independent — it never touches
// NDC depth, so it is identical on OpenGL ([-1,1]) and D3D12 ([0,1]).
//
// v_uv is the screen quad's already-V-flipped UV (postprocess.vert.glsl), so it
// samples the G-buffer upright; we stay in this UV space, projecting each
// hemisphere sample back to UV with the same focal lengths. u_uv_flip_y
// (+1 GL / -1 D3D) reconciles the view +Y direction with the G-buffer's UV.v per
// backend. Output: AO in R (1.0 = unoccluded) to a half-res target the blur
// pass cleans up.

in vec2 v_uv;

uniform sampler2D u_gbuffer;
uniform vec2  u_noise_scale;  // gbuffer resolution / noise tile size (4x4)
uniform float u_proj00;       // projection[0][0]
uniform float u_proj11;       // projection[1][1]
uniform float u_uv_flip_y;    // +1 on GL, -1 on D3D (UV.v vs view +Y)

uniform float u_radius;       // sample radius in VIEW-space units (world metres)
uniform float u_bias;         // depth bias to suppress self-occlusion acne
uniform float u_intensity;    // occlusion scale (tier-driven)
uniform float u_power;        // contrast curve applied to the final AO

out vec4 frag_color;

// 16-sample cosine-weighted hemisphere kernel (z >= 0), clustered to the origin.
const int KERNEL = 16;
const vec3 KERNEL_SAMPLES[16] = vec3[](
    vec3( 0.0490,  0.0512,  0.0186), vec3(-0.0386,  0.0699,  0.0820),
    vec3( 0.0900, -0.0598,  0.1158), vec3(-0.1339, -0.0408,  0.0506),
    vec3( 0.1721,  0.1265,  0.1106), vec3(-0.0571,  0.2137,  0.1331),
    vec3( 0.0233, -0.0762,  0.2693), vec3(-0.2532,  0.1182,  0.1626),
    vec3( 0.3037,  0.0660,  0.2099), vec3(-0.1218, -0.3491,  0.1347),
    vec3(-0.0876,  0.2304,  0.4144), vec3( 0.4470, -0.2381,  0.1331),
    vec3( 0.1232,  0.5263,  0.3046), vec3(-0.5657, -0.1098,  0.4174),
    vec3( 0.2789, -0.4427,  0.6512), vec3(-0.1971,  0.7036,  0.5063)
);

// Per-pixel rotation noise: a tiled pseudo-random tangent-plane vector. Derived
// from UV so it needs no noise texture (and adds no sampler to the SRV table).
vec3 rotationNoise(vec2 uv) {
    vec2 p = uv * u_noise_scale;
    float a = fract(sin(dot(floor(p), vec2(12.9898, 78.233))) * 43758.5453) * 6.2831853;
    return vec3(cos(a), sin(a), 0.0);
}

// Linear VIEW depth (world units) stored at UV, or 0 for sky/no-geometry.
float sampleViewDepth(vec2 uv) {
    return texture(u_gbuffer, uv).a;
}

// Decode the VIEW-space normal from the octahedral pair gbuffer.frag wrote into
// rg. Full-sphere inverse of octEncode — returns the true normal with ANY z sign
// (back-facing / grazing fragments under VIO_CULL_NONE survive intact), so the
// SSAO hemisphere is never flipped into the surface.
vec3 decodeViewNormal(vec2 e) {
    vec3 n = vec3(e, 1.0 - abs(e.x) - abs(e.y));
    float t = max(-n.z, 0.0);
    n.x += n.x >= 0.0 ? -t : t;
    n.y += n.y >= 0.0 ? -t : t;
    return normalize(n);
}

// Reconstruct VIEW-space position from UV + linear depth. Inverse of the
// perspective xy mapping:  view.xy = ndc.xy * z / proj ;  view.z = -depth.
vec3 viewPosFromUV(vec2 uv, float linearDepth) {
    vec2 ndc = uv * 2.0 - 1.0;
    ndc.y *= u_uv_flip_y;
    float vx = ndc.x * linearDepth / u_proj00;
    float vy = ndc.y * linearDepth / u_proj11;
    return vec3(vx, vy, -linearDepth);
}

void main() {
    vec4 g = texture(u_gbuffer, v_uv);
    float depth = g.a;

    // Sky / no geometry: leave fully lit (alpha-cleared to 0 -> depth 0).
    // G = 0 marks this texel as background so the (bilateral) blur pass can tell
    // sky apart from genuinely-unoccluded foreground; B carries the linear view
    // depth (scaled into 0..1) so the blur can reject neighbours across a depth
    // discontinuity. Both are written for every texel below; here the sky case
    // writes flag 0 / depth 0.
    if (depth <= 0.0) {
        frag_color = vec4(1.0, 0.0, 0.0, 1.0);
        return;
    }

    vec3 N = decodeViewNormal(g.rg);
    vec3 P = viewPosFromUV(v_uv, depth);

    // TBN oriented along N, randomly rotated per pixel (Gram-Schmidt).
    vec3 rvec = rotationNoise(v_uv);
    vec3 T = normalize(rvec - N * dot(rvec, N));
    vec3 B = cross(N, T);
    mat3 TBN = mat3(T, B, N);

    float occlusion = 0.0;
    for (int i = 0; i < KERNEL; i++) {
        vec3 samplePos = P + (TBN * KERNEL_SAMPLES[i]) * u_radius;

        // Project the sample back to UV (forward perspective xy mapping).
        float sampleDepth = -samplePos.z;
        if (sampleDepth <= 0.0) continue; // behind the eye plane
        vec2 sndc;
        sndc.x = (samplePos.x * u_proj00) / sampleDepth;
        sndc.y = (samplePos.y * u_proj11) / sampleDepth;
        sndc.y *= u_uv_flip_y;
        vec2 sampleUV = sndc * 0.5 + 0.5;
        if (sampleUV.x < 0.0 || sampleUV.x > 1.0 ||
            sampleUV.y < 0.0 || sampleUV.y > 1.0) continue;

        float storedDepth = sampleViewDepth(sampleUV);
        if (storedDepth <= 0.0) continue; // sampled into the sky

        // Occluded when the stored surface is CLOSER to the camera than the
        // sample point by more than the bias. Range check fades distant
        // occluders so a thin foreground sliver can't over-darken far geometry.
        float rangeCheck = smoothstep(0.0, 1.0, u_radius / max(abs(depth - storedDepth), 1e-4));
        if (storedDepth < sampleDepth - u_bias) {
            occlusion += rangeCheck;
        }
    }

    occlusion = occlusion / float(KERNEL);
    float ao = clamp(1.0 - occlusion * u_intensity, 0.0, 1.0);
    ao = pow(ao, u_power);

    // R = AO, G = foreground flag (1 = real geometry), B = compressed linear
    // depth in 0..1. The blur pass uses G/B to stay bilateral: it rejects sky
    // neighbours (G==0) and neighbours across a large depth step, so the noisy
    // per-pixel rotation is averaged only over the SAME surface. This is what
    // keeps thin geometry silhouetted against the background from speckling
    // (its blur footprint is otherwise mostly background). The compression is
    // monotonic and convention-independent (linear view depth, GL and D3D
    // identical); the blur only ever compares B values, never decodes metres.
    float bdepth = depth / (depth + 8.0);
    frag_color = vec4(ao, 1.0, bdepth, 1.0);
}
