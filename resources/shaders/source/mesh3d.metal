#include <metal_stdlib>
using namespace metal;

// ── Vertex layout (matches MetalRenderer3D::uploadMesh, 32 bytes per vertex) ──
struct VertexIn {
    float3 position [[attribute(0)]];
    float3 normal   [[attribute(1)]];
    float2 uv       [[attribute(2)]];
};

// ── Buffer slot 0: model matrix (via setVertexBytes, pushed inline per draw) ──
struct PushConstants {
    float4x4 model;
};

// ── Buffer slot 1: FrameUBO ───────────────────────────────────────────────────
struct FrameUBO {
    float4x4 view;
    float4x4 projection; // Z-corrected by CPU before upload
};

// ── Buffer slot 2: LightingUBO ───────────────────────────────────────────────
//
// PHP packs all vec3 fields as 3 consecutive floats (12 bytes) immediately
// followed by the scalar field.  MSL's unqualified float3 is 16 bytes (same
// as float4), which would silently misalign every field.  packed_float3 is
// exactly 12 bytes and matches the PHP layout.
//
struct PointLight {
    packed_float3 position;
    float  intensity;
    packed_float3 color;
    float  radius;
};

struct LightingUBO {
    packed_float3 ambient_color;
    float  ambient_intensity;

    packed_float3 dir_light_direction;
    float  dir_light_intensity;
    packed_float3 dir_light_color;
    float  _pad0;

    packed_float3 albedo;
    float  roughness;

    packed_float3 emission;
    float  metallic;

    packed_float3 fog_color;
    float  fog_near;

    packed_float3 camera_pos;
    float  fog_far;

    int    point_light_count;
    float  _pad2;
    float  _pad3;
    float  _pad4;

    // Procedural-mode environment block (added 2026-04-28 for sand/water/palm/etc.)
    packed_float3 sky_color;
    float  time;
    packed_float3 horizon_color;
    float  moon_phase;
    packed_float3 season_tint;
    int    proc_mode;       // 0=PBR, 1=sand, 2=water, 3=rock, 4=palm trunk,
                            // 5=palm leaf, 6=cloud, 7=wood planks, 8=thatch, 9=moon

    float  alpha;
    float  _pad5;
    float  _pad6;
    float  _pad7;

    PointLight point_lights[8];
};

// ── Interpolants ──────────────────────────────────────────────────────────────
struct VertexOut {
    float4 position [[position]];
    float3 normal;
    float3 world_pos;
    float2 uv;
};

// ── Noise helpers (port of mesh3d.frag.glsl) ─────────────────────────────────

static inline float hash21(float2 p) {
    p = fract(p * float2(127.1, 311.7));
    p += dot(p, p + 19.19);
    return fract(p.x * p.y);
}

static inline float hash31(float3 p) {
    p = fract(p * float3(443.897, 441.423, 437.195));
    p += dot(p, p.yzx + 19.19);
    return fract((p.x + p.y) * p.z);
}

static inline float vnoise(float2 p) {
    float2 i = floor(p);
    float2 f = fract(p);
    f = f * f * (3.0 - 2.0 * f);
    float a = hash21(i);
    float b = hash21(i + float2(1.0, 0.0));
    float c = hash21(i + float2(0.0, 1.0));
    float d = hash21(i + float2(1.0, 1.0));
    return mix(mix(a, b, f.x), mix(c, d, f.x), f.y);
}

static inline float fbm(float2 p, int octaves) {
    float value = 0.0;
    float amp = 0.5;
    float freq = 1.0;
    for (int i = 0; i < octaves; i++) {
        value += amp * vnoise(p * freq);
        freq *= 2.0;
        amp  *= 0.5;
    }
    return value;
}

static inline float3 fresnelSchlick(float cosTheta, float3 F0) {
    return F0 + (1.0 - F0) * pow(clamp(1.0 - cosTheta, 0.0, 1.0), 5.0);
}

// ── Vertex shader ─────────────────────────────────────────────────────────────

vertex VertexOut vertex_mesh3d(
    VertexIn         in     [[stage_in]],
    constant PushConstants& push  [[buffer(0)]],
    constant FrameUBO&      frame [[buffer(1)]]
) {
    float4 world_pos = push.model * float4(in.position, 1.0);

    // Approximation: use upper-left 3×3 of model (valid for uniform scale).
    float3x3 normalMatrix = float3x3(
        push.model[0].xyz,
        push.model[1].xyz,
        push.model[2].xyz
    );

    VertexOut out;
    out.position  = frame.projection * frame.view * world_pos;
    out.normal    = normalize(normalMatrix * in.normal);
    out.world_pos = world_pos.xyz;
    out.uv        = in.uv;
    return out;
}

// ────────────────────────────────────────────────────────────────────────────
//  Procedural materials — ports of mesh3d.frag.glsl
// ────────────────────────────────────────────────────────────────────────────

static float3 computeSand(VertexOut in, float3 V, float3 L, float3 season_tint, thread float& roughOut) {
    float zone = in.uv.x;
    float variant = in.uv.y;

    const float3 damp[4] = {
        float3(0.478, 0.369, 0.165), float3(0.408, 0.306, 0.125),
        float3(0.541, 0.408, 0.188), float3(0.290, 0.220, 0.094)
    };
    const float3 mid[4] = {
        float3(0.722, 0.565, 0.314), float3(0.627, 0.471, 0.220),
        float3(0.784, 0.596, 0.345), float3(0.420, 0.333, 0.157)
    };
    const float3 dry[4] = {
        float3(0.831, 0.722, 0.478), float3(0.769, 0.643, 0.384),
        float3(0.878, 0.769, 0.549), float3(0.545, 0.451, 0.251)
    };
    const float3 dune[4] = {
        float3(0.863, 0.753, 0.502), float3(0.910, 0.800, 0.565),
        float3(0.816, 0.706, 0.439), float3(0.604, 0.502, 0.282)
    };

    float3 c0, c1, c2, c3;
    if (zone < 0.125) {
        c0 = damp[0]; c1 = damp[1]; c2 = damp[2]; c3 = damp[3];
    } else if (zone < 0.375) {
        c0 = mid[0];  c1 = mid[1];  c2 = mid[2];  c3 = mid[3];
    } else if (zone < 0.625) {
        c0 = dry[0];  c1 = dry[1];  c2 = dry[2];  c3 = dry[3];
    } else {
        c0 = dune[0]; c1 = dune[1]; c2 = dune[2]; c3 = dune[3];
    }

    float vi = variant * 3.0;
    int idx = int(floor(vi));
    int idxA = clamp(idx, 0, 3);
    int idxB = clamp(idx + 1, 0, 3);
    float3 ringA = (idxA == 0) ? c0 : (idxA == 1 ? c1 : (idxA == 2 ? c2 : c3));
    float3 ringB = (idxB == 0) ? c0 : (idxB == 1 ? c1 : (idxB == 2 ? c2 : c3));
    float3 baseColor = mix(ringA, ringB, fract(vi));

    baseColor *= season_tint;

    float n1 = fbm(in.world_pos.xz * 1.5, 3);
    float n2 = vnoise(in.world_pos.xz * 6.0);
    float n3 = vnoise(in.world_pos.xz * 25.0);
    float n4 = vnoise(in.world_pos.xz * 80.0);

    float3 sandColor = baseColor;
    sandColor *= 0.82 + n1 * 0.36;
    sandColor *= 0.92 + (n2 - 0.5) * 0.16;
    sandColor += float3(0.02) * (n3 - 0.5);
    sandColor += float3(0.01, 0.008, 0.005) * (n4 - 0.5);

    float ripple = sin(in.world_pos.x * 3.0 + in.world_pos.z * 1.5 + n1 * 2.0) * 0.5 + 0.5;
    ripple = smoothstep(0.3, 0.7, ripple);
    float rippleStrength = smoothstep(0.3, 0.8, zone);
    sandColor *= 1.0 - ripple * 0.06 * rippleStrength;

    float scatter = max(dot(V, L), 0.0);
    scatter = pow(scatter, 4.0) * 0.08;
    sandColor += float3(0.15, 0.10, 0.04) * scatter;

    roughOut = mix(0.45, 0.95, smoothstep(0.0, 0.3, zone));
    if (zone < 0.15) {
        sandColor = mix(sandColor, sandColor * 1.15, 0.3);
    }
    return sandColor;
}

static float3 computeWater(VertexOut in, float3 N_in, float3 V, float3 L,
                           float time, float3 sky_color, float3 horizon_color,
                           float3 dir_light_color, float dir_light_intensity,
                           thread float& alphaOut, thread float& roughOut,
                           thread float3& N_out) {
    float2 uv1 = in.world_pos.xz * 0.8 + time * float2(0.03, 0.02);
    float2 uv2 = in.world_pos.xz * 1.6 + time * float2(-0.02, 0.04);
    float2 uv3 = in.world_pos.xz * 4.0 + time * float2(0.05, -0.03);
    float2 uv4 = in.world_pos.xz * 8.0 + time * float2(-0.04, 0.06);

    float eps = 0.05;
    float h1a = fbm(uv1, 3); float h1b = fbm(uv1 + float2(eps, 0), 3); float h1c = fbm(uv1 + float2(0, eps), 3);
    float h2a = fbm(uv2, 2); float h2b = fbm(uv2 + float2(eps, 0), 2); float h2c = fbm(uv2 + float2(0, eps), 2);
    float h3a = vnoise(uv3); float h3b = vnoise(uv3 + float2(eps, 0)); float h3c = vnoise(uv3 + float2(0, eps));
    float h4a = vnoise(uv4); float h4b = vnoise(uv4 + float2(eps, 0)); float h4c = vnoise(uv4 + float2(0, eps));

    float3 waveNormal = float3(0.0, 1.0, 0.0);
    waveNormal.x += (h1a - h1b) * 1.5 + (h2a - h2b) * 0.8 + (h3a - h3b) * 0.3 + (h4a - h4b) * 0.15;
    waveNormal.z += (h1a - h1c) * 1.5 + (h2a - h2c) * 0.8 + (h3a - h3c) * 0.3 + (h4a - h4c) * 0.15;
    waveNormal = normalize(waveNormal);

    float3 N = normalize(N_in + waveNormal * float3(1.0, 0.0, 1.0));
    N_out = N;

    float NdotV = max(dot(N, V), 0.0);
    float fresnel = pow(1.0 - NdotV, 5.0);
    fresnel = mix(0.02, 1.0, fresnel);

    float depth = clamp(max(0.0, -8.0 - in.world_pos.z) / 70.0, 0.0, 1.0);
    float3 shallowColor = float3(0.15, 0.55, 0.50);
    float3 deepColor    = float3(0.02, 0.08, 0.15);
    float3 waterColor   = mix(shallowColor, deepColor, depth);

    float3 R = reflect(-V, N);
    float skyBlend = clamp(R.y * 2.0, 0.0, 1.0);
    float3 reflectColor = mix(horizon_color, sky_color, skyBlend);
    float sunCatch = pow(max(dot(R, L), 0.0), 256.0);
    reflectColor = mix(reflectColor, dir_light_color, sunCatch * 2.0);

    float3 finalColor = mix(waterColor, reflectColor, fresnel);

    float3 Hw = normalize(V + L);
    float specWater = pow(max(dot(N, Hw), 0.0), 512.0);
    finalColor += dir_light_color * dir_light_intensity * specWater * 2.0;

    float foamLine = smoothstep(0.02, 0.0, depth);
    float foamNoise = fbm(in.world_pos.xz * 6.0 + time * 0.5, 3);
    float foam = foamLine * smoothstep(0.35, 0.65, foamNoise);
    finalColor = mix(finalColor, float3(0.9, 0.95, 1.0), foam * 0.7);

    if (depth < 0.3) {
        float caustic1 = vnoise(in.world_pos.xz * 3.0 + time * 0.8);
        float caustic2 = vnoise(in.world_pos.xz * 3.0 - time * 0.6 + 50.0);
        float caustic = pow(min(caustic1, caustic2), 3.0) * 2.0;
        finalColor += float3(0.1, 0.15, 0.1) * caustic * (1.0 - depth / 0.3);
    }

    alphaOut = mix(0.5, 0.92, depth);
    alphaOut = mix(alphaOut, 1.0, foam * 0.8);
    roughOut = 0.05;
    return finalColor;
}

static float3 computeRock(float3 N, float3 worldPos, float3 baseAlbedo, thread float& roughOut) {
    float3 p = worldPos * 2.5;
    float n1 = fbm(p.xz, 4);
    float n2 = fbm(p.xz * 3.0 + 50.0, 3);

    float3 darkStone  = baseAlbedo * 0.6;
    float3 lightStone = baseAlbedo * 1.3;
    float3 rockColor = mix(darkStone, lightStone, n1);

    float crack = vnoise(p.xz * 8.0 + float2(p.y * 2.0));
    crack = smoothstep(0.48, 0.52, crack);
    rockColor = mix(rockColor, rockColor * 0.5, crack * 0.4);

    float strata = sin(worldPos.y * 15.0 + n1 * 3.0) * 0.5 + 0.5;
    strata = smoothstep(0.4, 0.6, strata);
    rockColor *= 0.9 + strata * 0.2;

    float upFacing = max(dot(N, float3(0.0, 1.0, 0.0)), 0.0);
    float mossNoise = fbm(worldPos.xz * 4.0, 3);
    float moss = upFacing * smoothstep(0.4, 0.7, mossNoise) * smoothstep(0.5, 0.9, upFacing);
    rockColor = mix(rockColor, float3(0.15, 0.25, 0.08), moss * 0.6);

    float lichenNoise = vnoise(worldPos.xz * 10.0 + 200.0);
    if (lichenNoise > 0.85) {
        rockColor = mix(rockColor, float3(0.6, 0.5, 0.2), (lichenNoise - 0.85) * 4.0 * 0.3);
    }

    roughOut = 0.75 + n2 * 0.2;
    roughOut = mix(roughOut, 0.6, moss * 0.5);
    return rockColor;
}

static float3 computePalmTrunk(VertexOut in, float3 baseAlbedo, thread float& roughOut) {
    float ring = sin(in.uv.y * 6.2831 * 1.2) * 0.5 + 0.5;
    ring = smoothstep(0.3, 0.7, ring);
    float fiber     = vnoise(float2(in.uv.x * 20.0, in.uv.y * 4.0));
    float fiberFine = vnoise(float2(in.uv.x * 50.0, in.uv.y * 10.0));

    float3 darkBark  = baseAlbedo * 0.65;
    float3 lightBark = baseAlbedo * 1.2;
    float3 barkColor = mix(darkBark, lightBark, ring * 0.6 + fiber * 0.4);
    barkColor *= 0.85 + ring * 0.3;
    barkColor *= 0.95 + (fiberFine - 0.5) * 0.15;

    float weather = fbm(in.world_pos.xz * 5.0, 2);
    barkColor = mix(barkColor, barkColor * float3(0.85, 0.9, 0.8), weather * 0.2);

    roughOut = 0.85 + ring * 0.1;
    return barkColor;
}

static float3 computePalmLeaf(VertexOut in, float3 N, float3 V, float3 L, float3 baseAlbedo, thread float& roughOut) {
    float sideways = (in.uv.x - 0.5) * 2.0;
    float vein = abs(sin(sideways * 18.0));
    vein = smoothstep(0.0, 0.15, vein);

    float n = fbm(in.uv * 8.0, 3);
    float3 leafColor = baseAlbedo * (0.8 + n * 0.4);
    leafColor = mix(leafColor * 1.3, leafColor, vein);

    float age = smoothstep(0.6, 1.0, in.uv.y);
    leafColor = mix(leafColor, leafColor * float3(0.55, 0.45, 0.18) * 1.4, age * 0.35);

    float edgeNoise = vnoise(in.uv * 12.0);
    float edgeMask = smoothstep(0.6, 1.0, abs(sideways));
    leafColor = mix(leafColor, float3(0.4, 0.35, 0.15), edgeMask * edgeNoise * 0.25);

    float translucency = max(dot(-N, L), 0.0);
    translucency = pow(translucency, 2.0) * 0.3;
    leafColor += float3(0.1, 0.2, 0.02) * translucency;

    float scatter = pow(max(dot(V, L), 0.0), 3.0) * 0.1;
    leafColor += float3(0.05, 0.1, 0.02) * scatter;

    roughOut = 0.6 + edgeNoise * 0.15;
    return leafColor;
}

static float3 computeWoodPlanks(VertexOut in, float3 N_in, float3 worldPos, float3 baseAlbedo,
                                 thread float& roughOut, thread float3& N_out) {
    // Use world position as a fallback for plank coords — the engine doesn't
    // pass v_localPos / v_localNormal / v_objectScale through this shader yet.
    float3 absN = abs(N_in);
    float plankCoord, grainCoord;
    if (absN.y > absN.x && absN.y > absN.z) {
        plankCoord = worldPos.z * 6.5;
        grainCoord = worldPos.x * 8.0;
    } else if (absN.x >= absN.z) {
        plankCoord = worldPos.y * 6.5;
        grainCoord = worldPos.z * 8.0;
    } else {
        plankCoord = worldPos.y * 6.5;
        grainCoord = worldPos.x * 8.0;
    }

    float plankIndex  = floor(plankCoord);
    float withinPlank = fract(plankCoord);
    float gap = smoothstep(0.0, 0.03, withinPlank) * smoothstep(1.0, 0.97, withinPlank);

    float plankHash  = hash21(float2(plankIndex * 17.3, plankIndex * 7.1));
    float plankHash2 = hash21(float2(plankIndex * 31.7, plankIndex * 13.3));

    float3 woodColor = baseAlbedo;
    woodColor *= 0.8 + plankHash * 0.4;
    woodColor = mix(woodColor, woodColor * float3(1.05, 0.95, 0.85), plankHash2 * 0.3);

    float offsetGrain = grainCoord + plankHash * 20.0;
    float grain = sin(offsetGrain + vnoise(float2(offsetGrain * 0.5, plankIndex)) * 3.0);
    grain = grain * 0.5 + 0.5;
    woodColor *= 0.9 + grain * 0.15;

    float fineGrain = vnoise(float2(offsetGrain * 3.0, plankCoord * 2.0 + plankIndex * 5.0));
    woodColor *= 0.95 + fineGrain * 0.1;

    woodColor *= gap * 0.85 + 0.15;

    float weather = fbm(worldPos.xz * 3.0 + worldPos.y * 2.0, 2);
    woodColor *= 0.85 + weather * 0.2;

    float bumpX = vnoise(float2(offsetGrain + 0.1, plankCoord * 8.0)) - 0.5;
    float bumpY = (withinPlank < 0.04 || withinPlank > 0.96) ? -0.3 : 0.0;
    N_out = normalize(N_in + float3(bumpX * 0.08, bumpY, bumpX * 0.05));

    roughOut = 0.78 + plankHash * 0.15;
    return woodColor;
}

static float3 computeThatch(float3 N_in, float3 worldPos, float3 baseAlbedo,
                            thread float& roughOut, thread float3& N_out) {
    float strandAngle = worldPos.x * 12.0 + worldPos.z * 6.0 + worldPos.y * 4.0;
    float strand1 = sin(strandAngle) * 0.5 + 0.5;
    float strand2 = sin(strandAngle * 1.7 + 3.0) * 0.5 + 0.5;
    float strand3 = sin(strandAngle * 0.6 + 7.0) * 0.5 + 0.5;
    float density = strand1 * 0.4 + strand2 * 0.35 + strand3 * 0.25;
    density = smoothstep(0.2, 0.8, density);

    float3 strawColor = baseAlbedo;
    float n = fbm(worldPos.xz * 5.0 + worldPos.y * 3.0, 3);
    strawColor *= 0.75 + n * 0.5;

    float strandHighlight = pow(strand1, 8.0);
    strawColor += float3(0.1, 0.08, 0.02) * strandHighlight;

    float strandGap = smoothstep(0.45, 0.5, strand1) * smoothstep(0.55, 0.5, strand1);
    strawColor *= 1.0 - strandGap * 0.3;

    float age = vnoise(worldPos.xz * 8.0);
    strawColor = mix(strawColor, strawColor * 0.6, smoothstep(0.7, 0.9, age) * 0.4);

    float nx = sin(strandAngle + 0.1) * 0.1;
    float nz = cos(strandAngle * 0.7) * 0.08;
    N_out = normalize(N_in + float3(nx, 0.0, nz));

    roughOut = 0.92 + density * 0.06;
    return strawColor;
}

static float3 computeCloud(VertexOut in, float3 N, float3 V, float3 L, float3 baseAlbedo, thread float& alphaOut) {
    float NdotL = max(dot(N, L), 0.0);
    float3 sunColor    = float3(1.0, 0.98, 0.95);
    float3 shadowColor = float3(0.6, 0.65, 0.72);
    float3 cloudColor = mix(shadowColor, sunColor, NdotL * 0.7 + 0.3);

    float scatter = pow(max(dot(V, L), 0.0), 3.0);
    cloudColor += float3(0.3, 0.25, 0.15) * scatter * 0.4;

    float rim = pow(1.0 - max(dot(N, V), 0.0), 3.0);
    cloudColor += float3(0.5, 0.5, 0.4) * rim * scatter * 0.6;

    float n = fbm(in.world_pos.xz * 0.3, 3);
    cloudColor *= 0.9 + n * 0.2;

    float edgeFade = pow(max(dot(N, V), 0.0), 0.8);
    alphaOut = edgeFade * 0.85;
    return cloudColor;
}

static float3 computeMoon(float3 N, float3 V, float moon_phase) {
    float3 vUp = abs(V.y) > 0.99 ? float3(0.0, 0.0, 1.0) : float3(0.0, 1.0, 0.0);
    float3 viewRight = normalize(cross(V, vUp));
    float localX = dot(N, viewRight);
    float terminator = cos(moon_phase * 2.0 * 3.14159);
    float illumination = smoothstep(terminator - 0.12, terminator + 0.12, localX);

    float crater = vnoise(N.xz * 4.0 + N.y * 2.0);
    float mare = smoothstep(0.42, 0.55, crater) * 0.25;
    float detail = (vnoise(N.xz * 12.0 + N.yz * 8.0) - 0.5) * 0.08;
    float3 moonColor = float3(0.85, 0.87, 0.92) * (1.0 - mare) + detail;

    float3 lit = moonColor * illumination;
    lit += float3(0.02, 0.025, 0.04) * (1.0 - illumination);
    return lit;
}

// ── Fragment shader ───────────────────────────────────────────────────────────

fragment float4 fragment_mesh3d(
    VertexOut             in     [[stage_in]],
    constant LightingUBO& light  [[buffer(2)]],
    bool is_front_face            [[front_facing]]
) {
    float3 N = normalize(is_front_face ? in.normal : -in.normal);
    float3 V = normalize(light.camera_pos - in.world_pos);
    float3 L = normalize(-light.dir_light_direction);

    float roughness = clamp(light.roughness, 0.04, 1.0);
    float alpha     = light.alpha;
    float3 albedo   = light.albedo;

    int proc = light.proc_mode;

    // ---- Self-lit modes that bypass PBR and exit early ----
    if (proc == 2) {
        float3 Nw = N;
        albedo = computeWater(in, N, V, L, light.time, light.sky_color, light.horizon_color,
                              light.dir_light_color, light.dir_light_intensity,
                              alpha, roughness, Nw);
        // Fog
        float fogDist   = length(in.world_pos - light.camera_pos);
        float fogFactor = clamp((fogDist - light.fog_near) / (light.fog_far - light.fog_near), 0.0, 1.0);
        fogFactor = 1.0 - exp(-fogFactor * fogFactor * 3.0);
        float3 color = mix(albedo, light.fog_color, fogFactor);
        color = pow(max(color, float3(0.0)), float3(1.0 / 2.2));
        return float4(color, alpha);
    }
    if (proc == 6) {
        albedo = computeCloud(in, N, V, L, light.albedo, alpha);
        float fogDist   = length(in.world_pos - light.camera_pos);
        float fogFactor = clamp((fogDist - light.fog_near) / (light.fog_far - light.fog_near), 0.0, 1.0);
        fogFactor = 1.0 - exp(-fogFactor * fogFactor * 3.0);
        float3 color = mix(albedo, light.fog_color, fogFactor);
        color = pow(max(color, float3(0.0)), float3(1.0 / 2.2));
        return float4(color, alpha);
    }
    if (proc == 9) {
        float3 lit = computeMoon(N, V, light.moon_phase);
        return float4(pow(max(lit, float3(0.0)), float3(1.0 / 2.2)), 1.0);
    }

    // ---- PBR modes ----
    if (proc == 1) {
        albedo = computeSand(in, V, L, light.season_tint, roughness);
        float nx = vnoise(in.world_pos.xz * 20.0 + float2(0.1, 0.0));
        float nz = vnoise(in.world_pos.xz * 20.0 + float2(0.0, 0.1));
        N = normalize(N + float3((nx - 0.5) * 0.05, 0.0, (nz - 0.5) * 0.05));
    } else if (proc == 3) {
        albedo = computeRock(N, in.world_pos, light.albedo, roughness);
        float rx = vnoise(in.world_pos.xz * 15.0 + float2(0.1, 0.0));
        float rz = vnoise(in.world_pos.xz * 15.0 + float2(0.0, 0.1));
        float ry = vnoise(in.world_pos.yz * 15.0);
        N = normalize(N + float3((rx - 0.5) * 0.12, (ry - 0.5) * 0.08, (rz - 0.5) * 0.12));
    } else if (proc == 4) {
        albedo = computePalmTrunk(in, light.albedo, roughness);
        float tnx = vnoise(float2(in.world_pos.x * 30.0, in.world_pos.y * 5.0));
        N = normalize(N + float3((tnx - 0.5) * 0.08, 0.0, (tnx - 0.5) * 0.08));
    } else if (proc == 5) {
        albedo = computePalmLeaf(in, N, V, L, light.albedo, roughness);
    } else if (proc == 7) {
        float3 Nw = N;
        albedo = computeWoodPlanks(in, N, in.world_pos, light.albedo, roughness, Nw);
        N = Nw;
    } else if (proc == 8) {
        float3 Nw = N;
        albedo = computeThatch(N, in.world_pos, light.albedo, roughness, Nw);
        N = Nw;
    } else {
        // proc == 0 — plain PBR with light noise modulation
        float nse = vnoise(in.world_pos.xz * 0.4);
        float noiseMask = smoothstep(0.3, 0.9, roughness);
        albedo = light.albedo * (1.0 + (nse - 0.5) * 0.12 * noiseMask);
    }

    // ---- PBR lighting ----
    float shininess = exp2(10.0 * (1.0 - roughness) + 1.0);
    float3 F0 = mix(float3(0.04), albedo, light.metallic);
    float NdotL = max(dot(N, L), 0.0);

    float3 color = light.ambient_color * light.ambient_intensity * albedo
                   * (1.0 - light.metallic * 0.9);

    // Half-Lambert wrap on the directional light keeps low-angle terrain lit
    // (sunrise / sunset glow) — matches the GLSL renderer's behaviour.
    float rawNdotL  = dot(N, L);
    float halfLamb  = rawNdotL * 0.5 + 0.5;
    halfLamb       *= halfLamb;
    float diffNdotL = mix(NdotL, halfLamb, 0.4);

    if (diffNdotL > 0.0) {
        color += albedo * light.dir_light_color * light.dir_light_intensity
                 * diffNdotL * (1.0 - light.metallic);
    }
    if (NdotL > 0.0) {
        float3 H    = normalize(V + L);
        float NdotH = max(dot(N, H), 0.0);
        float spec  = pow(NdotH, shininess) * (shininess + 2.0) / 8.0;
        float3 F    = fresnelSchlick(max(dot(H, V), 0.0), F0);
        color += F * light.dir_light_color * light.dir_light_intensity * spec * NdotL;
    }

    for (int i = 0; i < light.point_light_count; i++) {
        float3 plPos   = light.point_lights[i].position;
        float3 plColor = light.point_lights[i].color;
        float  plInt   = light.point_lights[i].intensity;
        float  plRad   = max(light.point_lights[i].radius, 0.001);

        float3 Lp   = plPos - in.world_pos;
        float  dist = length(Lp);
        Lp = normalize(Lp);
        float3 Hp = normalize(V + Lp);
        float atten = clamp(1.0 - (dist * dist) / (plRad * plRad), 0.0, 1.0);
        atten *= atten;
        float NdotPL = max(dot(N, Lp), 0.0);
        if (NdotPL > 0.0) {
            color += albedo * plColor * plInt * NdotPL * atten * (1.0 - light.metallic);
            float specP = pow(max(dot(N, Hp), 0.0), shininess) * (shininess + 2.0) / 8.0;
            float3 FP   = fresnelSchlick(max(dot(Hp, V), 0.0), F0);
            color += FP * plColor * plInt * specP * NdotPL * atten;
        }
    }

    color += light.emission;

    float fogDist   = length(in.world_pos - light.camera_pos);
    float fogFactor = clamp((fogDist - light.fog_near) / (light.fog_far - light.fog_near), 0.0, 1.0);
    fogFactor = 1.0 - exp(-fogFactor * fogFactor * 3.0);
    color = mix(color, light.fog_color, fogFactor);

    // ACES filmic tonemap
    {
        const float a = 2.51, b = 0.03, c = 2.43, d = 0.59, e = 0.14;
        color = clamp((color * (a * color + b)) / (color * (c * color + d) + e), 0.0, 1.0);
    }
    color = pow(max(color, float3(0.0)), float3(1.0 / 2.2));
    return float4(color, alpha);
}
