#version 410 core

in vec3 v_normal;
in vec3 v_worldPos;
in vec2 v_uv;
in vec4 v_lightSpacePos;
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
uniform DirLight u_dir_lights[4];
uniform int u_dir_light_count;

#define u_dir_light_direction u_dir_lights[0].direction
#define u_dir_light_color u_dir_lights[0].color
#define u_dir_light_intensity u_dir_lights[0].intensity

// Member order matters: each trailing float packs into the tail of the
// preceding vec3's 16-byte slot, giving a clean 32-byte stride that both
// std140 and HLSL's natural cbuffer packing agree on. The previous
// ordering (vec3 pos, vec3 color, float intensity, float radius) ends up
// ambiguous — SPIRV-Cross rejects it with "cannot be expressed with
// either HLSL packing layout or packoffset".
struct PointLight {
    vec3 position;
    float radius;
    vec3 color;
    float intensity;
};
uniform PointLight u_point_lights[4];
uniform int u_point_light_count;

// Same packing discipline as PointLight: every vec3 is paired with a trailing
// float so each member lands on a clean 16-byte boundary that both std140 and
// HLSL cbuffer packing agree on (SPIRV-Cross rejects ambiguous layouts).
struct SpotLight {
    vec3 position;
    float range;
    vec3 direction;
    float angle;      // cone half-angle (radians)
    vec3 color;
    float intensity;
    float penumbra;   // soft-edge fraction 0..1
};
uniform SpotLight u_spot_lights[4];
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
uniform int   u_normal_pattern;
uniform float u_normal_scale;
uniform int   u_surface_pattern;
uniform float u_surface_scale;
uniform float u_surface_intensity;
uniform float u_wetness;
uniform vec3  u_subsurface_color;
uniform float u_subsurface_strength;
uniform float u_ssr_intensity;
// 1 when the real ray-marched SSR pass owns reflections this frame (VIO/D3D12,
// HDR path). When 1 the forward wetness SURROGATE below is suppressed so the two
// don't double-apply; when 0 (tier Off, or non-D3D / non-HDR) the surrogate is
// the only reflection cue and stays active. Mirrors u_ssao_enabled.
uniform int   u_ssr_enabled;
uniform int   u_volumetric_fog;
uniform float u_ao_strength;
uniform vec3  u_grade_lift;
uniform vec3  u_grade_gamma;
uniform vec3  u_grade_gain;
uniform float u_grade_saturation;
uniform float u_vignette_intensity;
uniform vec2  u_viewport_size;
uniform float u_snow_cover; // 0.0 = no snow, 1.0 = full blanket
uniform float u_rain_wetness; // 0.0 = dry, 1.0 = rain-soaked
uniform vec3 u_fog_color;
uniform float u_fog_near;
uniform float u_fog_far;
uniform vec3 u_camera_pos;
uniform float u_time;
uniform int u_proc_mode;

uniform vec3 u_sky_color;
uniform vec3 u_horizon_color;

uniform float u_moon_phase;
uniform vec3 u_season_tint;

// HDR pipeline
uniform int u_linear_output;

// Shadow
uniform int u_has_shadow_map;
uniform sampler2DShadow u_shadow_map;

// Cascade Shadow Maps (mirrors mesh3d.frag.glsl).
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

// Texture
uniform int u_has_albedo_texture;
uniform sampler2D u_albedo_texture;

// Screen-space ambient occlusion (real depth+normal SSAO; see ssao.frag.glsl).
// u_ssao_map is the SECOND regular sampler declared after u_albedo_texture, so
// SPIRV-Cross assigns it HLSL register t1 (regular samplers count 0,1,2..; the
// depth/shadow samplers above occupy t4..t7). The renderer binds the blurred
// AO texture (or a 1x1 white texture when AO is disabled) at the GL slot wired
// to this sampler — never left unbound, which would read as an empty SRV on
// D3D12 (the classic dark-disc failure mode). u_ssao_enabled gates the sample
// so behaviour is unchanged at Off/Low/downgraded tiers.
uniform int u_ssao_enabled;
uniform sampler2D u_ssao_map;
// Retained for ABI/uniform-reflection stability (the renderer still uploads it)
// but NO LONGER read: the AO map is sampled directly by normalised gl_FragCoord
// with no v flip — see the AO sample site for why a per-backend flip here was
// wrong (it double-counted postprocess.vert's pre-flip).
uniform float u_ssao_uv_flip_y;

out vec4 frag_color;

// ================================================================
//  Noise — lightweight
// ================================================================

float hash21(vec2 p) {
    return fract(sin(dot(p, vec2(127.1, 311.7))) * 43758.5453);
}

float hash31(vec3 p) {
    return fract(sin(dot(p, vec3(443.897, 441.423, 437.195))) * 43758.5453);
}

float noise(vec2 p) {
    vec2 i = floor(p);
    vec2 f = fract(p);
    f = f * f * (3.0 - 2.0 * f);
    return mix(mix(hash21(i), hash21(i + vec2(1,0)), f.x),
               mix(hash21(i + vec2(0,1)), hash21(i + vec2(1,1)), f.x), f.y);
}

float fbm2(vec2 p) {
    return noise(p) * 0.5 + noise(p * 2.0) * 0.25 + 0.25;
}

float fbm3(vec2 p) {
    return noise(p) * 0.5 + noise(p * 2.0) * 0.25 + noise(p * 4.0) * 0.125 + 0.125;
}

// ================================================================
//  Volumetric cloud shadow — mirrors sky_clouds.frag's density field so the
//  shadow patches on the ground line up with the clouds drifting overhead.
// ================================================================

uniform float u_cloud_cover;
uniform float u_cloud_altitude;
uniform float u_cloud_density;
uniform float u_cloud_wind_speed;
uniform vec2  u_cloud_wind_dir;
uniform float u_cloud_time;   // = sky pass's u_time, so shadows track the clouds

float cloudHash13(vec3 p) {
    p = fract(p * 0.1031);
    p += dot(p, p.yzx + 33.33);
    return fract((p.x + p.y) * p.z);
}

float cloudVnoise3(vec3 p) {
    vec3 i = floor(p);
    vec3 f = fract(p);
    f = f * f * (3.0 - 2.0 * f);
    float n000 = cloudHash13(i),               n100 = cloudHash13(i + vec3(1, 0, 0));
    float n010 = cloudHash13(i + vec3(0,1,0)), n110 = cloudHash13(i + vec3(1, 1, 0));
    float n001 = cloudHash13(i + vec3(0,0,1)), n101 = cloudHash13(i + vec3(1, 0, 1));
    float n011 = cloudHash13(i + vec3(0,1,1)), n111 = cloudHash13(i + vec3(1, 1, 1));
    return mix(mix(mix(n000, n100, f.x), mix(n010, n110, f.x), f.y),
               mix(mix(n001, n101, f.x), mix(n011, n111, f.x), f.y), f.z);
}

float cloudFbm3(vec3 p) {
    float total = 0.0, amp = 0.5;
    for (int i = 0; i < 4; i++) { total += cloudVnoise3(p) * amp; p *= 2.03; amp *= 0.5; }
    return total;
}

float cloudDensityAt(vec3 p) {
    float h = (p.y - u_cloud_altitude) / 30.0;   // SLAB_THICK matches the sky pass
    if (h < 0.0 || h > 1.0) return 0.0;
    float vGrad = smoothstep(0.0, 0.18, h) * smoothstep(1.0, 0.55, h);
    vec3 wp = p * 0.0026;
    wp.xz += u_cloud_wind_dir * (u_cloud_time * u_cloud_wind_speed * 0.0026);
    float n = cloudFbm3(wp);
    float cov = 1.0 - u_cloud_cover * 0.9;
    return smoothstep(cov, cov + 0.22, n) * vGrad * u_cloud_density;
}

// Sun transmittance through the cloud slab from a world point (1 = unshadowed,
// → 0 fully under a cloud). Marches a few steps toward the directional light.
float cloudShadow(vec3 worldPos) {
    if (u_cloud_cover <= 0.0) return 1.0;
    vec3 L = normalize(-u_dir_light_direction); // toward the light source
    if (L.y <= 0.05) return 1.0;                 // light at/below the horizon
    float base = u_cloud_altitude;
    float top  = base + 30.0;
    float tEnter = max((base - worldPos.y) / L.y, 0.0);
    float tExit  = (top - worldPos.y) / L.y;
    if (tExit <= tEnter) return 1.0;
    float stepLen = (tExit - tEnter) / 5.0;
    float dens = 0.0;
    for (int i = 0; i < 5; i++) {
        dens += cloudDensityAt(worldPos + L * (tEnter + (float(i) + 0.5) * stepLen));
    }
    // Extinction: 1 = clear path to the sun, → 0 fully under a cloud. The
    // consumer floors the direct beam (it never reaches 0), so a passing cloud
    // dims the world without blacking it out — the bright sky still fills via
    // ambient. Storm darkness is applied globally instead. ×1.2 extinction so
    // even thin clouds register as a visible dimming sweep.
    return clamp(exp(-dens * stepLen * 1.2), 0.0, 1.0);
}

// ================================================================
//  Shadow
// ================================================================

// Sample a single cascade with PCF 3x3.
float sampleCascade(sampler2DShadow map, mat4 lightSpace, vec3 worldPos, vec3 N) {
    vec4 lsp = lightSpace * vec4(worldPos, 1.0);
    vec3 pc  = lsp.xyz / lsp.w * 0.5 + 0.5;
    if (pc.x < 0.0 || pc.x > 1.0 || pc.y < 0.0 || pc.y > 1.0 || pc.z > 1.0) return 1.0;
    vec3 lightDir = normalize(-u_dir_light_direction);
    float NdotL = max(dot(N, lightDir), 0.0);
    float bias = mix(0.005, 0.001, NdotL);
    float s = 0.0;
    float ts = 1.0 / 2048.0;
    float rd = pc.z - bias;
    for (int x = -1; x <= 1; x++)
        for (int y = -1; y <= 1; y++)
            s += texture(map, vec3(pc.xy + vec2(x,y) * ts, rd));
    return s / 9.0;
}

float calcShadow(vec4 lsp, vec3 N) {
    if (u_has_shadow_map == 0) return 1.0;
    // Pick the smallest CSM cascade still containing the fragment based
    // on distance to the camera (matches the per-cascade ortho extents
    // built in PHP land).
    float dist = length(v_worldPos - u_camera_pos);
    if (u_csm_count >= 2 && dist > u_csm_far_0) {
        if (u_csm_count >= 3 && dist > u_csm_far_1) {
            return sampleCascade(u_csm_map_2, u_csm_matrix_2, v_worldPos, N);
        }
        return sampleCascade(u_csm_map_1, u_csm_matrix_1, v_worldPos, N);
    }
    return sampleCascade(u_csm_map_0, u_csm_matrix_0, v_worldPos, N);
}

// ================================================================
//  PBR helpers
// ================================================================

vec3 fresnelSchlick(float cosTheta, vec3 F0) {
    return F0 + (1.0 - F0) * pow(clamp(1.0 - cosTheta, 0.0, 1.0), 5.0);
}

float distributionGGX(float NdotH, float a2) {
    float denom = NdotH * NdotH * (a2 - 1.0) + 1.0;
    return a2 / (3.14159265 * denom * denom);
}

float geometrySmith(float NdotV, float NdotL, float a2) {
    float k = a2 * 0.5;
    float ggxV = NdotV / (NdotV * (1.0 - k) + k);
    float ggxL = NdotL / (NdotL * (1.0 - k) + k);
    return ggxV * ggxL;
}

vec3 cookTorranceSpecular(vec3 N, vec3 V, vec3 L, float roughness, vec3 F0) {
    vec3 H = normalize(V + L);
    float NdotH = max(dot(N, H), 0.0);
    float NdotV = max(dot(N, V), 0.001);
    float NdotL = max(dot(N, L), 0.0);
    float HdotV = max(dot(H, V), 0.0);

    float a = roughness * roughness;
    float a2 = a * a;

    float D = distributionGGX(NdotH, a2);
    float G = geometrySmith(NdotV, NdotL, a2);
    vec3  F = fresnelSchlick(HdotV, F0);

    return (D * G * F) / max(4.0 * NdotV * NdotL, 0.001);
}

// Curvature-based AO (mirrors mesh3d.frag.glsl). Cheap per-fragment
// surrogate for SSAO until a depth-buffer pass lands.
float curvatureAO(vec3 N, float strength) {
    if (strength <= 0.0) return 1.0;
    vec3 ddxN = dFdx(N);
    vec3 ddyN = dFdy(N);
    float curvature = length(ddxN) + length(ddyN);
    // Gentler than before: only strong curvature occludes (0.4→0.7 threshold),
    // the effect is damped (×0.7) and floored at 0.5 so high-frequency geometry
    // (rocks, foliage) can't black out — the old curve drove bumpy rock surfaces
    // to ~0 at AO=High and read as "too dark stones".
    float occlusion = smoothstep(0.05, 0.7, curvature);
    return clamp(1.0 - occlusion * strength * 0.7, 0.5, 1.0);
}

// ACES filmic tonemap (Narkowicz). Matches the tonemap post-process so
// the visual response stays the same whether the HDR/Bloom path is on
// (mesh writes linear, post does ACES) or off (mesh tonemaps inline).
vec3 toneMapACES(vec3 x) {
    const float a = 2.51;
    const float b = 0.03;
    const float c = 2.43;
    const float d = 0.59;
    const float e = 0.14;
    return clamp((x * (a * x + b)) / (x * (c * x + d) + e), 0.0, 1.0);
}

vec3 volumetricScatter(vec3 worldPos) {
    if (u_volumetric_fog == 0 || u_dir_light_count == 0) return vec3(0.0);
    vec3 rayStart = u_camera_pos;
    vec3 rayEnd   = worldPos;
    vec3 rayDir   = rayEnd - rayStart;
    float rayLen  = length(rayDir);
    if (rayLen < 0.01) return vec3(0.0);
    rayDir /= rayLen;
    float marchLen = min(rayLen, u_fog_far);
    const int STEPS = 8;
    float step = marchLen / float(STEPS);
    vec3 sunDir = normalize(-u_dir_lights[0].direction);
    float cosTheta = dot(rayDir, sunDir);
    // Strongly forward-biased phase: a TINY omnidirectional floor (0.08) so the
    // effect is a sun-facing godray, not a uniform haze. The old 0.5 floor
    // scattered equally in every direction, adding a flat ~1+ additive white
    // wash over the whole frame ("alles blass"). The high-power lobe keeps the
    // visible scattering concentrated around the sun direction.
    float phase = 0.08 + pow(max(cosTheta, 0.0), 7.0) * 4.0;
    vec3 scatter = vec3(0.0);
    float transmittance = 1.0;
    for (int i = 0; i < STEPS; i++) {
        vec3 p = rayStart + rayDir * (step * (float(i) + 0.5));
        float density = exp(-max(p.y, 0.0) * 0.08) * 0.06;
        vec3 inscatter = u_dir_lights[0].color * u_dir_lights[0].intensity * phase * density;
        scatter += inscatter * transmittance * step;
        transmittance *= exp(-density * step);
    }
    // Scale + cap the integrated in-scatter. The raw march integrates to ~1+
    // (a full white wash); 0.3× brings the away-from-sun haze down to a faint
    // tint while the cap keeps the sun-facing godray from blowing out.
    return min(scatter * 0.3, vec3(0.25));
}

vec3 applyColorGrading(vec3 color) {
    color = color + u_grade_lift;
    // Guard against gamma component == 0 (uninitialized uniform → div by 0 →
    // pow(x, +inf) collapses every fragment to 0 → fully black screen).
    vec3 gammaSafe = max(u_grade_gamma, vec3(1e-3));
    color = pow(max(color, vec3(0.0)), vec3(1.0) / gammaSafe);
    color = color * u_grade_gain;
    float luma = dot(color, vec3(0.2126, 0.7152, 0.0722));
    return mix(vec3(luma), color, u_grade_saturation);
}

vec3 applyVignette(vec3 color) {
    if (u_vignette_intensity <= 0.0 || u_viewport_size.x <= 0.0) {
        return color;
    }
    vec2 uv = gl_FragCoord.xy / u_viewport_size;
    vec2 d  = uv - 0.5;
    float r = length(d);
    float v = smoothstep(0.45, 0.85, r);
    return color * (1.0 - v * u_vignette_intensity);
}

vec4 outputColor(vec3 color, float alpha) {
    color = max(color, vec3(0.0));
    if (u_linear_output == 0) {
        // Tonemap + gamma only. Colour grade + vignette moved to the present
        // pass (fxaa/passthrough) so they cover the whole frame — including the
        // sky — uniformly, instead of being baked per geometry fragment here.
        color = toneMapACES(color);
        color = pow(color, vec3(1.0 / 2.2));
    }
    return vec4(color, alpha);
}

// ================================================================
//  Procedural Sand (optimized)
// ================================================================

vec3 computeSand(vec3 N, vec3 V, vec3 L, out float roughOut) {
    // One unified sand layer. The base colour comes from the material's
    // albedo (u_albedo) and is modulated by per-pixel environmental state:
    //
    //   - shore wetness (from terrain UV.x — low = close to water)
    //   - global rain wetness (u_rain_wetness from the weather system)
    //   - cumulative wetness darkens the sand and smooths roughness
    //   - rain-driven puddles open up in low-lying areas
    //   - snow, footprint decals, sun glint stay on top of this base
    //
    // Zone-based colour bands (dry/mid/damp/dune) are gone — everything
    // comes from the environmental state feeding the shader.
    float zone = v_uv.x;

    // Grass zone (UV.x = 1.0): inland terrain is painted green here instead
    // of as a separate overlay mesh, so one terrain mesh covers shore sand
    // AND inland grass. TerrainMesh encodes a 'grass' material as UV.x = 1.0.
    if (zone > 0.9) {
        vec3 grassBase = vec3(0.368, 0.478, 0.220) * u_season_tint; // ~#5E7A38
        float g1 = fbm2(v_worldPos.xz * 0.8);          // broad sun/shadow patches
        float g2 = noise(v_worldPos.xz * 5.0);         // clumps
        float g3 = noise(v_worldPos.xz * 22.0);        // blade-scale speckle
        vec3 grass = grassBase;
        grass *= 0.80 + g1 * 0.34;
        grass *= 0.90 + (g2 - 0.5) * 0.26;
        grass *= 0.92 + (g3 - 0.5) * 0.20;
        // Sun-dried tips on raised clumps — a touch warmer/yellower.
        grass = mix(grass, grass * vec3(1.12, 1.06, 0.66), smoothstep(0.6, 0.95, g2) * 0.3);
        // Rain deepens the green.
        grass = mix(grass, grass * 0.72, u_rain_wetness * 0.4);
        roughOut = 0.95;
        return grass;
    }

    vec3 baseColor = u_albedo * u_season_tint;

    // Three noise octaves: broad patches, mid texture, individual grains.
    float n1 = fbm2(v_worldPos.xz * 1.5);
    float n2 = noise(v_worldPos.xz * 6.0);
    float n3 = noise(v_worldPos.xz * 28.0);    // grain-scale high frequency
    float n4 = hash21(floor(v_worldPos.xz * 80.0)); // per-pixel speckle

    vec3 sandColor = baseColor;
    sandColor *= 0.82 + n1 * 0.36;                 // broad light/dark patches
    sandColor *= 0.88 + (n2 - 0.5) * 0.22;         // mid-scale variation
    sandColor *= 0.85 + (n3 - 0.5) * 0.30;         // individual grains
    sandColor *= 0.95 + (n4 - 0.5) * 0.12;         // sharp speckle

    // Darker mineral specks sprinkled on top (quartz/feldspar/mica grains).
    float specks = smoothstep(0.82, 0.88, n4);
    sandColor *= 1.0 - specks * 0.35;

    // Crisp wind-ripples — stronger on dunes, faded near water.
    float ripple = sin(v_worldPos.x * 3.5 + v_worldPos.z * 1.8 + n1 * 2.0) * 0.5 + 0.5;
    ripple = smoothstep(0.3, 0.7, ripple);
    sandColor *= 1.0 - ripple * 0.09 * smoothstep(0.35, 0.9, zone);

    // Shore wetness: world-Y driven so ONLY sand at/below the water
    // surface is permanently damp. Intertidal zone (-0.1..+0.1) fades,
    // above +0.1 is bone dry (until rain). Using world Y means dune tops
    // and elevated back-beach stay dry, and only actual water-touching
    // surfaces look wet — regardless of the mesh's UV zone encoding.
    float shoreWet = 1.0 - smoothstep(-0.1, 0.15, v_worldPos.y);
    float wetness = max(shoreWet, u_rain_wetness);

    // Wet sand is darker + warmer-brown. Keep blue channel low.
    vec3 wetTint = baseColor * vec3(0.40, 0.32, 0.20);
    sandColor = mix(sandColor, wetTint, wetness * 0.7);

    // Puddles: low-frequency noise gated by wetness. Flat reflective patches
    // of water tint over wet sand. Only form where wetness is high enough.
    if (wetness > 0.35) {
        float puddleNoise = fbm2(v_worldPos.xz * 0.35);
        float puddleMask = smoothstep(0.52, 0.68, puddleNoise)
                         * smoothstep(0.35, 0.85, wetness);
        // Reflective puddle colour: picks up ambient + sunlight on surface.
        vec3 puddleColor = u_ambient_color * u_ambient_intensity * 0.6
                         + u_dir_lights[0].color * u_dir_lights[0].intensity * 0.25
                         + vec3(0.04, 0.07, 0.09);
        sandColor = mix(sandColor, puddleColor, puddleMask * 0.75);
    }

    // Subsurface scattering from low-angle sun (warm halo effect).
    float scatter = pow(max(dot(V, L), 0.0), 4.0) * 0.08;
    sandColor += vec3(0.15, 0.10, 0.04) * scatter * (1.0 - wetness * 0.5);

    // Roughness: dry sand is rough, wet/puddle is smooth (specular).
    roughOut = mix(0.92, 0.20, wetness);

    return sandColor;
}

// ================================================================
//  Procedural Water (optimized — 2 layers instead of 4)
// ================================================================

vec3 computeWater(vec3 N, vec3 V, vec3 L, out float alphaOut, out float roughOut) {
    vec2 uv1 = v_worldPos.xz * 0.8 + u_time * vec2(0.03, 0.02);
    vec2 uv2 = v_worldPos.xz * 2.5 + u_time * vec2(-0.02, 0.04);

    float eps = 0.08;
    float h1a = fbm2(uv1); float h1b = fbm2(uv1 + vec2(eps,0)); float h1c = fbm2(uv1 + vec2(0,eps));
    float h2a = noise(uv2); float h2b = noise(uv2 + vec2(eps,0)); float h2c = noise(uv2 + vec2(0,eps));

    vec3 waveNormal = vec3(0.0, 1.0, 0.0);
    waveNormal.x += (h1a - h1b) * 1.8 + (h2a - h2b) * 0.5;
    waveNormal.z += (h1a - h1c) * 1.8 + (h2a - h2c) * 0.5;
    waveNormal = normalize(waveNormal);

    N = normalize(N + waveNormal * vec3(1.0, 0.0, 1.0));

    float NdotV = max(dot(N, V), 0.0);
    float fresnel = pow(1.0 - NdotV, 5.0);
    fresnel = mix(0.02, 1.0, fresnel);

    // RADIAL depth/shore from the island centre (origin), NOT a fixed Z line.
    // The old `-8 - z` depth + `shoreEdge`-by-z faded the water to ZERO alpha
    // across the entire north/west half of the island (invisible — "half the
    // island not in the sea", with the seabed showing through). Radial distance
    // gives a correct shoreline and open water all the way around. Tutorial
    // Island is centred at the world origin; ~98 m shore radius, ~70 m to depth.
    float r = length(v_worldPos.xz);
    float depth = clamp((r - 98.0) / 70.0, 0.0, 1.0);

    vec3 waterColor = mix(vec3(0.15, 0.55, 0.50), vec3(0.02, 0.08, 0.15), depth);

    vec3 R = reflect(-V, N);
    float skyBlend = clamp(R.y * 2.0, 0.0, 1.0);
    vec3 reflectColor = mix(u_horizon_color, u_sky_color, skyBlend);

    // Sun reflection on the water. As the sun lowers, the rough surface spreads
    // its reflection into a broad, vertically-stretched glitter path (the "sun
    // road") rather than a single dot: narrow along the sun's azimuth, long
    // along its elevation, and wider the lower the sun sits.
    float sunLow = 1.0 - clamp(L.y * 3.0, 0.0, 1.0);   // 0 = high sun, 1 = horizon
    vec3  dRL    = R - L;
    float roadW  = mix(0.0015, 0.012, sunLow);          // azimuth width
    float roadL  = mix(0.040,  0.300, sunLow);          // elevation length
    float road   = exp(-(dRL.x * dRL.x + dRL.z * dRL.z) / roadW)
                 * exp(-(dRL.y * dRL.y) / roadL);
    float core   = pow(max(dot(R, L), 0.0), 200.0);     // tight bright sun core
    float sunRefl = clamp(core * 2.0 + road * (0.5 + 1.2 * sunLow), 0.0, 1.0);
    reflectColor = mix(reflectColor, u_dir_light_color, sunRefl);

    vec3 finalColor = mix(waterColor, reflectColor, fresnel);

    // Broad soft sheen so the lit water isn't *only* the road.
    float specWater = pow(max(dot(N, normalize(V + L)), 0.0), 160.0);
    finalColor += u_dir_light_color * u_dir_light_intensity * specWater * 1.2;

    // Shore foam — multi-layered, animated, finer grain (radial shoreline)
    float shoreDepth = clamp((r - 98.0) / 8.0, 0.0, 1.0);
    float foamLine = smoothstep(0.15, 0.0, shoreDepth);
    float foamNoise = noise(v_worldPos.xz * 15.0 + u_time * 0.3) * 0.5
                    + noise(v_worldPos.xz * 30.0 - u_time * 0.5) * 0.3
                    + noise(v_worldPos.xz * 60.0 + u_time * 0.8) * 0.2;
    float foam = foamLine * smoothstep(0.25, 0.55, foamNoise);
    // Breaking wave foam band
    float waveFoam = smoothstep(0.08, 0.0, shoreDepth) * smoothstep(0.4, 0.7,
        noise(vec2(v_worldPos.x * 3.0, u_time * 0.6)));
    foam = max(foam, waveFoam);
    finalColor = mix(finalColor, vec3(0.92, 0.96, 1.0), foam * 0.8);

    // Alpha: transparent at shore (sand visible), opaque in deep water
    alphaOut = mix(0.15, 0.95, smoothstep(0.0, 0.4, depth));
    alphaOut = mix(alphaOut, 1.0, foam * 0.6);

    // Fade the water in just past the radial shoreline (where it meets sand).
    float shoreEdge = smoothstep(92.0, 100.0, r);
    alphaOut *= shoreEdge;

    roughOut = mix(0.02, 0.08, foam);

    return finalColor;
}

// ================================================================
//  Procedural Pool / Fountain Water (proc_mode 11)
//  Small raised basins (fountains, ponds). Shares the ocean's wave-normal
//  shimmer, fresnel sky reflection and sun specular, but deliberately has
//  NO radial depth/shoreline/foam — that model is the OCEAN's (centred at
//  the island origin) and would blank out any water near the centre, e.g.
//  the plaza fountain at r≈40 m. Finer ripples suit a small surface.
// ================================================================

vec3 computePoolWater(vec3 N, vec3 V, vec3 L, out float alphaOut, out float roughOut) {
    // Three ripple layers — coarse swell + chop + fine sparkle — for a lively
    // small-scale surface suited to a basin.
    vec2 uv1 = v_worldPos.xz * 1.8  + u_time * vec2(0.05, 0.04);
    vec2 uv2 = v_worldPos.xz * 5.0  + u_time * vec2(-0.06, 0.08);
    vec2 uv3 = v_worldPos.xz * 11.0 + u_time * vec2(0.09, -0.07);

    float eps = 0.05;
    float h1a = fbm2(uv1); float h1b = fbm2(uv1 + vec2(eps,0)); float h1c = fbm2(uv1 + vec2(0,eps));
    float h2a = noise(uv2); float h2b = noise(uv2 + vec2(eps,0)); float h2c = noise(uv2 + vec2(0,eps));
    float h3a = noise(uv3); float h3b = noise(uv3 + vec2(eps,0)); float h3c = noise(uv3 + vec2(0,eps));

    vec3 waveNormal = vec3(0.0, 1.0, 0.0);
    waveNormal.x += (h1a - h1b) * 1.4 + (h2a - h2b) * 0.6 + (h3a - h3b) * 0.25;
    waveNormal.z += (h1a - h1c) * 1.4 + (h2a - h2c) * 0.6 + (h3a - h3c) * 0.25;
    waveNormal = normalize(waveNormal);

    N = normalize(N + waveNormal * vec3(1.0, 0.0, 1.0));

    float NdotV = max(dot(N, V), 0.0);
    float fresnel = mix(0.04, 1.0, pow(1.0 - NdotV, 5.0));

    // Clear shallow water over a dark wet basin floor: top-down shows the floor,
    // grazing angles pick up the body tint and the sky reflection.
    vec3 floorColor = vec3(0.04, 0.16, 0.18);
    vec3 bodyColor  = vec3(0.10, 0.42, 0.48);
    vec3 waterColor = mix(floorColor, bodyColor, fresnel);

    vec3 R = reflect(-V, N);
    float skyBlend = clamp(R.y * 2.0, 0.0, 1.0);
    vec3 reflectColor = mix(u_horizon_color, u_sky_color, skyBlend);
    reflectColor = mix(reflectColor, u_dir_light_color, pow(max(dot(R, L), 0.0), 256.0) * 2.0);

    vec3 finalColor = mix(waterColor, reflectColor, fresnel);

    // Sun glints: a tight sparkle the ripples break up, plus a softer sheen.
    vec3 H = normalize(V + L);
    float specTight = pow(max(dot(N, H), 0.0), 600.0);
    float specSoft  = pow(max(dot(N, H), 0.0), 80.0) * 0.25;
    finalColor += u_dir_light_color * u_dir_light_intensity * (specTight * 2.5 + specSoft);

    // Caustic shimmer — animated bright filaments, like focused sunlight on the
    // basin floor; strongest where the water is clearest (viewed top-down).
    float cs1 = noise(v_worldPos.xz * 6.0 + u_time * 0.6);
    float cs2 = noise(v_worldPos.xz * 6.0 - u_time * 0.45 + 23.0);
    float caustic = pow(max(0.0, 1.0 - abs(cs1 - cs2) * 2.5), 3.0);
    finalColor += vec3(0.12, 0.22, 0.20) * caustic * (1.0 - fresnel);

    // See-through from above (floor visible), reflective at grazing angles.
    alphaOut = mix(0.45, 0.95, fresnel);
    roughOut = 0.03;

    return finalColor;
}

// ================================================================
//  Procedural Rock (optimized)
// ================================================================

vec3 computeRock(vec3 N, vec3 worldPos, vec3 baseAlbedo, out float roughOut) {
    vec3 p = worldPos * 2.5;
    float n1 = fbm2(p.xz);

    vec3 rockColor = baseAlbedo * (0.85 + n1 * 0.3);

    float crack = noise(p.xz * 8.0 + vec2(p.y * 2.0));
    rockColor *= 1.0 - smoothstep(0.48, 0.52, crack) * 0.15;

    float strata = sin(worldPos.y * 15.0 + n1 * 3.0) * 0.5 + 0.5;
    rockColor *= 0.95 + smoothstep(0.4, 0.6, strata) * 0.1;

    roughOut = 0.75 + noise(p.xz * 3.0) * 0.2;

    return rockColor;
}

// ================================================================
//  Procedural Palm Trunk (optimized)
// ================================================================

vec3 computePalmTrunk(vec3 worldPos, vec3 baseAlbedo, out float roughOut) {
    // Rings and fiber are locked to the cylinder's local axis via v_uv:
    // uv.x = angle around trunk (0..1), uv.y = height along segment (0..1).
    // This keeps scars perpendicular to the trunk even when the trunk leans
    // or curves, and stops the fiber noise from sliding when the trunk sways.
    float ring = smoothstep(0.3, 0.7, sin(v_uv.y * 6.2831 * 1.2) * 0.5 + 0.5);
    float fiber = noise(vec2(v_uv.x * 20.0, v_uv.y * 4.0));

    vec3 barkColor = mix(baseAlbedo * 0.65, baseAlbedo * 1.2, ring * 0.6 + fiber * 0.4);
    barkColor *= 0.85 + ring * 0.3;

    // Weathering still uses world-space so neighbouring trunks differ.
    float weather = noise(worldPos.xz * 5.0);
    barkColor = mix(barkColor, barkColor * vec3(0.85, 0.9, 0.8), weather * 0.2);

    roughOut = 0.85 + ring * 0.1;
    return barkColor;
}

// ================================================================
//  Procedural Palm Leaf (optimized)
// ================================================================

vec3 computePalmLeaf(vec3 worldPos, vec3 N, vec3 V, vec3 L, vec3 baseAlbedo, out float roughOut) {
    // PalmFrondMesh UVs:
    //   uv.y = distance along the frond (0 at rachis base → 1 at tip)
    //   uv.x = sideways from spine (0.5 on spine, ±0.5 at leaflet tips)
    // Using UV locks the pattern to the leaf geometry even when the frond
    // rotates and sways in the wind.
    float sideways = (v_uv.x - 0.5) * 2.0; // -1..1

    // Veins run outward from the spine (parallel to leaflet length). Dense
    // stripes along uv.x direction.
    float vein = smoothstep(0.0, 0.15, abs(sin(sideways * 18.0)));

    // Base variation — broad patches that follow the leaf surface.
    float n = fbm2(v_uv * 8.0);
    vec3 leafColor = baseAlbedo * (0.8 + n * 0.4);
    leafColor = mix(leafColor * 1.3, leafColor, vein);

    // Age gradient: young green at base, yellow/brown at tips.
    vec3 tipTint = vec3(0.55, 0.45, 0.18);
    float age = smoothstep(0.6, 1.0, v_uv.y);
    leafColor = mix(leafColor, leafColor * tipTint * 1.4, age * 0.35);

    // Edge darkening — strongest near leaflet outer edges (|sideways| ≈ 1).
    float edgeNoise = noise(v_uv * 12.0);
    float edgeMask = smoothstep(0.6, 1.0, abs(sideways));
    leafColor = mix(leafColor, vec3(0.4, 0.35, 0.15), edgeMask * edgeNoise * 0.25);

    // Translucent back-lighting when sun shines through the leaf.
    leafColor += vec3(0.1, 0.2, 0.02) * pow(max(dot(-N, L), 0.0), 2.0) * 0.3;
    leafColor += vec3(0.05, 0.1, 0.02) * pow(max(dot(V, L), 0.0), 3.0) * 0.1;

    roughOut = 0.6 + edgeNoise * 0.15;
    return leafColor;
}

// ================================================================
//  Procedural Wood Planks (optimized)
// ================================================================

vec3 computeWoodPlanks(vec3 N, vec3 worldPos, vec3 baseAlbedo, out float roughOut) {
    // Plank orientation follows the face's local normal so planks rotate with
    // the mesh and adapt to floors/walls/ceilings automatically:
    //   - horizontal face (|N.y| dominant) → planks laid flat, rows stacked in Z
    //   - wall facing X (|N.x| dominant)   → planks stacked vertically, grain along Z
    //   - wall facing Z (|N.z| dominant)   → planks stacked vertically, grain along X
    //
    // Local-space coordinates are scaled back to world distance via v_objectScale
    // so the plank density stays consistent across differently scaled meshes.
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

    float gap = smoothstep(0.0, 0.03, withinPlank) * smoothstep(1.0, 0.97, withinPlank);
    float plankHash = hash21(vec2(plankIndex * 17.3, plankIndex * 7.1));

    vec3 woodColor = baseAlbedo * (0.8 + plankHash * 0.4);

    float offsetGrain = grainCoord + plankHash * 20.0;
    float grain = sin(offsetGrain + noise(vec2(offsetGrain * 0.5, plankIndex)) * 3.0) * 0.5 + 0.5;
    woodColor *= 0.9 + grain * 0.15;

    woodColor *= gap * 0.85 + 0.15;
    // Broad colour variation in world space — keeps neighbouring panels from looking identical.
    woodColor *= 0.85 + noise(worldPos.xz * 3.0 + worldPos.y * 2.0) * 0.2;

    roughOut = 0.78 + plankHash * 0.15;
    return woodColor;
}

// ================================================================
//  Procedural Thatch (optimized)
// ================================================================

vec3 computeThatch(vec3 N, vec3 worldPos, vec3 baseAlbedo, out float roughOut) {
    // Strand direction is locked to the local mesh: fibres always run along
    // the roof's local X axis (down-slope), regardless of the hut's yaw.
    // Using local-space × object-scale keeps the density consistent when the
    // roof is scaled non-uniformly.
    vec3 scaledLocal = v_localPos * v_objectScale;
    float strandAngle = scaledLocal.x * 12.0 + scaledLocal.z * 6.0 + scaledLocal.y * 4.0;
    float strand1 = sin(strandAngle) * 0.5 + 0.5;

    vec3 strawColor = baseAlbedo * (0.75 + fbm2(scaledLocal.xz * 5.0 + scaledLocal.y * 3.0) * 0.5);
    strawColor += vec3(0.1, 0.08, 0.02) * pow(strand1, 8.0);

    // Weathering varies in world space so adjacent thatch panels look different.
    float age = noise(worldPos.xz * 8.0);
    strawColor = mix(strawColor, strawColor * 0.6, smoothstep(0.7, 0.9, age) * 0.4);

    roughOut = 0.92;
    return strawColor;
}

// ================================================================
//  Procedural Cloud (optimized)
// ================================================================

vec3 computeCloud(vec3 N, vec3 V, vec3 L, vec3 baseAlbedo, out float alphaOut) {
    float NdotL = max(dot(N, L), 0.0);
    vec3 cloudColor = mix(vec3(0.6, 0.65, 0.72), vec3(1.0, 0.98, 0.95), NdotL * 0.7 + 0.3);

    float scatter = pow(max(dot(V, L), 0.0), 3.0);
    cloudColor += vec3(0.3, 0.25, 0.15) * scatter * 0.4;
    cloudColor += vec3(0.5, 0.5, 0.4) * pow(1.0 - max(dot(N, V), 0.0), 3.0) * scatter * 0.6;

    cloudColor *= 0.9 + noise(v_worldPos.xz * 0.3) * 0.2;

    alphaOut = pow(max(dot(N, V), 0.0), 0.8) * 0.85;
    return cloudColor;
}

// ================================================================
//  Procedural Normal Maps (mirrors mesh3d.frag.glsl)
// ================================================================

vec3 np_bricks(vec2 uv) {
    vec2 cell = vec2(0.5, 1.0);
    float rowIndex = floor(uv.y / cell.y);
    float xOffset = mod(rowIndex, 2.0) * 0.5 * cell.x;
    vec2 local = vec2(fract((uv.x + xOffset) / cell.x),
                      fract(uv.y / cell.y));
    float mortarX = 1.0 - (smoothstep(0.0, 0.06, local.x) *
                           smoothstep(1.0, 0.94, local.x));
    float mortarY = 1.0 - (smoothstep(0.0, 0.06, local.y) *
                           smoothstep(1.0, 0.94, local.y));
    float groove = max(mortarX, mortarY);
    vec2 slope = vec2(mortarX, mortarY) *
                 vec2(local.x < 0.5 ? 1.0 : -1.0,
                      local.y < 0.5 ? 1.0 : -1.0);
    return normalize(vec3(slope * 0.6, 1.0 - groove * 0.5));
}

vec3 np_bumps(vec2 uv) {
    float e = 0.05;
    float h  = noise(uv * 8.0);
    float hx = noise(uv * 8.0 + vec2(e, 0.0));
    float hy = noise(uv * 8.0 + vec2(0.0, e));
    vec2 grad = vec2(hx - h, hy - h) / e;
    return normalize(vec3(-grad * 0.4, 1.0));
}

vec3 np_orange_peel(vec2 uv) {
    vec2 p = uv * 60.0;
    float h  = hash21(floor(p));
    float hx = hash21(floor(p) + vec2(1.0, 0.0));
    float hy = hash21(floor(p) + vec2(0.0, 1.0));
    return normalize(vec3((h - hx) * 0.6, (h - hy) * 0.6, 1.0));
}

vec3 np_hammered(vec2 uv) {
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
    vec2 p = uv * 5.0;
    vec2 a = vec2(p.x + p.y * 0.5, p.y * 0.866);
    vec2 af = fract(a) - 0.5;
    vec2 slope = -af * 1.2;
    float edge = smoothstep(0.45, 0.50, max(abs(af.x), abs(af.y)));
    return normalize(vec3(slope * (1.0 - edge), 1.0 - edge * 0.4));
}

vec3 np_wood_grain(vec2 uv) {
    float grad = cos(uv.y * 80.0 + noise(uv * vec2(20.0, 4.0)) * 6.0) * 80.0;
    float slopeY = grad * 0.005;
    return normalize(vec3(0.0, slopeY, 1.0));
}

vec3 np_scratches(vec2 uv) {
    float rotated = uv.x * 0.97 + uv.y * 0.24;
    float across  = -uv.x * 0.24 + uv.y * 0.97;
    float lane = floor(across * 80.0);
    float laneJitter = hash21(vec2(lane, 0.0));
    float scratch = sin((rotated + laneJitter * 6.28) * 30.0);
    float mask = step(0.6, hash21(vec2(lane, 13.0)));
    return normalize(vec3(scratch * mask * 0.5, 0.0, 1.0));
}

vec3 np_cracked(vec2 uv) {
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

vec3 np_noise_pattern(vec2 uv) {
    float e = 0.04;
    float h  = fbm3(uv * 6.0);
    float hx = fbm3(uv * 6.0 + vec2(e, 0.0));
    float hy = fbm3(uv * 6.0 + vec2(0.0, e));
    vec2 grad = vec2(hx - h, hy - h) / e;
    return normalize(vec3(-grad * 0.5, 1.0));
}

// Skin micro-relief: medium-scale pore noise + slow wrinkle FBM. The
// deflection is kept small (0.06) so a fullscreen flat surface looks
// like skin under raking light, not like a topographic map. Tune
// u_normal_intensity from game code to push harder.
vec3 np_skin(vec2 uv) {
    float e = 0.02;
    float h  = noise(uv * 14.0) * 0.55 + fbm3(uv * 4.0) * 0.45;
    float hx = noise((uv + vec2(e, 0.0)) * 14.0) * 0.55
             + fbm3((uv + vec2(e, 0.0)) * 4.0) * 0.45;
    float hy = noise((uv + vec2(0.0, e)) * 14.0) * 0.55
             + fbm3((uv + vec2(0.0, e)) * 4.0) * 0.45;
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
    if (code == 9)  return np_noise_pattern(uv);
    if (code == 10) return np_skin(uv);
    return vec3(0.0, 0.0, 1.0);
}

// ── Procedural Surface-Wear (mirrors mesh3d.frag.glsl) ────────────────────────

vec3 sp_worn_paint(vec2 uv) {
    float wear = fbm3(uv * 3.0);
    float chip = step(0.55, wear);
    float albedoT = mix(0.50, 0.30, chip);
    float roughD  = mix(0.0,  0.35,  chip);
    float metalD  = mix(0.0,  0.55,  chip);
    return vec3(albedoT, roughD, metalD);
}

vec3 sp_rust(vec2 uv) {
    float spotty = fbm3(uv * 5.0);
    float rust   = smoothstep(0.45, 0.65, spotty);
    float albedoT = mix(0.50, 0.62, rust);
    float roughD  = mix(0.0,  0.45,  rust);
    float metalD  = mix(0.0, -0.50,  rust);
    return vec3(albedoT, roughD, metalD);
}

vec3 sp_brushed_metal(vec2 uv) {
    float lane = sin(uv.y * 600.0);
    return vec3(0.50, lane * 0.10, 0.0);
}

vec3 sp_polished_rings(vec2 uv) {
    vec2 c = uv - 0.5;
    float r = length(c);
    float ring = sin(r * 80.0);
    float matte = smoothstep(0.0, 0.4, ring);
    return vec3(0.50, matte * 0.50 - 0.10, 0.0);
}

// Skin freckles + blotchy pigmentation. Two smoothstep gates instead of a
// binary `step()` so freckles fade in/out rather than tiling like an
// animal print. The pigment lift is small (~12 % albedo darkening at max
// intensity) - the goal is mottled skin, not warpaint.
vec3 sp_skin(vec2 uv) {
    float blotchy = fbm3(uv * 1.5);
    float fine    = fbm3(uv * 5.0);
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

vec3 perturbNormalProcedural(vec3 N, vec3 worldPos, vec2 uv,
                             int patternCode, float patternScale,
                             float intensity) {
    if (patternCode == 0 || intensity <= 0.0) return N;
    vec3 dpx = dFdx(worldPos);
    vec3 dpy = dFdy(worldPos);
    vec2 duvx = dFdx(uv);
    vec2 duvy = dFdy(uv);
    float det = duvx.x * duvy.y - duvy.x * duvx.y;
    if (abs(det) < 1e-8) return N;
    vec3 T = (dpx * duvy.y - dpy * duvx.y) / det;
    T = normalize(T - N * dot(N, T));
    vec3 B = normalize(cross(N, T));
    mat3 TBN = mat3(T, B, N);

    vec3 nMap = dispatchProceduralNormal(patternCode, uv * patternScale);
    nMap = mix(vec3(0.0, 0.0, 1.0), nMap, clamp(intensity, 0.0, 4.0));
    return normalize(TBN * nMap);
}

// ================================================================
//  Main
// ================================================================

void main() {
    vec3 N = normalize(v_normal);
    // View-facing normal for specular/fresnel (flipped for back faces)
    vec3 Nv = gl_FrontFacing ? N : -N;

    vec3 V = normalize(u_camera_pos - v_worldPos);
    vec3 L = normalize(-u_dir_light_direction);

    float roughness = clamp(u_roughness, 0.04, 1.0);
    float metallic  = u_metallic;
    float alpha = u_alpha;
    vec3 albedo;

    vec3 texAlbedo = u_albedo;
    if (u_has_albedo_texture == 1) {
        texAlbedo *= texture(u_albedo_texture, v_uv).rgb;
    }

    // ---- Material selection ----
    if (u_proc_mode == 2) {
        albedo = computeWater(N, V, L, alpha, roughness);
        float fd = length(v_worldPos - u_camera_pos);
        float ff = clamp((fd - u_fog_near) / (u_fog_far - u_fog_near), 0.0, 1.0);
        frag_color = outputColor(mix(albedo, u_fog_color, 1.0 - exp(-ff*ff*3.0)), alpha);
        return;
    } else if (u_proc_mode == 1) {
        albedo = computeSand(N, V, L, roughness);
    } else if (u_proc_mode == 3) {
        albedo = computeRock(N, v_worldPos, u_albedo, roughness);
    } else if (u_proc_mode == 4) {
        albedo = computePalmTrunk(v_worldPos, u_albedo, roughness);
    } else if (u_proc_mode == 5) {
        albedo = computePalmLeaf(v_worldPos, N, V, L, u_albedo, roughness);
    } else if (u_proc_mode == 7) {
        albedo = computeWoodPlanks(N, v_worldPos, u_albedo, roughness);
    } else if (u_proc_mode == 8) {
        albedo = computeThatch(N, v_worldPos, u_albedo, roughness);
    } else if (u_proc_mode == 6) {
        albedo = computeCloud(N, V, L, u_albedo, alpha);
        float fd = length(v_worldPos - u_camera_pos);
        float ff = clamp((fd - u_fog_near) / (u_fog_far - u_fog_near), 0.0, 1.0);
        frag_color = outputColor(mix(albedo, u_fog_color, 1.0 - exp(-ff*ff*3.0)), alpha);
        return;
    } else if (u_proc_mode == 9) {
        vec3 moonN = normalize(N);
        vec3 vUp = abs(V.y) > 0.99 ? vec3(0.0, 0.0, 1.0) : vec3(0.0, 1.0, 0.0);
        float localX = dot(moonN, normalize(cross(V, vUp)));
        float tp = cos(u_moon_phase * 6.28318);
        float illum = smoothstep(tp - 0.12, tp + 0.12, localX);
        float crater = noise(moonN.xz * 4.0 + moonN.y * 2.0);
        vec3 mc = vec3(0.85, 0.87, 0.92) * (1.0 - smoothstep(0.42, 0.55, crater) * 0.25);
        frag_color = outputColor(mc * illum + vec3(0.02, 0.025, 0.04) * (1.0 - illum), 1.0);
        return;
    } else if (u_proc_mode == 11) {
        // Pool / fountain water — clear basin water, no ocean shoreline fade.
        albedo = computePoolWater(N, V, L, alpha, roughness);
        float fd = length(v_worldPos - u_camera_pos);
        float ff = clamp((fd - u_fog_near) / (u_fog_far - u_fog_near), 0.0, 1.0);
        frag_color = outputColor(mix(albedo, u_fog_color, 1.0 - exp(-ff*ff*3.0)), alpha);
        return;
    } else if (u_proc_mode == 10) {
        // Carpaint: metallic flake micro-normal + per-fragment colour wash.
        float nse = noise(v_worldPos.xz * 0.4);
        albedo = texAlbedo * (1.0 + (nse - 0.5) * 0.04);
        if (u_flakes > 0.0) {
            vec3 flakePos = floor(v_worldPos * 220.0);
            float h1 = hash31(flakePos);
            float h2 = hash31(flakePos + vec3(13.0, 7.0, 5.0));
            float h3 = hash31(flakePos + vec3(31.0, 17.0, 11.0));
            vec3 jitter = vec3(h1 - 0.5, h2 - 0.5, h3 - 0.5);
            N = normalize(N + jitter * 0.18 * u_flakes * u_normal_intensity);
        }
    } else {
        float nse = noise(v_worldPos.xz * 0.4);
        float noiseMask = smoothstep(0.3, 0.9, roughness);
        albedo = texAlbedo * (1.0 + (nse - 0.5) * 0.12 * noiseMask);
    }

    // Procedural normal-map pattern (mirrors mesh3d.frag.glsl). Self-shading
    // procedural materials (water, cloud, moon) have early-returned above so
    // this only affects standard, sand, rock, palm, wood, thatch, carpaint.
    N = perturbNormalProcedural(N, v_worldPos, v_uv,
                                u_normal_pattern, u_normal_scale,
                                u_normal_intensity);

    if (u_surface_pattern > 0 && u_surface_intensity > 0.0) {
        vec3 wear = dispatchSurfacePattern(u_surface_pattern, v_uv * u_surface_scale);
        float t = clamp(u_surface_intensity, 0.0, 4.0);
        vec3 tint = mix(vec3(1.0), vec3(wear.x * 2.0), t);
        albedo *= tint;
        roughness = clamp(roughness + wear.y * t, 0.04, 1.0);
        metallic  = clamp(metallic  + wear.z * t, 0.0,  1.0);
    }

    // Per-material wetness (SSR SURROGATE). Up-facing fragments get a smoother +
    // darker + brighter-IBL pass to read as wet/polished. This is the FALLBACK:
    // it runs only when the real ray-marched SSR pass is OFF (u_ssr_enabled==0 —
    // tier Off, or a non-D3D / non-HDR path). When the real pass owns reflections
    // it composites the actual reflected scene into the HDR buffer afterwards, so
    // suppressing the surrogate here prevents double-darkening the same surface.
    if (u_wetness > 0.0 && u_ssr_enabled == 0) {
        float upMask = clamp(dot(N, vec3(0.0, 1.0, 0.0)) * 1.4 - 0.2, 0.0, 1.0);
        float w = u_wetness * upMask;
        roughness = mix(roughness, max(roughness * 0.25, 0.04), w);
        albedo    = mix(albedo,    albedo * 0.7,                 w);
    }

    // ---- Snow cover: upward-facing surfaces turn white ----
    if (u_snow_cover > 0.01 && u_proc_mode != 2 && u_proc_mode != 6) {
        float upFacing = max(dot(N, vec3(0.0, 1.0, 0.0)), 0.0);
        // Snow sticks more on flat/upward surfaces, less on steep sides
        float snowMask = smoothstep(0.3, 0.7, upFacing) * u_snow_cover;
        // Add noise for natural patchy edges
        snowMask *= 0.7 + noise(v_worldPos.xz * 2.0) * 0.3;
        snowMask = clamp(snowMask, 0.0, 1.0);
        albedo = mix(albedo, vec3(0.92, 0.93, 0.97), snowMask);
        roughness = mix(roughness, 0.8, snowMask); // snow is matte
    }

    // ---- PBR Lighting (Cook-Torrance GGX) ----
    roughness = clamp(roughness, 0.04, 1.0);
    vec3 F0 = mix(vec3(0.04), albedo, metallic);
    float NdotV = max(dot(N, V), 0.001);
    float shadow = calcShadow(v_lightSpacePos, N);
    // Volumetric cloud shadow: dims the primary (sun) light where a cloud blocks
    // it, so the clouds drifting overhead cast moving shadow patches on the world.
    float cloudSh = cloudShadow(v_worldPos);

    float primaryIntensity = u_dir_light_count > 0 ? u_dir_lights[0].intensity : 0.0;
    float ambientShadow = mix(1.0, mix(0.5, 1.0, shadow), clamp(primaryIntensity, 0.0, 1.0) * cloudSh);
    vec3 F_ambient = fresnelSchlick(NdotV, F0);
    vec3 kD_ambient = (1.0 - F_ambient) * (1.0 - metallic);
    // Ambient occlusion. When real depth+normal SSAO is active it OWNS the AO and
    // the cheap curvature surrogate is DROPPED: curvatureAO is a screen-space
    // normal-derivative (dFdx/dFdy of N), which spikes at every geometry edge and
    // draws dark outlines around objects — the "Umrisse" artifact. Real SSAO gives
    // the soft contact shadows without that edge ringing, so we use it alone. The
    // curvature path stays only as the fallback when SSAO is off (Off/Low tier or
    // non-D3D backend).
    //
    // Sampling the AO map: it is a screen-space render target that was PRODUCED by
    // the fullscreen SSAO/blur passes (postprocess.vert, whose quad UV.v is
    // pre-flipped so those passes reconcile NDC-up vs the RT's top-left texel
    // origin) and therefore stored in the SAME orientation as every other
    // offscreen RT — i.e. the texel at (gl_FragCoord.xy / viewport) already holds
    // this fragment's AO. So we index it directly by normalised gl_FragCoord with
    // NO per-backend v flip. An earlier flip (sUV.y = 1 - sUV.y when
    // u_ssao_uv_flip_y < 0) DOUBLE-counted the orientation: the pre-flip in
    // postprocess.vert already did the reconciliation when the AO was written, so
    // re-flipping here applied the AO vertically mirrored (the "Umrisse"
    // outline-seam artifact). Verified empirically on D3D12 (raw-AO viz A/B): with
    // the flip the palm/lamp AO floated offset from its caster; without it the AO
    // sits on the geometry. On GL u_ssao_uv_flip_y is +1 so the old code already
    // did NOT flip — this change is a no-op there and only removes the wrong D3D
    // branch. u_ssao_uv_flip_y is now unused by this shader.
    float ao;
    if (u_ssao_enabled == 1) {
        vec2 sUV = gl_FragCoord.xy / u_viewport_size;
        ao = clamp(texture(u_ssao_map, sUV).r, 0.0, 1.0);
    } else {
        ao = curvatureAO(N, u_ao_strength);
    }
    vec3 color = u_ambient_color * u_ambient_intensity * albedo * kD_ambient * ambientShadow * ao;

    // Clamp the loop bound to the array size: u_*_light_count is a GPU
    // uniform and a stale/garbage value would otherwise run the loop for
    // millions of iterations (and index out of bounds) → GPU TDR / device hang.
    int dirCount = min(u_dir_light_count, 4);
    for (int dl = 0; dl < dirCount; dl++) {
        vec3 dL = normalize(-u_dir_lights[dl].direction);
        float rawNdotL = dot(N, dL);
        // When a cloud blocks the sun the sharp shadow must vanish (the light is
        // re-scattered as soft diffuse): fade the shadow map toward 1.0 as cloud
        // cover rises, and dim the direct beam only mildly — so the world stays
        // lit but shadowless under a cloud rather than going black.
        float dShadow = (dl == 0) ? mix(1.0, shadow, cloudSh) : 1.0;
        vec3 radiance = u_dir_lights[dl].color * u_dir_lights[dl].intensity;
        // Under a thick cloud the direct beam nearly vanishes (floor 0.1)
        // while ambient keeps the scene readable — tuned in-game: the strong
        // sweep is what makes passing clouds readable as weather.
        if (dl == 0) radiance *= mix(0.1, 1.0, cloudSh);

        if (u_subsurface_strength > 0.0) {
            // Skin path: wrap-diffuse extends light past the terminator,
            // warm subsurface tint bleeds at grazing angles, and a small
            // back-transmission term lights up thin areas viewed against
            // the light (ear edges, nose tip, finger silhouettes).
            float wrap        = 0.5;
            float wrapNdotL   = clamp((rawNdotL + wrap) / (1.0 + wrap), 0.0, 1.0);
            float terminator  = (1.0 - clamp(rawNdotL, 0.0, 1.0)) * wrapNdotL;
            vec3  scatterTint = mix(vec3(1.0), u_subsurface_color, terminator * u_subsurface_strength);
            vec3  effAlbedo   = albedo * scatterTint;
            float backlight   = pow(clamp(dot(V, -dL), 0.0, 1.0), 3.0)
                              * clamp(-rawNdotL, 0.0, 1.0);

            vec3 F = fresnelSchlick(max(dot(normalize(V + dL), V), 0.0), F0);
            vec3 kD = (1.0 - F) * (1.0 - metallic);
            color += (kD * effAlbedo / 3.14159265) * radiance * wrapNdotL * dShadow;
            if (rawNdotL > 0.0) {
                vec3 spec = cookTorranceSpecular(N, V, dL, roughness, F0);
                color += spec * radiance * rawNdotL * dShadow;
            }
            color += u_subsurface_color * albedo * backlight * u_subsurface_strength * radiance;
        } else {
            float dNdotL = max(rawNdotL, 0.0);
            if (dNdotL > 0.0) {
                vec3 spec = cookTorranceSpecular(N, V, dL, roughness, F0);
                vec3 F = fresnelSchlick(max(dot(normalize(V + dL), V), 0.0), F0);
                vec3 kD = (1.0 - F) * (1.0 - metallic);
                color += (kD * albedo / 3.14159265 + spec) * radiance * dNdotL * dShadow;
            }
        }
    }

    int pointCount = min(u_point_light_count, 4);
    for (int i = 0; i < pointCount; i++) {
        vec3 Lp = u_point_lights[i].position - v_worldPos;
        float dist = length(Lp);
        Lp /= dist;
        float r = max(u_point_lights[i].radius, 0.001);
        float atten = clamp(1.0 - dist*dist/(r*r), 0.0, 1.0);
        atten *= atten;
        float NdotPL = max(dot(N, Lp), 0.0);
        if (NdotPL > 0.0) {
            vec3 spec = cookTorranceSpecular(N, V, Lp, roughness, F0);
            vec3 F = fresnelSchlick(max(dot(normalize(V + Lp), V), 0.0), F0);
            vec3 kD = (1.0 - F) * (1.0 - metallic);

            vec3 radiance = u_point_lights[i].color * u_point_lights[i].intensity * atten;
            color += (kD * albedo / 3.14159265 + spec) * radiance * NdotPL;
        }
    }

    // Spot lights — point-light falloff multiplied by a cone factor.
    int spotCount = min(u_spot_light_count, 4);
    for (int i = 0; i < spotCount; i++) {
        vec3 Ls = u_spot_lights[i].position - v_worldPos;
        float dist = length(Ls);
        Ls /= dist;
        float r = max(u_spot_lights[i].range, 0.001);
        float atten = clamp(1.0 - dist*dist/(r*r), 0.0, 1.0);
        atten *= atten;

        float cosOuter = cos(u_spot_lights[i].angle);
        float cosInner = cos(u_spot_lights[i].angle * (1.0 - u_spot_lights[i].penumbra));
        float cd = dot(-Ls, normalize(u_spot_lights[i].direction));
        float cone = smoothstep(cosOuter, cosInner, cd);
        atten *= cone;

        float NdotSL = max(dot(N, Ls), 0.0);
        if (NdotSL > 0.0 && cone > 0.0) {
            vec3 spec = cookTorranceSpecular(N, V, Ls, roughness, F0);
            vec3 F = fresnelSchlick(max(dot(normalize(V + Ls), V), 0.0), F0);
            vec3 kD = (1.0 - F) * (1.0 - metallic);

            vec3 radiance = u_spot_lights[i].color * u_spot_lights[i].intensity * atten;
            color += (kD * albedo / 3.14159265 + spec) * radiance * NdotSL;
        }
    }

    // ---- Clearcoat lobe (carpaint, dielectric F0 ≈ 0.04) ----
    if (u_clearcoat > 0.0 && u_dir_light_count > 0) {
        float ccRough = clamp(u_clearcoat_roughness, 0.02, 1.0);
        vec3 ccF0 = vec3(0.04);
        vec3 ccL = normalize(-u_dir_lights[0].direction);
        float ccNdotL = max(dot(N, ccL), 0.0);
        if (ccNdotL > 0.0) {
            vec3 ccSpec = cookTorranceSpecular(N, V, ccL, ccRough, ccF0);
            color += ccSpec * u_dir_lights[0].color * u_dir_lights[0].intensity
                   * ccNdotL * shadow * u_clearcoat;
        }
        // Sky-tint pseudo-IBL when no cubemap binding is available in
        // this backend: blend horizon/sky based on the reflection vector
        // and modulate by clearcoat roughness.
        vec3 ccR = reflect(-V, N);
        float skyBlend = clamp(ccR.y * 2.0, 0.0, 1.0);
        vec3 ccEnv = mix(u_horizon_color, u_sky_color, skyBlend);
        vec3 ccFres = fresnelSchlick(NdotV, ccF0);
        color += ccEnv * ccFres * u_clearcoat * (1.0 - ccRough * 0.5) * 0.4;
    }

    color += u_emission;

    float fogDist = length(v_worldPos - u_camera_pos);
    float fogFactor = clamp((fogDist - u_fog_near) / (u_fog_far - u_fog_near), 0.0, 1.0);
    color = mix(color, u_fog_color, 1.0 - exp(-fogFactor * fogFactor * 3.0));

    color += volumetricScatter(v_worldPos);

    frag_color = outputColor(color, alpha);
}
