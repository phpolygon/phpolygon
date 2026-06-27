#version 410 core

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
uniform PointLight u_point_lights[32];
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

// Underwater absorption tint. The ocean surface sits at WATER_Y; any fragment
// below it is submerged (e.g. a sunken harbour). Water absorbs warm wavelengths
// first, so with depth the lit colour is pulled toward a blue-green body colour
// AND darkened. Subtle near the surface, stronger with depth (saturating ~13 m
// down). Applied at the very end of the lit path so it affects terrain AND
// buildings uniformly. MUST stay identical to the vio mirror
// (vio/mesh3d.frag.glsl): same WATER_Y, tint colour, depth scale and weights.
// FIXME(coupling): WATER_Y, the dome centres/radius and the world offset below
// are scene constants supplied by the consuming game — they should arrive as
// uniforms, not be hardcoded in the engine shader. See engine CLAUDE.md.
const float WATER_Y = -0.4;
vec3 applyUnderwaterTint(vec3 color, vec3 worldPos) {
    if (worldPos.y >= WATER_Y) return color;
    // Dry interior of an underwater glass dome — no underwater tint inside its
    // horizontal footprint. Two discs: an embedded scene (+X world offset) and a
    // standalone one. (Game-supplied centres — see FIXME above.)
    const float DOME_R2 = 120.0 * 120.0;
    vec2 dE = worldPos.xz - vec2(957.3, 176.5);   // embedded (shipping) scene
    vec2 dL = worldPos.xz - vec2(-242.7, 176.5);  // standalone scene
    if (dot(dE, dE) < DOME_R2 || dot(dL, dL) < DOME_R2) {
        return color; // inside the dry bubble
    }
    // Dry sealed cellar under each city centre: a control room sits below the
    // plaza, well under the water plane, so its whole footprint must stay
    // un-tinted. ROOM_HALF≈22 → a 32 m disc covers the square room's corners.
    // (Game-supplied centres — see the FIXME above.)
    const float CELLAR_R2 = 32.0 * 32.0;
    vec2 cE = worldPos.xz - vec2(1200.0, 0.0); // embedded (shipping) scene
    vec2 cL = worldPos.xz - vec2(0.0, 0.0);    // standalone scene
    if (dot(cE, cE) < CELLAR_R2 || dot(cL, cL) < CELLAR_R2) {
        return color; // inside the dry cellar
    }
    // dry shaft+corridor tube: keep its interior un-tinted
    // (the descent shaft over deep water + the glass corridor to the dome).
    // Pure-XZ point-to-segment distance → exception holds over the full shaft
    // height and the whole corridor; water OUTSIDE the 6-unit radius stays tinted.
    {
        vec2 p = worldPos.xz;
        // embedded scene segment (+1200 X)
        { vec2 a = vec2(1070.6, 94.0), b = vec2(1050.3, 108.7); vec2 ab = b - a; float t = clamp(dot(p - a, ab) / dot(ab, ab), 0.0, 1.0); vec2 c = a + t * ab; if (dot(p - c, p - c) < 6.0 * 6.0) return color; }
        // standalone scene segment
        { vec2 a = vec2(-129.4, 94.0), b = vec2(-149.7, 108.7); vec2 ab = b - a; float t = clamp(dot(p - a, ab) / dot(ab, ab), 0.0, 1.0); vec2 c = a + t * ab; if (dot(p - c, p - c) < 6.0 * 6.0) return color; }
    }
    float depth = (WATER_Y - worldPos.y) / 13.0;       // 0 at surface → 1 at ~13 m
    depth = clamp(depth, 0.0, 1.0);
    const vec3 WATER_TINT = vec3(0.06, 0.22, 0.28);    // blue-green body colour
    float tintAmt = depth * 0.85;                       // colour shift, subtle near top
    float darken  = mix(1.0, 0.30, depth);             // light loss with depth
    color = mix(color, WATER_TINT, tintAmt) * darken;
    return color;
}

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

// ================================================================
//  Procedural Sand
// ================================================================

vec3 computeSand(vec3 N, vec3 V, vec3 L, out float roughOut) {
    float zone = v_uv.x;    // 0.0=damp, 0.25=mid, 0.5=dry, 0.75=dune; 1.5=industrial
    float variant = v_uv.y;

    // ===============================================================
    //  Themed district ground bands (BOUNDED, keyed by UV.x sentinel).
    //  Half-open intervals so bands can't swallow one another; CPU sets exact
    //  sentinels (1.5, 2.0, 2.5, 3.0, 3.5) that land mid-band. Order/thresholds
    //  /constants MUST stay identical to the vio mirror (vio/mesh3d.frag.glsl).
    //  This backend's fbm helper is fbm(p, octaves), not fbm2.
    //  Bands:
    //    [1.25,1.75) industrial concrete    — look unchanged
    //    [1.75,2.25) marble / flagstone
    //    [2.25,2.75) asphalt + neon flecks
    //    [2.75,3.25) cobblestone
    //    [3.25,+)    wet harbour stone (underwater)
    // ===============================================================

    // --- [1.25,1.75) Industrial concrete — UNCHANGED LOOK ------------------
    if (zone >= 1.25 && zone < 1.75) {
        const vec3  CONCRETE_BASE = vec3(0.334, 0.340, 0.354); // ~#55565A soot-grey
        const float CRACK_STRENGTH = 0.55;
        const float STAIN_STRENGTH = 0.45;
        const float CONCRETE_ROUGH = 0.88;
        vec3 base = CONCRETE_BASE * u_season_tint;

        float w1 = fbm(v_worldPos.xz * 0.35, 3);
        float w2 = noise(v_worldPos.xz * 4.0);
        float w3 = noise(v_worldPos.xz * 26.0);
        base *= 0.80 + w1 * 0.40;
        base *= 0.90 + (w2 - 0.5) * 0.22;
        base *= 0.93 + (w3 - 0.5) * 0.16;

        float cA = abs(noise(v_worldPos.xz * 0.9) - 0.5) * 2.0;
        float cB = abs(noise(v_worldPos.xz * 0.9 + vec2(37.2, 11.7)) - 0.5) * 2.0;
        float crackField = min(cA, cB);
        float cracks = 1.0 - smoothstep(0.0, 0.10, crackField);
        base *= 1.0 - cracks * CRACK_STRENGTH;

        float stain = smoothstep(0.58, 0.80, fbm(v_worldPos.xz * 0.18 + 13.0, 3));
        base = mix(base, base * vec3(0.55, 0.57, 0.62), stain * STAIN_STRENGTH);

        roughOut = mix(CONCRETE_ROUGH, 0.55, stain * 0.6);
        return base;
    }

    // --- [1.75,2.25) Marble / flagstone ------------------------------------
    // Pale grey-white slabs: broad value mottle, subtle grey veining, thin
    // recessed flag joints. Low-mid roughness (polished academic stone).
    if (zone >= 1.75 && zone < 2.25) {
        const vec3  MARBLE_BASE  = vec3(0.86, 0.85, 0.83); // warm off-white
        const vec3  VEIN_COLOR   = vec3(0.55, 0.56, 0.60); // cool grey vein
        const float MARBLE_ROUGH = 0.32;                   // polished
        vec3 base = MARBLE_BASE * u_season_tint;

        float cloud = fbm(v_worldPos.xz * 0.5, 3);
        base *= 0.92 + cloud * 0.16;

        float v1 = abs(fbm(v_worldPos.xz * 0.7 + vec2(5.0, 9.0), 3) - 0.5) * 2.0;
        float vein = 1.0 - smoothstep(0.0, 0.18, v1);
        base = mix(base, VEIN_COLOR, vein * 0.35);

        vec2 cell = fract(v_worldPos.xz * 1.0);
        float jointX = 1.0 - smoothstep(0.0, 0.04, cell.x) * smoothstep(1.0, 0.96, cell.x);
        float jointZ = 1.0 - smoothstep(0.0, 0.04, cell.y) * smoothstep(1.0, 0.96, cell.y);
        float joint = max(jointX, jointZ);
        base *= 1.0 - joint * 0.45;

        float pit = noise(v_worldPos.xz * 24.0);
        base *= 0.96 + (pit - 0.5) * 0.08;

        roughOut = mix(MARBLE_ROUGH, 0.70, joint);
        return base;
    }

    // --- [2.25,2.75) Asphalt + neon flecks ---------------------------------
    // Near-black asphalt with aggregate grain + sparse emissive cyan/magenta
    // specks (added to albedo so they read self-lit). Time-pulsed shimmer.
    if (zone >= 2.25 && zone < 2.75) {
        const vec3  ASPHALT_BASE  = vec3(0.045, 0.047, 0.052); // almost black
        const float ASPHALT_ROUGH = 0.62;                       // semi-matte tarmac
        vec3 base = ASPHALT_BASE * u_season_tint;

        float a1 = noise(v_worldPos.xz * 6.0);
        float a2 = noise(v_worldPos.xz * 30.0);
        base *= 0.85 + a1 * 0.30;
        base += vec3(0.03) * smoothstep(0.7, 0.95, a2);

        vec2 fc = floor(v_worldPos.xz * 12.0);
        float fh = hash21(fc);
        float pick = hash21(fc + 41.0);
        float pulse = 0.6 + 0.4 * sin(u_time * 2.0 + fh * 30.0);
        float speck = smoothstep(0.93, 0.985, fh) * pulse;
        vec3 neon = mix(vec3(0.0, 0.85, 1.0), vec3(1.0, 0.15, 0.8), step(0.5, pick));
        base += neon * speck * 0.9;

        roughOut = ASPHALT_ROUGH;
        return base;
    }

    // --- [2.75,3.25) Cobblestone -------------------------------------------
    // Rounded cobbles via a cheap cellular (Worley-ish) field: bright domed
    // crowns, dark recessed mortar at the cell edges. Warm grey-brown, rough.
    if (zone >= 2.75 && zone < 3.25) {
        const vec3  COBBLE_BASE  = vec3(0.40, 0.36, 0.31); // warm grey-brown
        const float COBBLE_ROUGH = 0.90;                   // rough hewn stone
        vec3 base = COBBLE_BASE * u_season_tint;

        // Domain-warp the lookup with a low-frequency field so the cobble cells
        // do not pack into axis-aligned rows / a regular hexagonal grid.
        vec2 wp = v_worldPos.xz;
        vec2 warp = vec2(noise(wp * 0.35 + 11.3), noise(wp * 0.35 + 47.7)) - 0.5;
        vec2 p = (wp + warp * 0.9) * 1.6;
        vec2 ip = floor(p);
        vec2 fp = fract(p);

        // Worley over the 3x3 neighbourhood, tracking the nearest (F1) AND the
        // second-nearest (F2) distance plus the nearest cell id. F2-F1 yields a
        // crisp mortar seam that follows the irregular cell borders (real
        // cobbles), not a concentric dome that reads as uniform hexagons.
        float d1 = 8.0, d2 = 8.0;
        vec2  nearId = ip;
        for (int y = -1; y <= 1; y++) {
            for (int x = -1; x <= 1; x++) {
                vec2 g = vec2(float(x), float(y));
                vec2 cell = ip + g;
                vec2 o = vec2(hash21(cell), hash21(cell + 19.0));
                float d = length(g + o - fp);
                if (d < d1) { d2 = d1; d1 = d; nearId = cell; }
                else if (d < d2) { d2 = d; }
            }
        }

        float seam = smoothstep(0.0, 0.07, d2 - d1);  // dark joint -> bright stone
        float dome = 1.0 - smoothstep(0.0, 0.70, d1); // gentle worn crown
        base *= 0.45 + seam * 0.45 + dome * 0.18;

        float stoneHash = hash21(nearId);
        base *= 0.80 + stoneHash * 0.38;
        base *= 0.92 + 0.16 * step(0.82, hash21(nearId + 5.0));

        float g3 = noise(v_worldPos.xz * 22.0);
        base *= 0.94 + (g3 - 0.5) * 0.12;

        roughOut = COBBLE_ROUGH - dome * 0.05;
        return base;
    }

    // --- [3.25,+) Wet harbour stone (underwater) ---------------------------
    // Dark blue-green sea-wet stone, low roughness (wet sheen). The submerged
    // tint pass in main() adds the deeper colour/darkening with depth.
    if (zone >= 3.25) {
        const vec3  WET_BASE   = vec3(0.10, 0.16, 0.17); // dark teal-grey stone
        const float WET_ROUGH  = 0.14;                   // wet, glossy
        vec3 base = WET_BASE * u_season_tint;

        float m1 = fbm(v_worldPos.xz * 0.6, 3);
        base *= 0.80 + m1 * 0.40;
        float algae = smoothstep(0.55, 0.80, fbm(v_worldPos.xz * 0.9 + 7.0, 3));
        base = mix(base, base * vec3(0.55, 0.95, 0.70), algae * 0.5);

        vec2 cell = fract(v_worldPos.xz * 0.7);
        float jointX = 1.0 - smoothstep(0.0, 0.05, cell.x) * smoothstep(1.0, 0.95, cell.x);
        float jointZ = 1.0 - smoothstep(0.0, 0.05, cell.y) * smoothstep(1.0, 0.95, cell.y);
        float joint = max(jointX, jointZ);
        base *= 1.0 - joint * 0.55;

        float g3 = noise(v_worldPos.xz * 20.0);
        base *= 0.93 + (g3 - 0.5) * 0.14;

        roughOut = mix(WET_ROUGH, 0.30, joint);
        return base;
    }

    // Zone color palettes — warm natural beach tones
    const vec3 damp[4] = vec3[](
        vec3(0.478, 0.369, 0.165), vec3(0.408, 0.306, 0.125),
        vec3(0.541, 0.408, 0.188), vec3(0.290, 0.220, 0.094)
    );
    const vec3 mid[4] = vec3[](
        vec3(0.722, 0.565, 0.314), vec3(0.627, 0.471, 0.220),
        vec3(0.784, 0.596, 0.345), vec3(0.420, 0.333, 0.157)
    );
    const vec3 dry[4] = vec3[](
        vec3(0.831, 0.722, 0.478), vec3(0.769, 0.643, 0.384),
        vec3(0.878, 0.769, 0.549), vec3(0.545, 0.451, 0.251)
    );
    const vec3 dune[4] = vec3[](
        vec3(0.863, 0.753, 0.502), vec3(0.910, 0.800, 0.565),
        vec3(0.816, 0.706, 0.439), vec3(0.604, 0.502, 0.282)
    );

    // Blend between zones smoothly
    vec3 colors[4];
    if (zone < 0.125)      colors = damp;
    else if (zone < 0.375) colors = mid;
    else if (zone < 0.625) colors = dry;
    else                   colors = dune;

    // Smooth variant blending
    float vi = variant * 3.0;
    int idx = int(floor(vi));
    vec3 baseColor = mix(colors[clamp(idx, 0, 3)], colors[clamp(idx + 1, 0, 3)], fract(vi));

    // Seasonal tint modulates terrain color
    baseColor *= u_season_tint;

    // Multi-scale noise — creates natural organic sand pattern
    float n1 = fbm(v_worldPos.xz * 1.5, 3);          // large color patches
    float n2 = noise(v_worldPos.xz * 6.0);             // medium grain clumps
    float n3 = noise(v_worldPos.xz * 25.0);            // individual grains
    float n4 = noise(v_worldPos.xz * 80.0);            // micro detail

    vec3 sandColor = baseColor;
    sandColor *= 0.82 + n1 * 0.36;                     // broad variation
    sandColor *= 0.92 + (n2 - 0.5) * 0.16;             // clump variation
    sandColor += vec3(0.02) * (n3 - 0.5);              // grain-level color shift
    sandColor += vec3(0.01, 0.008, 0.005) * (n4 - 0.5); // warm micro detail

    // Wind ripple patterns — diagonal lines across the beach
    float ripple = sin(v_worldPos.x * 3.0 + v_worldPos.z * 1.5 + n1 * 2.0) * 0.5 + 0.5;
    ripple = smoothstep(0.3, 0.7, ripple);
    float rippleStrength = smoothstep(0.3, 0.8, zone); // stronger on dry/dune
    sandColor *= 1.0 - ripple * 0.06 * rippleStrength;

    // Subsurface scattering approximation — warm glow when backlit
    float scatter = max(dot(V, L), 0.0);
    scatter = pow(scatter, 4.0) * 0.08;
    sandColor += vec3(0.15, 0.10, 0.04) * scatter;

    // Sparkle / glint — individual grains catching sunlight
    vec3 grainPos = floor(v_worldPos * 40.0);
    float glint = hash31(grainPos);
    vec3 grainNormal = normalize(vec3(
        hash21(grainPos.xz) - 0.5,
        1.0,
        hash21(grainPos.xz + 100.0) - 0.5
    ));
    float glintSpec = pow(max(dot(reflect(-L, grainNormal), V), 0.0), 80.0);
    if (glint > 0.96) {
        sandColor += vec3(0.4, 0.35, 0.25) * glintSpec * max(dot(N, L), 0.0);
    }

    // Roughness per zone — wet sand is shinier
    roughOut = mix(0.45, 0.95, smoothstep(0.0, 0.3, zone));
    // Wet sand also gets slight specular tint
    if (zone < 0.15) {
        sandColor = mix(sandColor, sandColor * 1.15, 0.3);
    }

    return sandColor;
}

// ================================================================
//  Procedural Water
// ================================================================

vec3 computeWater(vec3 N, vec3 V, vec3 L, out float alphaOut, out float roughOut) {
    // Animated wave normals — multiple layers at different speeds and scales
    vec2 uv1 = v_worldPos.xz * 0.8 + u_time * vec2(0.03, 0.02);
    vec2 uv2 = v_worldPos.xz * 1.6 + u_time * vec2(-0.02, 0.04);
    vec2 uv3 = v_worldPos.xz * 4.0 + u_time * vec2(0.05, -0.03);
    vec2 uv4 = v_worldPos.xz * 8.0 + u_time * vec2(-0.04, 0.06);

    // Compute normal perturbation from noise derivatives
    float eps = 0.05;
    float h1a = fbm(uv1, 3); float h1b = fbm(uv1 + vec2(eps, 0), 3); float h1c = fbm(uv1 + vec2(0, eps), 3);
    float h2a = fbm(uv2, 2); float h2b = fbm(uv2 + vec2(eps, 0), 2); float h2c = fbm(uv2 + vec2(0, eps), 2);
    float h3a = noise(uv3);  float h3b = noise(uv3 + vec2(eps, 0));   float h3c = noise(uv3 + vec2(0, eps));
    float h4a = noise(uv4);  float h4b = noise(uv4 + vec2(eps, 0));   float h4c = noise(uv4 + vec2(0, eps));

    vec3 waveNormal = vec3(0.0, 1.0, 0.0);
    // Large swell
    waveNormal.x += (h1a - h1b) * 1.5 + (h2a - h2b) * 0.8;
    waveNormal.z += (h1a - h1c) * 1.5 + (h2a - h2c) * 0.8;
    // Detail ripples
    waveNormal.x += (h3a - h3b) * 0.3 + (h4a - h4b) * 0.15;
    waveNormal.z += (h3a - h3c) * 0.3 + (h4a - h4c) * 0.15;
    waveNormal = normalize(waveNormal);

    // Blend wave normal with geometry normal
    N = normalize(N + waveNormal * vec3(1.0, 0.0, 1.0));

    // Seen from UNDERWATER (camera below this surface fragment): the sky/fresnel
    // mirror below reads as flat white. Render the surface as a tinted,
    // translucent ceiling with a soft light shimmer instead.
    if (u_camera_pos.y < v_worldPos.y) {
        float shimmer = fbm(uv1 * 1.5, 3) * 0.25 + noise(uv2 * 2.0) * 0.15;
        vec3 underCol = mix(vec3(0.04, 0.16, 0.20), vec3(0.10, 0.34, 0.38), clamp(N.y, 0.0, 1.0));
        underCol += shimmer * vec3(0.10, 0.18, 0.20);
        alphaOut = 0.55;
        roughOut = 0.12;
        return underCol;
    }

    // Fresnel — more reflective at shallow angles (realistic water!)
    float NdotV = max(dot(N, V), 0.0);
    float fresnel = pow(1.0 - NdotV, 5.0);
    fresnel = mix(0.02, 1.0, fresnel); // water F0 ≈ 0.02

    // Depth-based coloring. Use RADIAL distance from the island centre (world
    // origin), not a fixed Z line: the old `-8 - z` assumed one south-facing
    // shore, so the entire north/west half of the island read as shallow water
    // + shore foam ("half the island not in the sea"). Radial distance gives a
    // proper shallow ring at the coastline all the way around. An island centred
    // at the origin (~100 m shore radius, ~70 m to full depth) gets the ring;
    // far-offset water simply reads as deep, which is fine.
    // FIXME(coupling): the 100/70 shore radii are a game scene constant — should
    // be a uniform, not hardcoded here. See engine CLAUDE.md.
    float depth = clamp((length(v_worldPos.xz) - 100.0) / 70.0, 0.0, 1.0);

    vec3 shallowColor = vec3(0.15, 0.55, 0.50);  // turquoise
    vec3 deepColor    = vec3(0.02, 0.08, 0.15);   // dark navy
    vec3 waterColor   = mix(shallowColor, deepColor, depth);

    // Reflection — cubemap or sky color fallback
    vec3 R = reflect(-V, N);
    vec3 reflectColor;
    if (u_has_environment_map == 1) {
        reflectColor = texture(u_environment_map, R).rgb;
    } else {
        // Blend between horizon (low R.y) and sky (high R.y) based on reflection direction
        float skyBlend = clamp(R.y * 2.0, 0.0, 1.0);
        reflectColor = mix(u_horizon_color, u_sky_color, skyBlend);
    }
    // Sun hotspot on reflection
    float sunCatch = pow(max(dot(R, L), 0.0), 256.0);
    reflectColor = mix(reflectColor, u_dir_light_color, sunCatch * 2.0);

    // Combine: fresnel blends between water body color and reflection
    vec3 finalColor = mix(waterColor, reflectColor, fresnel);

    // Sun specular hotspot on water
    vec3 Hw = normalize(V + L);
    float specWater = pow(max(dot(N, Hw), 0.0), 512.0);
    finalColor += u_dir_light_color * u_dir_light_intensity * specWater * 2.0;

    // Shore foam — white noise patches where water is shallow
    float foamLine = smoothstep(0.02, 0.0, depth);
    float foamNoise = fbm(v_worldPos.xz * 6.0 + u_time * 0.5, 3);
    float foam = foamLine * smoothstep(0.35, 0.65, foamNoise);
    finalColor = mix(finalColor, vec3(0.9, 0.95, 1.0), foam * 0.7);

    // Caustic light pattern on shallow water (subtle)
    if (depth < 0.3) {
        float caustic1 = noise(v_worldPos.xz * 3.0 + u_time * 0.8);
        float caustic2 = noise(v_worldPos.xz * 3.0 - u_time * 0.6 + 50.0);
        float caustic = pow(min(caustic1, caustic2), 3.0) * 2.0;
        finalColor += vec3(0.1, 0.15, 0.1) * caustic * (1.0 - depth / 0.3);
    }

    // Transparency: shallow = more transparent, deep = more opaque
    alphaOut = mix(0.5, 0.92, depth);
    // Foam areas are opaque
    alphaOut = mix(alphaOut, 1.0, foam * 0.8);

    roughOut = 0.05; // water is very smooth

    return finalColor;
}

// ================================================================
//  Procedural Pool / Fountain Water (proc_mode 11)
//  Small raised basins (fountains, ponds). Same wave-normal shimmer + fresnel
//  sky reflection + sun specular as the ocean, but NO radial depth/shoreline/
//  foam — that model is the ocean's (centred at the island origin) and would
//  blank out water near the centre (e.g. a plaza fountain at r≈40 m).
// ================================================================

vec3 computePoolWater(vec3 N, vec3 V, vec3 L, out float alphaOut, out float roughOut) {
    // Three ripple layers — coarse swell + chop + fine sparkle.
    vec2 uv1 = v_worldPos.xz * 1.8  + u_time * vec2(0.05, 0.04);
    vec2 uv2 = v_worldPos.xz * 5.0  + u_time * vec2(-0.06, 0.08);
    vec2 uv3 = v_worldPos.xz * 11.0 + u_time * vec2(0.09, -0.07);

    float eps = 0.05;
    float h1a = fbm(uv1, 3); float h1b = fbm(uv1 + vec2(eps,0), 3); float h1c = fbm(uv1 + vec2(0,eps), 3);
    float h2a = noise(uv2);  float h2b = noise(uv2 + vec2(eps,0));  float h2c = noise(uv2 + vec2(0,eps));
    float h3a = noise(uv3);  float h3b = noise(uv3 + vec2(eps,0));  float h3c = noise(uv3 + vec2(0,eps));

    vec3 waveNormal = vec3(0.0, 1.0, 0.0);
    waveNormal.x += (h1a - h1b) * 1.4 + (h2a - h2b) * 0.6 + (h3a - h3b) * 0.25;
    waveNormal.z += (h1a - h1c) * 1.4 + (h2a - h2c) * 0.6 + (h3a - h3c) * 0.25;
    waveNormal = normalize(waveNormal);

    N = normalize(N + waveNormal * vec3(1.0, 0.0, 1.0));

    float NdotV = max(dot(N, V), 0.0);
    float fresnel = mix(0.04, 1.0, pow(1.0 - NdotV, 5.0));

    vec3 floorColor = vec3(0.04, 0.16, 0.18);
    vec3 bodyColor  = vec3(0.10, 0.42, 0.48);
    vec3 waterColor = mix(floorColor, bodyColor, fresnel);

    vec3 R = reflect(-V, N);
    float skyBlend = clamp(R.y * 2.0, 0.0, 1.0);
    vec3 reflectColor = mix(u_horizon_color, u_sky_color, skyBlend);
    reflectColor = mix(reflectColor, u_dir_light_color, pow(max(dot(R, L), 0.0), 256.0) * 2.0);

    vec3 finalColor = mix(waterColor, reflectColor, fresnel);

    vec3 H = normalize(V + L);
    float specTight = pow(max(dot(N, H), 0.0), 600.0);
    float specSoft  = pow(max(dot(N, H), 0.0), 80.0) * 0.25;
    finalColor += u_dir_light_color * u_dir_light_intensity * (specTight * 2.5 + specSoft);

    float cs1 = noise(v_worldPos.xz * 6.0 + u_time * 0.6);
    float cs2 = noise(v_worldPos.xz * 6.0 - u_time * 0.45 + 23.0);
    float caustic = pow(max(0.0, 1.0 - abs(cs1 - cs2) * 2.5), 3.0);
    finalColor += vec3(0.12, 0.22, 0.20) * caustic * (1.0 - fresnel);

    alphaOut = mix(0.45, 0.95, fresnel);
    roughOut = 0.03;

    return finalColor;
}

// ================================================================
//  Procedural Rock
// ================================================================

vec3 computeRock(vec3 N, vec3 worldPos, vec3 baseAlbedo, out float roughOut) {
    vec3 p = worldPos * 2.5;

    // Base rock color with large-scale variation
    float n1 = fbm(p.xz, 4);
    float n2 = fbm(p.xz * 3.0 + 50.0, 3);
    float n3 = noise(p.xz * 12.0);

    // Mix between dark and light stone
    vec3 darkStone  = baseAlbedo * 0.6;
    vec3 lightStone = baseAlbedo * 1.3;
    vec3 rockColor = mix(darkStone, lightStone, n1);

    // Veins / cracks — dark lines
    float crack = noise(p.xz * 8.0 + vec2(p.y * 2.0));
    crack = smoothstep(0.48, 0.52, crack);
    rockColor = mix(rockColor, rockColor * 0.5, crack * 0.4);

    // Strata layers — horizontal bands common in sedimentary rock
    float strata = sin(worldPos.y * 15.0 + n1 * 3.0) * 0.5 + 0.5;
    strata = smoothstep(0.4, 0.6, strata);
    rockColor *= 0.9 + strata * 0.2;

    // Moss patches — green on top-facing surfaces
    float upFacing = max(dot(N, vec3(0.0, 1.0, 0.0)), 0.0);
    float mossNoise = fbm(worldPos.xz * 4.0, 3);
    float moss = upFacing * smoothstep(0.4, 0.7, mossNoise) * smoothstep(0.5, 0.9, upFacing);
    vec3 mossColor = vec3(0.15, 0.25, 0.08);
    rockColor = mix(rockColor, mossColor, moss * 0.6);

    // Lichen spots — orange/yellow patches
    float lichenNoise = noise(worldPos.xz * 10.0 + 200.0);
    if (lichenNoise > 0.85) {
        vec3 lichenColor = vec3(0.6, 0.5, 0.2);
        rockColor = mix(rockColor, lichenColor, (lichenNoise - 0.85) * 4.0 * 0.3);
    }

    // Surface roughness variation
    roughOut = 0.75 + n2 * 0.2;
    roughOut = mix(roughOut, 0.6, moss * 0.5); // moss is smoother

    return rockColor;
}

// ================================================================
//  Procedural Palm Trunk
// ================================================================

vec3 computePalmTrunk(vec3 worldPos, vec3 baseAlbedo, out float roughOut) {
    // Rings and fibers are locked to the cylinder's local UV so scars stay
    // perpendicular to the trunk when it leans/curves, and the fiber pattern
    // doesn't slide across the surface when the trunk sways.
    //   v_uv.x = angular (0..1 around trunk)
    //   v_uv.y = height along segment (0..1)
    float ring = sin(v_uv.y * 6.2831 * 1.2) * 0.5 + 0.5;
    ring = smoothstep(0.3, 0.7, ring);

    float fiber = noise(vec2(v_uv.x * 20.0, v_uv.y * 4.0));
    float fiberFine = noise(vec2(v_uv.x * 50.0, v_uv.y * 10.0));

    // Base bark color with warm brown variation
    vec3 darkBark  = baseAlbedo * 0.65;
    vec3 lightBark = baseAlbedo * 1.2;
    vec3 barkColor = mix(darkBark, lightBark, ring * 0.6 + fiber * 0.4);

    // Ring shadows — darker in the grooves
    barkColor *= 0.85 + ring * 0.3;

    // Fiber detail
    barkColor *= 0.95 + (fiberFine - 0.5) * 0.15;

    // Slight green/grey weathering
    float weather = fbm(worldPos.xz * 5.0, 2);
    barkColor = mix(barkColor, barkColor * vec3(0.85, 0.9, 0.8), weather * 0.2);

    roughOut = 0.85 + ring * 0.1;
    return barkColor;
}

// ================================================================
//  Procedural Palm Leaf
// ================================================================

vec3 computePalmLeaf(vec3 worldPos, vec3 N, vec3 V, vec3 L, vec3 baseAlbedo, out float roughOut) {
    // PalmFrondMesh UV layout:
    //   v_uv.y = distance along frond (0 base → 1 tip)
    //   v_uv.x = sideways from the spine (0.5 centre, ±0.5 leaflet tips)
    // UV-based patterns rotate with the frond when it sways.
    float sideways = (v_uv.x - 0.5) * 2.0;

    // Veins run outward from the spine, along the leaflet length.
    float vein = abs(sin(sideways * 18.0));
    vein = smoothstep(0.0, 0.15, vein);

    float n = fbm(v_uv * 8.0, 3);
    vec3 leafColor = baseAlbedo * (0.8 + n * 0.4);

    leafColor = mix(leafColor * 1.3, leafColor, vein);

    // Age gradient: young green at the base, warmer yellow-brown at the tip.
    float age = smoothstep(0.6, 1.0, v_uv.y);
    leafColor = mix(leafColor, leafColor * vec3(0.55, 0.45, 0.18) * 1.4, age * 0.35);

    // Edge browning concentrated near leaflet outer edges.
    float edgeNoise = noise(v_uv * 12.0);
    float edgeMask = smoothstep(0.6, 1.0, abs(sideways));
    leafColor = mix(leafColor, vec3(0.4, 0.35, 0.15), edgeMask * edgeNoise * 0.25);

    // Translucency — light shining through leaf
    float translucency = max(dot(-N, L), 0.0);
    translucency = pow(translucency, 2.0) * 0.3;
    leafColor += vec3(0.1, 0.2, 0.02) * translucency;

    // Subsurface scattering
    float scatter = pow(max(dot(V, L), 0.0), 3.0) * 0.1;
    leafColor += vec3(0.05, 0.1, 0.02) * scatter;

    roughOut = 0.6 + edgeNoise * 0.15;
    return leafColor;
}

// ================================================================
//  Procedural Wood Planks (beach hut walls/furniture)
// ================================================================

vec3 computeWoodPlanks(vec3 N, vec3 worldPos, vec3 baseAlbedo, out float roughOut) {
    // Plank orientation follows the face's local normal so planks rotate
    // with the mesh and adapt to walls, floors and ceilings automatically.
    // Local-space coordinates are scaled back to world distance via
    // v_objectScale so density stays consistent across differently scaled
    // entities (wall, floor, door panel).
    vec3 scaledLocal = v_localPos * v_objectScale;
    vec3 absN = abs(v_localNormal);

    float plankCoord;
    float grainCoord;
    if (absN.y > absN.x && absN.y > absN.z) {
        plankCoord = scaledLocal.z * 6.5;
        grainCoord = scaledLocal.x * 8.0;
    } else if (absN.x >= absN.z) {
        plankCoord = scaledLocal.y * 6.5;
        grainCoord = scaledLocal.z * 8.0;
    } else {
        plankCoord = scaledLocal.y * 6.5;
        grainCoord = scaledLocal.x * 8.0;
    }

    float plankIndex = floor(plankCoord);
    float withinPlank = fract(plankCoord);

    // Gap between planks — dark thin line
    float gap = smoothstep(0.0, 0.03, withinPlank) * smoothstep(1.0, 0.97, withinPlank);

    // Each plank has a unique color shift based on its index
    float plankHash = hash21(vec2(plankIndex * 17.3, plankIndex * 7.1));
    float plankHash2 = hash21(vec2(plankIndex * 31.7, plankIndex * 13.3));

    // Base wood color with per-plank variation
    vec3 woodColor = baseAlbedo;
    woodColor *= 0.8 + plankHash * 0.4; // brightness variation
    woodColor = mix(woodColor, woodColor * vec3(1.05, 0.95, 0.85), plankHash2 * 0.3); // hue shift

    // Wood grain — runs along the plank's local axis picked above.
    float offsetGrain = grainCoord + plankHash * 20.0;
    float grain = sin(offsetGrain + noise(vec2(offsetGrain * 0.5, plankIndex)) * 3.0);
    grain = grain * 0.5 + 0.5;
    woodColor *= 0.9 + grain * 0.15;

    // Fine grain detail
    float fineGrain = noise(vec2(offsetGrain * 3.0, plankCoord * 2.0 + plankIndex * 5.0));
    woodColor *= 0.95 + fineGrain * 0.1;

    // Knot holes — rare dark circles
    float knotSeed = hash21(vec2(plankIndex * 43.7, floor(offsetGrain * 0.3)));
    if (knotSeed > 0.92) {
        vec2 knotCenter = vec2(
            fract(knotSeed * 127.1) * 0.8 + 0.1,
            0.5
        );
        vec2 knotUV = vec2(fract(offsetGrain * 0.15), withinPlank);
        float knotDist = length(knotUV - knotCenter);
        if (knotDist < 0.08) {
            woodColor *= 0.4 + knotDist * 5.0; // dark center, lighter rim
        }
    }

    // Nail heads — small bright spots
    float nailSeed = hash21(vec2(plankIndex * 11.1, 0.0));
    if (fract(nailSeed * 7.7) > 0.7) {
        vec2 nailPos = vec2(fract(nailSeed * 31.3) * 0.6 + 0.2, 0.5);
        vec2 nailUV = vec2(fract(grainCoord * 2.0), withinPlank);
        if (length(nailUV - nailPos) < 0.015) {
            woodColor = vec3(0.3, 0.3, 0.35); // metal nail
        }
    }

    // Apply gap — dark line between planks
    woodColor *= gap * 0.85 + 0.15;

    // Weathering — random darker patches
    float weather = fbm(worldPos.xz * 3.0 + worldPos.y * 2.0, 2);
    woodColor *= 0.85 + weather * 0.2;

    // Normal perturbation — grain direction bumps
    float bumpX = noise(vec2(offsetGrain + 0.1, plankCoord * 8.0)) - 0.5;
    float bumpY = (withinPlank < 0.04 || withinPlank > 0.96) ? -0.3 : 0.0; // gap indent
    N = normalize(N + vec3(bumpX * 0.08, bumpY, bumpX * 0.05));

    roughOut = 0.78 + plankHash * 0.15;
    return woodColor;
}

// ================================================================
//  Procedural Thatch / Straw (beach hut roof)
// ================================================================

vec3 computeThatch(vec3 N, vec3 worldPos, vec3 baseAlbedo, out float roughOut) {
    // Strand direction is locked to the roof's local axis so fibres follow
    // the slope, regardless of the hut's yaw. Local coords × object scale
    // keep the strand density consistent when the mesh is non-uniformly
    // scaled.
    vec3 scaledLocal = v_localPos * v_objectScale;
    float strandAngle = scaledLocal.x * 12.0 + scaledLocal.z * 6.0 + scaledLocal.y * 4.0;
    float strand1 = sin(strandAngle) * 0.5 + 0.5;
    float strand2 = sin(strandAngle * 1.7 + 3.0) * 0.5 + 0.5;
    float strand3 = sin(strandAngle * 0.6 + 7.0) * 0.5 + 0.5;

    // Straw density layers
    float density = strand1 * 0.4 + strand2 * 0.35 + strand3 * 0.25;
    density = smoothstep(0.2, 0.8, density);

    // Color — golden straw with variation; local-space fbm follows the roof.
    vec3 strawColor = baseAlbedo;
    float n = fbm(scaledLocal.xz * 5.0 + scaledLocal.y * 3.0, 3);
    strawColor *= 0.75 + n * 0.5;

    // Individual strand highlights
    float strandHighlight = pow(strand1, 8.0);
    strawColor += vec3(0.1, 0.08, 0.02) * strandHighlight;

    // Darker gaps between strands
    float strandGap = smoothstep(0.45, 0.5, strand1) * smoothstep(0.55, 0.5, strand1);
    strawColor *= 1.0 - strandGap * 0.3;

    // Weathering — some strands are darker/older
    float age = noise(worldPos.xz * 8.0);
    strawColor = mix(strawColor, strawColor * 0.6, smoothstep(0.7, 0.9, age) * 0.4);

    // Normal perturbation for strand direction
    float nx_p = sin(strandAngle + 0.1) * 0.1;
    float nz_p = cos(strandAngle * 0.7) * 0.08;
    N = normalize(N + vec3(nx_p, 0.0, nz_p));

    roughOut = 0.92 + density * 0.06;
    return strawColor;
}

// ================================================================
//  Procedural Ruin (proc_mode 13)
//  Mirrors vio/mesh3d.frag.glsl computeRuined(). Shades intact box geometry
//  as weathered, cracked, moss/soot-stained concrete so a not-yet-rebuilt
//  district building reads as a ruin with NO geometry change. Pure world-
//  space, deterministic (no u_time). Returns modulated albedo + roughness;
//  the caller runs the standard PBR lighting (surface stays fully LIT).
//  NOTE: this backend's fbm helper is fbm(p, octaves) rather than fbm2/fbm3.
// ================================================================

vec3 computeRuined(vec3 N, vec3 worldPos, vec3 baseAlbedo, out float roughOut) {
    // ---- Tuning constants (keep in sync with vio/mesh3d.frag.glsl) ----
    const float CRACK_SCALE   = 1.6;
    const float CRACK_DARKEN  = 0.55;
    const float MOSS_AMOUNT   = 0.55;
    const float MOSS_MAX_Y    = 6.0;
    const float SOOT_AMOUNT   = 0.45;
    const float BASE_DARKEN   = 0.62;
    const float DESATURATE    = 0.35;
    const float EDGE_BLEACH   = 0.10;

    vec3 col = baseAlbedo;

    vec3 an = abs(normalize(N));
    vec2 facePlane;
    if (an.y >= an.x && an.y >= an.z)      facePlane = worldPos.xz;
    else if (an.x >= an.z)                 facePlane = worldPos.zy;
    else                                   facePlane = worldPos.xy;

    float grime = fbm(facePlane * CRACK_SCALE, 3);
    float crack = smoothstep(0.30, 0.46, grime);
    float blotch = 0.85 + (grime - 0.5) * 0.5;
    col *= blotch;
    col = mix(col * (1.0 - CRACK_DARKEN), col, crack);

    float pit = hash21(floor(facePlane * 26.0));
    col *= 0.92 + pit * 0.12;

    float streakX = noise(vec2(facePlane.x * 3.0, worldPos.y * 0.35));
    float streak  = smoothstep(0.55, 0.85, streakX);
    float drip = clamp(1.0 - worldPos.y / max(MOSS_MAX_Y * 1.8, 0.001), 0.0, 1.0);
    float soot = streak * drip * (1.0 - an.y);
    col *= 1.0 - soot * SOOT_AMOUNT;

    float upFace = clamp(N.y, 0.0, 1.0);
    float lowGround = 1.0 - smoothstep(0.0, MOSS_MAX_Y, worldPos.y);
    float mossPatch = smoothstep(0.45, 0.75, fbm(facePlane * 2.2, 2));
    float moss = upFace * lowGround * mossPatch * MOSS_AMOUNT;
    vec3 mossColor = vec3(0.18, 0.27, 0.12);
    col = mix(col, mossColor, moss);

    float high = smoothstep(MOSS_MAX_Y * 0.6, MOSS_MAX_Y * 2.2, worldPos.y);
    col = mix(col, col * 1.25 + vec3(0.04), high * EDGE_BLEACH);

    float luma = dot(col, vec3(0.2126, 0.7152, 0.0722));
    col = mix(col, vec3(luma), DESATURATE);
    col *= BASE_DARKEN;

    roughOut = clamp(0.88 + (1.0 - crack) * 0.08 + moss * 0.05, 0.04, 1.0);
    return col;
}

// ================================================================
//  Procedural Cloud
// ================================================================

vec3 computeCloud(vec3 N, vec3 V, vec3 L, vec3 baseAlbedo, out float alphaOut) {
    // Cloud color based on sun-facing
    float NdotL = max(dot(N, L), 0.0);

    // Bright top, darker base
    vec3 sunColor = vec3(1.0, 0.98, 0.95);
    vec3 shadowColor = vec3(0.6, 0.65, 0.72);
    vec3 cloudColor = mix(shadowColor, sunColor, NdotL * 0.7 + 0.3);

    // Subsurface scattering — light passes through cloud edges
    float scatter = pow(max(dot(V, L), 0.0), 3.0);
    cloudColor += vec3(0.3, 0.25, 0.15) * scatter * 0.4;

    // Silver lining — bright rim when backlit
    float rim = pow(1.0 - max(dot(N, V), 0.0), 3.0);
    cloudColor += vec3(0.5, 0.5, 0.4) * rim * scatter * 0.6;

    // Soft noise variation
    float n = fbm(v_worldPos.xz * 0.3, 3);
    cloudColor *= 0.9 + n * 0.2;

    // Edge transparency — clouds are more transparent at edges
    float edgeFade = pow(max(dot(N, V), 0.0), 0.8);
    alphaOut = edgeFade * 0.85;

    return cloudColor;
}

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
    if (u_proc_mode == 2) {
        // Punch a hole in the ocean sheet over an elevator shaft so the open
        // shaft (it spans from above the surface down to the basin) reads as DRY
        // air, not flooded. Shaft XZ in each placement (an embedded +X-offset
        // scene and a standalone one). The dome keeps water above it and the
        // corridor sits below this plane, so only the shaft needs it.
        // (Game-supplied shaft centres — see the FIXME on applyUnderwaterTint.)
        {
            const float W3_SHAFT_R2 = 5.0 * 5.0;
            vec2 shE = v_worldPos.xz - vec2(1070.6, 94.0);
            vec2 shL = v_worldPos.xz - vec2(-129.4, 94.0);
            if (dot(shE, shE) < W3_SHAFT_R2 || dot(shL, shL) < W3_SHAFT_R2) {
                discard;
            }
        }
        // Water — full procedural with reflections, foam, caustics
        albedo = computeWater(N, V, L, alpha, roughness);

        // Water handles its own lighting — skip PBR, go to fog
        float fogDist = length(v_worldPos - u_camera_pos);
        float fogFactor = clamp((fogDist - u_fog_near) / (u_fog_far - u_fog_near), 0.0, 1.0);
        fogFactor = 1.0 - exp(-fogFactor * fogFactor * 3.0);
        vec3 color = mix(albedo, u_fog_color, fogFactor);
        color = finalize(color);
        frag_color = vec4(color, alpha);
        return;

    } else if (u_proc_mode == 1) {
        // Sand terrain
        albedo = computeSand(N, V, L, roughness);
        float nx_p = noise(v_worldPos.xz * 20.0 + vec2(0.1, 0.0));
        float nz_p = noise(v_worldPos.xz * 20.0 + vec2(0.0, 0.1));
        N = normalize(N + vec3((nx_p - 0.5) * 0.05, 0.0, (nz_p - 0.5) * 0.05));

    } else if (u_proc_mode == 3) {
        // Rock
        albedo = computeRock(N, v_worldPos, u_albedo, roughness);
        // Rock normal perturbation for surface roughness
        float rnx = noise(v_worldPos.xz * 15.0 + vec2(0.1, 0.0));
        float rnz = noise(v_worldPos.xz * 15.0 + vec2(0.0, 0.1));
        float rny = noise(v_worldPos.yz * 15.0);
        N = normalize(N + vec3((rnx - 0.5) * 0.12, (rny - 0.5) * 0.08, (rnz - 0.5) * 0.12));

    } else if (u_proc_mode == 4) {
        // Palm trunk
        albedo = computePalmTrunk(v_worldPos, u_albedo, roughness);
        float tnx = noise(vec2(v_worldPos.x * 30.0, v_worldPos.y * 5.0));
        N = normalize(N + vec3((tnx - 0.5) * 0.08, 0.0, (tnx - 0.5) * 0.08));

    } else if (u_proc_mode == 5) {
        // Palm leaf
        albedo = computePalmLeaf(v_worldPos, N, V, L, u_albedo, roughness);

    } else if (u_proc_mode == 7) {
        // Wood planks — beach hut walls, furniture
        albedo = computeWoodPlanks(N, v_worldPos, u_albedo, roughness);
        float wnx = noise(v_worldPos.xz * 15.0 + vec2(0.1, 0.0));
        float wnz = noise(v_worldPos.xz * 15.0 + vec2(0.0, 0.1));
        N = normalize(N + vec3((wnx - 0.5) * 0.06, 0.0, (wnz - 0.5) * 0.06));

    } else if (u_proc_mode == 8) {
        // Thatch / straw — beach hut roof
        albedo = computeThatch(N, v_worldPos, u_albedo, roughness);

    } else if (u_proc_mode == 13) {
        // Ruined district building — weathered/cracked concrete from the flat
        // material albedo. Stays LIT: falls through to the standard PBR path.
        albedo = computeRuined(N, v_worldPos, u_albedo, roughness);

    } else if (u_proc_mode == 6) {
        // Cloud — self-lit, skip PBR
        albedo = computeCloud(N, V, L, u_albedo, alpha);

        float fogDist = length(v_worldPos - u_camera_pos);
        float fogFactor = clamp((fogDist - u_fog_near) / (u_fog_far - u_fog_near), 0.0, 1.0);
        fogFactor = 1.0 - exp(-fogFactor * fogFactor * 3.0);
        vec3 color = mix(albedo, u_fog_color, fogFactor);
        color = finalize(color);
        frag_color = vec4(color, alpha);
        return;

    } else if (u_proc_mode == 9) {
        // Moon — procedural phase rendering
        vec3 moonN = normalize(N);

        // Build view-space right vector for terminator direction
        vec3 vUp = abs(V.y) > 0.99 ? vec3(0.0, 0.0, 1.0) : vec3(0.0, 1.0, 0.0);
        vec3 viewRight = normalize(cross(V, vUp));

        // Local X on moon face: -1 = left, +1 = right (from camera's perspective)
        float localX = dot(moonN, viewRight);

        // Terminator sweeps with phase:
        // 0.0 = new moon (all dark), 0.5 = full (all lit), 1.0 = new again
        float terminatorPos = cos(u_moon_phase * 2.0 * 3.14159);
        float illumination = smoothstep(terminatorPos - 0.12, terminatorPos + 0.12, localX);

        // Moon surface with procedural craters
        vec3 objPos = moonN;
        float crater = noise(objPos.xz * 4.0 + objPos.y * 2.0);
        float mare = smoothstep(0.42, 0.55, crater) * 0.25;
        float detail = (noise(objPos.xz * 12.0 + objPos.yz * 8.0) - 0.5) * 0.08;
        vec3 moonColor = vec3(0.85, 0.87, 0.92) * (1.0 - mare) + detail;

        // Apply illumination (no limb darkening — caused the blackout)
        vec3 litColor = moonColor * illumination;

        // Earthshine: faint blue on dark side
        litColor += vec3(0.02, 0.025, 0.04) * (1.0 - illumination);

        // Gamma, no fog
        frag_color = vec4(finalize(litColor), 1.0);
        return;

    } else if (u_proc_mode == 11) {
        // Pool / fountain water — clear basin water, no ocean shoreline fade.
        albedo = computePoolWater(N, V, L, alpha, roughness);

        float fogDist = length(v_worldPos - u_camera_pos);
        float fogFactor = clamp((fogDist - u_fog_near) / (u_fog_far - u_fog_near), 0.0, 1.0);
        fogFactor = 1.0 - exp(-fogFactor * fogFactor * 3.0);
        vec3 color = mix(albedo, u_fog_color, fogFactor);
        color = finalize(color);
        frag_color = vec4(color, alpha);
        return;

    } else if (u_proc_mode == 12) {
        // UNLIT TEXTURED / HOLOGRAM. The panel is its own light source: emit the
        // albedo directly with no ambient/directional/emission/shadow/fog, so a
        // learning board reads identically day and night and never washes out in
        // bright sun. NOTE (OpenGL backend limitation): this shader variant has
        // NO u_albedo_texture sampler — the baked text texture is sampled only on
        // the vio/D3D12 path (vio/mesh3d.frag.glsl). Here proc_mode 12 can only
        // emit the flat material albedo (u_albedo), so on OpenGL the hologram
        // panel shows as a solid unlit pane WITHOUT the baked text. finalize()
        // applies the same gamma/exposure convention as the other branches.
        frag_color = vec4(finalize(u_albedo), alpha);
        return;

    } else if (u_proc_mode == 10) {
        // Carpaint: metallic flakes + clearcoat. The base albedo from the
        // material drives the paint colour; the standard PBR loop below
        // computes diffuse / direct specular, and the post-loop section
        // adds the clearcoat lobe and the environment reflection.
        float nse = noise(v_worldPos.xz * 0.4);
        albedo = u_albedo * (1.0 + (nse - 0.5) * 0.04);
        // Flake-driven micro normal — only when flakes > 0.
        N = perturbNormalFlakes(N, u_flakes * u_normal_intensity);
    } else {
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
    int pointCount = min(u_point_light_count, 32);
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

    // Submerged fragments (below the water surface) get a depth-based
    // blue-green absorption tint so a sunken harbour reads as underwater.
    // Applied on the LINEAR colour before finalize(), matching the vio mirror
    // which tints before outputColor()'s tonemap/gamma.
    color = applyUnderwaterTint(color, v_worldPos);

    // Gamma correction
    color = finalize(color);

    frag_color = vec4(color, alpha);
}
