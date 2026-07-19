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

struct SpotLight {
    packed_float3 position;
    float  intensity;
    packed_float3 direction;
    float  range;
    packed_float3 color;
    float  angle;       // cone half-angle (radians)
    float  penumbra;    // soft-edge fraction 0..1
    float  _spad0;
    float  _spad1;
    float  _spad2;
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

    // Carpaint material extension (added with mesh3d.metal carpaint port).
    float  clearcoat;            // 0 = off, 1 = full clearcoat lobe
    float  clearcoat_roughness;  // independent lobe roughness
    float  flakes;               // metallic flake density
    float  normal_intensity;     // procedural normal-perturbation multiplier

    // Per-material IBL toggle. When 0, this material always uses the
    // sky/horizon-tinted reflection regardless of cubemap availability.
    int    use_environment_map;
    // Per-frame flag set by the renderer when an actual cubemap texture
    // has been bound to fragment texture slot 0. The shader samples the
    // cubemap only when both flags are 1.
    int    has_environment_map;
    // Highest available mip level in the cubemap (mipmapLevelCount - 1).
    // Used to map material roughness to a sampling LOD.
    float  environment_mip_max;
    float  _pad10;

    // Procedural normal-map pattern (mirrors PHPolygon\Rendering\NormalPattern).
    // 0 = no normal map, 1..9 = pattern code dispatched in the fragment
    // shader. normal_scale is a UV tiling multiplier; normal_intensity is
    // shared with the carpaint flake jitter for consistency.
    int    normal_pattern;
    float  normal_scale;
    // Screen-space AO strength (0 = off, 1 = full curvature darkening).
    float  ao_strength;
    float  _pad11;

    // Procedural surface-wear pattern (PHPolygon\Rendering\SurfacePattern).
    // 0 = no wear pattern, 1..4 = code dispatched in the fragment shader.
    int    surface_pattern;
    float  surface_scale;
    float  surface_intensity;
    // Per-material wetness (SSR surrogate). Up-facing fragments get a
    // smoother + darker + brighter-IBL pass to read as wet/polished.
    float  wetness;

    // Color-grading parameters from ColorGradingPreset::params() and the
    // vignette intensity / viewport size used by the in-shader post stage.
    packed_float3 grade_lift;
    float  grade_saturation;
    packed_float3 grade_gamma;
    float  vignette_intensity;
    packed_float3 grade_gain;
    // ScreenSpaceReflections::intensity() - amplifies the wetness IBL lobe
    // to mirror the OpenGL/Vio behaviour (see ssr_intensity in mesh3d.frag.glsl).
    float  ssr_intensity;
    float  viewport_w;
    float  viewport_h;
    int    volumetric_fog;
    float  _pad14;

    // Procedural cloth (mirrors PHPolygon\Rendering\Material::$cloth*).
    // Anchor weight is computed from local Y over the mesh's local AABB
    // exactly like the GLSL backend.
    int    cloth;
    float  cloth_strength;
    float  cloth_frequency;
    float  cloth_phase;
    int    cloth_anchor_top;
    float  _pad_cloth_a;
    float  _pad_cloth_b;
    float  _pad_cloth_c;

    packed_float3 wind_direction;
    float  wind_intensity;
    packed_float3 mesh_local_aabb_min;
    float  _pad_aabb_a;
    packed_float3 mesh_local_aabb_max;
    float  _pad_aabb_b;

    PointLight point_lights[8];

    int    spot_light_count;
    float  _spad_count0;
    float  _spad_count1;
    float  _spad_count2;
    SpotLight spot_lights[8];

    // Fieldtracing (SDF GI) — mirrors the vio + opengl mesh-shader copies
    // (PHPOLYGON_FIELDTRACING.md §7). 0=Off 1=ProbesOnly 2=SdfOcclusion
    // 3=SdfBounce. Appended at the struct tail (after the arrays) so existing
    // field offsets are unchanged. The Metal 3D draw path is currently stubbed,
    // so the renderer does not yet upload these — present for three-copy parity
    // and forward-compat (ft_mode defaults to 0 => strict no-op).
    float ft_mode;
    float ft_intensity;
    float ft_ao;
    // SDF trace-pass result toggle. The screen-space SDF AO/shadow pass is not
    // wired on Metal (its 3D draw path is stubbed), so this stays 0 and the
    // mesh keeps the ProbesOnly contribution above. Present for struct/parity
    // with the GLSL copies; the AO-map texture sample is part of the deferred
    // Metal 3D pass.
    float ft_sdf_ao_enabled;
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
    constant FrameUBO&      frame [[buffer(1)]],
    constant LightingUBO&   light [[buffer(2)]]
) {
    float3 pos = in.position;

    // Procedural cloth sway — anchor weight from local Y over the mesh AABB,
    // matches the GLSL backend (mesh3d.vert.glsl) exactly.
    if (light.cloth == 1) {
        float3 aabbMin = float3(light.mesh_local_aabb_min);
        float3 aabbMax = float3(light.mesh_local_aabb_max);
        float aabbHeight = max(aabbMax.y - aabbMin.y, 1e-4);
        float yNorm = clamp((pos.y - aabbMin.y) / aabbHeight, 0.0, 1.0);
        float anchorWeight = light.cloth_anchor_top == 1 ? yNorm : (1.0 - yNorm);
        float swayMask = 1.0 - anchorWeight;
        float t = light.time * light.cloth_frequency + light.cloth_phase;
        float wave = sin(t + pos.x * 2.0) * 0.7 + cos(t * 1.3 + pos.z * 1.5) * 0.3;
        float3 wd = float3(light.wind_direction);
        float3 windDir = length(wd) > 1e-4 ? normalize(wd) : float3(0.0, 0.0, 1.0);
        float3 sway = windDir * (wave * light.cloth_strength * light.wind_intensity * swayMask);
        sway.y *= 0.15;
        pos += sway;
    }

    float4 world_pos = push.model * float4(pos, 1.0);

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

// PHPOLYGON:PROCMODE_HELPERS_MSL

// Color grading + vignette (mirrors mesh3d.frag.glsl). Lift / Gamma /
// Gain + saturation, applied in linear space prior to ACES, with a
// radial vignette painted on after gamma-encoding.
static inline float3 applyColorGrading(float3 color, float3 lift, float3 gamma, float3 gain, float saturation) {
    color = color + lift;
    color = pow(max(color, float3(0.0)), float3(1.0) / gamma);
    color = color * gain;
    float luma = dot(color, float3(0.2126, 0.7152, 0.0722));
    return mix(float3(luma), color, saturation);
}

static inline float3 applyVignette(float3 color, float2 fragCoord, float2 viewport, float intensity) {
    if (intensity <= 0.0 || viewport.x <= 0.0) return color;
    float2 uv = fragCoord / viewport;
    float2 d  = uv - 0.5;
    float r = length(d);
    float v = smoothstep(0.45, 0.85, r);
    return color * (1.0 - v * intensity);
}

// Volumetric scatter (mirrors mesh3d.frag.glsl). Cheap 8-step
// ray-march along the view ray, weighted by sun phase function.
static inline float3 volumetricScatterMetal(float3 worldPos, constant LightingUBO& light) {
    if (light.volumetric_fog == 0) return float3(0.0);
    float3 rayStart = light.camera_pos;
    float3 rayDir   = worldPos - rayStart;
    float rayLen    = length(rayDir);
    if (rayLen < 0.01) return float3(0.0);
    rayDir /= rayLen;
    float marchLen = min(rayLen, light.fog_far);
    const int STEPS = 8;
    float step = marchLen / float(STEPS);
    float3 sunDir = normalize(-float3(light.dir_light_direction));
    float cosTheta = dot(rayDir, sunDir);
    float phase = 0.5 + pow(max(cosTheta, 0.0), 6.0) * 4.0;
    float3 scatter = float3(0.0);
    float transmittance = 1.0;
    for (int i = 0; i < STEPS; i++) {
        float3 p = rayStart + rayDir * (step * (float(i) + 0.5));
        float density = exp(-max(p.y, 0.0) * 0.08) * 0.06;
        float3 inscatter = float3(light.dir_light_color) * light.dir_light_intensity * phase * density;
        scatter += inscatter * transmittance * step;
        transmittance *= exp(-density * step);
    }
    return scatter;
}

// ── Procedural surface-wear patterns (mirror mesh3d.frag.glsl) ───────────────

static inline float3 sp_worn_paint(float2 uv) {
    float wear = fbm(uv * 3.0, 3);
    float chip = step(0.55, wear);
    float albedoT = mix(0.50, 0.30, chip);
    float roughD  = mix(0.0,  0.35,  chip);
    float metalD  = mix(0.0,  0.55,  chip);
    return float3(albedoT, roughD, metalD);
}
static inline float3 sp_rust(float2 uv) {
    float spotty = fbm(uv * 5.0, 4);
    float rust   = smoothstep(0.45, 0.65, spotty);
    float albedoT = mix(0.50, 0.62, rust);
    float roughD  = mix(0.0,  0.45,  rust);
    float metalD  = mix(0.0, -0.50,  rust);
    return float3(albedoT, roughD, metalD);
}
static inline float3 sp_brushed_metal(float2 uv) {
    float lane = sin(uv.y * 600.0);
    return float3(0.50, lane * 0.10, 0.0);
}
static inline float3 sp_polished_rings(float2 uv) {
    float2 c = uv - 0.5;
    float r = length(c);
    float ring = sin(r * 80.0);
    float matte = smoothstep(0.0, 0.4, ring);
    return float3(0.50, matte * 0.50 - 0.10, 0.0);
}
// Skin freckles + blotchy pigmentation (mirrors the GLSL copies). Two
// smoothstep gates so freckles fade in/out rather than tiling like an
// animal print.
static inline float3 sp_skin(float2 uv) {
    float blotchy = fbm(uv * 1.5, 3);
    float fine    = fbm(uv * 5.0, 3);
    float freckle = smoothstep(0.65, 0.78, fine) * smoothstep(0.40, 0.60, blotchy);
    float albedoT = mix(0.50, 0.44, freckle);
    float roughD  = mix(0.0,  0.04, freckle);
    return float3(albedoT, roughD, 0.0);
}

static inline float3 dispatchSurfacePattern(int code, float2 uv) {
    if (code == 1) return sp_worn_paint(uv);
    if (code == 2) return sp_rust(uv);
    if (code == 3) return sp_brushed_metal(uv);
    if (code == 4) return sp_polished_rings(uv);
    if (code == 5) return sp_skin(uv);
    return float3(0.5, 0.0, 0.0);
}

// One-shot final stage shared by every mesh3d exit path: color grade,
// tone-map, gamma encode, vignette. Keeps the four exits in lock-step
// with the GLSL `finalize()` helper.
struct LightingUBO; // forward declared above
static inline float3 toneMapACES(float3 x); // defined below; finalizeColor uses it first
static inline float3 finalizeColor(float3 color, constant LightingUBO& light, float2 fragCoord) {
    color = applyColorGrading(max(color, float3(0.0)),
                              float3(light.grade_lift),
                              float3(light.grade_gamma),
                              float3(light.grade_gain),
                              light.grade_saturation);
    color = toneMapACES(color);
    color = pow(color, float3(1.0 / 2.2));
    return applyVignette(color, fragCoord,
                         float2(light.viewport_w, light.viewport_h),
                         light.vignette_intensity);
}

// Curvature-based AO (mirrors mesh3d.frag.glsl). Cheap surrogate for
// SSAO until a depth-buffer pre-pass lands.
static inline float curvatureAO(float3 N, float strength) {
    if (strength <= 0.0) return 1.0;
    float3 ddxN = dfdx(N);
    float3 ddyN = dfdy(N);
    float curvature = length(ddxN) + length(ddyN);
    float occlusion = smoothstep(0.0, 0.4, curvature);
    return clamp(1.0 - occlusion * strength, 0.0, 1.0);
}

// ACES filmic tonemap (Narkowicz). Used by every fragment-shader exit
// path in this file so the tone response stays consistent whether the
// HDR/Bloom post-process is on or not.
static inline float3 toneMapACES(float3 x) {
    const float a = 2.51;
    const float b = 0.03;
    const float c = 2.43;
    const float d = 0.59;
    const float e = 0.14;
    return clamp((x * (a * x + b)) / (x * (c * x + d) + e), 0.0, 1.0);
}

// ── Procedural normal-map patterns (mirror mesh3d.frag.glsl) ─────────────────

static inline float3 np_bricks(float2 uv) {
    float2 cell = float2(0.5, 1.0);
    float rowIndex = floor(uv.y / cell.y);
    float xOffset = fmod(rowIndex, 2.0) * 0.5 * cell.x;
    float2 local = float2(fract((uv.x + xOffset) / cell.x),
                          fract(uv.y / cell.y));
    float mortarX = 1.0 - (smoothstep(0.0, 0.06, local.x) *
                           smoothstep(1.0, 0.94, local.x));
    float mortarY = 1.0 - (smoothstep(0.0, 0.06, local.y) *
                           smoothstep(1.0, 0.94, local.y));
    float groove = max(mortarX, mortarY);
    float2 slope = float2(mortarX, mortarY) *
                   float2(local.x < 0.5 ? 1.0 : -1.0,
                          local.y < 0.5 ? 1.0 : -1.0);
    return normalize(float3(slope * 0.6, 1.0 - groove * 0.5));
}

static inline float3 np_bumps(float2 uv) {
    float e = 0.05;
    float h  = vnoise(uv * 8.0);
    float hx = vnoise(uv * 8.0 + float2(e, 0.0));
    float hy = vnoise(uv * 8.0 + float2(0.0, e));
    float2 grad = float2(hx - h, hy - h) / e;
    return normalize(float3(-grad * 0.4, 1.0));
}

static inline float3 np_orange_peel(float2 uv) {
    float2 p = uv * 60.0;
    float h  = hash21(floor(p));
    float hx = hash21(floor(p) + float2(1.0, 0.0));
    float hy = hash21(floor(p) + float2(0.0, 1.0));
    return normalize(float3((h - hx) * 0.6, (h - hy) * 0.6, 1.0));
}

static inline float3 np_hammered(float2 uv) {
    float2 grid = uv * 6.0;
    float2 cell = floor(grid);
    float2 local = fract(grid) - 0.5;
    float2 jitter = float2(hash21(cell), hash21(cell + 17.0)) - 0.5;
    float2 centred = local - jitter * 0.4;
    float r = length(centred);
    float rim = smoothstep(0.45, 0.20, r);
    float2 slope = -centred * rim * 1.4;
    return normalize(float3(slope, 1.0));
}

static inline float3 np_hexagons(float2 uv) {
    float2 p = uv * 5.0;
    float2 a = float2(p.x + p.y * 0.5, p.y * 0.866);
    float2 af = fract(a) - 0.5;
    float2 slope = -af * 1.2;
    float edge = smoothstep(0.45, 0.50, max(abs(af.x), abs(af.y)));
    return normalize(float3(slope * (1.0 - edge), 1.0 - edge * 0.4));
}

static inline float3 np_wood_grain(float2 uv) {
    float grad = cos(uv.y * 80.0 + vnoise(uv * float2(20.0, 4.0)) * 6.0) * 80.0;
    float slopeY = grad * 0.005;
    return normalize(float3(0.0, slopeY, 1.0));
}

static inline float3 np_scratches(float2 uv) {
    float rotated = uv.x * 0.97 + uv.y * 0.24;
    float across  = -uv.x * 0.24 + uv.y * 0.97;
    float lane = floor(across * 80.0);
    float laneJitter = hash21(float2(lane, 0.0));
    float scratch = sin((rotated + laneJitter * 6.28) * 30.0);
    float mask = step(0.6, hash21(float2(lane, 13.0)));
    return normalize(float3(scratch * mask * 0.5, 0.0, 1.0));
}

static inline float3 np_cracked(float2 uv) {
    float2 p = uv * 8.0;
    float2 ip = floor(p);
    float2 fp = fract(p);
    float d1 = 8.0;
    float d2 = 8.0;
    for (int x = -1; x <= 1; x++) {
        for (int y = -1; y <= 1; y++) {
            float2 g = float2(float(x), float(y));
            float2 jitter = float2(hash21(ip + g),
                                   hash21(ip + g + 51.0));
            float d = length(g + jitter - fp);
            if (d < d1) { d2 = d1; d1 = d; }
            else if (d < d2) { d2 = d; }
        }
    }
    float crack = smoothstep(0.04, 0.0, d2 - d1);
    return normalize(float3(0.0, 0.0, 1.0) +
                     float3((fp.x - 0.5) * crack, (fp.y - 0.5) * crack, 0.0));
}

static inline float3 np_noise_pattern(float2 uv) {
    float e = 0.04;
    float h  = fbm(uv * 6.0, 3);
    float hx = fbm(uv * 6.0 + float2(e, 0.0), 3);
    float hy = fbm(uv * 6.0 + float2(0.0, e), 3);
    float2 grad = float2(hx - h, hy - h) / e;
    return normalize(float3(-grad * 0.5, 1.0));
}

// Skin micro-relief: medium-scale pore noise + slow wrinkle FBM (mirrors
// the Vio + OpenGL shader copies verbatim).
static inline float3 np_skin(float2 uv) {
    float e = 0.02;
    float h  = vnoise(uv * 14.0) * 0.55 + fbm(uv * 4.0, 3) * 0.45;
    float hx = vnoise((uv + float2(e, 0.0)) * 14.0) * 0.55
             + fbm((uv + float2(e, 0.0)) * 4.0, 3) * 0.45;
    float hy = vnoise((uv + float2(0.0, e)) * 14.0) * 0.55
             + fbm((uv + float2(0.0, e)) * 4.0, 3) * 0.45;
    float2 grad = float2(hx - h, hy - h) / e;
    return normalize(float3(-grad * 0.06, 1.0));
}

static inline float3 dispatchProceduralNormal(int code, float2 uv) {
    if (code == 1)  return np_bricks(uv);
    if (code == 2)  return np_bumps(uv);
    if (code == 3)  return np_orange_peel(uv);
    if (code == 4)  return np_hammered(uv);
    if (code == 5)  return np_hexagons(uv);
    if (code == 6)  return np_wood_grain(uv);
    if (code == 7)  return np_scratches(uv);
    if (code == 8)  return np_cracked(uv);
    if (code == 9)  return np_noise_pattern(uv);
    if (code == 10) return np_skin(uv);
    return float3(0.0, 0.0, 1.0);
}

static inline float3 perturbNormalProcedural(float3 N, float3 worldPos, float2 uv,
                                             int patternCode, float patternScale,
                                             float intensity) {
    if (patternCode == 0 || intensity <= 0.0) return N;
    float3 dpx = dfdx(worldPos);
    float3 dpy = dfdy(worldPos);
    float2 duvx = dfdx(uv);
    float2 duvy = dfdy(uv);
    float det = duvx.x * duvy.y - duvy.x * duvx.y;
    if (abs(det) < 1e-8) return N;
    float3 T = (dpx * duvy.y - dpy * duvx.y) / det;
    T = normalize(T - N * dot(N, T));
    float3 B = normalize(cross(N, T));
    float3x3 TBN = float3x3(T, B, N);

    float3 nMap = dispatchProceduralNormal(patternCode, uv * patternScale);
    nMap = mix(float3(0.0, 0.0, 1.0), nMap, clamp(intensity, 0.0, 4.0));
    return normalize(TBN * nMap);
}

// ── Fragment shader ───────────────────────────────────────────────────────────

fragment float4 fragment_mesh3d(
    VertexOut             in     [[stage_in]],
    constant LightingUBO& light  [[buffer(2)]],
    texturecube<float>    env    [[texture(0)]],
    sampler               envSampler [[sampler(0)]],
    bool is_front_face            [[front_facing]]
) {
    float3 N = normalize(is_front_face ? in.normal : -in.normal);
    float3 V = normalize(light.camera_pos - in.world_pos);
    float3 L = normalize(-light.dir_light_direction);

    float roughness = clamp(light.roughness, 0.04, 1.0);
    float metallic  = light.metallic;
    float alpha     = light.alpha;
    float3 albedo   = light.albedo;

    int proc = light.proc_mode;

    // ---- Self-lit modes that bypass PBR and exit early ----
/* PHPOLYGON:PROCMODE_BRANCHES_MSL */ {
        // proc == 0 — plain PBR with light noise modulation
        float nse = vnoise(in.world_pos.xz * 0.4);
        float noiseMask = smoothstep(0.3, 0.9, roughness);
        albedo = light.albedo * (1.0 + (nse - 0.5) * 0.12 * noiseMask);
    }

    // Procedural normal-map pattern (mirrors mesh3d.frag.glsl).
    N = perturbNormalProcedural(N, in.world_pos, in.uv,
                                light.normal_pattern, light.normal_scale,
                                light.normal_intensity);

    if (light.surface_pattern > 0 && light.surface_intensity > 0.0) {
        float3 wear = dispatchSurfacePattern(light.surface_pattern, in.uv * light.surface_scale);
        float t = clamp(light.surface_intensity, 0.0, 4.0);
        float3 tint = mix(float3(1.0), float3(wear.x * 2.0), t);
        albedo *= tint;
        roughness = clamp(roughness + wear.y * t, 0.04, 1.0);
        metallic  = clamp(metallic  + wear.z * t, 0.0,  1.0);
    }

    float wetnessApplied = 0.0;
    if (light.wetness > 0.0) {
        float upMask = clamp(dot(N, float3(0.0, 1.0, 0.0)) * 1.4 - 0.2, 0.0, 1.0);
        wetnessApplied = light.wetness * upMask;
        roughness = mix(roughness, max(roughness * 0.25, 0.04), wetnessApplied);
        albedo    = mix(albedo,    albedo * 0.7,                 wetnessApplied);
    }

    // ---- PBR lighting ----
    float shininess = exp2(10.0 * (1.0 - roughness) + 1.0);
    float3 F0 = mix(float3(0.04), albedo, metallic);
    float NdotL = max(dot(N, L), 0.0);

    float ao = curvatureAO(N, light.ao_strength);
    float3 color = light.ambient_color * light.ambient_intensity * albedo
                   * (1.0 - metallic * 0.9) * ao;

    // Fieldtracing contribution (before finalizeColor): hemisphere "probe"
    // ambient layered over the flat ambient (ProbesOnly tier), modulated by AO.
    // mode 0 (Off) is a strict no-op.
    //
    // NOTE: the vio/opengl copies additionally sample a baked SH-L1 irradiance
    // probe field (u_probe_*) here for directional GI. Metal keeps the analytic
    // hemisphere only — wiring the probe field needs a texture3d binding plus the
    // ft_* fields packed into the lighting UBO (buildLightingUboBytes), which is
    // byte-offset-sensitive and must be done + validated on a Metal device.
    if (light.ft_mode >= 0.5f) {
        float  ftHemi   = N.y * 0.5f + 0.5f;
        float3 ambientC = float3(light.ambient_color);
        float3 ftSky    = ambientC * 1.2f + float3(0.015f, 0.03f, 0.06f);
        float3 ftGround = ambientC * 0.6f;
        float3 ftProbe  = mix(ftGround, ftSky, ftHemi) * light.ambient_intensity;
        color += albedo * (1.0f - metallic * 0.9f) * ftProbe * ao * (0.35f * light.ft_intensity);
    }

    // Half-Lambert wrap on the directional light keeps low-angle terrain lit
    // (sunrise / sunset glow) — matches the GLSL renderer's behaviour.
    float rawNdotL  = dot(N, L);
    float halfLamb  = rawNdotL * 0.5 + 0.5;
    halfLamb       *= halfLamb;
    float diffNdotL = mix(NdotL, halfLamb, 0.4);

    if (diffNdotL > 0.0) {
        color += albedo * light.dir_light_color * light.dir_light_intensity
                 * diffNdotL * (1.0 - metallic);
    }
    if (NdotL > 0.0) {
        float3 H    = normalize(V + L);
        float NdotH = max(dot(N, H), 0.0);
        float spec  = pow(NdotH, shininess) * (shininess + 2.0) / 8.0;
        float3 F    = fresnelSchlick(max(dot(H, V), 0.0), F0);
        color += F * light.dir_light_color * light.dir_light_intensity * spec * NdotL;
    }

    // Clamp the loop bound to the array size: *_light_count is GPU-supplied
    // and a stale/garbage value would otherwise run the loop for millions of
    // iterations (and index out of bounds) → GPU hang.
    int pointCount = min(light.point_light_count, 4);
    for (int i = 0; i < pointCount; i++) {
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
            color += albedo * plColor * plInt * NdotPL * atten * (1.0 - metallic);
            float specP = pow(max(dot(N, Hp), 0.0), shininess) * (shininess + 2.0) / 8.0;
            float3 FP   = fresnelSchlick(max(dot(Hp, V), 0.0), F0);
            color += FP * plColor * plInt * specP * NdotPL * atten;
        }
    }

    // Spot lights — point-light falloff multiplied by a cone factor.
    int spotCount = min(light.spot_light_count, 4);
    for (int i = 0; i < spotCount; i++) {
        float3 slPos   = light.spot_lights[i].position;
        float3 slDir   = light.spot_lights[i].direction;
        float3 slColor = light.spot_lights[i].color;
        float  slInt   = light.spot_lights[i].intensity;
        float  slRange = max(light.spot_lights[i].range, 0.001);

        float3 Ls   = slPos - in.world_pos;
        float  dist = length(Ls);
        Ls = normalize(Ls);
        float3 Hs = normalize(V + Ls);
        float atten = clamp(1.0 - (dist * dist) / (slRange * slRange), 0.0, 1.0);
        atten *= atten;

        float cosOuter = cos(light.spot_lights[i].angle);
        float cosInner = cos(light.spot_lights[i].angle * (1.0 - light.spot_lights[i].penumbra));
        float cd = dot(-Ls, normalize(slDir));
        float cone = smoothstep(cosOuter, cosInner, cd);
        atten *= cone;

        float NdotSL = max(dot(N, Ls), 0.0);
        if (NdotSL > 0.0 && cone > 0.0) {
            color += albedo * slColor * slInt * NdotSL * atten * (1.0 - metallic);
            float specS = pow(max(dot(N, Hs), 0.0), shininess) * (shininess + 2.0) / 8.0;
            float3 FS   = fresnelSchlick(max(dot(Hs, V), 0.0), F0);
            color += FS * slColor * slInt * specS * NdotSL * atten;
        }
    }

    // ---- IBL reflection ----
    // Phase 4: when has_environment_map == 1 the renderer has bound the
    // sky-rendered cubemap to fragment texture slot 0; we sample with
    // explicit LOD to drive Roughness-correct trilinear blur. Otherwise
    // we fall back to the sky/horizon-tinted gradient.
    if (light.use_environment_map == 1) {
        float3 R = reflect(-V, N);
        float NdotV = max(dot(N, V), 0.0);
        float3 F_ibl = fresnelSchlick(NdotV, F0);

        float3 envColor;
        if (light.has_environment_map == 1) {
            // Map perceptual roughness to mip LOD. Linear mapping is good
            // enough for procedural sky cubemaps; for HDRI environments a
            // GGX prefilter would be more accurate but is out of scope.
            float lod = roughness * light.environment_mip_max;
            envColor = env.sample(envSampler, R, level(lod)).rgb;
        } else {
            float skyBlend = clamp(R.y * 2.0, 0.0, 1.0);
            envColor = mix(light.horizon_color, light.sky_color, skyBlend);
        }

        float iblWeight = mix(0.15, 1.0, metallic) * (1.0 - roughness * 0.6);
        // Wetness IBL boost; the SSR setting amplifies it further so the
        // standard wetness lobe doubles as the SSR-equivalent gain on
        // Metal (matches mesh3d.frag.glsl line 1415).
        iblWeight *= (1.0 + wetnessApplied * (1.5 + light.ssr_intensity * 2.0));
        color += envColor * F_ibl * iblWeight;
    }

    // ---- Clearcoat lobe (carpaint, dielectric F0 ≈ 0.04) ----
    if (light.clearcoat > 0.0) {
        float ccRough = clamp(light.clearcoat_roughness, 0.02, 1.0);
        float ccShininess = exp2(10.0 * (1.0 - ccRough) + 1.0);
        float3 ccF0 = float3(0.04);

        // Direct sun specular
        float3 ccL = normalize(-light.dir_light_direction);
        float3 ccH = normalize(V + ccL);
        float ccNdotL = max(dot(N, ccL), 0.0);
        if (ccNdotL > 0.0) {
            float ccNdotH = max(dot(N, ccH), 0.0);
            float ccSpec = pow(ccNdotH, ccShininess) * (ccShininess + 2.0) / 8.0;
            float3 ccFres = fresnelSchlick(max(dot(ccH, V), 0.0), ccF0);
            color += ccFres * light.dir_light_color * light.dir_light_intensity
                   * ccSpec * ccNdotL * light.clearcoat;
        }

        // Clearcoat IBL — sharp reflection (low LOD) since the lobe is
        // independently rough from the base material.
        if (light.use_environment_map == 1) {
            float3 ccR = reflect(-V, N);
            float ccNdotV = max(dot(N, V), 0.0);
            float3 ccFres = fresnelSchlick(ccNdotV, ccF0);

            float3 ccEnv;
            if (light.has_environment_map == 1) {
                float ccLod = ccRough * light.environment_mip_max;
                ccEnv = env.sample(envSampler, ccR, level(ccLod)).rgb;
            } else {
                float ccSkyBlend = clamp(ccR.y * 2.0, 0.0, 1.0);
                ccEnv = mix(light.horizon_color, light.sky_color, ccSkyBlend);
            }
            color += ccEnv * ccFres * light.clearcoat * (1.0 - ccRough * 0.5);
        }
    }

    color += light.emission;

    float fogDist   = length(in.world_pos - light.camera_pos);
    float fogFactor = clamp((fogDist - light.fog_near) / (light.fog_far - light.fog_near), 0.0, 1.0);
    fogFactor = 1.0 - exp(-fogFactor * fogFactor * 3.0);
    color = mix(color, light.fog_color, fogFactor);

    color += volumetricScatterMetal(in.world_pos, light);

    return float4(finalizeColor(color, light, in.position.xy), alpha);
}
