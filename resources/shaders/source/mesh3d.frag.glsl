#version 150 core
in vec3 v_normal;
in vec3 v_worldPos;
in vec2 v_uv;
in vec3 v_localPos;
in vec3 v_localNormal;
in vec3 v_objectScale;

uniform vec3 u_ambient_color;
uniform float u_ambient_intensity;

struct DirLight {
    vec3 direction;
    vec3 color;
    float intensity;
};
uniform DirLight u_dir_lights[16];
uniform int u_dir_light_count;

// Legacy single-light aliases (used by shadow map and some proc modes)
#define u_dir_light_direction u_dir_lights[0].direction
#define u_dir_light_color u_dir_lights[0].color
#define u_dir_light_intensity u_dir_lights[0].intensity

struct PointLight {
    vec3 position;
    vec3 color;
    float intensity;
    float radius;
};
uniform PointLight u_point_lights[8];
uniform int u_point_light_count;

struct SpotLight {
    vec3 position;
    vec3 direction;
    vec3 color;
    float intensity;
    float range;
    float angle;      // cone half-angle (radians)
    float penumbra;   // soft-edge fraction 0..1
};
uniform SpotLight u_spot_lights[8];
uniform int u_spot_light_count;

uniform vec3 u_albedo;
uniform vec3 u_emission;
uniform float u_roughness;
uniform float u_metallic;
uniform float u_alpha;
uniform float u_clearcoat;
uniform float u_clearcoat_roughness;
uniform float u_flakes;
uniform float u_normal_intensity;
uniform int   u_use_environment_map;

// Procedural normal-map pattern: 0 = none, 1..9 = pattern code
// (see PHPolygon\Rendering\NormalPattern). Tangent space is derived
// per-fragment via dFdx/dFdy on world position + UV - meshes do not
// need to ship a tangent buffer.
uniform int   u_normal_pattern;
uniform float u_normal_scale;

// Procedural surface-wear pattern (see PHPolygon\Rendering\SurfacePattern).
// 0 = none, 1..4 = pattern code. Modulates albedo / roughness / metallic
// per-fragment so a material can read as worn/rusted/brushed without
// shipping ORM texture maps.
uniform int   u_surface_pattern;
uniform float u_surface_scale;
uniform float u_surface_intensity;

// Volumetric fog toggle (0 = off, 1 = on). When on, the shader runs a
// short ray-march from camera to fragment, accumulates atmospheric
// scatter weighted by view-vs-sun phase function, and blends the
// result into the final colour. Independent from the linear distance
// fog set by SetFog - that one stays unconditional.
uniform int u_volumetric_fog;

// Wetness (0 = dry, 1 = soaked). Forward-renderer surrogate for SSR:
// wet surfaces read as smoother (lower roughness), slightly darker, and
// receive a stronger cubemap-IBL contribution. Real ray-marched SSR
// would require a G-buffer pre-pass which the forward pipeline doesn't
// own yet.
uniform float u_wetness;

// Screen-space-reflections intensity scaler bound from
// PHPolygon\Rendering\Quality\ScreenSpaceReflections::intensity().
// 0 = pipeline disabled (only the bare wetness lobe runs); 1 = full
// SSR-equivalent IBL gain. The current implementation amplifies the
// wetness reflection - when the depth-buffer ray-marcher pass lands
// it will replace this scaler at the same uniform name, no caller
// changes required.
uniform float u_ssr_intensity;

// Screen-space AO strength (0 = off, 1 = full curvature darkening).
// The current implementation is a per-fragment curvature approximation
// derived from screen-space derivatives of the surface normal, gated by
// this single uniform so a future depth-buffer SSAO pass can replace
// the in-shader path without touching the renderer wiring.
uniform float u_ao_strength;

// Fieldtracing (SDF global illumination) — see PHPOLYGON_FIELDTRACING.md §7.
// Keep in sync with the vio + metal mesh-shader copies. mode: 0=Off 1=ProbesOnly
// 2=SdfOcclusion 3=SdfBounce (float; int-in-UBO unreliable across SPIRV-Cross).
// Default 0 => strict no-op.
uniform float u_ft_mode;
uniform float u_ft_intensity;
uniform float u_ft_ao;
// Baked COLOURED irradiance probe field (RGB SH-L1, with 1-bounce). Mirror of
// the vio copy: one 3D texture per channel, RGBA = coeffs (c0,c1,c2,c3); per
// channel E = c0+c1*n.x+c2*n.y+c3*n.z. u_probe_enabled 0 => hemisphere fallback.
uniform float u_probe_enabled;
uniform sampler3D u_probe_field_r;
uniform sampler3D u_probe_field_g;
uniform sampler3D u_probe_field_b;
uniform vec3  u_probe_origin;
uniform vec3  u_probe_size;
uniform float u_probe_range;
// SDF trace-pass result (R = AO, G = soft sun shadow). The screen-space SDF
// pass is D3D-only (like the G-buffer SSAO), so on OpenGL u_sdf_ao_enabled is
// always 0 and these are neutral — declared for three-copy parity with the vio
// shader. Mirrors the vio copy.
uniform float     u_sdf_ao_enabled;   // float to mirror the vio copy (SPIRV-Cross int-in-UBO)
uniform sampler2D u_sdf_ao_map;

// Color-grading parameters (Lift/Gamma/Gain + saturation). Bound from
// PHPolygon\Rendering\Quality\ColorGradingPreset::params(). Neutral
// preset emits identity values so the math collapses to a no-op.
uniform vec3  u_grade_lift;
uniform vec3  u_grade_gamma;
uniform vec3  u_grade_gain;
uniform float u_grade_saturation;

// Radial vignette intensity (0 = none, 1 = strong). The vignette is
// evaluated in screen-space using gl_FragCoord, so the renderer must
// also bind u_viewport_size for the falloff to be aspect-correct.
uniform float u_vignette_intensity;
uniform vec2  u_viewport_size;

uniform vec3 u_fog_color;
uniform float u_fog_near;
uniform float u_fog_far;

uniform vec3 u_camera_pos;
uniform float u_time;
// Procedural material modes: 0=standard, 1=sand terrain, 2=water, 3=rock, 4=palm trunk, 5=palm leaf
uniform int u_proc_mode;

// Environment reflection
uniform samplerCube u_environment_map;
uniform int u_has_environment_map;
uniform vec3 u_sky_color;
uniform vec3 u_horizon_color;

// Shadow mapping
uniform sampler2DShadow u_shadow_map;
uniform mat4 u_light_space_matrix;
uniform int u_has_shadow_map;

// Cascade Shadow Maps. Cascade 0 may alias u_shadow_map (legacy slot)
// when the renderer wires both - the cascade-aware sampling path
// always reads u_csm_map_*. u_csm_far_i holds the per-cascade ortho
// half-extent; the shader picks the smallest cascade whose box still
// contains the fragment.
uniform sampler2DShadow u_csm_map_0;
uniform sampler2DShadow u_csm_map_1;
uniform sampler2DShadow u_csm_map_2;
uniform mat4 u_csm_matrix_0;
uniform mat4 u_csm_matrix_1;
uniform mat4 u_csm_matrix_2;
uniform float u_csm_far_0;
uniform float u_csm_far_1;
uniform float u_csm_far_2;
uniform int u_csm_count;

// Cloud shadow map (opacity-based, separate from depth shadow)
uniform sampler2D u_cloud_shadow_map;
uniform int u_has_cloud_shadow;

// Moon phase for procedural moon shader
uniform float u_moon_phase;

// Seasonal tint applied to terrain and vegetation
uniform vec3 u_season_tint; // multiplied with base colors (1,1,1 = no change)

out vec4 frag_color;

// ================================================================
//  Noise functions
// ================================================================

float hash21(vec2 p) {
    p = fract(p * vec2(127.1, 311.7));
    p += dot(p, p + 19.19);
    return fract(p.x * p.y);
}

float hash31(vec3 p) {
    p = fract(p * vec3(443.897, 441.423, 437.195));
    p += dot(p, p.yzx + 19.19);
    return fract((p.x + p.y) * p.z);
}

float noise(vec2 p) {
    vec2 i = floor(p);
    vec2 f = fract(p);
    f = f * f * (3.0 - 2.0 * f);
    float a = hash21(i);
    float b = hash21(i + vec2(1.0, 0.0));
    float c = hash21(i + vec2(0.0, 1.0));
    float d = hash21(i + vec2(1.0, 1.0));
    return mix(mix(a, b, f.x), mix(c, d, f.x), f.y);
}

// Fractal Brownian Motion — layered noise
float fbm(vec2 p, int octaves) {
    float value = 0.0;
    float amp = 0.5;
    float freq = 1.0;
    for (int i = 0; i < octaves; i++) {
        value += amp * noise(p * freq);
        freq *= 2.0;
        amp *= 0.5;
    }
    return value;
}

// ================================================================
//  Shadow calculation
// ================================================================

// Sample one cascade with PCF 3x3. Returns 1.0 (lit) when the fragment
// falls outside the cascade's projection box, so callers can treat the
// out-of-range case as "next cascade owns this pixel".
float sampleCascade(sampler2DShadow shadowMap, mat4 lightSpace, vec3 worldPos) {
    vec4 lightSpacePos = lightSpace * vec4(worldPos, 1.0);
    vec3 projCoords = lightSpacePos.xyz / lightSpacePos.w;
    projCoords = projCoords * 0.5 + 0.5;
    if (projCoords.x < 0.0 || projCoords.x > 1.0 ||
        projCoords.y < 0.0 || projCoords.y > 1.0 ||
        projCoords.z > 1.0) return 1.0;
    float texelSize = 1.0 / 2048.0;
    float bias = 0.002;
    float refDepth = projCoords.z - bias;
    float sum = 0.0;
    for (int x = -1; x <= 1; x++) {
        for (int y = -1; y <= 1; y++) {
            vec2 offset = vec2(float(x), float(y)) * texelSize;
            sum += texture(shadowMap, vec3(projCoords.xy + offset, refDepth));
        }
    }
    return sum / 9.0;
}

float calcShadow(vec3 worldPos) {
    if (u_has_shadow_map == 0 && u_has_cloud_shadow == 0) return 1.0;

    vec4 lightSpacePos = u_light_space_matrix * vec4(worldPos, 1.0);
    vec3 projCoords = lightSpacePos.xyz / lightSpacePos.w;
    projCoords = projCoords * 0.5 + 0.5;

    // Outside shadow map → no shadow
    if (projCoords.x < 0.0 || projCoords.x > 1.0 ||
        projCoords.y < 0.0 || projCoords.y > 1.0 ||
        projCoords.z > 1.0) return 1.0;

    float shadow = 1.0;

    // Geometry shadow with cascade-shadow-map dispatch. Pick the
    // smallest cascade that still contains the fragment, based on the
    // distance to the camera (a coarse but cheap proxy for view-space
    // depth that matches the per-cascade ortho extents the renderer
    // built in OpenGLRenderer3D::renderShadowMap()).
    if (u_has_shadow_map == 1) {
        float dist = length(worldPos - u_camera_pos);
        float geomShadow;
        if (u_csm_count >= 2 && dist > u_csm_far_0) {
            if (u_csm_count >= 3 && dist > u_csm_far_1) {
                geomShadow = sampleCascade(u_csm_map_2, u_csm_matrix_2, worldPos);
            } else {
                geomShadow = sampleCascade(u_csm_map_1, u_csm_matrix_1, worldPos);
            }
        } else {
            geomShadow = sampleCascade(u_csm_map_0, u_csm_matrix_0, worldPos);
        }
        shadow *= geomShadow;
    }

    // Cloud shadow (opacity-based, wide soft blur for realistic cloud penumbra)
    if (u_has_cloud_shadow == 1) {
        float cloudShadow = 0.0;
        float cloudTexelSize = 1.0 / 1024.0;

        // Large 5×5 Gaussian-weighted blur for soft cloud shadow edges
        // Weights: center=4, adjacent=2, diagonal=1 (total 48)
        float weights[5] = float[](1.0, 2.0, 4.0, 2.0, 1.0);
        float totalWeight = 0.0;
        for (int x = -2; x <= 2; x++) {
            for (int y = -2; y <= 2; y++) {
                float w = weights[x + 2] * weights[y + 2];
                vec2 offset = vec2(float(x), float(y)) * cloudTexelSize * 3.0; // 3× spread for wider blur
                float cloudAlpha = texture(u_cloud_shadow_map, projCoords.xy + offset).r;
                cloudShadow += cloudAlpha * w;
                totalWeight += w;
            }
        }
        cloudShadow /= totalWeight;

        // Cloud opacity attenuates sunlight (0 = fully blocked, 1 = no cloud)
        shadow *= (1.0 - cloudShadow * 0.7); // Clouds don't block 100% — some light scatters through
    }

    return shadow;
}

// ================================================================
//  PBR helpers
// ================================================================

vec3 fresnelSchlick(float cosTheta, vec3 F0) {
    return F0 + (1.0 - F0) * pow(clamp(1.0 - cosTheta, 0.0, 1.0), 5.0);
}

// One-shot finalize: color grade -> tone-map -> gamma -> vignette.
// Used by every shader exit path so the order stays consistent and the
// vignette is applied to the display-encoded value (where the radial
// darkening is most perceptually linear).
vec3 finalize(vec3 color);
// Forward declaration: finalize() calls toneMapACES() (defined further down).
// Without this prototype, strict GLSL drivers (Mesa/llvmpipe) reject the
// call-before-definition — lenient GPU drivers accept it, which is why this
// only surfaces on the software rasteriser / older Mesa stacks.
vec3 toneMapACES(vec3 x);

// Volumetric fog: short ray-march from camera to fragment. Density
// drops with height (atmospheric profile) and the in-scatter is
// weighted by a Henyey-Greenstein-style phase function aligned with
// the primary directional light - this is what produces the
// godray look when looking towards the sun.
//
// 8 samples is the sweet spot: enough to look continuous, cheap
// enough to leave on for default-quality scenes. Skipped entirely
// when u_volumetric_fog == 0.
vec3 volumetricScatter(vec3 worldPos) {
    if (u_volumetric_fog == 0 || u_dir_light_count == 0) {
        return vec3(0.0);
    }
    vec3 rayStart = u_camera_pos;
    vec3 rayEnd   = worldPos;
    vec3 rayDir   = rayEnd - rayStart;
    float rayLen  = length(rayDir);
    if (rayLen < 0.01) return vec3(0.0);
    rayDir /= rayLen;

    // Cap the marched length so very distant fragments don't dominate;
    // beyond fog_far the contribution is already saturated anyway.
    float marchLen = min(rayLen, u_fog_far);
    const int STEPS = 8;
    float step = marchLen / float(STEPS);

    vec3 sunDir = normalize(-u_dir_lights[0].direction);
    float cosTheta = dot(rayDir, sunDir);
    // Henyey-Greenstein-ish forward-scatter: peaks when looking at sun.
    float phase = 0.5 + pow(max(cosTheta, 0.0), 6.0) * 4.0;

    vec3 scatter = vec3(0.0);
    float transmittance = 1.0;
    for (int i = 0; i < STEPS; i++) {
        vec3 p = rayStart + rayDir * (step * (float(i) + 0.5));
        // Atmospheric density: thicker near the ground, exponential
        // fall-off with altitude. Tuned for a "ground-fog" look.
        float density = exp(-max(p.y, 0.0) * 0.08) * 0.06;
        // Sun radiance at this sample (no shadow march - cheap path).
        vec3 inscatter = u_dir_lights[0].color * u_dir_lights[0].intensity * phase * density;
        scatter += inscatter * transmittance * step;
        transmittance *= exp(-density * step);
    }
    return scatter;
}

// Color grading: Lift / Gamma / Gain + saturation. Applied in linear
// space *before* the final tone-map / gamma so the LGG curve operates
// on the radiance values, not the display-encoded ones. Neutral
// preset (lift=0, gamma=1, gain=1, sat=1) reduces to a no-op.
vec3 applyColorGrading(vec3 color) {
    color = color + u_grade_lift;
    color = pow(max(color, vec3(0.0)), vec3(1.0) / u_grade_gamma);
    color = color * u_grade_gain;
    float luma = dot(color, vec3(0.2126, 0.7152, 0.0722));
    return mix(vec3(luma), color, u_grade_saturation);
}

// Radial vignette in screen-space. Strength of 0 collapses to a no-op.
vec3 applyVignette(vec3 color) {
    if (u_vignette_intensity <= 0.0 || u_viewport_size.x <= 0.0) {
        return color;
    }
    vec2 uv = gl_FragCoord.xy / u_viewport_size;
    vec2 d  = uv - 0.5;
    // Radial falloff: smoothstep keeps the centre flat and rolls off at the edges.
    float r = length(d);
    float v = smoothstep(0.45, 0.85, r);
    return color * (1.0 - v * u_vignette_intensity);
}

vec3 finalize(vec3 color) {
    color = applyColorGrading(max(color, vec3(0.0)));
    color = toneMapACES(color);
    color = pow(color, vec3(1.0 / 2.2));
    return applyVignette(color);
}

// PHPOLYGON:POSTCOLOR_HELPERS

// Curvature-based ambient occlusion approximation. Real screen-space AO
// requires a depth pre-pass and a multi-tap sample loop; until that
// pipeline lands the shader uses a cheap per-fragment surrogate that
// reads the spatial rate-of-change of the surface normal. Concave
// regions (corners, crevices, interior edges) have a higher curvature
// magnitude and therefore receive more darkening, which is the visual
// signature SSAO is shipped for.
float curvatureAO(vec3 N, float strength) {
    if (strength <= 0.0) return 1.0;
    vec3 ddxN = dFdx(N);
    vec3 ddyN = dFdy(N);
    float curvature = length(ddxN) + length(ddyN);
    // smoothstep gives a soft falloff and keeps perfectly-flat surfaces at 1.0.
    float occlusion = smoothstep(0.0, 0.4, curvature);
    return clamp(1.0 - occlusion * strength, 0.0, 1.0);
}

// ACES filmic tonemap (Narkowicz approximation, identical to the
// post-process tonemap pass used in VioRenderer3D's HDR pipeline).
// Bringing ACES into the mesh shader keeps the visual response film-like
// for all backends, including the OpenGL fallback that does not own a
// post-process chain.
vec3 toneMapACES(vec3 x) {
    const float a = 2.51;
    const float b = 0.03;
    const float c = 2.43;
    const float d = 0.59;
    const float e = 0.14;
    return clamp((x * (a * x + b)) / (x * (c * x + d) + e), 0.0, 1.0);
}

// GGX Normal Distribution Function
float distributionGGX(float NdotH, float rough) {
    float a = rough * rough;
    float a2 = a * a;
    float denom = NdotH * NdotH * (a2 - 1.0) + 1.0;
    return a2 / (3.14159 * denom * denom + 0.0001);
}

// PHPOLYGON:PROCMODE_HELPERS

// ================================================================
//  Procedural Carpaint (proc_mode = 10)
// ================================================================
//
//  Goal: a metallic, glossy automotive surface with three contributions
//  on top of the base PBR pass:
//
//  1. Microfacet flakes:       per-grain hash noise rotates the surface
//                              normal slightly so individual flakes catch
//                              the sun specular at varying angles.
//  2. Clearcoat lobe:          a second specular term with low roughness
//                              layered on top of the base lobe (Tesla /
//                              GTA hero-car look).
//  3. Environment reflection:  cubemap sample blended in by metallic.
//
//  All three are gated by their own material uniform, so the carpaint
//  mode degrades smoothly to the standard PBR pass when set to 0.

vec3 perturbNormalFlakes(vec3 N, float intensity) {
    if (intensity <= 0.0) return N;
    vec3 flakePos = floor(v_localPos * 220.0);
    float h1 = hash31(flakePos);
    float h2 = hash31(flakePos + vec3(13.0, 7.0, 5.0));
    float h3 = hash31(flakePos + vec3(31.0, 17.0, 11.0));
    vec3 jitter = vec3(h1 - 0.5, h2 - 0.5, h3 - 0.5);
    return normalize(N + jitter * 0.18 * intensity);
}

vec3 sampleEnvironment(vec3 R, float roughness) {
    // textureLod with roughness mapped to a mip level approximates the
    // pre-filtered IBL environment used by industry PBR pipelines. The
    // current cubemaps are 256² without baked mips, so we additionally
    // blur towards a flat horizon mix as roughness grows.
    if (u_has_environment_map == 0) {
        float skyBlend = clamp(R.y * 2.0, 0.0, 1.0);
        return mix(u_horizon_color, u_sky_color, skyBlend);
    }
    float lod = roughness * 6.0;
    vec3 envColor = textureLod(u_environment_map, R, lod).rgb;
    // Soft blend with sky/horizon at high roughness so the cubemap mip
    // chain doesn't have to be physically pre-filtered to look plausible.
    float skyBlend = clamp(R.y * 2.0, 0.0, 1.0);
    vec3 fallback = mix(u_horizon_color, u_sky_color, skyBlend);
    return mix(envColor, fallback, smoothstep(0.4, 1.0, roughness));
}

// ================================================================
//  Procedural Normal Maps
// ================================================================
//
//  Pattern functions return a tangent-space normal in the [-1, 1]^3
//  range with z dominant (perturbations relative to the surface). The
//  dispatcher dispatchProceduralNormal() picks the right pattern by
//  pattern code, evaluates it on a scaled UV, and the caller transforms
//  the result into world space using a TBN derived from screen-space
//  derivatives (perturbNormalProcedural()).
//
//  All patterns use only the noise / hash helpers already declared above
//  so no procedurally-generated mesh has to ship a tangent buffer.

vec3 np_bricks(vec2 uv) {
    // Brick layout: 2:1 aspect, alternate rows offset by half a brick.
    vec2 cell = vec2(0.5, 1.0);
    float rowIndex = floor(uv.y / cell.y);
    float xOffset = mod(rowIndex, 2.0) * 0.5 * cell.x;
    vec2 local = vec2(fract((uv.x + xOffset) / cell.x),
                      fract(uv.y / cell.y));
    // Mortar grooves at the cell borders, smoothstep gives a chamfered edge.
    float mortarX = 1.0 - (smoothstep(0.0, 0.06, local.x) *
                           smoothstep(1.0, 0.94, local.x));
    float mortarY = 1.0 - (smoothstep(0.0, 0.06, local.y) *
                           smoothstep(1.0, 0.94, local.y));
    // Combined groove drives an inward slope around the brick perimeter.
    float groove = max(mortarX, mortarY);
    // Tangent-space slope: positive z = surface, dent towards (0,0,1) at edges.
    vec2 slope = vec2(mortarX, mortarY) *
                 vec2(local.x < 0.5 ? 1.0 : -1.0,
                      local.y < 0.5 ? 1.0 : -1.0);
    return normalize(vec3(slope * 0.6, 1.0 - groove * 0.5));
}

vec3 np_bumps(vec2 uv) {
    // Smooth bumps via two-tap noise gradient (cheap finite difference).
    float e = 0.05;
    float h  = noise(uv * 8.0);
    float hx = noise(uv * 8.0 + vec2(e, 0.0));
    float hy = noise(uv * 8.0 + vec2(0.0, e));
    vec2 grad = vec2(hx - h, hy - h) / e;
    return normalize(vec3(-grad * 0.4, 1.0));
}

vec3 np_orange_peel(vec2 uv) {
    // Fine high-frequency hash for the speckled "skin" feel.
    vec2 p = uv * 60.0;
    float h  = hash21(floor(p));
    float hx = hash21(floor(p) + vec2(1.0, 0.0));
    float hy = hash21(floor(p) + vec2(0.0, 1.0));
    return normalize(vec3((h - hx) * 0.6, (h - hy) * 0.6, 1.0));
}

vec3 np_hammered(vec2 uv) {
    // Concentric divots on a jittered grid: each cell carries one circular
    // dent, the inside slopes towards the centre.
    vec2 grid = uv * 6.0;
    vec2 cell = floor(grid);
    vec2 local = fract(grid) - 0.5;
    vec2 jitter = vec2(hash21(cell), hash21(cell + 17.0)) - 0.5;
    vec2 centred = local - jitter * 0.4;
    float r = length(centred);
    float rim = smoothstep(0.45, 0.20, r);
    vec2 slope = -centred * rim * 1.4;
    return normalize(vec3(slope, 1.0));
}

vec3 np_hexagons(vec2 uv) {
    // Hexagonal tiling via skewed coordinates. Each tile slopes towards
    // its centre to read as a tiled paving.
    vec2 p = uv * 5.0;
    vec2 a = vec2(p.x + p.y * 0.5, p.y * 0.866);
    vec2 ai = floor(a);
    vec2 af = fract(a) - 0.5;
    vec2 slope = -af * 1.2;
    float edge = smoothstep(0.45, 0.50, max(abs(af.x), abs(af.y)));
    return normalize(vec3(slope * (1.0 - edge), 1.0 - edge * 0.4));
}

vec3 np_wood_grain(vec2 uv) {
    // Anisotropic pattern: long grain along U, short ring noise along V.
    float ring = sin(uv.y * 80.0 + noise(uv * vec2(20.0, 4.0)) * 6.0);
    float grad = cos(uv.y * 80.0 + noise(uv * vec2(20.0, 4.0)) * 6.0) * 80.0;
    float slopeY = grad * 0.005;
    return normalize(vec3(0.0, slopeY, 1.0));
}

vec3 np_scratches(vec2 uv) {
    // Diagonal directional lines, hashed per row for randomness.
    float rotated = uv.x * 0.97 + uv.y * 0.24;
    float across  = -uv.x * 0.24 + uv.y * 0.97;
    float lane = floor(across * 80.0);
    float laneJitter = hash21(vec2(lane, 0.0));
    float scratch = sin((rotated + laneJitter * 6.28) * 30.0);
    float mask = step(0.6, hash21(vec2(lane, 13.0)));
    return normalize(vec3(scratch * mask * 0.5, 0.0, 1.0));
}

vec3 np_cracked(vec2 uv) {
    // Voronoi-style cracks: pick the closest of 9 neighbour cell points
    // and drop a groove at the midpoint between the two closest.
    vec2 p = uv * 8.0;
    vec2 ip = floor(p);
    vec2 fp = fract(p);
    float d1 = 8.0;
    float d2 = 8.0;
    for (int x = -1; x <= 1; x++) {
        for (int y = -1; y <= 1; y++) {
            vec2 g = vec2(float(x), float(y));
            vec2 jitter = vec2(hash21(ip + g),
                               hash21(ip + g + 51.0));
            float d = length(g + jitter - fp);
            if (d < d1) { d2 = d1; d1 = d; }
            else if (d < d2) { d2 = d; }
        }
    }
    float crack = smoothstep(0.04, 0.0, d2 - d1);
    return normalize(vec3(0.0, 0.0, 1.0) +
                     vec3((fp.x - 0.5) * crack, (fp.y - 0.5) * crack, 0.0));
}

vec3 np_noise(vec2 uv) {
    // FBM gradient: a cheap default "rough surface" pattern.
    float e = 0.04;
    float h  = fbm(uv * 6.0, 3);
    float hx = fbm(uv * 6.0 + vec2(e, 0.0), 3);
    float hy = fbm(uv * 6.0 + vec2(0.0, e), 3);
    vec2 grad = vec2(hx - h, hy - h) / e;
    return normalize(vec3(-grad * 0.5, 1.0));
}

// Skin micro-relief: medium-scale pore noise + slow wrinkle FBM (mirrors
// the Vio shader copy verbatim).
vec3 np_skin(vec2 uv) {
    float e = 0.02;
    float h  = noise(uv * 14.0) * 0.55 + fbm(uv * 4.0, 3) * 0.45;
    float hx = noise((uv + vec2(e, 0.0)) * 14.0) * 0.55
             + fbm((uv + vec2(e, 0.0)) * 4.0, 3) * 0.45;
    float hy = noise((uv + vec2(0.0, e)) * 14.0) * 0.55
             + fbm((uv + vec2(0.0, e)) * 4.0, 3) * 0.45;
    vec2 grad = vec2(hx - h, hy - h) / e;
    return normalize(vec3(-grad * 0.06, 1.0));
}

vec3 dispatchProceduralNormal(int code, vec2 uv) {
    if (code == 1)  return np_bricks(uv);
    if (code == 2)  return np_bumps(uv);
    if (code == 3)  return np_orange_peel(uv);
    if (code == 4)  return np_hammered(uv);
    if (code == 5)  return np_hexagons(uv);
    if (code == 6)  return np_wood_grain(uv);
    if (code == 7)  return np_scratches(uv);
    if (code == 8)  return np_cracked(uv);
    if (code == 9)  return np_noise(uv);
    if (code == 10) return np_skin(uv);
    return vec3(0.0, 0.0, 1.0);
}

// ================================================================
//  Procedural Surface-Wear Patterns
// ================================================================
//
//  Each pattern returns vec3(albedoTint, roughnessDelta, metallicDelta):
//    - albedoTint    : 0.5 = neutral, < 0.5 = darker, > 0.5 = lighter
//                      (multiplied by 2.0 → identity at 0.5)
//    - roughnessDelta: -1..+1, added to base roughness
//    - metallicDelta : -1..+1, added to base metallic
//
//  Patterns are evaluated against the surface UV scaled by
//  u_surface_scale, with the final delta multiplied by
//  u_surface_intensity so a single pattern can fade in/out without
//  re-registering the material.

vec3 sp_worn_paint(vec2 uv) {
    // Larger fbm controls where paint has chipped off; the cell hash
    // breaks up the boundary so the wear edges look organic.
    float wear = fbm(uv * 3.0, 3);
    float chip = step(0.55, wear);
    // Where chipped: darker, much rougher, more metallic (paint -> bare metal)
    float albedoT  = mix(0.50, 0.30, chip);          // 0.5 = neutral
    float roughD   = mix(0.0,  0.35,  chip);
    float metalD   = mix(0.0,  0.55,  chip);
    return vec3(albedoT, roughD, metalD);
}

vec3 sp_rust(vec2 uv) {
    // Rust accumulates in low-curvature areas; we approximate with a
    // multi-scale noise mask. Where rusted: warm tint, very rough, no metal.
    float spotty = fbm(uv * 5.0, 4);
    float rust   = smoothstep(0.45, 0.65, spotty);
    float albedoT = mix(0.50, 0.62, rust); // warmer
    float roughD  = mix(0.0,  0.45,  rust);
    float metalD  = mix(0.0, -0.50,  rust); // strip metallic
    return vec3(albedoT, roughD, metalD);
}

vec3 sp_brushed_metal(vec2 uv) {
    // Anisotropic strands along U; alternating roughness lanes for the
    // characteristic brushed-aluminium look.
    float lane = sin(uv.y * 600.0);
    float roughD = lane * 0.10;
    return vec3(0.50, roughD, 0.0);
}

vec3 sp_polished_rings(vec2 uv) {
    // Concentric rings (e.g. machined turntable / disc-brake); alternates
    // between near-mirror and matte bands.
    vec2 c = uv - 0.5;
    float r = length(c);
    float ring = sin(r * 80.0);
    float matte = smoothstep(0.0, 0.4, ring);
    float roughD = matte * 0.50 - 0.10;
    return vec3(0.50, roughD, 0.0);
}

// Skin freckles + blotchy pigmentation (mirrors the Vio shader copy
// verbatim). Two smoothstep gates so freckles fade in/out rather than
// tiling like an animal print.
vec3 sp_skin(vec2 uv) {
    float blotchy = fbm(uv * 1.5, 3);
    float fine    = fbm(uv * 5.0, 3);
    float freckle = smoothstep(0.65, 0.78, fine) * smoothstep(0.40, 0.60, blotchy);
    float albedoT = mix(0.50, 0.44, freckle);
    float roughD  = mix(0.0,  0.04, freckle);
    return vec3(albedoT, roughD, 0.0);
}

vec3 dispatchSurfacePattern(int code, vec2 uv) {
    if (code == 1) return sp_worn_paint(uv);
    if (code == 2) return sp_rust(uv);
    if (code == 3) return sp_brushed_metal(uv);
    if (code == 4) return sp_polished_rings(uv);
    if (code == 5) return sp_skin(uv);
    return vec3(0.5, 0.0, 0.0);
}

// Derive a tangent basis per-fragment from world-position + UV
// derivatives (Mikkelsen "Surface Gradient", 2010). This avoids having
// to ship pre-computed tangents on every procedurally-generated mesh.
vec3 perturbNormalProcedural(vec3 N, vec3 worldPos, vec2 uv,
                             int patternCode, float patternScale,
                             float intensity) {
    if (patternCode == 0 || intensity <= 0.0) return N;
    vec3 dpx = dFdx(worldPos);
    vec3 dpy = dFdy(worldPos);
    vec2 duvx = dFdx(uv);
    vec2 duvy = dFdy(uv);
    // Cogent's solve: T = (dp/dx * duv/dy.t - dp/dy * duv/dx.t) / det
    float det = duvx.x * duvy.y - duvy.x * duvx.y;
    if (abs(det) < 1e-8) return N;
    vec3 T = (dpx * duvy.y - dpy * duvx.y) / det;
    T = normalize(T - N * dot(N, T));
    vec3 B = normalize(cross(N, T));
    mat3 TBN = mat3(T, B, N);

    vec3 nMap = dispatchProceduralNormal(patternCode, uv * patternScale);
    // Lerp between geometric normal and pattern normal by intensity, then
    // re-normalise after TBN transform.
    nMap = mix(vec3(0.0, 0.0, 1.0), nMap, clamp(intensity, 0.0, 4.0));
    return normalize(TBN * nMap);
}

// ================================================================
//  Main
// ================================================================

void main() {
    vec3 N = normalize(v_normal);
    if (!gl_FrontFacing) N = -N;

    vec3 V = normalize(u_camera_pos - v_worldPos);
    vec3 L = normalize(-u_dir_light_direction);
    vec3 H = normalize(V + L);

    float roughness = clamp(u_roughness, 0.04, 1.0);
    // Local metallic: surface patterns may modulate this per-fragment.
    float metallic = u_metallic;
    float alpha = u_alpha;
    vec3 albedo;

    // ---- Material selection ----
/* PHPOLYGON:PROCMODE_BRANCHES */ {
        // Standard material
        float nse = noise(v_worldPos.xz * 0.4);
        float noiseMask = smoothstep(0.3, 0.9, roughness);
        albedo = u_albedo * (1.0 + (nse - 0.5) * 0.12 * noiseMask);
    }

    // Procedural normal-map pattern. Skipped for proc_modes 0 (standard)
    // when no pattern is selected; safe to apply on top of the carpaint
    // flake jitter as well because both perturbations stay tangent-bound.
    // Self-shading procedural materials (water, cloud, moon) early-return
    // above so this line never runs for them.
    N = perturbNormalProcedural(N, v_worldPos, v_uv,
                                u_normal_pattern, u_normal_scale,
                                u_normal_intensity);

    // Procedural surface-wear pattern. Returns vec3(albedoTint, rough,
    // metal) deltas applied with intensity to the base PBR values.
    if (u_surface_pattern > 0 && u_surface_intensity > 0.0) {
        vec3 wear = dispatchSurfacePattern(u_surface_pattern, v_uv * u_surface_scale);
        float t = clamp(u_surface_intensity, 0.0, 4.0);
        // Albedo tint: 0.5 = neutral, mapped to *2.0 around centre.
        vec3 tint = mix(vec3(1.0), vec3(wear.x * 2.0), t);
        albedo *= tint;
        roughness = clamp(roughness + wear.y * t, 0.04, 1.0);
        metallic  = clamp(metallic  + wear.z * t, 0.0,  1.0);
    }

    // Wetness ("SSR surrogate"): smoother + darker + stronger IBL on
    // upward-facing fragments. Down-facing surfaces stay dry so the
    // effect doesn't affect ceilings / undersides.
    float wetnessApplied = 0.0;
    if (u_wetness > 0.0) {
        float upMask = clamp(dot(N, vec3(0.0, 1.0, 0.0)) * 1.4 - 0.2, 0.0, 1.0);
        wetnessApplied = u_wetness * upMask;
        roughness = mix(roughness, max(roughness * 0.25, 0.04), wetnessApplied);
        albedo    = mix(albedo,    albedo * 0.7,                 wetnessApplied);
    }

    // ---- PBR Lighting (sand + standard materials) ----
    float shininess = exp2(10.0 * (1.0 - roughness) + 1.0);

    vec3 F0 = mix(vec3(0.04), albedo, metallic);

    // Shadow factor (from primary light — index 0)
    float shadow = calcShadow(v_worldPos);

    // Ambient shadow strength scales with light intensity.
    // Strong shadows in bright sunlight, subtle in moonlight.
    float primaryIntensity = u_dir_light_count > 0 ? u_dir_lights[0].intensity : 0.0;
    float shadowStrength = clamp(primaryIntensity / 1.0, 0.0, 1.0); // 0 at night, 1 at noon
    float ambientShadow = mix(1.0, mix(0.5, 1.0, shadow), shadowStrength);
    float ao = curvatureAO(N, u_ao_strength);

    // Fieldtracing SDF trace-pass result (D3D only; neutral on GL). Mirror of vio.
    float ftSunShadow = 1.0;
    if (u_sdf_ao_enabled > 0.5) {
        vec2 ftUV = gl_FragCoord.xy / u_viewport_size;
        vec2 ftAoSh = texture(u_sdf_ao_map, ftUV).rg;
        ao = min(ao, clamp(ftAoSh.r, 0.0, 1.0));
        ftSunShadow = clamp(ftAoSh.g, 0.0, 1.0);
    }

    vec3 color = u_ambient_color * u_ambient_intensity * albedo * (1.0 - metallic * 0.9) * ambientShadow * ao;

    // Fieldtracing contribution (before finalize()): hemisphere "probe" ambient
    // layered over the flat ambient as a cheap diffuse-GI surrogate (ProbesOnly
    // tier), modulated by AO. mode 0 (Off) is a strict no-op. SdfOcclusion /
    // SdfBounce render as ProbesOnly here; their SDF-traced terms come from the
    // separate trace pass. Mirror of the vio copy.
    if (u_ft_mode >= 0.5) {
        vec3 ftProbe;
        if (u_probe_enabled > 0.5) {
            // Coloured RGB SH-L1 (mirror of the vio copy); colour is baked in.
            vec3 puvw = clamp((v_worldPos - u_probe_origin) / u_probe_size, 0.0, 1.0);
            vec4 nd = vec4(1.0, N.x, N.y, N.z);
            vec4 cr = (texture(u_probe_field_r, puvw) * 2.0 - 1.0) * u_probe_range;
            vec4 cg = (texture(u_probe_field_g, puvw) * 2.0 - 1.0) * u_probe_range;
            vec4 cb = (texture(u_probe_field_b, puvw) * 2.0 - 1.0) * u_probe_range;
            vec3 E = max(vec3(dot(cr, nd), dot(cg, nd), dot(cb, nd)), vec3(0.0));
            float bounceGain = u_ft_mode >= 2.5 ? 1.0 : 0.7;
            ftProbe = E * (0.45 * bounceGain) * u_ambient_intensity;
        } else {
            float ftHemi   = N.y * 0.5 + 0.5;
            vec3  ftSky    = u_ambient_color * 1.2 + vec3(0.015, 0.03, 0.06);
            vec3  ftGround = u_ambient_color * 0.6;
            ftProbe = mix(ftGround, ftSky, ftHemi) * u_ambient_intensity;
        }
        color += albedo * (1.0 - metallic * 0.9) * ftProbe * ao * (0.35 * u_ft_intensity);
    }

    // All directional lights (with Half-Lambert wrap for terrain/sand)
    // Clamp the loop bound to the array size: u_*_light_count is a GPU
    // uniform and a stale/garbage value would otherwise run the loop for
    // millions of iterations (and index out of bounds) → GPU TDR / device hang.
    int dirCount = min(u_dir_light_count, 4);
    for (int dl = 0; dl < dirCount; dl++) {
        vec3 dL = normalize(-u_dir_lights[dl].direction);
        vec3 dH = normalize(V + dL);
        float rawNdotL = dot(N, dL);
        float dNdotL = max(rawNdotL, 0.0);

        // Half-Lambert: wraps lighting around surfaces so horizontal terrain
        // still receives light at low sun angles (sunrise/sunset golden glow).
        // Standard: max(NdotL, 0) gives 0 at 90°.
        // Half-Lambert: (NdotL * 0.5 + 0.5)² gives 0.25 at 90° — much softer.
        float halfLambert = rawNdotL * 0.5 + 0.5;
        halfLambert *= halfLambert;
        // Blend: use half-Lambert for diffuse, standard NdotL for specular
        float diffuseNdotL = mix(dNdotL, halfLambert, 0.4); // 40% wrap

        // Shadow only applies to primary light (index 0)
        float dShadow = (dl == 0) ? shadow * ftSunShadow : 1.0;

        if (diffuseNdotL > 0.0) {
            color += albedo * u_dir_lights[dl].color * u_dir_lights[dl].intensity * diffuseNdotL * dShadow * (1.0 - metallic);
        }
        if (dNdotL > 0.0) {
            float dNdotH = max(dot(N, dH), 0.0);
            float spec = pow(dNdotH, shininess) * (shininess + 2.0) / 8.0;
            vec3 F = fresnelSchlick(max(dot(dH, V), 0.0), F0);
            color += F * u_dir_lights[dl].color * u_dir_lights[dl].intensity * spec * dNdotL * dShadow;
        }
    }

    // Point lights
    int pointCount = min(u_point_light_count, 4);
    for (int i = 0; i < pointCount; i++) {
        vec3 Lp = u_point_lights[i].position - v_worldPos;
        float dist = length(Lp);
        Lp = normalize(Lp);
        vec3 Hp = normalize(V + Lp);

        float radius = max(u_point_lights[i].radius, 0.001);
        float atten = clamp(1.0 - (dist * dist) / (radius * radius), 0.0, 1.0);
        atten *= atten;

        float NdotPL = max(dot(N, Lp), 0.0);
        if (NdotPL > 0.0) {
            color += albedo * u_point_lights[i].color * u_point_lights[i].intensity
                     * NdotPL * atten * (1.0 - metallic);
            float NdotHP = max(dot(N, Hp), 0.0);
            float specP = pow(NdotHP, shininess) * (shininess + 2.0) / 8.0;
            vec3 FP = fresnelSchlick(max(dot(Hp, V), 0.0), F0);
            color += FP * u_point_lights[i].color * u_point_lights[i].intensity
                     * specP * NdotPL * atten;
        }
    }

    // Spot lights — point-light falloff multiplied by a cone factor.
    int spotCount = min(u_spot_light_count, 4);
    for (int i = 0; i < spotCount; i++) {
        vec3 Ls = u_spot_lights[i].position - v_worldPos;
        float dist = length(Ls);
        Ls = normalize(Ls);
        vec3 Hs = normalize(V + Ls);

        float range = max(u_spot_lights[i].range, 0.001);
        float atten = clamp(1.0 - (dist * dist) / (range * range), 0.0, 1.0);
        atten *= atten;

        // Cone factor: smoothstep between the outer (cos(angle)) and inner
        // (cos(angle * (1 - penumbra))) edges. -Ls points from the light
        // toward the fragment; comparing against the beam direction.
        float cosOuter = cos(u_spot_lights[i].angle);
        float cosInner = cos(u_spot_lights[i].angle * (1.0 - u_spot_lights[i].penumbra));
        float cd = dot(-Ls, normalize(u_spot_lights[i].direction));
        float cone = smoothstep(cosOuter, cosInner, cd);
        atten *= cone;

        float NdotSL = max(dot(N, Ls), 0.0);
        if (NdotSL > 0.0 && cone > 0.0) {
            color += albedo * u_spot_lights[i].color * u_spot_lights[i].intensity
                     * NdotSL * atten * (1.0 - metallic);
            float NdotHS = max(dot(N, Hs), 0.0);
            float specS = pow(NdotHS, shininess) * (shininess + 2.0) / 8.0;
            vec3 FS = fresnelSchlick(max(dot(Hs, V), 0.0), F0);
            color += FS * u_spot_lights[i].color * u_spot_lights[i].intensity
                     * specS * NdotSL * atten;
        }
    }

    // ---- Image-Based Lighting (IBL) reflection ----
    // Skip for procedural modes that drive their own lighting (water = 2,
    // cloud = 6, moon = 9 — already early-returned above) and for the
    // legacy unlit fallback (proc_mode reserved values).
    if (u_use_environment_map == 1) {
        vec3 R = reflect(-V, N);
        // Reflection contribution scales with metallic; dielectrics at
        // glancing angles still get a mild Fresnel reflection via the F
        // term used below.
        float NdotV = max(dot(N, V), 0.0);
        vec3 F_ibl = fresnelSchlick(NdotV, F0);
        vec3 envColor = sampleEnvironment(R, roughness);
        // Energy compensation: the diffuse surface already received
        // ambient energy, so attenuate IBL by (1 - roughness) for
        // dielectrics and by metallic for metals.
        float iblWeight = mix(0.15, 1.0, metallic) * (1.0 - roughness * 0.6);
        // Wetness boost: amplify IBL contribution to fake the bright
        // sky reflection of wet/polished surfaces (SSR surrogate).
        // When SSR setting is enabled, scale the wetness lobe further
        // - this is the hook that the future depth-buffer ray-marcher
        // will tap into.
        iblWeight *= (1.0 + wetnessApplied * (1.5 + u_ssr_intensity * 2.0));
        color += envColor * F_ibl * iblWeight * shadow;
    }

    // ---- Clearcoat lobe (carpaint) ----
    if (u_clearcoat > 0.0) {
        // Fixed-IOR clearcoat (n=1.5 → F0=0.04). Independent roughness
        // gives the characteristic high-gloss "wet paint" highlight on
        // top of the metallic base.
        float ccRough = clamp(u_clearcoat_roughness, 0.02, 1.0);
        float ccShininess = exp2(10.0 * (1.0 - ccRough) + 1.0);
        vec3 ccF0 = vec3(0.04);

        // Direct sun specular for the clearcoat (uses primary light only
        // to keep the cost bounded — secondary lights stop at the base
        // lobe, which is good enough for hero-asset car lighting).
        if (u_dir_light_count > 0) {
            vec3 ccL = normalize(-u_dir_lights[0].direction);
            vec3 ccH = normalize(V + ccL);
            float ccNdotL = max(dot(N, ccL), 0.0);
            if (ccNdotL > 0.0) {
                float ccNdotH = max(dot(N, ccH), 0.0);
                float ccSpec = pow(ccNdotH, ccShininess) * (ccShininess + 2.0) / 8.0;
                vec3 ccFres = fresnelSchlick(max(dot(ccH, V), 0.0), ccF0);
                color += ccFres * u_dir_lights[0].color * u_dir_lights[0].intensity
                       * ccSpec * ccNdotL * shadow * u_clearcoat;
            }
        }

        // Clearcoat IBL reflection — sharp, weakly-roughness-modulated.
        if (u_use_environment_map == 1) {
            vec3 ccR = reflect(-V, N);
            vec3 ccEnv = sampleEnvironment(ccR, ccRough);
            float ccNdotV = max(dot(N, V), 0.0);
            vec3 ccFres = fresnelSchlick(ccNdotV, ccF0);
            color += ccEnv * ccFres * u_clearcoat * (1.0 - ccRough * 0.5);
        }
    }

    // Emission
    color += u_emission;

    // Fog
    float fogDist = length(v_worldPos - u_camera_pos);
    float fogFactor = clamp((fogDist - u_fog_near) / (u_fog_far - u_fog_near), 0.0, 1.0);
    fogFactor = 1.0 - exp(-fogFactor * fogFactor * 3.0);
    color = mix(color, u_fog_color, fogFactor);

    // Volumetric scatter (god-ray surrogate). Added on top of distance
    // fog so the standard exp-fog still anchors the long-range falloff.
    color += volumetricScatter(v_worldPos);

    // PHPOLYGON:POSTCOLOR

    // Gamma correction
    color = finalize(color);

    frag_color = vec4(color, alpha);
}
