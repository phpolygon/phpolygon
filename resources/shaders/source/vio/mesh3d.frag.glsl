#version 410 core

in vec3 v_normal;
in vec3 v_worldPos;
in vec2 v_uv;
in vec4 v_lightSpacePos;
in vec3 v_localPos;
in vec3 v_localNormal;
in vec3 v_objectScale;
#ifdef PHPOLYGON_INSTANCE_PARAMS
// Three free floats per draw or instance (ProcModeShaderRegistry::enableInstanceParams).
flat in vec3 v_instance_params;
#endif // PHPOLYGON_INSTANCE_PARAMS

uniform vec3 u_ambient_color;
uniform float u_ambient_intensity;
// Sky ambient (Command\SetSkyAmbient): diffuse ambient that follows the sky instead of one flat colour: the
// light of the zenith from above, of the horizon from the sides, of the ground bounce from below, blended by
// the normal's elevation (a first-order SH-like irradiance). Already scaled by the gain and pi. mode 0 = off.
uniform vec3 u_sky_amb_up;
uniform vec3 u_sky_amb_side;
uniform vec3 u_sky_amb_down;
uniform float u_sky_amb_mode;
vec3 flatOrSkyAmbient(vec3 n) {
    if (u_sky_amb_mode > 0.5) {
        float up = clamp(n.y, 0.0, 1.0);
        float down = clamp(-n.y, 0.0, 1.0);
        return u_sky_amb_up * up + u_sky_amb_down * down + u_sky_amb_side * (1.0 - up - down);
    }
    return u_ambient_color * u_ambient_intensity;
}

struct DirLight {
    vec3 direction;
    vec3 color;
    float intensity;
};
uniform float u_light_halo;       // light scattered by the air per metre (halos, cones)
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
uniform PointLight u_point_lights[8];
uniform int u_point_light_count;

// D3D11 puts fragment UAVs behind the render targets (u0..u7 shared): with the six
// targets of the motion path the grid's buffers (u5..u7) do not fit, so that program
// lights with the eight nearest point lights like a backend without the grid.
#if defined(PHPOLYGON_LIGHT_GRID) && defined(PHPOLYGON_MOTION) && defined(PHPOLYGON_UAV_SHARE_TARGETS)
#undef PHPOLYGON_LIGHT_GRID
#endif
#ifdef PHPOLYGON_LIGHT_GRID
// World light grid (PHPolygon\Rendering\Lighting\LightGrid): all point lights of
// the frame, and per x/z cell the ones that reach it. Declared writable like every
// fragment storage buffer of vio (the backends bind them as UAVs); only read here.
layout(std430, binding = 1) buffer LightGridLights { vec4 lgLights[]; };  // 2 per light: pos + radius, colour·intensity
layout(std430, binding = 2) buffer LightGridCells { uint lgCells[]; };    // header (8), then 2 per cell: offset, count
layout(std430, binding = 3) buffer LightGridIndex { uint lgIndex[]; };
uniform vec4 u_light_grid;       // w: 1 = read the grid; the shape comes from the cells header
uniform vec4 u_light_grid_dims;  // unused by the shader (kept for tools)
const uint LIGHT_GRID_MAX = 32u; // LightGrid::MAX_PER_CELL
#endif

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

// Rectangular area lights (Component\RectAreaLight): centre, half-extent axes, emits
// along normalize(cross(axisX, axisY)); color = colour x intensity x pi, as a point light.
struct RectLight {
    vec3 center;
    vec3 axisX;
    vec3 axisY;
    vec3 color;
    float range;
};
uniform RectLight u_rect_lights[4];
uniform int u_rect_light_count;

uniform vec3 u_albedo;
// Grass blades (Material::$grass): colour along the blade from v_uv (y = 0 root
// .. 1 tip, x = blade random). Mirrors the other mesh fragment stage.
uniform int   u_grass_shade;      // 1 for Material::$grass (a stage-local name: a uniform
                                  // shared with the vertex stage is not set in both on every backend)
uniform vec3  u_grass_tip;        // Material::$grassTip
uniform vec4  u_grass_look;       // bands (0 = smooth), variation, root shade, patch noise scale
uniform vec2  u_grass_far_fade;   // SetGrass full / fade range (fragment copy)
uniform vec3 u_emission;
uniform float u_emission_mask;    // 1: the albedo texture's alpha scales u_emission (Material::$emissionByTextureAlpha)
uniform float u_roughness;
uniform float u_metallic;
uniform float u_alpha;
uniform float u_clearcoat;
uniform float u_clearcoat_roughness;
uniform float u_flakes;
uniform float u_normal_intensity;
uniform int   u_use_environment_map;
#ifdef PHPOLYGON_HEMI_SPECULAR
uniform float u_hemi_specular;
#endif // PHPOLYGON_HEMI_SPECULAR
uniform int   u_normal_pattern;
uniform float u_normal_scale;
uniform int   u_surface_pattern;
uniform float u_surface_scale;
uniform float u_surface_intensity;
// Relief of the procedural normal pattern (both need u_normal_pattern):
// u_cavity darkens its recesses (0 = off, 1 = recesses unlit by ambient),
// u_parallax_depth marches the view ray through that many world units of
// relief below the surface (0 = off).
uniform float u_cavity;
uniform float u_parallax_depth;
uniform float u_wetness;
uniform vec3  u_subsurface_color;
uniform float u_subsurface_strength;
uniform int   u_two_sided;          // Material::$twoSided
uniform float u_ssr_intensity;
// 1 when the real ray-marched SSR pass owns reflections this frame (VIO/D3D12,
// HDR path). When 1 the forward wetness SURROGATE below is suppressed so the two
// don't double-apply; when 0 (tier Off, or non-D3D / non-HDR) the surrogate is
// the only reflection cue and stays active. Mirrors u_ssao_enabled.
uniform int   u_ssr_enabled;
uniform int   u_volumetric_fog;
// Instance colour variation from the vertex stage (1 = none).
flat in vec3 v_instance_tint;
// Splatted ground (Material::$splatLayers, shader plan 5.1): layer count (0 = off), the
// world area the splat map (the albedo texture) covers, the blend's sharpness, and per
// layer rgb + roughness and pattern code, pattern scale, height weight.
uniform float u_splat_count;
uniform vec4  u_splat_area;
uniform float u_splat_sharpness;
uniform vec4  u_splat_color[4];
uniform vec4  u_splat_pattern[4];
// Micro-shadows and bent normals (GraphicsSettings::$microShadows, shader plan 5.1): 0 or 1.
uniform float u_micro_shadow;
// 1 - DirectionalLight::$volumetricScale of the primary light: how much LESS that
// light scatters in the volumetric fog (0 = full, the default when unset; the
// moon scatters far less than its lighting intensity suggests).
uniform float u_volumetric_light_dim;
uniform float u_ao_strength;
// Fieldtracing (SDF global illumination) — see PHPOLYGON_FIELDTRACING.md §7.
// mode: 0=Off 1=ProbesOnly 2=SdfOcclusion 3=SdfBounce (float; int-in-UBO is
// unreliable across SPIRV-Cross targets). Default 0 => strict no-op.
uniform float u_ft_mode;
uniform float u_ft_intensity;
uniform float u_ft_ao;
// Reflection cubemap (sky/scene probe). Water samples it for off-screen
// reflections; u_has_environment_map gates it. Parity with the OpenGL copy.
uniform samplerCube u_environment_map;
uniform int   u_has_environment_map;
// Last mip level of u_environment_map (0 when the probe has no mip chain).
// Lets surfaces pick a roughness-appropriate LOD via textureLod().
uniform float u_env_mip_max;
// Baked COLOURED irradiance probe field (RGB SH-L1, with 1-bounce). One 3D
// texture per channel; RGBA = signed-encoded coeffs (c0,c1,c2,c3) over
// [-u_probe_range, +u_probe_range]. Per channel: E = c0 + c1*n.x + c2*n.y + c3*n.z.
// u_probe_enabled 0 => fall back to the analytic hemisphere.
uniform float u_probe_enabled;
#ifndef PHPOLYGON_OMIT_PROBE_GI
// R, G and B SH-L1 volumes stacked along z (depth x 3), one texture: probeCoeffs().
uniform sampler3D u_probe_field;
#endif // PHPOLYGON_OMIT_PROBE_GI
uniform vec3  u_probe_origin;
uniform vec3  u_probe_size;
uniform float u_probe_range;
uniform vec3  u_grade_lift;
uniform vec3  u_grade_gamma;
uniform vec3  u_grade_gain;
uniform float u_grade_saturation;
uniform float u_vignette_intensity;
uniform vec2  u_viewport_size;
uniform float u_snow_cover; // 0.0 = no snow, 1.0 = full blanket
uniform float u_rain_wetness; // 0.0 = dry, 1.0 = rain-soaked
uniform float u_rain_dry_below; // no rain below this world height (Weather::$dryBelowY)
uniform vec3 u_fog_color;
uniform float u_fog_near;
uniform float u_fog_far;
uniform vec3 u_camera_pos;
// Animation time of this stage. The vertex stage declares u_time too, and php-vio's
// cbuffer backends (D3D11, D3D12, Vulkan, Metal) set a name declared in both stages
// in the vertex stage only - the fragment copy read 0. The renderer uploads the same
// value under this stage-local name; fragment code and snippets keep writing u_time.
uniform float u_frag_time;
#define u_time u_frag_time
uniform int u_proc_mode;

uniform vec3 u_sky_color;
uniform vec3 u_horizon_color;

uniform float u_moon_phase;
uniform vec3 u_season_tint;
// Season colour map for vegetation (shader plan 4.1): rgb tint, a desaturation.
uniform vec4 u_season_grade;
// Sandstorm (shader plan 4.1): rgb sand colour, a intensity; wind direction xz.
uniform vec4 u_sandstorm;
uniform vec2 u_sandstorm_wind;
// Height fog and local fog bodies (shader plan 4.2, AtmosphereFogSystem).
uniform vec4 u_height_fog;        // density, falloff, base height, on
uniform vec4 u_height_fog_color;  // rgb, 1 = own colour (else the distance-fog colour)
uniform int  u_fog_volume_count;
uniform vec4 u_fog_volumes[12];   // per volume: (centre, yaw), (half extents, density), (colour, edge)
uniform float u_soft_particle;    // Material::$softParticle: soft puff, depth fade distance
uniform float u_triplanar;        // Material::$triplanar: tiles per metre, 0 = mesh UVs
uniform float u_alpha_cutoff;     // Material::$alphaCutoff: cut out below this albedo alpha, 0 = off
uniform float u_hair;             // Material::$hair: v_normal is the strand direction
uniform vec4 u_fur_shell;         // Material::$furLength: shell height 0..1, strands per UV unit, darkening at the skin, on
// Decal volumes (shader plan 5.1, DecalSystem): per decal (centre, yaw),
// (half extents, strength), (colour, kind 0 stain 1 soot 2 moss 3 cracks).
uniform int  u_decal_count;
// Weathering (shader plan 5.2, AgingSystem): zones (centre, yaw), (half, age);
// params (north x, north z, waterline y, edge); the material's susceptibility.
uniform int  u_age_zone_count;
uniform vec4 u_age_zones[16];
uniform vec4 u_age_params;
uniform float u_weathering;
// Glass look (shader plan 5.3): x = Fresnel edges, y = dirt.
uniform vec2 u_glass;
// Emissive looks (shader plan 5.4): x = lit window fraction, y = neon tube,
// z = window grid follows the mesh UV instead of the world position.
uniform vec3 u_emissive_look;
// Window grid: xy = cell size, zw = origin (metres, or UV units when z of the look is set).
uniform vec4 u_window_grid;
// Material::$windowVary: mix the cell's world position into the lit/dark choice.
uniform float u_window_vary;
// Material looks (shader plan 5.5): x = cloth sheen, y = brushed metal, z = glitter.
uniform vec3 u_material_look;
// Dithered distance fade (shader plan 6.1): (start, end) metres, end <= 0 = off.
uniform vec2 u_fade_range;
// Figure looks (shader plan 6.2): x = rim light, y = hair shine.
uniform vec2 u_figure_look;
// Refraction (Material::$refraction, $frost): x = strength, y = frost.
uniform vec2 u_refract;
// Particle colour over life (shader plan 7.1): v_instance_params.x packs RGBA6.
uniform float u_particle_tint;
// Flipbook (shader plan 7.1): columns, rows, fps; w = streak fade.
uniform vec4 u_flipbook;
// Ray-traced sun shadows (shader plan 10.1, GraphicsSettings::$rayTracedShadows): one
// inline ray per pixel towards the sun through the scene's acceleration structure.
uniform float u_rt_shadows;
#ifdef PHPOLYGON_RAY_QUERY
layout(binding = 0) uniform accelerationStructureEXT u_tlas;
// Ray-traced reflections (shader plan 10.1): the albedo of every instance of the
// acceleration structure, in its order - what a reflection ray hits gets this
// colour, lit by the ambient and (a second ray) the sun.
// 4 floats per instance (rgb, emission) as a float array: a vec4 array in a
// 16-byte-stride buffer reads wrong on D3D12.
layout(std430, binding = 0) readonly buffer RtAlbedo { float rtAlbedo[]; };
uniform float u_rt_reflections;
// Ray-traced GI (shader plan 10.2, GraphicsSettings::$rayTracedGi): strength of the one-bounce diffuse light.
uniform float u_rt_gi;
#endif
#ifdef PHPOLYGON_SCENE_COLOR
// Copy of the opaque scene colour (more than 16 texture units, or a sampler-less texture above them).
#ifdef PHPOLYGON_SCENE_COLOR_FETCH
// Separate texture read by texelFetch: no sampler state, so it sits above the sampler limit of D3D11 and
// D3D12 with FXC (php-vio fetch_units). The binding is VioRenderer3D::SCENE_COLOR_SLOT.
layout(binding = 16) uniform texture2D u_scene_color;
vec3 sceneColorAt(vec2 uv) {
    vec2 sz = vec2(textureSize(u_scene_color, 0));
    vec2 p = uv * sz - 0.5;
    ivec2 i0 = ivec2(floor(p));
    vec2 f = fract(p);
    ivec2 hi = ivec2(sz) - 1;
    vec3 a = texelFetch(u_scene_color, clamp(i0, ivec2(0), hi), 0).rgb;
    vec3 b = texelFetch(u_scene_color, clamp(i0 + ivec2(1, 0), ivec2(0), hi), 0).rgb;
    vec3 c = texelFetch(u_scene_color, clamp(i0 + ivec2(0, 1), ivec2(0), hi), 0).rgb;
    vec3 d = texelFetch(u_scene_color, clamp(i0 + ivec2(1, 1), ivec2(0), hi), 0).rgb;
    return mix(mix(a, b, f.x), mix(c, d, f.x), f.y);
}
#else
uniform sampler2D u_scene_color;
vec3 sceneColorAt(vec2 uv) {
    return textureLod(u_scene_color, uv, 0.0).rgb;
}
#endif
uniform float     u_scene_color_ready;
#endif
// Wind for fragment looks (shader plan 6.1): xz direction, intensity.
uniform vec3 u_frag_wind;
uniform vec4 u_decals[24];

// HDR pipeline
uniform int u_linear_output;

// Shadow
uniform int u_has_shadow_map;

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

// Spot shadow (VioRenderer3D::renderSpotShadowPass): one perspective depth map of
// u_spot_lights[u_spot_shadow_index]; index -1 = no map this frame.
uniform sampler2DShadow u_spot_shadow_map;
uniform mat4 u_spot_shadow_matrix;
uniform int u_spot_shadow_index;

// Point shadow (VioRenderer3D::renderPointShadowPass): one depth cube around the
// light at u_point_shadow_pos, read as a plain cube with the compare done here.
// u_point_shadow_range = (near, far, 1 / face size). The light is found by its
// position, in the eight uniforms and in the light grid alike.
#ifdef PHPOLYGON_POINT_SHADOW
uniform samplerCube u_point_shadow_map;
uniform float u_point_shadow_on;
uniform vec3 u_point_shadow_pos;
uniform vec3 u_point_shadow_range;
#endif

// Texture
uniform int u_has_albedo_texture;
uniform sampler2D u_albedo_texture;
#ifdef PHPOLYGON_BINDLESS_MATERIALS
// Bindless materials (shader plan 10.1): the albedo from the context's texture table,
// through the 16x anisotropic table sampler; the slot per draw or per instance.
// v_albedo_slot = slot * 2 + 1 for a clamped texture (signs, plates, atlases), + 0 for
// a repeating one - the table's samplers carry the wrap mode, not the texture.
flat in int v_albedo_slot;
layout(set = 1, binding = 0) uniform texture2D vio_textures[];
layout(set = 1, binding = 7) uniform sampler vio_sampler_aniso;
layout(set = 1, binding = 8) uniform sampler vio_sampler_aniso_clamp;
vec4 albedoGrad(vec2 uv, vec2 dx, vec2 dy) {
    int slot = v_albedo_slot >> 1;
    if ((v_albedo_slot & 1) != 0) {
        return textureGrad(sampler2D(vio_textures[nonuniformEXT(slot)], vio_sampler_aniso_clamp), uv, dx, dy);
    }
    return textureGrad(sampler2D(vio_textures[nonuniformEXT(slot)], vio_sampler_aniso), uv, dx, dy);
}
ivec2 albedoSize() {
    return textureSize(sampler2D(vio_textures[nonuniformEXT(v_albedo_slot >> 1)], vio_sampler_aniso), 0);
}
#else
vec4 albedoGrad(vec2 uv, vec2 dx, vec2 dy) {
    return textureGrad(u_albedo_texture, uv, dx, dy);
}
ivec2 albedoSize() {
    return textureSize(u_albedo_texture, 0);
}
#endif
// Implicit footprint, taken before any branch (neighbouring pixels of another
// triangle may take the other sampler).
vec4 albedoAuto(vec2 uv) {
    return albedoGrad(uv, dFdx(uv), dFdy(uv));
}
uniform float u_material_mip_bias; // Material::$mipBias, in mip levels (negative = sharper)
uniform vec2 u_material_aniso;     // x: most taps along the stretched axis (1 = one tap), y: the texture's own anisotropy

// The albedo texture over a pixel's footprint. Material::$anisotropy above the
// texture's own level takes several taps along the footprint's long axis, each
// with the hardware filter over its share, so a sign seen at a grazing angle
// keeps its strokes instead of blurring to the mip of the long axis.
vec3 sampleAlbedoTexture(vec2 uv, vec2 dx, vec2 dy) {
    if (u_material_aniso.x > 1.0) {
        // Lengths in texels: a texture that is not square stretches uv space.
        vec2 texels = vec2(albedoSize());
        float lx = dot(dx * texels, dx * texels);
        float ly = dot(dy * texels, dy * texels);
        vec2 major = lx >= ly ? dx : dy;
        vec2 minor = lx >= ly ? dy : dx;
        float ratio = sqrt(max(lx, ly) / max(min(lx, ly), 1e-20));
        float n = clamp(ceil(ratio / u_material_aniso.y), 1.0, u_material_aniso.x);
        if (n > 1.0) {
            vec2 tapGrad = major / n;
            vec3 sum = vec3(0.0);
            for (int i = 0; i < 16; i++) {
                if (float(i) >= n) {
                    break;
                }
                sum += albedoGrad(uv + major * ((float(i) + 0.5) / n - 0.5), tapGrad, minor).rgb;
            }
            return sum / n;
        }
    }
    return albedoGrad(uv, dx, dy).rgb;
}

// Triplanar albedo (Material::$triplanar): the texture projected from the three
// world axes, blended by the (sharpened) normal; automatic derivatives.
vec3 sampleAlbedoTriplanar(vec3 p, vec3 n, float scale) {
    vec3 w = pow(abs(n), vec3(4.0));
    w /= max(w.x + w.y + w.z, 1e-4);
    vec3 q = p * scale;
    return albedoAuto(q.zy).rgb * w.x
         + albedoAuto(q.xz).rgb * w.y
         + albedoAuto(q.xy).rgb * w.z;
}

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

// Fieldtracing SDF trace-pass result (SdfOcclusion / SdfBounce). R = SDF AO,
// G = soft sun shadow. Written by sdf_ao.frag, sampled here at slot 2. Gated by
// u_sdf_ao_enabled (0 when the pass didn't run — e.g. ProbesOnly tier, no volume,
// or non-D3D backend); a 1x1 white texture is bound then so the sampler is valid.
uniform float     u_sdf_ao_enabled;   // float: int-in-UBO is unreliable across SPIRV-Cross targets
uniform sampler2D u_sdf_ao_map;

// PHPOLYGON:SNIPPET_UNIFORMS

#ifdef PHPOLYGON_SCENE_DEPTH
// Linear view depth of the opaque scene, copied between the opaque and the transparent
// pass (ProcModeShaderRegistry::requireSceneDepth). Transparent surfaces and particles
// read it through sceneLinearDepth() / sceneDepthDelta(), behind the POSTCOLOR helpers.
uniform sampler2D u_scene_depth;
uniform float     u_scene_depth_ready; // 0: opaque pass, or the frame has no readable depth
uniform vec4      u_scene_view_z;      // third row of the view matrix (fragment-stage copy)
#endif // PHPOLYGON_SCENE_DEPTH

#ifdef PHPOLYGON_MRT
// Deferred-composite path (engine RFC docs/rfcs/mrt-gbuffer.md). The lit result is
// SPLIT so a later fullscreen composite can apply the screen-space AO terms exactly
// where this shader applies them on the single-target path, and the G-buffer rides
// along as a fourth attachment instead of costing a second geometry pass:
//   o_sun      primary directional light only (the SDF soft sun shadow multiplies it)
//   o_local    everything else that is NOT AO-modulated: other lights, IBL, clearcoat,
//              emission, fog colour, volumetrics, unlit/sky output
//   o_ambient  the AO-modulated terms: flat ambient + probe GI
//   o_gbuffer  gbuffer.frag's layout (oct view normal, reflectivity, linear depth)
// All four are written linear; composite.frag tonemaps for LDR scene targets.
layout(location = 0) out vec4 o_sun;
layout(location = 1) out vec4 o_local;
layout(location = 2) out vec4 o_ambient;
layout(location = 3) out vec4 o_gbuffer;
// Snippets that end a fragment early (`frag_color = outputColor(...); return;`) keep
// working unchanged: their unlit colour is local light, and every attachment was
// initialised at the top of main().
#define frag_color o_local
uniform mat4 u_gbuffer_view;   // camera view matrix (fragment-stage copy)
uniform int  u_gbuffer_write;  // 0 => this draw opts out of the G-buffer (excludeFromGbuffer)
#ifdef PHPOLYGON_MOTION
// Temporal techniques (only together with PHPOLYGON_MRT) add two attachments:
//   o_motion    RG16F  prevUv - uv in the scene target's texture space (opaque pass)
//   o_reactive  R8     how little the history should be trusted: the coverage of
//                      transparent surfaces, which carry no motion of their own
//                      (transparent pass, additive), or Material::$reactive of an
//                      opaque surface whose content moves (opaque pass, only in
//                      frames that draw one; the others write 0 then)
// The pipelines mask whichever of the two a pass must not touch.
in vec4 v_currClip;
in vec4 v_prevClip;
layout(location = 4) out vec4 o_motion;
layout(location = 5) out vec4 o_reactive;
uniform float u_motion_flip_y;  // +1 GL, -1 where render-target row 0 is NDC top
uniform float u_mip_bias;       // texture LOD bias for the upscale ratio (negative = sharper)
uniform float u_material_reactive; // Material::$reactive
#endif
#else
out vec4 frag_color;
#endif

// ================================================================
//  Noise — lightweight
// ================================================================

float hash21(vec2 p) {
#ifdef PHPOLYGON_PRECISE_HASH
    // The sine hash amplifies its argument by ~43758 per unit, and noise() reads the
    // same lattice point from both cells beside it (`i + vec2(1,0)` here, `floor(p)`
    // there). A compiler free to fuse or reorder the dot product rounds those two
    // differently, and one ulp of a dot product near 1e5 is a different hash: value
    // noise breaks at every cell edge, and the bump and noise normal patterns turn
    // into per-pixel grain once a cell is smaller than a pixel. Set per backend
    // (VioRenderer3D::preciseHashOn()); `precise` pins the order.
    precise float d = fma(p.y, 311.7, p.x * 127.1);
    precise float s = sin(d) * 43758.5453;
    return fract(s);
#else
    return fract(sin(dot(p, vec2(127.1, 311.7))) * 43758.5453);
#endif // PHPOLYGON_PRECISE_HASH
}

float hash31(vec3 p) {
    return fract(sin(dot(p, vec3(443.897, 441.423, 437.195))) * 43758.5453);
}

float noise(vec2 p) {
    vec2 i = floor(p);
    vec2 f = fract(p);
    f = f * f * (3.0 - 2.0 * f);
#ifdef PHPOLYGON_PRECISE_HASH
    // The corners from the integer lattice point: `precise` in hash21() does not reach
    // the `i + vec2(1,0)` out here. With the corners added in floats the Intel UHD 630
    // Vulkan driver still broke 39 cell edges of VioPreciseHashTest's wall, from the
    // integer lattice none (2026-11-10). Integer sums are exact, and an exact integer
    // converts to the same float in both cells.
    ivec2 c = ivec2(i);
    return mix(mix(hash21(vec2(c)), hash21(vec2(c + ivec2(1, 0))), f.x),
               mix(hash21(vec2(c + ivec2(0, 1))), hash21(vec2(c + ivec2(1, 1))), f.x), f.y);
#else
    return mix(mix(hash21(i), hash21(i + vec2(1,0)), f.x),
               mix(hash21(i + vec2(0,1)), hash21(i + vec2(1,1)), f.x), f.y);
#endif // PHPOLYGON_PRECISE_HASH
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
uniform vec2  u_cloud_wind_dir;
uniform float u_cloud_offset; // = sky pass's u_cloud_offset, so shadows track the clouds
#ifdef PHPOLYGON_NORMAL_MAP
// Tangent-space normal map (Material::$normalTexture, shader plan 5.1).
uniform sampler2D u_normal_texture;
uniform int u_has_normal_texture;
#endif
#ifdef PHPOLYGON_CLOUD_SHADOW_MAP
uniform sampler2D u_cloud_shadow_map;   // r = sun transmittance, seen from above
uniform vec4 u_cloud_shadow_info;       // centre x, centre z, half size, 1 = baked this frame
#endif

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
    wp.xz += u_cloud_wind_dir * (u_cloud_offset * 0.0026);
    float n = cloudFbm3(wp);
    float cov = 1.0 - u_cloud_cover * 0.9;
    return smoothstep(cov, cov + 0.22, n) * vGrad * u_cloud_density;
}

// The slab density with three octaves: the cloud shadow's cheaper twin of
// cloudDensityAt (same coverage curve, the finest detail left out).
float cloudDensityShadow(vec3 p) {
    float h = (p.y - u_cloud_altitude) / 30.0;
    if (h < 0.0 || h > 1.0) return 0.0;
    float vGrad = smoothstep(0.0, 0.18, h) * smoothstep(1.0, 0.55, h);
    vec3 wp = p * 0.0026;
    wp.xz += u_cloud_wind_dir * (u_cloud_offset * 0.0026);
    float total = 0.0, amp = 0.5;
    for (int i = 0; i < 3; i++) { total += cloudVnoise3(wp) * amp; wp *= 2.03; amp *= 0.5; }
    // The missing fourth octave averages 0.0625 * 0.5: add its mean back.
    total += 0.03125;
    float cov = 1.0 - u_cloud_cover * 0.9;
    return smoothstep(cov, cov + 0.22, total) * vGrad * u_cloud_density;
}

// Sun transmittance through the cloud slab from a world point (1 = unshadowed,
// → 0 fully under a cloud). Marches a few steps toward the directional light.
#ifndef PHPOLYGON_OMIT_CLOUD_SHADOW
float cloudShadow(vec3 worldPos) {
    if (u_cloud_cover <= 0.0) return 1.0;
    vec3 L = normalize(-u_dir_light_direction); // toward the light source
    if (L.y <= 0.05) return 1.0;                 // light at/below the horizon
#ifdef PHPOLYGON_CLOUD_SHADOW_MAP
    // The baked map (cloud_shadow_map.frag): the texel where this point's sun ray
    // crosses y = 0. Outside the map the march below takes over.
    if (u_cloud_shadow_info.w > 0.5) {
        vec2 cg = worldPos.xz - worldPos.y * L.xz / L.y;
        vec2 cuv = (cg - u_cloud_shadow_info.xy) / (2.0 * u_cloud_shadow_info.z) + 0.5;
        if (cuv.x > 0.01 && cuv.y > 0.01 && cuv.x < 0.99 && cuv.y < 0.99) {
            return textureLod(u_cloud_shadow_map, cuv, 0.0).r;
        }
    }
#endif
    float base = u_cloud_altitude;
    float top  = base + 30.0;
    float tEnter = max((base - worldPos.y) / L.y, 0.0);
    float tExit  = (top - worldPos.y) / L.y;
    if (tExit <= tEnter) return 1.0;
    // Two samples through the 30 m slab with a three-octave density (shader plan
    // 3.3): about a third of the former 5 x 4-octave cost per lit fragment. The
    // shadow is a soft dimming sweep, the finest octave never showed in it. A
    // baked cloud shadow map would need a sampler the base program no longer has
    // on D3D12 (8 registers).
    float stepLen = (tExit - tEnter) / 2.0;
    float dens = 0.0;
    for (int i = 0; i < 2; i++) {
        dens += cloudDensityShadow(worldPos + L * (tEnter + (float(i) + 0.5) * stepLen));
    }
    // Extinction: 1 = clear path to the sun, → 0 fully under a cloud. The
    // consumer floors the direct beam (it never reaches 0), so a passing cloud
    // dims the world without blacking it out — the bright sky still fills via
    // ambient. Storm darkness is applied globally instead. ×1.2 extinction so
    // even thin clouds register as a visible dimming sweep.
    return clamp(exp(-dens * stepLen * 1.2), 0.0, 1.0);
}
#else
float cloudShadow(vec3 worldPos) { return 1.0; }
#endif // PHPOLYGON_OMIT_CLOUD_SHADOW

// ================================================================
//  Shadow
// ================================================================

// Sample a single cascade: 12 taps of a Poisson disc, turned per pixel (interleaved
// gradient noise) so the penumbra reads soft instead of as a 3x3 staircase; the
// temporal resolve smooths the remaining grain (shader plan 3.3). The disc spans
// about 2.5 shadow texels. A true PCSS blocker search would need the depth map as
// a plain sampler; with the comparison sampler the width stays fixed.
const vec2 SHADOW_DISC[12] = vec2[12](
    vec2(-0.326, -0.406), vec2(-0.840, -0.074), vec2(-0.696, 0.457), vec2(-0.203, 0.621),
    vec2(0.962, -0.195), vec2(0.473, -0.480), vec2(0.519, 0.767), vec2(0.185, -0.893),
    vec2(0.507, 0.064), vec2(0.896, 0.412), vec2(-0.322, -0.933), vec2(-0.792, -0.598));

float sampleCascade(sampler2DShadow map, mat4 lightSpace, vec3 worldPos, vec3 N) {
    vec4 lsp = lightSpace * vec4(worldPos, 1.0);
    vec3 pc  = lsp.xyz / lsp.w * 0.5 + 0.5;
    if (pc.x < 0.0 || pc.x > 1.0 || pc.y < 0.0 || pc.y > 1.0 || pc.z > 1.0) return 1.0;
    vec3 lightDir = normalize(-u_dir_light_direction);
    float NdotL = max(dot(N, lightDir), 0.0);
    float bias = mix(0.005, 0.001, NdotL);
    float ts = 2.5 / 2048.0;
    float rd = pc.z - bias;
    float a = 6.2831853 * fract(52.9829189 * fract(dot(gl_FragCoord.xy, vec2(0.06711056, 0.00583715))));
    mat2 rot = mat2(cos(a), sin(a), -sin(a), cos(a));
    float s = 0.0;
    for (int i = 0; i < 12; i++) {
        s += texture(map, vec3(pc.xy + rot * SHADOW_DISC[i] * ts, rd));
    }
    return s / 12.0;
}

// The spot beam's shadow: the point pushed off the surface along the normal by a
// distance that grows with its distance to the lamp (the texel footprint of the
// perspective map), then 8 taps of the disc, turned per pixel.
float spotShadow(vec3 worldPos, vec3 N, vec3 L, float dist) {
    float NdotL = max(dot(N, L), 0.0);
    vec3 p = worldPos + N * (0.01 + 0.004 * dist) * (1.5 - NdotL);
    vec4 lsp = u_spot_shadow_matrix * vec4(p, 1.0);
    if (lsp.w <= 0.0) return 1.0;
    vec3 pc = lsp.xyz / lsp.w * 0.5 + 0.5;
    if (pc.x < 0.0 || pc.x > 1.0 || pc.y < 0.0 || pc.y > 1.0 || pc.z > 1.0) return 1.0;
    float ts = 1.5 / float(textureSize(u_spot_shadow_map, 0).x);
    float a = 6.2831853 * fract(52.9829189 * fract(dot(gl_FragCoord.xy, vec2(0.06711056, 0.00583715))));
    mat2 rot = mat2(cos(a), sin(a), -sin(a), cos(a));
    float s = 0.0;
    for (int i = 0; i < 8; i++) {
        s += texture(u_spot_shadow_map, vec3(pc.xy + rot * SHADOW_DISC[i] * ts, pc.z - 0.0002));
    }
    return s / 8.0;
}

#ifdef PHPOLYGON_POINT_SHADOW
// The depth a face of the point cube stored for a direction d from the light: the
// face's perspective depth of the major axis, mapped to 0..1 like the cascades.
float pointCubeDepth(vec3 d) {
    float n = u_point_shadow_range.x;
    float f = u_point_shadow_range.y;
    float za = max(max(abs(d.x), abs(d.y)), abs(d.z));
    float ndc = (f + n) / (f - n) - 2.0 * f * n / ((f - n) * max(za, 1e-4));
    return ndc * 0.5 + 0.5;
}

float pointShadow(vec3 worldPos, vec3 N, vec3 L, float dist) {
    if (u_point_shadow_on < 0.5 || dist >= u_point_shadow_range.y) return 1.0;
    float NdotL = max(dot(N, L), 0.0);
    float texel = 2.0 * dist * u_point_shadow_range.z;
    vec3 d = worldPos + N * (texel * 1.5 + 0.01) * (1.5 - NdotL) - u_point_shadow_pos;
    float ref = pointCubeDepth(d) - 0.0004;
    vec3 ad = normalize(d);
    vec3 t = normalize(cross(abs(ad.y) < 0.99 ? vec3(0.0, 1.0, 0.0) : vec3(1.0, 0.0, 0.0), ad));
    vec3 b = cross(ad, t);
    float a = 6.2831853 * fract(52.9829189 * fract(dot(gl_FragCoord.xy, vec2(0.06711056, 0.00583715))));
    float ca = cos(a), sa = sin(a);
    float s = 0.0;
    for (int i = 0; i < 6; i++) {
        vec2 o = vec2(ca * SHADOW_DISC[i].x - sa * SHADOW_DISC[i].y, sa * SHADOW_DISC[i].x + ca * SHADOW_DISC[i].y) * texel * 1.5;
        s += textureLod(u_point_shadow_map, d + t * o.x + b * o.y, 0.0).r >= ref ? 1.0 : 0.0;
    }
    return s / 6.0;
}

// The point shadow for a light at lightPos: 1 unless it is the light with the cube.
float pointShadowFor(vec3 lightPos, vec3 worldPos, vec3 N, vec3 L, float dist) {
    if (u_point_shadow_on < 0.5 || distance(lightPos, u_point_shadow_pos) >= 0.01) return 1.0;
    return pointShadow(worldPos, N, L, dist);
}
#else
float pointShadowFor(vec3 lightPos, vec3 worldPos, vec3 N, vec3 L, float dist) { return 1.0; }
#endif

// One edge of Lambert's polygon formula: the angle between the two (unit) corner
// directions along the edge's normal; theta / sin(theta) keeps near-parallel
// corners stable.
vec3 rectEdge(vec3 a, vec3 b) {
    float c = clamp(dot(a, b), -0.9999, 0.9999);
    float theta = acos(c);
    return cross(a, b) * (theta / max(sqrt(1.0 - c * c), 1e-4));
}

// Irradiance over radiance of a rectangle at P with normal N: the exact cosine form
// factor (times pi) of the rectangle clipped at P's horizon (at most five corners).
float rectFormFactor(vec3 P, vec3 N, vec3 c, vec3 ex, vec3 ey) {
    vec3 q[4];
    q[0] = c - ex - ey - P;
    q[1] = c + ex - ey - P;
    q[2] = c + ex + ey - P;
    q[3] = c - ex + ey - P;
    vec3 o[5];
    o[0] = vec3(0.0); o[1] = vec3(0.0); o[2] = vec3(0.0); o[3] = vec3(0.0); o[4] = vec3(0.0);
    int n = 0;
    for (int i = 0; i < 4; i++) {
        vec3 a = q[i];
        vec3 b = q[i == 3 ? 0 : i + 1];
        float da = dot(a, N);
        float db = dot(b, N);
        if (da >= 0.0 && n < 5) { o[n] = a; n++; }
        if ((da >= 0.0) != (db >= 0.0) && n < 5) { o[n] = a + (b - a) * (da / (da - db)); n++; }
    }
    if (n < 3) return 0.0;
    vec3 F = vec3(0.0);
    for (int i = 0; i < 5; i++) {
        if (i >= n) break;
        F += rectEdge(normalize(o[i]), normalize(o[i + 1 < n ? i + 1 : 0]));
    }
    return abs(dot(F, N)) * 0.5;
}

float calcShadow(vec4 lsp, vec3 N) {
    if (u_has_shadow_map == 0) return 1.0;
    // Pick the smallest CSM cascade still containing the fragment based
    // on distance to the camera (matches the per-cascade ortho extents
    // built in PHP land).
    // Over the last eighth of a cascade the next one fades in, so the switch of
    // resolution does not draw a line across the ground (shader plan 3.3).
    float dist = length(v_worldPos - u_camera_pos);
    if (u_csm_count >= 2 && dist > u_csm_far_0) {
        if (u_csm_count >= 3 && dist > u_csm_far_1) {
            return sampleCascade(u_csm_map_2, u_csm_matrix_2, v_worldPos, N);
        }
        float s1 = sampleCascade(u_csm_map_1, u_csm_matrix_1, v_worldPos, N);
        float b1 = u_csm_count >= 3 ? smoothstep(u_csm_far_1 * 0.875, u_csm_far_1, dist) : 0.0;
        return b1 > 0.0 ? mix(s1, sampleCascade(u_csm_map_2, u_csm_matrix_2, v_worldPos, N), b1) : s1;
    }
    float s0 = sampleCascade(u_csm_map_0, u_csm_matrix_0, v_worldPos, N);
    float b0 = u_csm_count >= 2 ? smoothstep(u_csm_far_0 * 0.875, u_csm_far_0, dist) : 0.0;
    return b0 > 0.0 ? mix(s0, sampleCascade(u_csm_map_1, u_csm_matrix_1, v_worldPos, N), b0) : s0;
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

// Interior light mask (Component\InteriorVolume, System\InteriorVolumeSystem): inside
// these boxes the sun term and most of the sky reflection are dropped, so a hall
// with a thin roof or walls the shadow cascades miss does not leak daylight.
// Slot k: centre xyz + yaw, half extents xyz + strength (0 = unused).
uniform vec4 u_interior_box_0;
uniform vec4 u_interior_ext_0;
uniform vec4 u_interior_box_1;
uniform vec4 u_interior_ext_1;
uniform vec4 u_interior_box_2;
uniform vec4 u_interior_ext_2;
uniform vec4 u_interior_box_3;
uniform vec4 u_interior_ext_3;

float interiorBox(vec3 p, vec4 c, vec4 e) {
    if (e.w <= 0.0) return 0.0;
    vec3 d = p - c.xyz;
    float cs = cos(c.w);
    float sn = sin(c.w);
    vec3 l = abs(vec3(cs * d.x - sn * d.z, d.y, sn * d.x + cs * d.z)) - e.xyz;
    // 0.5 m soft edge inside the box.
    return e.w * (1.0 - smoothstep(-0.5, 0.0, max(max(l.x, l.y), l.z)));
}

float interiorMask(vec3 p) {
    return max(max(interiorBox(p, u_interior_box_0, u_interior_ext_0), interiorBox(p, u_interior_box_1, u_interior_ext_1)),
               max(interiorBox(p, u_interior_box_2, u_interior_ext_2), interiorBox(p, u_interior_box_3, u_interior_ext_3)));
}

// Rain on the ground (shader plan 4.1): how soaked a point with normal n is (x) and
// how much of that is a standing puddle (y), from the weather's u_rain_wetness.
// Only up-facing, non-water surfaces outside every interior volume (any volume
// keeps the rain out, whatever its light strength); puddles stand in
// the hollows of a slow two-octave noise and spread as the rain grows.
vec2 rainSoak(vec3 p, vec3 n) {
    if (u_rain_wetness <= 0.0 || u_proc_mode == 2 || p.y < u_rain_dry_below) return vec2(0.0);
    float up = clamp(dot(n, vec3(0.0, 1.0, 0.0)) * 1.6 - 0.6, 0.0, 1.0);
    if (up <= 0.0 || interiorMask(p) > 0.0) return vec2(0.0);
    float soak = clamp(u_rain_wetness, 0.0, 1.0) * up;
    float h = noise(p.xz * 0.11) * 0.65 + noise(p.xz * 0.43) * 0.35;
    float t = 0.78 - 0.2 * u_rain_wetness;
    float puddle = smoothstep(t, t + 0.06, 1.0 - h) * soak;
    return vec2(soak, puddle);
}

// Sandstorm veil over a fog value: from 5 m on the scene drowns in sand of the
// storm's colour, lit by the ambient light, streaked by bands that race along the
// wind (shader plan 4.1). Not from inside an interior volume.
vec4 sandVeil(vec3 p, float d, vec4 fog) {
    if (u_sandstorm.a <= 0.0 || interiorMask(u_camera_pos) > 0.0) return fog;
    vec2 w = u_sandstorm_wind;
    vec2 across = vec2(-w.y, w.x);
    float band = noise(vec2(dot(p.xz, across) * 0.08, p.y * 0.25 + dot(p.xz, w) * 0.01 - u_frag_time * 1.6));
    float s = u_sandstorm.a * smoothstep(3.0, 60.0, d) * (0.8 + 0.4 * band) * 1.15;
    s = clamp(s, 0.0, 0.97);
    vec3 sandLit = u_sandstorm.rgb * (u_ambient_color * u_ambient_intensity + 0.15);
    float total = 1.0 - (1.0 - fog.a) * (1.0 - s);
    vec3 c = (fog.rgb * fog.a + sandLit * s * (1.0 - fog.a)) / max(total, 1e-4);
    return vec4(c, total);
}

// Fog at a world point, rgb = its colour, a = how much of it: the distance fog and,
// in rain, the rain veil (shader plan 4.1) – beyond 25 m surfaces fade into the
// falling rain, lit like a white surface under that sky (the ambient light; the
// fog and horizon colours stay bright under clouds), denser shafts drift across (a slow
// noise over the view direction and time); not from inside an interior volume.
// Shared with the proc-mode branches that output on their own:
// mix(colour, fog.rgb, fog.a).
// Height fog and fog bodies over a fog value (shader plan 4.2), both integrated
// analytically along camera -> p: the exponential height fog's optical depth in
// closed form, and each box volume's density times the length of the ray inside
// it (slab test in its yawed frame, softened by its edge at the segment middle).
// Own colours are lit by the ambient and a little sun light.
vec4 mediaFog(vec3 p, float d, vec4 fog) {
    if (u_height_fog.w < 0.5 && u_fog_volume_count <= 0) return fog;
    vec3 light = u_ambient_color * u_ambient_intensity + u_dir_light_color * u_dir_light_intensity * 0.25;
    float tau = 0.0;
    vec3 tc = vec3(0.0);
    if (u_height_fog.w > 0.5) {
        // No fog below the base (a sea level: the underwater view has its own
        // tint): the ray is clipped to its part above it.
        float b = u_height_fog.y;
        float base = u_height_fog.z;
        float y0 = u_camera_pos.y;
        float y1 = p.y;
        float th = 0.0;
        if (max(y0, y1) > base) {
            float frac = 1.0;
            if (y0 < base) { frac = (y1 - base) / (y1 - y0); y0 = base; }
            else if (y1 < base) { frac = (y0 - base) / (y0 - y1); y1 = base; }
            float k = b * (y1 - y0);
            float shape = abs(k) > 1e-4 ? (1.0 - exp(-k)) / k : 1.0;
            th = u_height_fog.x * exp(-b * min(y0 - base, 400.0)) * d * frac * shape;
        }
        th = clamp(th, 0.0, 40.0);
        tau += th;
        tc += th * (u_height_fog_color.w > 0.5 ? u_height_fog_color.rgb * light : u_fog_color);
    }
    for (int k = 0; k < 4; k++) {
        if (k >= u_fog_volume_count) break;
        vec4 cb = u_fog_volumes[k * 3];
        vec4 eh = u_fog_volumes[k * 3 + 1];
        vec4 cc = u_fog_volumes[k * 3 + 2];
        float cs = cos(cb.w);
        float sn = sin(cb.w);
        vec3 o = u_camera_pos - cb.xyz;
        vec3 e = p - cb.xyz;
        o = vec3(cs * o.x - sn * o.z, o.y, sn * o.x + cs * o.z);
        e = vec3(cs * e.x - sn * e.z, e.y, sn * e.x + cs * e.z);
        vec3 r = e - o;
        vec3 inv = 1.0 / (sign(r) * max(abs(r), vec3(1e-5)) + vec3(1e-9));
        vec3 t0 = (-eh.xyz - o) * inv;
        vec3 t1 = ( eh.xyz - o) * inv;
        vec3 tmin = min(t0, t1);
        vec3 tmax = max(t0, t1);
        float a = clamp(max(max(tmin.x, tmin.y), tmin.z), 0.0, 1.0);
        float z = clamp(min(min(tmax.x, tmax.y), tmax.z), 0.0, 1.0);
        if (z <= a) continue;
        vec3 mid = abs(o + r * (0.5 * (a + z))) / max(eh.xyz, vec3(1e-3));
        float soft = 1.0 - smoothstep(1.0 - cc.w, 1.0, max(max(mid.x, mid.y), mid.z));
        float tv = clamp(eh.w * (z - a) * d * soft, 0.0, 40.0);
        tau += tv;
        tc += tv * cc.rgb * light;
    }
    if (tau <= 1e-5) return fog;
    float am = 1.0 - exp(-tau);
    vec3 mc = tc / tau;
    float total = 1.0 - (1.0 - fog.a) * (1.0 - am);
    vec3 c = (fog.rgb * fog.a + mc * am * (1.0 - fog.a)) / max(total, 1e-4);
    return vec4(c, total);
}

vec4 sceneFog(vec3 p) {
    float d = length(p - u_camera_pos);
    float f = clamp((d - u_fog_near) / (u_fog_far - u_fog_near), 0.0, 1.0);
    vec4 base = mediaFog(p, d, vec4(u_fog_color, 1.0 - exp(-f * f * 3.0)));
    float t = base.a;
    if (u_rain_wetness > 0.0 && u_camera_pos.y >= u_rain_dry_below && interiorMask(u_camera_pos) <= 0.0) {
        vec3 vd = (p - u_camera_pos) / max(d, 1e-3);
        float shaft = 0.55 + 0.45 * noise(vd.xz * 7.0 + vec2(u_frag_time * 0.12, u_frag_time * 0.05));
        float veil = clamp(u_rain_wetness, 0.0, 1.0) * 0.45 * smoothstep(25.0, 160.0, d) * shaft;
        float total = 1.0 - (1.0 - t) * (1.0 - veil);
        vec3 veilColor = u_ambient_color * u_ambient_intensity;
        vec3 c = (base.rgb * t + veilColor * veil * (1.0 - t)) / max(total, 1e-4);
        return sandVeil(p, d, vec4(c, total));
    }
    return sandVeil(p, d, base);
}

// Light the air scatters towards the eye between it and p (shader plan 4.2): a halo
// round each of the nearest point lights - the inverse-square in-scatter along
// the ray in closed form, windowed by the light's radius - and the visible cone
// of each spot light, sampled through its range sphere (with the spot shadow for
// the beam that has one). Rain thickens the air. Additive local light.
vec3 lightInscatter(vec3 p) {
    float sigma = u_light_halo + 0.03 * clamp(u_rain_wetness, 0.0, 1.0);
    // Indoor air is dry: a third of the scattering from inside an interior volume.
    if (interiorMask(u_camera_pos) > 0.0) sigma *= 0.3;
    // NaN-safe: an unset uniform must not poison the colour.
    if (!(sigma > 0.0)) return vec3(0.0);
    vec3 o = u_camera_pos;
    vec3 rd = p - o;
    float len = length(rd);
    if (len < 0.01) return vec3(0.0);
    rd /= len;
    vec3 sum = vec3(0.0);
    int pn = min(u_point_light_count, 8);
    for (int i = 0; i < 8; i++) {
        if (i >= pn) break;
        vec3 lp = u_point_lights[i].position;
        // The halo hugs the lamp: a third of its reach, at most 3.5 m.
        float r = clamp(u_point_lights[i].radius * 0.3, 0.01, 3.5);
        float tc = dot(lp - o, rd);
        float h = max(length(lp - (o + rd * tc)), 0.08);
        if (h >= r) continue;
        float a = (atan((len - tc) / h) - atan(-tc / h)) / h;
        float w = 1.0 - h * h / (r * r);
        // Lamps behind the viewer would tint the whole view: they fade out.
        sum += u_point_lights[i].color * u_point_lights[i].intensity * a * w * w * 3.0 * smoothstep(-1.0, 3.0, tc);
    }
    float jit = fract(52.9829189 * fract(dot(gl_FragCoord.xy, vec2(0.06711056, 0.00583715))));
    int sn = min(u_spot_light_count, 4);
    for (int i = 0; i < 4; i++) {
        if (i >= sn) break;
        vec3 lp = u_spot_lights[i].position;
        float R = max(u_spot_lights[i].range, 0.01);
        vec3 oc = lp - o;
        float b = dot(oc, rd);
        float disc = b * b - (dot(oc, oc) - R * R);
        if (disc <= 0.0) continue;
        float sq = sqrt(disc);
        float t0 = max(b - sq, 0.0);
        float t1 = min(b + sq, len);
        if (t1 <= t0) continue;
        vec3 sd = normalize(u_spot_lights[i].direction);
        // Sample round where the view ray passes closest to the cone's axis, over
        // the cone's width there: a narrow beam is hit, a wide cone filled.
        vec3 w0 = o - lp;
        float bb = dot(rd, sd);
        float dd = dot(rd, w0);
        float ee = dot(sd, w0);
        float den = max(1.0 - bb * bb, 1e-4);
        float tRay = clamp((bb * ee - dd) / den, t0, t1);
        float sAxis = clamp(dot(o + rd * tRay - lp, sd), 0.0, R);
        float hw = sAxis * tan(u_spot_lights[i].angle) * 1.5 / max(sqrt(den), 0.2) + 1.0;
        float a0 = max(t0, tRay - hw);
        float a1 = min(t1, tRay + hw);
        if (a1 <= a0) continue;
        float st = (a1 - a0) / 8.0;
        float cosOuter = cos(u_spot_lights[i].angle);
        float cosInner = cos(u_spot_lights[i].angle * (1.0 - u_spot_lights[i].penumbra));
        vec3 acc = vec3(0.0);
        for (int j = 0; j < 8; j++) {
            vec3 q = o + rd * (a0 + (float(j) + jit) * st);
            vec3 lq = lp - q;
            float dq = length(lq);
            vec3 ln = lq / max(dq, 1e-3);
            float cone = smoothstep(cosOuter, cosInner, dot(-ln, sd));
            if (cone <= 0.0) continue;
            float at = clamp(1.0 - dq * dq / (R * R), 0.0, 1.0);
            // The engine's light falloff (a window, no inverse square), as on surfaces.
            // (No shadow lookup here: a comparison sample in this loop miscompiled.)
            acc += vec3(at * at * cone);
        }
        sum += u_spot_lights[i].color * u_spot_lights[i].intensity * acc * st * 0.15;
    }
    return sum * sigma * 0.08;
}

// Rain drops on glass (shader plan 4.1): resting drops in 2 cm cells and, on steep
// panes, drops running down in columns. Surface coordinates: along the pane's
// horizontal tangent and world height. Returns x = coverage 0..1, yz = the tilt
// of the normal in tangent/up.
vec3 glassRain(vec3 p, vec3 n, float time) {
    vec3 t = cross(n, vec3(0.0, 1.0, 0.0));
    float tl = length(t);
    t = tl > 1e-3 ? t / tl : vec3(1.0, 0.0, 0.0);
    vec2 s = vec2(dot(p, t), p.y);
    // Resting drops.
    vec2 c = floor(s * 45.0);
    vec2 f = fract(s * 45.0) - 0.5;
    vec2 jit = vec2(hash21(c), hash21(c + 5.3)) - 0.5;
    vec2 d = f - jit * 0.4;
    float r = 0.15 + 0.2 * hash21(c + 9.1);
    float rest = step(0.55, hash21(c + 2.7)) * smoothstep(r, r * 0.6, length(d));
    vec2 tilt = rest > 0.0 ? d / max(length(d), 1e-3) * rest * 0.6 : vec2(0.0);
    // Running drops on steep panes: a column every 6 cm, a drop sliding down.
    float steep = 1.0 - abs(n.y);
    float col = floor(s.x * 16.0);
    float cx = fract(s.x * 16.0) - 0.5;
    float speed = 0.25 + 0.35 * hash21(vec2(col, 1.7));
    float y = fract(s.y * 1.5 + time * speed + hash21(vec2(col, 4.4)));
    float head = smoothstep(0.12, 0.0, abs(cx)) * smoothstep(0.04, 0.0, abs(y - 0.5));
    float trail = smoothstep(0.06, 0.0, abs(cx)) * smoothstep(0.5, 0.52, y) * (1.0 - smoothstep(0.52, 0.9, y)) * 0.5;
    float run = (head + trail) * step(0.6, hash21(vec2(col, 8.2))) * steep;
    return vec3(clamp(rest + run, 0.0, 1.0), tilt.x + cx * run, tilt.y);
}

// Rain rings on wet ground (shader plan 4.1): two offset grids of 0.35 m cells, each
// with a drop landing at a random spot and time; its ring grows and fades over its
// cycle. Returns the horizontal tilt of the normal.
vec2 rainRipples(vec2 xz, float time) {
    vec2 tilt = vec2(0.0);
    for (int layer = 0; layer < 2; layer++) {
        vec2 q = xz / 0.35 + vec2(float(layer) * 0.37, float(layer) * 0.61);
        vec2 cell = floor(q);
        float rnd = hash21(cell + float(layer) * 17.0);
        vec2 centre = cell + 0.2 + 0.6 * vec2(hash21(cell + 3.1), hash21(cell + 7.7));
        float phase = fract(time * 1.3 + rnd);
        vec2 d = (q - centre) * 0.35;
        float dist = length(d);
        float r = phase * 0.16;
        float ring = sin(clamp((dist - r) * 90.0, -3.14159, 3.14159)) * (1.0 - phase) * step(abs(dist - r), 0.035);
        tilt += (d / max(dist, 1e-4)) * ring;
    }
    return tilt * 0.35;
}

// Environment (IBL) reflection: the bound cubemap — GPU sky probe (mipmapped,
// u_env_mip_max > 0) or a baked reflection_probe — sampled at a roughness-
// mapped LOD; falls back to the horizon/sky tint when no map is bound.
vec3 sampleEnvironmentProbe(vec3 R, float roughness);
vec3 sampleEnvironment(vec3 R, float roughness) {
#ifdef PHPOLYGON_RAY_QUERY
    if (u_rt_reflections > 0.5 && roughness < 0.45) {
        rayQueryEXT rr;
        rayQueryInitializeEXT(rr, u_tlas, gl_RayFlagsOpaqueEXT, 0xFF, v_worldPos + R * 0.05, 0.02, R, 400.0);
        while (rayQueryProceedEXT(rr)) {}
        if (rayQueryGetIntersectionTypeEXT(rr, true) != gl_RayQueryCommittedIntersectionNoneEXT) {
            int id = rayQueryGetIntersectionInstanceIdEXT(rr, true);
            float t = rayQueryGetIntersectionTEXT(rr, true);
            vec3 hp = v_worldPos + R * t;
            vec3 alb = vec3(rtAlbedo[id * 4], rtAlbedo[id * 4 + 1], rtAlbedo[id * 4 + 2]);
            vec3 lit = u_ambient_color * 0.55;
            if (u_dir_light_count > 0) {
                vec3 sL = normalize(-u_dir_lights[0].direction);
                rayQueryEXT rs;
                rayQueryInitializeEXT(rs, u_tlas, gl_RayFlagsTerminateOnFirstHitEXT | gl_RayFlagsOpaqueEXT, 0xFF, hp - R * 0.05, 0.02, sL, 2000.0);
                while (rayQueryProceedEXT(rs)) {}
                float vis = rayQueryGetIntersectionTypeEXT(rs, true) == gl_RayQueryCommittedIntersectionNoneEXT ? 1.0 : 0.0;
                lit += u_dir_lights[0].color * u_dir_lights[0].intensity * vis * 0.55;
            }
            vec3 hit = alb * lit + rtAlbedo[id * 4 + 3] * alb;
            // Rougher surfaces blur the hit into the sky probe.
            return mix(hit, sampleEnvironmentProbe(R, roughness), smoothstep(0.15, 0.45, roughness));
        }
    }
#endif
    return sampleEnvironmentProbe(R, roughness);
}

vec3 sampleEnvironmentProbe(vec3 R, float roughness) {
    float skyBlend = clamp(R.y * 2.0, 0.0, 1.0);
    vec3 fallback = mix(u_horizon_color, u_sky_color, skyBlend);
    if (u_has_environment_map == 0) {
        return fallback;
    }
    float lod = roughness * (u_env_mip_max > 0.0 ? u_env_mip_max : 6.0);
    vec3 envColor = textureLod(u_environment_map, R, lod).rgb;
    // Un-mipped probes cannot be pre-filtered: blend towards the flat sky mix
    // as roughness grows so the reflection still reads plausibly.
    float blur = u_env_mip_max > 0.0 ? 0.0 : smoothstep(0.4, 1.0, roughness);
    return mix(envColor, fallback, blur);
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

#ifndef PHPOLYGON_OMIT_VOLUMETRIC
// Visibility of the primary light at a point ON the view ray (not on a surface):
// one tap of the cascade that covers it, no normal bias. Outside every cascade
// (beyond the shadow distance) the point counts as lit.
float volumetricShadow(vec3 p) {
    if (u_has_shadow_map == 0) return 1.0;
    float dist = length(p - u_camera_pos);
    vec4 lsp;
    if (u_csm_count >= 2 && dist > u_csm_far_0) {
        if (u_csm_count >= 3 && dist > u_csm_far_1) {
            lsp = u_csm_matrix_2 * vec4(p, 1.0);
            vec3 pc = lsp.xyz / lsp.w * 0.5 + 0.5;
            if (pc.x < 0.0 || pc.x > 1.0 || pc.y < 0.0 || pc.y > 1.0 || pc.z > 1.0) return 1.0;
            return texture(u_csm_map_2, vec3(pc.xy, pc.z - 0.002));
        }
        lsp = u_csm_matrix_1 * vec4(p, 1.0);
        vec3 pc = lsp.xyz / lsp.w * 0.5 + 0.5;
        if (pc.x < 0.0 || pc.x > 1.0 || pc.y < 0.0 || pc.y > 1.0 || pc.z > 1.0) return 1.0;
        return texture(u_csm_map_1, vec3(pc.xy, pc.z - 0.002));
    }
    lsp = u_csm_matrix_0 * vec4(p, 1.0);
    vec3 pc = lsp.xyz / lsp.w * 0.5 + 0.5;
    if (pc.x < 0.0 || pc.x > 1.0 || pc.y < 0.0 || pc.y > 1.0 || pc.z > 1.0) return 1.0;
    return texture(u_csm_map_0, vec3(pc.xy, pc.z - 0.002));
}

vec3 volumetricScatter(vec3 worldPos) {
    if (u_volumetric_fog == 0 || u_dir_light_count == 0) return vec3(0.0);
    float lightScale = clamp(1.0 - u_volumetric_light_dim, 0.0, 1.0);
    if (lightScale <= 0.0) return vec3(0.0);
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
    // Per-pixel start offset (interleaved gradient noise): 8 shadow taps along
    // the ray would band at shadow edges; jittered, temporal AA resolves them.
    float jitter = fract(52.9829189 * fract(dot(gl_FragCoord.xy, vec2(0.06711056, 0.00583715))));
    vec3 scatter = vec3(0.0);
    float transmittance = 1.0;
    for (int i = 0; i < STEPS; i++) {
        vec3 p = rayStart + rayDir * (step * (float(i) + jitter));
        float density = exp(-max(p.y, 0.0) * 0.08) * 0.06;
        // Only light that reaches the sample scatters: without this a wall
        // between the camera and the sun (or the room around the camera) still
        // glowed with the full godray, as if the light shone through it.
        float lit = volumetricShadow(p);
        vec3 inscatter = u_dir_lights[0].color * u_dir_lights[0].intensity * phase * density * lit;
        scatter += inscatter * transmittance * step;
        transmittance *= exp(-density * step);
    }
    // Scale + cap the integrated in-scatter. The raw march integrates to ~1+
    // (a full white wash); 0.3× brings the away-from-sun haze down to a faint
    // tint while the cap keeps the sun-facing godray from blowing out. The light
    // scale applies AFTER the cap: the march saturates long before the cap for
    // any light of useful intensity, so a scale inside it would change nothing.
    return min(scatter * 0.3, vec3(0.25)) * lightScale;
}
#else
vec3 volumetricScatter(vec3 worldPos) { return vec3(0.0); }
#endif // PHPOLYGON_OMIT_VOLUMETRIC

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

#ifdef PHPOLYGON_SCENE_DEPTH
// Linear depth of the opaque surface behind this fragment; far away while unknown.
float sceneLinearDepth() {
    if (u_scene_depth_ready < 0.5) return 60000.0;
    return texture(u_scene_depth, gl_FragCoord.xy / u_viewport_size).r;
}

// How far the opaque surface lies behind this fragment, in world units (0 = touching).
float sceneDepthDelta() {
    return sceneLinearDepth() + dot(u_scene_view_z, vec4(v_worldPos, 1.0));
}
#endif // PHPOLYGON_SCENE_DEPTH

// PHPOLYGON:POSTCOLOR_HELPERS

#ifdef PHPOLYGON_MRT
// The attachments' alpha is COVERAGE for the composite: it blends the split over
// the scene target's clear colour and divides the colour back out by it. An
// opaque draw (material alpha 1, drawn with blending off) covers its pixel
// whatever alpha its shading computed - a procedural branch such as water that
// fades to 0 at the shore would otherwise punch the clear colour (black) into
// the frame wherever that surface is the nearest one.
float mrtCoverage(float alpha) {
    return u_alpha >= 1.0 ? 1.0 : alpha;
}
#ifdef PHPOLYGON_MOTION
// The reactive mask: a transparent surface's coverage, an opaque one's own value
// (Material::$reactive), whichever is larger.
float reactiveValue(float coverage) {
    return clamp(max(u_material_reactive, u_alpha >= 1.0 ? 0.0 : coverage), 0.0, 1.0);
}
#endif
#endif

vec4 outputColor(vec3 color, float alpha) {
    color = max(color, vec3(0.0));
    if (u_linear_output == 0) {
        // Tonemap + gamma only. Colour grade + vignette moved to the present
        // pass (fxaa/passthrough) so they cover the whole frame — including the
        // sky — uniformly, instead of being baked per geometry fragment here.
        color = toneMapACES(color);
        color = pow(color, vec3(1.0 / 2.2));
    }
#ifdef PHPOLYGON_MRT
    // Only branches that end the fragment early call this on the MRT path
    // (`frag_color = outputColor(c, alpha); return;` writes o_local). main()
    // seeded o_sun / o_ambient with the MATERIAL alpha, but the branch may
    // have computed its own: all attachments must carry the same coverage, or
    // the empty sun / ambient terms of a surface the branch made invisible
    // cover the geometry behind it (a black patch).
    alpha = mrtCoverage(alpha);
    o_sun.a = alpha;
    o_ambient.a = alpha;
#ifdef PHPOLYGON_MOTION
    float reactiveAlpha = reactiveValue(alpha);
    o_reactive = vec4(reactiveAlpha, reactiveAlpha, reactiveAlpha, 1.0);
#endif
#endif
    return vec4(color, alpha);
}

// Game post-colour hook (PHPOLYGON:POSTCOLOR). Wrapped in a function so the MRT
// path can evaluate it as an affine map: hook(c) = S * c + A with A = hook(0),
// S = hook(1) - A, and distribute S/A exactly over the split outputs. Any hook that
// is affine in the colour (tints, fades, absorption) round-trips exactly; the
// single-target path still applies it to the summed colour as before.
vec3 applyPostColor(vec3 color) {
    // PHPOLYGON:POSTCOLOR
    return color;
}

#ifdef PHPOLYGON_MRT
// Octahedral normal encoding — identical to gbuffer.frag so ssao/sdf_ao/ssr decode
// it unchanged (full-sphere, never flips a normal).
vec2 octWrap(vec2 v) {
    return (1.0 - abs(v.yx)) * vec2(v.x >= 0.0 ? 1.0 : -1.0, v.y >= 0.0 ? 1.0 : -1.0);
}
vec2 octEncode(vec3 n) {
    n /= (abs(n.x) + abs(n.y) + abs(n.z));
    n.xy = n.z >= 0.0 ? n.xy : octWrap(n.xy);
    return n.xy;
}
// gbuffer.frag's fragment, evaluated from the forward pass's inputs: view-space
// geometric normal (before procedural perturbation, like the G-buffer pass), the
// material reflectivity (metallic / smoothness / wetness with the ocean shoreline
// fade) and linear view depth. a == 0 marks sky for the AO passes, so an opted-out
// draw writes zeros.
vec4 gbufferValue(vec3 worldN) {
    if (u_gbuffer_write == 0) return vec4(0.0);
    vec3 n = normalize(mat3(u_gbuffer_view) * worldN);
    float linearDepth = -(u_gbuffer_view * vec4(v_worldPos, 1.0)).z;
    float smoothness = 1.0 - clamp(u_roughness, 0.0, 1.0);
    // Rain: a wet film reflects a little, a puddle a lot (rainSoak, shader plan 4.1).
    vec2 soak = rainSoak(v_worldPos, worldN);
    float metalRefl = u_metallic * smoothness;
    float wetRefl   = max(u_wetness * smoothness * smoothness, soak.x * 0.3 + soak.y * 0.6);
    float reflectivity = clamp(max(metalRefl, wetRefl), 0.0, 1.0);
    if (u_proc_mode == 2) {
        float r = length(v_worldPos.xz);
        reflectivity *= smoothstep(92.0, 100.0, r);
    }
    // Skin (subsurface on a closed surface, not two-sided foliage) marks itself for the
    // screen-space subsurface blur of the composite: a negative value, its strength.
    // Reflections treat it as matte.
    if (u_subsurface_strength > 0.0 && u_two_sided == 0) {
        reflectivity = -clamp(u_subsurface_strength, 0.01, 1.0);
    }
    return vec4(octEncode(n), reflectivity, linearDepth);
}
#endif

// PHPOLYGON:PROCMODE_HELPERS
// PHPOLYGON:SURFACE_HELPERS

#ifndef PHPOLYGON_OMIT_NORMAL_PATTERNS
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

#ifndef PHPOLYGON_OMIT_KIT_PATTERNS
// ── Kit patterns: formwork, aggregate, brushed, soft hammered, planks ─────────
// Lengths are metres of pattern UV at normalScale 1 (metric UVs). Each normal is
// taken from the pattern's own height field (central differences over a few
// millimetres), so relief, cavity and parallax agree.

// Plank layout: rows 0.12 m wide, planks 1.2 m long along U, a random butt-joint
// offset per row. id = (plank, row), local = position in the plank (along, across) 0..1.
void plankLayout(vec2 p, out vec2 id, out vec2 local) {
    float row = floor(p.y / 0.12);
    float along = (p.x + hash21(vec2(row, 4.1)) * 1.2) / 1.2;
    id = vec2(floor(along), row);
    local = vec2(fract(along), fract(p.y / 0.12));
}

// One octave of aggregate: a jittered pit or bump per cell, soft-edged. -1..1.
float aggregateCell(vec2 p, float seed) {
    vec2 id = floor(p);
    vec2 f = fract(p) - 0.5;
    vec2 j = (vec2(hash21(id + seed), hash21(id + seed + 7.3)) - 0.5) * 0.4;
    float size = 0.2 + 0.25 * hash21(id + seed + 3.1);
    float sgn = hash21(id + seed + 9.7) > 0.5 ? 1.0 : -1.0;
    return sgn * smoothstep(size, 0.0, length(f - j));
}

float nh_formwork(vec2 uv);
vec3 np_formwork(vec2 uv) {
    float e = 0.003;
    float hx = nh_formwork(uv + vec2(e, 0.0)) - nh_formwork(uv - vec2(e, 0.0));
    float hy = nh_formwork(uv + vec2(0.0, e)) - nh_formwork(uv - vec2(0.0, e));
    return normalize(vec3(-hx * 1.5, -hy * 1.5, 1.0));
}

float nh_aggregate(vec2 uv);
vec3 np_aggregate(vec2 uv) {
    float e = 0.002;
    float hx = nh_aggregate(uv + vec2(e, 0.0)) - nh_aggregate(uv - vec2(e, 0.0));
    float hy = nh_aggregate(uv + vec2(0.0, e)) - nh_aggregate(uv - vec2(0.0, e));
    return normalize(vec3(-hx * 1.2, -hy * 1.2, 1.0));
}

// Anisotropic strands along U: 300 per metre, each with its own depth and phase.
// Fades out where a strand is thinner than about a pixel.
vec3 np_brushed(vec2 uv) {
    float t = uv.y * 300.0;
    float lane = floor(t);
    float strand = hash21(vec2(lane, 11.3));
    float along = 0.7 + 0.3 * noise(vec2(uv.x * 5.0, lane * 0.37));
    float aa = 1.0 - smoothstep(0.3, 0.8, fwidth(t));
    float slopeY = cos(fract(t) * 6.28318 + strand * 6.28318) * (0.3 + 0.7 * strand) * along * aa;
    float slopeX = (noise(vec2(uv.x * 60.0, lane * 0.31)) - 0.5) * 0.3 * strand * aa;
    return normalize(vec3(-slopeX * 0.25, -slopeY * 0.25, 1.0));
}

float nh_hammered_soft(vec2 uv);
vec3 np_hammered_soft(vec2 uv) {
    float e = 0.01;
    float hx = nh_hammered_soft(uv + vec2(e, 0.0)) - nh_hammered_soft(uv - vec2(e, 0.0));
    float hy = nh_hammered_soft(uv + vec2(0.0, e)) - nh_hammered_soft(uv - vec2(0.0, e));
    return normalize(vec3(-hx * 4.0, -hy * 4.0, 1.0));
}

float nh_wood_planks(vec2 uv);
vec3 np_wood_planks(vec2 uv) {
    float e = 0.0003;
    float hx = nh_wood_planks(uv + vec2(e, 0.0)) - nh_wood_planks(uv - vec2(e, 0.0));
    float hy = nh_wood_planks(uv + vec2(0.0, e)) - nh_wood_planks(uv - vec2(0.0, e));
    return normalize(vec3(-hx * 3.0, -hy * 3.0, 1.0));
}

#endif // PHPOLYGON_OMIT_KIT_PATTERNS
float nh_cobblestone(vec2 uv);
// Normal from the cobblestone height field (central differences).
float nh_roof_tiles(vec2 uv);
vec3 np_roof_tiles(vec2 uv) {
    float e = 0.004;
    float hx = nh_roof_tiles(uv + vec2(e, 0.0)) - nh_roof_tiles(uv - vec2(e, 0.0));
    float hy = nh_roof_tiles(uv + vec2(0.0, e)) - nh_roof_tiles(uv - vec2(0.0, e));
    return normalize(vec3(-hx * 2.5, -hy * 2.5, 1.0));
}

vec3 np_cobblestone(vec2 uv) {
    float e = 0.004;
    float hx = nh_cobblestone(uv + vec2(e, 0.0)) - nh_cobblestone(uv - vec2(e, 0.0));
    float hy = nh_cobblestone(uv + vec2(0.0, e)) - nh_cobblestone(uv - vec2(0.0, e));
    return normalize(vec3(-hx * 3.0, -hy * 3.0, 1.0));
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
    if (code == 11) return np_cobblestone(uv);
    if (code == 12) return np_roof_tiles(uv);
#ifndef PHPOLYGON_OMIT_KIT_PATTERNS
    if (code == 13) return np_formwork(uv);
    if (code == 14) return np_aggregate(uv);
    if (code == 15) return np_brushed(uv);
    if (code == 16) return np_hammered_soft(uv);
    if (code == 17) return np_wood_planks(uv);
#endif // PHPOLYGON_OMIT_KIT_PATTERNS
    return vec3(0.0, 0.0, 1.0);
}
#endif // PHPOLYGON_OMIT_NORMAL_PATTERNS

#ifndef PHPOLYGON_OMIT_SURFACE_PATTERNS
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

// Marble (shader plan 5.5): soft dark veins winding through pale stone.
vec3 sp_marble(vec2 uv) {
    float w = uv.x * 2.0 + uv.y * 1.3 + fbm3(uv * 3.0) * 3.5;
    float vein = 1.0 - smoothstep(0.0, 0.12, abs(sin(w * 3.14159)));
    return vec3(0.5 - vein * 0.18 + (noise(uv * 20.0) - 0.5) * 0.03, -0.15 * (1.0 - vein), 0.0);
}

// Sandstone: fine grain and horizontal bedding layers.
vec3 sp_sandstone(vec2 uv) {
    float layers = sin(uv.y * 18.0 + noise(uv * vec2(2.0, 6.0)) * 3.0) * 0.5 + 0.5;
    float grain = noise(uv * 60.0);
    return vec3(0.5 + (layers - 0.5) * 0.12 + (grain - 0.5) * 0.08, 0.05, 0.0);
}

#ifndef PHPOLYGON_OMIT_KIT_PATTERNS
// Soft mottling of the albedo only: +-8 % at intensity 1, blotches of about a metre.
vec3 sp_mottle(vec2 uv) {
    float m = smoothstep(0.3, 0.7, fbm3(uv * 1.3));
    return vec3(0.5 + (m - 0.5) * 0.08, 0.0, 0.0);
}

// Worn paint without the hard step: chips fade in, the underlay is 15 % darker,
// roughness / metal deltas stop at 0.1 (scuffing, not camouflage).
vec3 sp_worn_paint_soft(vec2 uv) {
    float chip = smoothstep(0.50, 0.68, fbm3(uv * 3.0));
    return vec3(mix(0.50, 0.425, chip), 0.1 * chip, 0.1 * chip);
}

// Per-plank tone, roughness and darker gaps; the layout of np_wood_planks (same
// scale gives the same planks): rows 0.12 m wide, planks 1.2 m long along U.
vec3 sp_wood_planks(vec2 uv) {
    float row = floor(uv.y / 0.12);
    float along = (uv.x + hash21(vec2(row, 4.1)) * 1.2) / 1.2;
    vec2 id = vec2(floor(along), row);
    vec2 loc = vec2(fract(along), fract(uv.y / 0.12));
    vec2 edge = min(loc, 1.0 - loc) * vec2(1.2, 0.12);
    float gap = 1.0 - smoothstep(0.0015, 0.004, min(edge.x, edge.y));
    float tone = (hash21(id + 1.3) - 0.5) * 0.11 + (noise(vec2(uv.x * 2.0, row * 3.1)) - 0.5) * 0.03;
    return vec3((0.5 + tone) * (1.0 - 0.35 * gap), (hash21(id + 6.1) - 0.5) * 0.1, 0.0);
}

#endif // PHPOLYGON_OMIT_KIT_PATTERNS
vec3 dispatchSurfacePattern(int code, vec2 uv) {
    if (code == 1) return sp_worn_paint(uv);
    if (code == 2) return sp_rust(uv);
    if (code == 3) return sp_brushed_metal(uv);
    if (code == 4) return sp_polished_rings(uv);
    if (code == 5) return sp_skin(uv);
    if (code == 6) return sp_marble(uv);
    if (code == 7) return sp_sandstone(uv);
#ifndef PHPOLYGON_OMIT_KIT_PATTERNS
    if (code == 8) return sp_mottle(uv);
    if (code == 9) return sp_worn_paint_soft(uv);
    if (code == 10) return sp_wood_planks(uv);
#endif // PHPOLYGON_OMIT_KIT_PATTERNS
    return vec3(0.5, 0.0, 0.0);
}
#endif // PHPOLYGON_OMIT_SURFACE_PATTERNS

#ifndef PHPOLYGON_OMIT_NORMAL_PATTERNS
// ── Procedural height fields (mirrors mesh3d.frag.glsl) ─────────────────────
// One per normal pattern, over the same pattern UV: 1 = the surface, 0 = the
// deepest recess. They drive cavity darkening and parallax occlusion; lighting
// keeps using the normal patterns above.

float nh_bricks(vec2 uv) {
    vec2 cell = vec2(0.5, 1.0);
    float rowIndex = floor(uv.y / cell.y);
    float xOffset = mod(rowIndex, 2.0) * 0.5 * cell.x;
    vec2 local = vec2(fract((uv.x + xOffset) / cell.x),
                      fract(uv.y / cell.y));
    float mortarX = 1.0 - (smoothstep(0.0, 0.06, local.x) *
                           smoothstep(1.0, 0.94, local.x));
    float mortarY = 1.0 - (smoothstep(0.0, 0.06, local.y) *
                           smoothstep(1.0, 0.94, local.y));
    return 1.0 - max(mortarX, mortarY);
}

float nh_bumps(vec2 uv) {
    return noise(uv * 8.0);
}

float nh_orange_peel(vec2 uv) {
    return 0.75 + 0.25 * hash21(floor(uv * 60.0));
}

float nh_hammered(vec2 uv) {
    vec2 grid = uv * 6.0;
    vec2 cell = floor(grid);
    vec2 local = fract(grid) - 0.5;
    vec2 jitter = vec2(hash21(cell), hash21(cell + 17.0)) - 0.5;
    float r = length(local - jitter * 0.4);
    return 1.0 - 0.6 * smoothstep(0.45, 0.0, r);
}

float nh_hexagons(vec2 uv) {
    vec2 p = uv * 5.0;
    vec2 af = fract(vec2(p.x + p.y * 0.5, p.y * 0.866)) - 0.5;
    return 1.0 - smoothstep(0.42, 0.50, max(abs(af.x), abs(af.y)));
}

float nh_wood_grain(vec2 uv) {
    return 0.85 + 0.15 * sin(uv.y * 80.0 + noise(uv * vec2(20.0, 4.0)) * 6.0);
}

float nh_scratches(vec2 uv) {
    float rotated = uv.x * 0.97 + uv.y * 0.24;
    float across  = -uv.x * 0.24 + uv.y * 0.97;
    float lane = floor(across * 80.0);
    float scratch = sin((rotated + hash21(vec2(lane, 0.0)) * 6.28) * 30.0);
    float mask = step(0.6, hash21(vec2(lane, 13.0)));
    return 1.0 - 0.5 * mask * smoothstep(0.6, 1.0, -scratch);
}

float nh_cracked(vec2 uv) {
    vec2 p = uv * 8.0;
    vec2 ip = floor(p);
    vec2 fp = fract(p);
    float d1 = 8.0;
    float d2 = 8.0;
    for (int x = -1; x <= 1; x++) {
        for (int y = -1; y <= 1; y++) {
            vec2 g = vec2(float(x), float(y));
            vec2 jitter = vec2(hash21(ip + g), hash21(ip + g + 51.0));
            float d = length(g + jitter - fp);
            if (d < d1) { d2 = d1; d1 = d; }
            else if (d < d2) { d2 = d; }
        }
    }
    // A little wider than the lit crack line, so the recess reads at a distance.
    return smoothstep(0.0, 0.08, d2 - d1);
}

float nh_noise(vec2 uv) {
    return fbm3(uv * 6.0);
}

float nh_skin(vec2 uv) {
    return noise(uv * 14.0) * 0.55 + fbm3(uv * 4.0) * 0.45;
}

// Cobblestones (shader plan 5.1): rounded setts in staggered rows, each a little
// wider or narrower, higher or lower than its neighbours, in a mortar bed. No
// cell search (it runs inside the parallax march): one cell, its own jitter.
float nh_cobblestone(vec2 uv) {
    vec2 p = uv * vec2(5.0, 7.0);
    float row = floor(p.y);
    p.x += hash21(vec2(row, 3.0)) * 0.8 + mod(row, 2.0) * 0.5;
    vec2 id = floor(p);
    vec2 f = fract(p) - 0.5;
    vec2 size = 0.40 + 0.06 * vec2(hash21(id + 1.7), hash21(id + 5.3));
    vec2 q = abs(f) - size + 0.12;
    float d = length(max(q, 0.0)) + min(max(q.x, q.y), 0.0) - 0.12;
    float dome = smoothstep(0.04, -0.10, d);
    float stone = 0.82 + 0.18 * hash21(id + 9.0);
    return stone * sqrt(dome);
}

// Roof tiles (shader plan 5.5): rows of tiles, staggered, each rising towards its
// lower edge where it overlaps the row below; rounded along the row.
float nh_roof_tiles(vec2 uv) {
    vec2 p = uv * vec2(6.0, 9.0);
    float row = floor(p.y);
    p.x += mod(row, 2.0) * 0.5;
    vec2 f = fract(p);
    float lift = smoothstep(0.0, 0.85, 1.0 - f.y);
    float round_ = sin(f.x * 3.14159);
    float gap = smoothstep(0.0, 0.05, f.x) * smoothstep(1.0, 0.95, f.x);
    return (0.35 + 0.45 * lift + 0.2 * round_) * gap * (0.9 + 0.1 * hash21(floor(p) + 3.0));
}

#ifndef PHPOLYGON_OMIT_KIT_PATTERNS
// Board-formed concrete (metres): soft board joints every 0.2 m (each board a hair
// higher or lower), a panel joint every 2.4 m, tie holes on a 0.6 m grid (they sit
// mid-board), fine grain.
float nh_formwork(vec2 uv) {
    float board = uv.y / 0.2;
    float bf = fract(board);
    float joint = 1.0 - smoothstep(0.0, 0.012, min(bf, 1.0 - bf) * 0.2);
    float level = 0.03 * hash21(vec2(floor(board), 1.3));
    float pf = fract(uv.x / 2.4);
    float panel = 1.0 - smoothstep(0.0, 0.01, min(pf, 1.0 - pf) * 2.4);
    float tie = smoothstep(0.03, 0.012, length((fract(uv / 0.6) - 0.5) * 0.6));
    float grain = (fbm3(uv * 30.0) - 0.5) * 0.04;
    return clamp(1.0 - 0.35 * joint - 0.5 * panel - 0.45 * tie - level + grain, 0.0, 1.0);
}

// Aggregate: two octaves of soft pits and bumps (about 2.2 cm and 0.9 cm cells).
float nh_aggregate(vec2 uv) {
    return 0.85 + aggregateCell(uv * 45.0, 0.0) * 0.10 + aggregateCell(uv * 110.0, 17.0) * 0.05;
}

// Brushed strands, 300 per metre along U.
float nh_brushed(vec2 uv) {
    float t = uv.y * 300.0;
    float strand = hash21(vec2(floor(t), 11.3));
    return 1.0 - 0.15 * (0.5 + 0.5 * sin(fract(t) * 6.28318 + strand * 6.28318)) * (0.3 + 0.7 * strand);
}

// Soft hammered: dimples about 0.33 m apart, each a little deeper or shallower.
float nh_hammered_soft(vec2 uv) {
    vec2 grid = uv * 3.0;
    vec2 cell = floor(grid);
    vec2 jitter = vec2(hash21(cell), hash21(cell + 17.0)) - 0.5;
    float r = length(fract(grid) - 0.5 - jitter * 0.3);
    return 1.0 - (0.25 + 0.2 * hash21(cell + 5.0)) * smoothstep(0.42, 0.0, r);
}

// Planks: per-plank grain spacing (500..1200 rad/m across the plank) and phase, a
// slow waver along the plank, an occasional knot with rings, a gap between planks.
float nh_wood_planks(vec2 uv) {
    vec2 id;
    vec2 loc;
    plankLayout(uv, id, loc);
    float freq = 500.0 + 700.0 * hash21(id + 2.3);
    float warp = noise(vec2(uv.x * 3.0 + hash21(id) * 17.0, uv.y * 14.0)) * 5.0;
    float grain = 0.5 + 0.5 * sin(uv.y * freq + hash21(id + 5.9) * 6.2832 + warp);
    float h = 0.92 + 0.08 * grain * (0.5 + 0.5 * hash21(id + 8.8));
    float knot = step(0.82, hash21(id + 3.7));
    vec2 kc = vec2(0.2 + 0.6 * hash21(id + 6.6), 0.3 + 0.4 * hash21(id + 7.7));
    float kd = length((loc - kc) * vec2(1.2, 0.12));
    h -= knot * (0.25 * smoothstep(0.03, 0.0, kd) - 0.05 * sin(kd * 200.0) * smoothstep(0.06, 0.0, kd));
    vec2 edge = min(loc, 1.0 - loc) * vec2(1.2, 0.12);
    h -= 0.5 * (1.0 - smoothstep(0.0015, 0.004, min(edge.x, edge.y)));
    return clamp(h, 0.0, 1.0);
}

#endif // PHPOLYGON_OMIT_KIT_PATTERNS
float dispatchProceduralHeight(int code, vec2 uv) {
    if (code == 1)  return nh_bricks(uv);
    if (code == 2)  return nh_bumps(uv);
    if (code == 3)  return nh_orange_peel(uv);
    if (code == 4)  return nh_hammered(uv);
    if (code == 5)  return nh_hexagons(uv);
    if (code == 6)  return nh_wood_grain(uv);
    if (code == 7)  return nh_scratches(uv);
    if (code == 8)  return nh_cracked(uv);
    if (code == 9)  return nh_noise(uv);
    if (code == 10) return nh_skin(uv);
    if (code == 11) return nh_cobblestone(uv);
    if (code == 12) return nh_roof_tiles(uv);
#ifndef PHPOLYGON_OMIT_KIT_PATTERNS
    if (code == 13) return nh_formwork(uv);
    if (code == 14) return nh_aggregate(uv);
    if (code == 15) return nh_brushed(uv);
    if (code == 16) return nh_hammered_soft(uv);
    if (code == 17) return nh_wood_planks(uv);
#endif // PHPOLYGON_OMIT_KIT_PATTERNS
    return 1.0;
}

// Parallax occlusion over the pattern's height field: the view ray descends
// `depth` world units below the surface and the returned mesh UV is where it
// meets the relief. The screen-space derivatives are passed in (taken in main,
// outside any branch); T/B are the world-space vectors of one UV unit, so the
// UV shift follows the mesh's own UV density. The march is capped at 12 steps
// and the last step is refined linearly.
vec2 parallaxProceduralUv(vec3 N, vec3 V, vec2 uv, vec3 dpx, vec3 dpy,
                          vec2 duvx, vec2 duvy,
                          int patternCode, float patternScale, float depth) {
    float det = duvx.x * duvy.y - duvy.x * duvx.y;
    if (abs(det) < 1e-12) return uv;
    vec3 T = (dpx * duvy.y - dpy * duvx.y) / det;
    vec3 B = (dpy * duvx.x - dpx * duvy.x) / det;
    float tt = dot(T, T);
    float bb = dot(B, B);
    if (tt < 1e-12 || bb < 1e-12) return uv;

    float NdotV = max(dot(N, V), 0.2);
    // Full-depth UV shift of the ray; grazing angles are capped by the 0.2 floor.
    vec2 shift = vec2(dot(-V, T) / tt, dot(-V, B) / bb) * (depth / NdotV);
    float steps = floor(mix(12.0, 5.0, NdotV));
    float layer = 1.0 / steps;

    vec2 cur = uv;
    float curDepth = 0.0;
    float surf = 1.0 - dispatchProceduralHeight(patternCode, cur * patternScale);
    vec2 prevUv = uv;
    float prevGap = surf;
    for (int i = 0; i < 12; i++) {
        if (curDepth >= surf) break;
        prevUv = cur;
        prevGap = surf - curDepth;
        cur += shift * layer;
        curDepth += layer;
        surf = 1.0 - dispatchProceduralHeight(patternCode, cur * patternScale);
    }
    float gap = surf - curDepth;                  // <= 0 once the ray is inside
    float w = prevGap / max(prevGap - gap, 1e-5); // 0 at prevUv, 1 at cur
    return mix(prevUv, cur, clamp(w, 0.0, 1.0));
}

// `uv` builds the tangent basis (the mesh UV), `sampleUv` is where the pattern
// is read (the parallax-shifted UV, or the same UV without relief).
vec3 perturbNormalProcedural(vec3 N, vec3 worldPos, vec2 uv, vec2 sampleUv,
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

    vec3 nMap = dispatchProceduralNormal(patternCode, sampleUv * patternScale);
    nMap = mix(vec3(0.0, 0.0, 1.0), nMap, clamp(intensity, 0.0, 4.0));
    return normalize(TBN * nMap);
}
#endif // PHPOLYGON_OMIT_NORMAL_PATTERNS

#ifdef PHPOLYGON_NORMAL_MAP
// The normal map's normal in the frame built from the screen derivatives of the
// position and of `uv` (no tangent attribute needed); read at `sampleUv`.
vec3 perturbNormalMap(vec3 N, vec3 worldPos, vec2 uv, vec2 sampleUv, float intensity) {
    vec3 dpx = dFdx(worldPos);
    vec3 dpy = dFdy(worldPos);
    vec2 duvx = dFdx(uv);
    vec2 duvy = dFdy(uv);
    float det = duvx.x * duvy.y - duvy.x * duvx.y;
    vec3 nMap = texture(u_normal_texture, sampleUv).xyz * 2.0 - 1.0;
    if (abs(det) < 1e-8 || intensity <= 0.0) return N;
    // Cotangent frame: T = dP/du, and dP/dv the same way - both divide by the UV
    // determinant, so the screen's y direction (opposite on GL and D3D) cancels out and
    // mirrored UV layouts come out right. Green points to the image's top, i.e. against
    // v (row 0 is the top): B = -dP/dv, the OpenGL convention.
    vec3 T = (dpx * duvy.y - dpy * duvx.y) / det;
    T = normalize(T - N * dot(N, T));
    vec3 B = (dpy * duvx.x - dpx * duvy.x) / det;
    B = -normalize(B - N * dot(N, B) - T * dot(T, B));
    nMap.xy *= clamp(intensity, 0.0, 4.0);
    return normalize(mat3(T, B, N) * normalize(nMap));
}
#endif

// ================================================================
//  Main
// ================================================================

// Light falloff (SetLightFalloff): 0 = the classic smooth window (1 - d^2/r^2)^2, 1 = inverse square
// with a smooth window at the light's radius (intensity = radiance at 1 m, distances in metres).
uniform float u_light_falloff;
float lightFalloff(float dist, float r) {
    if (u_light_falloff > 0.5) {
        float x = dist / max(r, 1e-3);
        float w = clamp(1.0 - x * x * x * x, 0.0, 1.0);
        return w * w / max(dist * dist, 0.01);
    }
    float a = clamp(1.0 - dist * dist / (r * r), 0.0, 1.0);
    return a * a;
}

void main() {
#ifdef PHPOLYGON_DEPTH_ONLY
    // Depth prepass twin (shader plan 9.1): colour is masked, only depth counts.
    // Compiled in, not a uniform: a runtime return would put every derivative
    // below into non-uniform control flow, undefined even when never taken.
    return;
#endif
    vec3 N = normalize(v_normal);
    // Two-sided opaque surfaces (foliage, fronds, flags): where the authored
    // normal faces away from the viewer it is mirrored through the triangle's
    // plane, so a thin shell's back is lit like its front instead of showing
    // only sky ambient (blue-black). The mirror keeps the in-plane part of a
    // smooth normal, so soft shading survives. Decided per fragment by the
    // normal (not gl_FrontFacing), which also handles authored normals that
    // disagree with the winding.
    if (u_two_sided == 1) {
        vec3 tsNg = cross(dFdx(v_worldPos), dFdy(v_worldPos));
        float tsLen = length(tsNg);
        if (dot(N, u_camera_pos - v_worldPos) < 0.0 && tsLen > 1e-12) {
            tsNg /= tsLen;
            N = normalize(N - 2.0 * dot(N, tsNg) * tsNg);
        }
    }
    // Strand hair (shader plan 10.2): the normal holds the strand direction. Shading
    // takes the normal of a cylinder around the strand that faces the camera - a strand
    // is lit by the sine to the light, the highlight runs in a band along the strands.
#ifndef PHPOLYGON_OMIT_HAIR
    if (u_hair > 0.5) {
        vec3 strand = normalize(v_normal);
        vec3 toEye = normalize(u_camera_pos - v_worldPos);
        vec3 across = toEye - strand * dot(strand, toEye);
        N = length(across) > 1e-4 ? normalize(across) : toEye;
    }
#endif // PHPOLYGON_OMIT_HAIR
#ifdef PHPOLYGON_MRT
    // Every attachment holds a defined value even when a procedural branch
    // returns early: no sun/ambient, unlit colour in o_local, geometry in the
    // G-buffer (a = linear depth, so the AO passes see this surface).
    o_sun     = vec4(0.0, 0.0, 0.0, u_alpha);
    o_local   = vec4(0.0, 0.0, 0.0, u_alpha);
    o_ambient = vec4(0.0, 0.0, 0.0, u_alpha);
    o_gbuffer = gbufferValue(N);
#ifdef PHPOLYGON_MOTION
    vec2 currNdc = v_currClip.xy / v_currClip.w;
    vec2 prevNdc = v_prevClip.xy / v_prevClip.w;
    o_motion = vec4((prevNdc - currNdc) * vec2(0.5, 0.5 * u_motion_flip_y), 0.0, 0.0);
    float reactive = reactiveValue(u_alpha);
    o_reactive = vec4(reactive, reactive, reactive, 1.0);
#endif
#endif
    // View-facing normal for specular/fresnel (flipped for back faces)
    vec3 Nv = gl_FrontFacing ? N : -N;
#ifndef PHPOLYGON_OMIT_HAIR
    if (u_hair > 0.5) {
        Nv = N; // already towards the camera, on both sides of the ribbon
    }
#endif // PHPOLYGON_OMIT_HAIR

    vec3 V = normalize(u_camera_pos - v_worldPos);
    vec3 L = normalize(-u_dir_light_direction);

    float roughness = clamp(u_roughness, 0.04, 1.0);
    float metallic  = u_metallic;
    float alpha = u_alpha;
    vec3 albedo;

    // Parallax occlusion over the normal pattern's height field moves where the
    // pattern, the surface wear and the albedo texture are read. The derivatives
    // are taken here, outside any branch, and the texture keeps the mesh UV's
    // gradients so its mip level does not jump at relief edges.
    vec3 posDx = dFdx(v_worldPos);
    vec3 posDy = dFdy(v_worldPos);
    vec2 uvDx = dFdx(v_uv);
    vec2 uvDy = dFdy(v_uv);
    // Splatted ground: the albedo texture holds the layer weights over u_splat_area; each
    // layer's pattern height lifts it in the blend (height blend), and the dominant layer
    // lends its pattern to the relief below.
    int nPattern = u_normal_pattern;
    float nScale = u_normal_scale;
#ifndef PHPOLYGON_OMIT_SPLAT
    bool splatOn = u_splat_count > 0.5 && u_has_albedo_texture == 1;
#else
    const bool splatOn = false;
#endif // PHPOLYGON_OMIT_SPLAT
    vec3 splatAlbedo = u_albedo;
    float splatRough = roughness;
#ifndef PHPOLYGON_OMIT_SPLAT
    if (splatOn) {
        vec2 suv = (v_worldPos.xz - u_splat_area.xy) / u_splat_area.zw;
        vec4 sw = albedoGrad(suv, dFdx(suv), dFdy(suv));
        float weights[4] = float[4](sw.r, sw.g, sw.b, sw.a);
        float lifted[4];
        float top = -1.0e9;
        for (int si = 0; si < 4; si++) {
            float h = 0.5;
#ifndef PHPOLYGON_OMIT_NORMAL_PATTERNS
            int code = int(u_splat_pattern[si].x + 0.5);
            if (code != 0) h = dispatchProceduralHeight(code, v_uv * u_splat_pattern[si].y);
#endif // PHPOLYGON_OMIT_NORMAL_PATTERNS
            lifted[si] = float(si) < u_splat_count ? weights[si] + h * u_splat_pattern[si].z : -1.0e9;
            top = max(top, lifted[si]);
        }
        float depth = 1.0 / max(u_splat_sharpness, 0.01);
        float sum = 0.0;
        vec3 col = vec3(0.0);
        float rough = 0.0;
        float best = -1.0;
        int dominant = 0;
        for (int si = 0; si < 4; si++) {
            float b = max(lifted[si] - top + depth, 0.0);
            sum += b;
            col += u_splat_color[si].rgb * b;
            rough += u_splat_color[si].a * b;
            if (b > best) { best = b; dominant = si; }
        }
        splatAlbedo = col / max(sum, 1.0e-5);
        splatRough = clamp(rough / max(sum, 1.0e-5), 0.04, 1.0);
        nPattern = int(u_splat_pattern[dominant].x + 0.5);
        nScale = u_splat_pattern[dominant].y;
        roughness = splatRough;
    }
#endif // PHPOLYGON_OMIT_SPLAT
    vec2 patternUv = v_uv;
#ifndef PHPOLYGON_OMIT_NORMAL_PATTERNS
    if (nPattern != 0 && u_parallax_depth > 0.0) {
        patternUv = parallaxProceduralUv(Nv, V, v_uv, posDx, posDy, uvDx, uvDy,
                                         nPattern, nScale, u_parallax_depth);
    }
#endif // PHPOLYGON_OMIT_NORMAL_PATTERNS

    vec3 texAlbedo = (splatOn ? splatAlbedo : u_albedo) * v_instance_tint;
    // Emission behind the texture's alpha glows only where the texture is opaque
    // (the ink of a text plate); without a texture there is nothing to glow.
    float emissionMask = 0.0;
    if (u_has_albedo_texture == 1 && !splatOn) {
#ifdef PHPOLYGON_MOTION
        // Temporal upscaling reconstructs display-resolution detail: pick the
        // mip for the display, not the render resolution (2^bias scales the LOD).
        float mipScale = exp2(u_mip_bias + u_material_mip_bias);
#else
        float mipScale = exp2(u_material_mip_bias);
#endif
        vec2 fbUv = patternUv;
        vec2 fbScale = vec2(1.0);
#ifndef PHPOLYGON_OMIT_FLIPBOOK
        if (u_flipbook.x > 0.5 && u_flipbook.y > 0.5) {
            // Flipbook (shader plan 7.1): the frame of the particle's age, or by time.
            float frames = u_flipbook.x * u_flipbook.y;
            float life = fract(u_frag_time * u_flipbook.z / frames);
#ifdef PHPOLYGON_INSTANCE_PARAMS
            if (v_instance_params.y > 0.0) life = clamp(v_instance_params.y, 0.0, 0.9999);
#endif
            float frame = floor(life * frames);
            vec2 cell = vec2(mod(frame, u_flipbook.x), floor(frame / u_flipbook.x));
            fbScale = 1.0 / u_flipbook.xy;
            fbUv = (fract(patternUv) + cell) * fbScale;
        }
#endif // PHPOLYGON_OMIT_FLIPBOOK
#ifndef PHPOLYGON_OMIT_TRIPLANAR
        texAlbedo *= u_triplanar > 0.0
            ? sampleAlbedoTriplanar(v_worldPos, N, u_triplanar)
            : sampleAlbedoTexture(fbUv, uvDx * mipScale * fbScale, uvDy * mipScale * fbScale);
#else
        texAlbedo *= sampleAlbedoTexture(fbUv, uvDx * mipScale * fbScale, uvDy * mipScale * fbScale);
#endif // PHPOLYGON_OMIT_TRIPLANAR
#ifndef PHPOLYGON_OMIT_FUR
        if (u_fur_shell.w > 0.5) {
            texAlbedo *= mix(u_fur_shell.z, 1.0, u_fur_shell.x);
        }
#endif // PHPOLYGON_OMIT_FUR
#ifdef PHPOLYGON_CUTOUT
        if (u_alpha_cutoff > 0.0 && albedoGrad(fbUv, uvDx * mipScale * fbScale, uvDy * mipScale * fbScale).a < u_alpha_cutoff) {
            discard;
        }
#endif
        if (u_emission_mask > 0.5) {
            emissionMask = albedoGrad(fbUv, uvDx * mipScale * fbScale, uvDy * mipScale * fbScale).a;
        }
    }

    // ---- Material selection ----
/* PHPOLYGON:PROCMODE_BRANCHES */ {
        // World-space detail variation (shader plan 5.1): a large patchy
        // brightness and hue drift and a fine grain, both from the 3D position
        // projected along the dominant normal axis - walls vary like the ground,
        // repeated surfaces stop looking stamped. Only rough materials.
        vec3 an = abs(N);
        vec2 wp = an.y > max(an.x, an.z) ? v_worldPos.xz : (an.x > an.z ? v_worldPos.zy : v_worldPos.xy);
        float macro = noise(wp * 0.07) * 0.65 + noise(wp * 0.21) * 0.35;
        float grain = noise(wp * 2.3);
        float noiseMask = smoothstep(0.3, 0.9, roughness);
        vec3 drift = vec3(1.0 + (macro - 0.5) * 0.16, 1.0 + (macro - 0.5) * 0.12, 1.0 + (macro - 0.5) * 0.06);
        albedo = texAlbedo * mix(vec3(1.0), drift * (1.0 + (grain - 0.5) * 0.08), noiseMask);
    }

#ifndef PHPOLYGON_OMIT_GRASS
    // Grass blades: root colour (albedo) to tip colour along the blade, flat
    // bands for the stylised look or a smooth gradient, per-blade and field-wide
    // brightness variation, darker toward the root (contact shading).
    if (u_grass_shade == 1) {
        float gt = clamp(v_uv.y, 0.0, 1.0);
        float gb = gt;
        if (u_grass_look.x >= 1.0) {
            gb = floor(gt * u_grass_look.x + 0.5) / u_grass_look.x;
        }
        vec3 gc = mix(albedo, u_grass_tip, pow(gb, 1.3));
        float gPatch = noise(v_worldPos.xz * max(u_grass_look.w, 1e-4)) - 0.5;
        float gBlade = fract(v_uv.x * 7.31) - 0.5;
        gc *= max(0.0, 1.0 + (gBlade * 0.6 + gPatch) * u_grass_look.y * 2.0);
        gc *= mix(1.0 - clamp(u_grass_look.z, 0.0, 1.0), 1.0, smoothstep(0.0, 0.45, gt));
        // Toward the fade range the blades take the root colour - the colour of
        // the ground they thin out into - so the field has no bright rim.
        float gFar = smoothstep(u_grass_far_fade.x, u_grass_far_fade.y, length(v_worldPos - u_camera_pos));
        gc = mix(gc, albedo, gFar * 0.7);
        albedo = gc;
    }
#endif // PHPOLYGON_OMIT_GRASS

    // Transparent surfaces are seen from both sides (a pane, the far wall of a
    // glass box): light them with the normal that faces the viewer, so a back
    // face is not shaded as if it pointed away (dark, unlit triangle halves).
    // Opaque geometry keeps its authored normal (two-sided opaque materials
    // were mirrored toward the viewer at the top of main). Self-shading procedural
    // branches (water, cloud, moon) have returned above and are unaffected.
    if (u_alpha < 1.0) {
        N = Nv;
    }

    // The surface's own normal before maps and patterns add detail: the open direction
    // of their small cavities (bent normal, below).
    vec3 Nmacro = N;

    // Procedural normal-map pattern (mirrors mesh3d.frag.glsl). Self-shading
    // procedural materials (water, cloud, moon) have early-returned above so
    // this only affects standard, sand, rock, palm, wood, thatch, carpaint.
#ifdef PHPOLYGON_NORMAL_MAP
    if (u_has_normal_texture == 1) {
        N = perturbNormalMap(N, v_worldPos, v_uv, patternUv, u_normal_intensity);
    }
#endif
#ifndef PHPOLYGON_OMIT_NORMAL_PATTERNS
    N = perturbNormalProcedural(N, v_worldPos, v_uv, patternUv,
                                nPattern, nScale,
                                u_normal_intensity);
#endif // PHPOLYGON_OMIT_NORMAL_PATTERNS

#ifndef PHPOLYGON_OMIT_SURFACE_PATTERNS
    if (u_surface_pattern > 0 && u_surface_intensity > 0.0) {
        vec3 wear = dispatchSurfacePattern(u_surface_pattern, patternUv * u_surface_scale);
        float t = clamp(u_surface_intensity, 0.0, 4.0);
        vec3 tint = mix(vec3(1.0), vec3(wear.x * 2.0), t);
        albedo *= tint;
        roughness = clamp(roughness + wear.y * t, 0.04, 1.0);
        metallic  = clamp(metallic  + wear.z * t, 0.0,  1.0);
    }
#endif // PHPOLYGON_OMIT_SURFACE_PATTERNS

    // Cavity: the recesses of the pattern's height field catch less light. The
    // ambient terms take the full factor (through `ao` below), direct light half.
    float patternCavity = 1.0;
#ifndef PHPOLYGON_OMIT_NORMAL_PATTERNS
    if (nPattern != 0 && u_cavity > 0.0) {
        float cavityHeight = dispatchProceduralHeight(nPattern, patternUv * nScale);
        patternCavity = 1.0 - clamp(u_cavity, 0.0, 1.0) * (1.0 - cavityHeight);
        albedo *= mix(1.0, patternCavity, 0.5);
    }
#endif // PHPOLYGON_OMIT_NORMAL_PATTERNS

    // Per-material wetness (SSR SURROGATE). Up-facing fragments get a smoother +
    // darker + brighter-IBL pass to read as wet/polished. This is the FALLBACK:
    // it runs only when the real ray-marched SSR pass is OFF (u_ssr_enabled==0 —
    // tier Off, or a non-D3D / non-HDR path). When the real pass owns reflections
    // it composites the actual reflected scene into the HDR buffer afterwards, so
    // suppressing the surrogate here prevents double-darkening the same surface.
#ifndef PHPOLYGON_OMIT_WETNESS
    if (u_wetness > 0.0 && u_ssr_enabled == 0) {
        float upMask = clamp(dot(N, vec3(0.0, 1.0, 0.0)) * 1.4 - 0.2, 0.0, 1.0);
        float w = u_wetness * upMask;
        roughness = mix(roughness, max(roughness * 0.25, 0.04), w);
        albedo    = mix(albedo,    albedo * 0.7,                 w);
    }
#endif // PHPOLYGON_OMIT_WETNESS

    // Rain-soaked ground (shader plan 4.1): porous surfaces darken, the water film
    // smooths them, puddles are near mirrors lying flat. Mirrors in the G-buffer.
    vec2 soak = rainSoak(v_worldPos, N);
    if (soak.x > 0.0) {
        albedo *= mix(1.0, 0.6, soak.x * (1.0 - metallic));
        roughness = mix(roughness, mix(0.22, 0.04, soak.y / max(soak.x, 1e-4)), soak.x);
        N = normalize(mix(N, vec3(0.0, 1.0, 0.0), soak.y));
        // Drops ring the puddles (the film only a little).
        // Far away the rings would only flicker: they fade out from 15 to 35 m.
        float ripFade = 1.0 - smoothstep(15.0, 35.0, length(u_camera_pos - v_worldPos));
        vec2 rip = ripFade > 0.0 ? rainRipples(v_worldPos.xz, u_frag_time) * (soak.y + 0.25 * soak.x) * u_rain_wetness * ripFade : vec2(0.0);
        N = normalize(N + vec3(rip.x, 0.0, rip.y));
    }

    // Decal volumes (shader plan 5.1): stains, soot, moss and cracks painted on
    // whatever lies inside each box (the ground, a wall, a crate), fading towards
    // its edges and broken up by noise. Not on water or unlit materials.
    if (u_decal_count > 0 && u_proc_mode != 2) {
        for (int k = 0; k < 8; k++) {
            if (k >= u_decal_count) break;
            vec4 cb = u_decals[k * 3];
            vec4 hs = u_decals[k * 3 + 1];
            vec4 ck = u_decals[k * 3 + 2];
            vec3 d = v_worldPos - cb.xyz;
            float cs = cos(cb.w);
            float sn = sin(cb.w);
            vec3 l = vec3(cs * d.x - sn * d.z, d.y, sn * d.x + cs * d.z) / max(hs.xyz, vec3(1e-3));
            float edge = max(max(abs(l.x), abs(l.y)), abs(l.z));
            if (edge >= 1.0) continue;
            vec2 sp = vec2(l.x * hs.x, l.z * hs.z);
            float blot = noise(sp * 1.3 + cb.xz) * 0.6 + noise(sp * 4.1 + cb.zx) * 0.4;
            float rad = length(l.xz);
            float mask = (1.0 - smoothstep(0.35, 1.0, rad + (blot - 0.5) * 0.7)) * (1.0 - smoothstep(0.7, 1.0, abs(l.y)));
            mask *= hs.w;
            if (mask <= 0.0) continue;
            int kind = int(ck.w + 0.5);
            if (kind == 3) {
                // Cracks: thin dark lines where two ridged noises cross zero.
                float r1 = abs(noise(sp * 1.8) - 0.5);
                float r2 = abs(noise(sp * 4.3 + 11.0) - 0.5);
                float line = (1.0 - smoothstep(0.0, 0.025, r1)) + 0.6 * (1.0 - smoothstep(0.0, 0.02, r2));
                line = clamp(line, 0.0, 1.0);
                albedo *= 1.0 - line * mask * 0.75;
            } else if (kind == 2) {
                // Moss / algae: a green, rough skin, thicker where the noise is high.
                float g = mask * smoothstep(0.3, 0.7, blot);
                albedo = mix(albedo, ck.rgb * (0.8 + 0.4 * blot), g);
                roughness = mix(roughness, 0.95, g);
            } else {
                // Stain (0) tints towards its colour; soot (1) darkens and mattes.
                float s = mask * (0.6 + 0.4 * blot);
                albedo = mix(albedo, albedo * ck.rgb, s);
                if (kind == 1) roughness = mix(roughness, 1.0, s);
                else roughness = mix(roughness, max(roughness * 0.6, 0.2), s * 0.5);
            }
        }
    }

    // Brick variation (shader plan 5.2): each brick of the bricks pattern a little
    // lighter, darker or warmer than its neighbours; the mortar lines lighter.
    if (nPattern == 1) {
        vec2 bu = patternUv * nScale;
        float brow = floor(bu.y);
        vec2 bcell = vec2(floor((bu.x + mod(brow, 2.0) * 0.25) / 0.5), brow);
        float bh = hash21(bcell + 4.1);
        albedo *= vec3(0.88 + 0.24 * bh, 0.9 + 0.18 * bh, 0.92 + 0.12 * hash21(bcell + 9.3));
    }

    // Weathering (shader plan 5.2): inside the aging zones dust settles on top,
    // rain streaks run down walls, metal rusts (copper turns green), moss grows
    // on the north side, bricks bloom white. Near a waterline vertical surfaces
    // carry a salt crust above it and algae below, whatever the zone.
#ifndef PHPOLYGON_OMIT_WEATHERING
    if (u_weathering > 0.0 && u_proc_mode == 0 && alpha > 0.95 && dot(u_emission, vec3(1.0)) < 0.05) {
        float age = 0.0;
        for (int k = 0; k < 8; k++) {
            if (k >= u_age_zone_count) break;
            vec4 zc = u_age_zones[k * 2];
            vec4 zh = u_age_zones[k * 2 + 1];
            vec3 d = v_worldPos - zc.xyz;
            float cs = cos(zc.w);
            float sn = sin(zc.w);
            vec3 l = abs(vec3(cs * d.x - sn * d.z, d.y, sn * d.x + cs * d.z)) / max(zh.xyz, vec3(1e-3));
            float e = max(max(l.x, l.y), l.z);
            age = max(age, zh.w * (1.0 - smoothstep(1.0 - u_age_params.w, 1.0, e)));
        }
        float a = age * u_weathering;
        vec3 an = abs(N);
        vec2 wp = an.y > max(an.x, an.z) ? v_worldPos.xz : (an.x > an.z ? v_worldPos.zy : v_worldPos.xy);
        if (a > 0.0) {
            float n1 = noise(wp * 0.7);
            float n2 = noise(wp * 2.9 + 7.0);
            // Dust on top: a dull grey-brown film, patchy, never on grass.
            float dust = a * smoothstep(0.6, 0.95, N.y) * (0.35 + 0.65 * n1) * 0.22 * (u_grass_shade == 1 ? 0.0 : 1.0);
            albedo = mix(albedo, vec3(0.36, 0.33, 0.28), dust);
            roughness = mix(roughness, 1.0, dust);
            // Rain streaks down walls: dark vertical runs.
            if (an.y < 0.4) {
                vec2 hv = normalize(vec2(-N.z, N.x) + vec2(1e-5));
                float along = dot(v_worldPos.xz, hv);
                float streak = smoothstep(0.55, 0.85, noise(vec2(along * 3.5, v_worldPos.y * 0.25)));
                albedo *= 1.0 - a * streak * 0.32;
            }
            if (metallic > 0.4) {
                // Rust, or verdigris on copper-coloured metal.
                bool copper = albedo.r > albedo.b * 1.35 && albedo.r > albedo.g * 1.05;
                float r = a * smoothstep(0.42, 0.7, n1 * 0.6 + n2 * 0.4);
                albedo = mix(albedo, copper ? vec3(0.22, 0.48, 0.38) : vec3(0.34, 0.13, 0.05), r);
                metallic = mix(metallic, 0.0, r);
                roughness = mix(roughness, 0.9, r);
            } else {
                // Moss on the north side, lower and patchy.
                float north = dot(N.xz, u_age_params.xy);
                float moss = a * smoothstep(0.15, 0.7, north) * smoothstep(0.45, 0.75, n1 * 0.5 + n2 * 0.5) * (1.0 - smoothstep(0.2, 0.9, N.y));
                albedo = mix(albedo, vec3(0.16, 0.24, 0.08), moss);
                roughness = mix(roughness, 0.95, moss);
                // Efflorescence: white bloom on bricks.
                if (nPattern == 1) {
                    float bloom = a * smoothstep(0.6, 0.85, n2) * 0.5;
                    albedo = mix(albedo, vec3(0.86, 0.85, 0.82), bloom);
                }
            }
        }
        // Salt crust above the waterline, algae below (vertical surfaces only).
        if (u_age_params.z > -1.0e5 && an.y < 0.5 && metallic < 0.5) {
            float h = v_worldPos.y - u_age_params.z;
            float sn3 = noise(wp * 1.7 + 3.0);
            float salt = smoothstep(1.8, 0.4, h) * smoothstep(-0.1, 0.25, h) * smoothstep(0.35, 0.7, sn3) * 0.55 * u_weathering;
            albedo = mix(albedo, vec3(0.82, 0.8, 0.74), salt);
            float algae = smoothstep(0.3, -0.1, h) * smoothstep(-1.6, -0.6, h) * (0.6 + 0.4 * sn3) * 0.7 * u_weathering;
            albedo = mix(albedo, vec3(0.06, 0.12, 0.05), algae);
            roughness = mix(roughness, 0.95, max(salt, algae));
        }
    }
#endif // PHPOLYGON_OMIT_WEATHERING

    // Rain on glass (shader plan 4.1): transparent, smooth, default-shaded panes
    // outside interior volumes. Drops thicken the pane a little and catch the sky.
    float glassDrops = 0.0;
    if (u_rain_wetness > 0.0 && alpha < 0.95 && u_roughness < 0.25 && u_proc_mode == 0
        && v_worldPos.y >= u_rain_dry_below && interiorMask(v_worldPos) <= 0.0) {
        vec3 gr = glassRain(v_worldPos, N, u_frag_time);
        float gw = clamp(u_rain_wetness, 0.0, 1.0);
        glassDrops = gr.x * gw * (1.0 - smoothstep(8.0, 20.0, length(u_camera_pos - v_worldPos)));
        vec3 gt = normalize(cross(N, vec3(0.0, 1.0, 0.0)) + vec3(1e-4));
        N = normalize(N + (gt * gr.y + vec3(0.0, 1.0, 0.0) * gr.z) * glassDrops);
        alpha = mix(alpha, min(1.0, alpha + 0.35), glassDrops);
    }

    // Glass (shader plan 5.3, Material::$glass): towards grazing angles the pane
    // turns opaque and mirror-like, so edges and curvature read; dirt (smudges and
    // dried rain runs) thickens and dulls it.
#ifndef PHPOLYGON_OMIT_GLASS
    if (u_glass.x > 0.0 && alpha < 0.98) {
        float gNdotV = clamp(dot(N, V), 0.0, 1.0);
        float gF = 0.04 + 0.96 * pow(1.0 - gNdotV, 5.0);
        alpha = mix(alpha, 1.0, gF * u_glass.x * 0.85);
        if (u_glass.y > 0.0) {
            vec3 gan = abs(N);
            vec2 gwp = gan.y > max(gan.x, gan.z) ? v_worldPos.xz : (gan.x > gan.z ? v_worldPos.zy : v_worldPos.xy);
            float smudge = smoothstep(0.45, 0.8, noise(gwp * 1.6)) * 0.7;
            float runs = smoothstep(0.6, 0.85, noise(vec2(gwp.x * 9.0, v_worldPos.y * 0.7))) * 0.5;
            float gd = clamp((smudge + runs) * u_glass.y, 0.0, 1.0);
            alpha = mix(alpha, max(alpha, 0.7), gd * 0.6);
            albedo = mix(albedo, vec3(0.42, 0.39, 0.33), gd * 0.5);
            roughness = mix(roughness, 0.7, gd);
        }
    }
#endif // PHPOLYGON_OMIT_GLASS

    // Particle colour over life (shader plan 7.1): the packed RGBA6 of the
    // instance tints albedo, emission (below) and alpha.
    vec3 particleRgb = vec3(1.0);
#ifdef PHPOLYGON_INSTANCE_PARAMS
    if (u_particle_tint > 0.5 && v_instance_params.x > 0.0) {
        // + 0.25, not + 0.5: packed values from 2^23 up (alpha >= 32/63) have no
        // fraction bits; + 0.5 rounds an odd one up to the next even float.
        float pv = floor(v_instance_params.x + 0.25);
        vec4 pc = vec4(mod(pv, 64.0), mod(floor(pv / 64.0), 64.0), mod(floor(pv / 4096.0), 64.0), floor(pv / 262144.0)) / 63.0;
        particleRgb = pc.rgb;
        albedo *= pc.rgb;
        alpha *= pc.a;
    }
#endif
    // Streaks fade towards their tail (Material::$streakFade, shader plan 7.1).
#ifndef PHPOLYGON_OMIT_FLIPBOOK
    if (u_flipbook.w > 0.5) {
        alpha *= smoothstep(0.0, 0.85, v_uv.y);
    }
#endif // PHPOLYGON_OMIT_FLIPBOOK
    // Fur shells (shader plan 10.2): a shell keeps only the strand dots, thinner the
    // higher it lies; the base (height 0) stays whole.
#ifndef PHPOLYGON_OMIT_FUR
    if (u_fur_shell.w > 0.5 && u_fur_shell.x > 0.0) {
        vec2 g = v_uv * u_fur_shell.y;
        vec2 cell = floor(g);
        float h = fract(sin(dot(cell, vec2(12.9898, 78.233))) * 43758.5453);
        float radius = 0.48 * (1.0 - u_fur_shell.x) * (0.55 + 0.45 * h);
#ifdef PHPOLYGON_CUTOUT
        if (h < 0.12 || length(fract(g) - 0.5) > radius) {
            discard;
        }
#endif
    }
#endif // PHPOLYGON_OMIT_FUR
    // Dithered distance fade (shader plan 6.1): dissolve instead of popping.
    // A negative range fades IN with the complementary dither (LOD cross-fade,
    // shader plan 9.1): the outgoing and the incoming level never cover the
    // same pixel, and together they cover all of them.
    if (u_fade_range.y != 0.0) {
        float fadeT = smoothstep(abs(u_fade_range.x), abs(u_fade_range.y), length(u_camera_pos - v_worldPos));
        float dith = fract(52.9829189 * fract(dot(gl_FragCoord.xy, vec2(0.06711056, 0.00583715))));
        bool keep = u_fade_range.y > 0.0 ? fadeT <= dith : fadeT > dith;
#ifdef PHPOLYGON_CUTOUT
        if (!keep) {
            discard;
        }
#endif
    }
    // Season colour map: grass blades and two-sided translucent foliage.
    bool isPlant = u_grass_shade == 1 || (u_two_sided == 1 && u_subsurface_strength > 0.0);
    if (isPlant) {
        // Lawn waves (shader plan 6.1): gusts run over the grass as lighter bands
        // (bent blades show their pale side), stronger with the wind.
        if (u_grass_shade == 1 && u_frag_wind.z > 0.0) {
            float along = dot(v_worldPos.xz, u_frag_wind.xy);
            float across = dot(v_worldPos.xz, vec2(-u_frag_wind.y, u_frag_wind.x));
            float band = sin(along * 0.35 - u_frag_time * 1.5 + sin(across * 0.1) * 2.0) * 0.5 + 0.5;
            float gustW = smoothstep(0.55, 1.0, band) * clamp(u_frag_wind.z, 0.0, 1.5);
            albedo = mix(albedo, albedo * vec3(1.4, 1.35, 1.1) + vec3(0.02), gustW * 0.75);
        }
        // Distant foliage (shader plan 6.1): a little paler and bluer far away,
        // so tree lines step back before the fog takes over.
        float farT = smoothstep(40.0, 220.0, length(u_camera_pos - v_worldPos));
        float farL = dot(albedo, vec3(0.299, 0.587, 0.114));
        albedo = mix(albedo, vec3(farL) * vec3(0.9, 1.0, 1.12), farT * 0.45);
        float sl = dot(albedo, vec3(0.299, 0.587, 0.114));
        albedo = mix(albedo, vec3(sl), u_season_grade.a) * u_season_grade.rgb;
    }
    // Drops on wet leaves (shader plan 4.1): a wet plant darkens and turns glossy,
    // and small drops catch the sky light (outside interior volumes only).
    float leafDrops = 0.0;
    float snowSparkle = 0.0;
    if (isPlant && u_rain_wetness > 0.0 && v_worldPos.y >= u_rain_dry_below && interiorMask(v_worldPos) <= 0.0) {
        float lw = clamp(u_rain_wetness, 0.0, 1.0);
        albedo *= 1.0 - 0.18 * lw;
        roughness = mix(roughness, 0.35, lw);
        vec3 dc = floor(v_worldPos * 24.0);
        vec3 df = fract(v_worldPos * 24.0) - 0.5;
        float dh = hash31(dc);
        leafDrops = step(1.0 - 0.12 * lw, dh) * smoothstep(0.42, 0.2, length(df.xz)) * lw;
        roughness = mix(roughness, 0.06, leafDrops);
    }

#ifndef PHPOLYGON_OMIT_SNOW
    // ---- Snow cover: upward-facing surfaces turn white ----
    if (u_snow_cover > 0.01 && u_proc_mode != 2 && u_proc_mode != 6) {
        float upFacing = max(dot(N, vec3(0.0, 1.0, 0.0)), 0.0);
        // Snow sticks more on flat/upward surfaces, less on steep sides
        float snowMask = smoothstep(0.3, 0.7, upFacing) * u_snow_cover;
        // Add noise for natural patchy edges
        snowMask *= 0.7 + noise(v_worldPos.xz * 2.0) * 0.3;
        // Drifts (shader plan 4.1): the wind piles the snow in soft mounds where a
        // slow noise is high; thin patches stay where it is low. The mounds tilt
        // the normal (their slope from the noise gradient).
        vec2 sp = v_worldPos.xz * 0.35;
        float drift = noise(sp) * 0.7 + noise(sp * 2.7) * 0.3;
        snowMask *= mix(0.55, 1.35, drift);
        snowMask = clamp(snowMask, 0.0, 1.0);
        vec2 eps = vec2(0.15, 0.0);
        float dx = (noise((v_worldPos.xz + eps.xy) * 0.35) - noise((v_worldPos.xz - eps.xy) * 0.35));
        float dz = (noise((v_worldPos.xz + eps.yx) * 0.35) - noise((v_worldPos.xz - eps.yx) * 0.35));
        N = normalize(mix(N, normalize(vec3(-dx * 3.0, 1.0, -dz * 3.0)), snowMask * u_snow_cover));
        albedo = mix(albedo, vec3(0.92, 0.93, 0.97), snowMask);
        roughness = mix(roughness, 0.8, snowMask); // snow is matte
        // Crystals glint here and there in the cover.
        float sh = hash31(floor(v_worldPos * 30.0));
        snowSparkle = step(0.985, sh) * snowMask * (1.0 - smoothstep(6.0, 18.0, length(u_camera_pos - v_worldPos)));
    }
#endif // PHPOLYGON_OMIT_SNOW

    // Game surface hook (ProcModeShaderRegistry::registerSurfaceGlsl): the last word
    // on albedo, roughness and metallic before lighting; may discard; self-lit colour
    // goes into surfaceEmission (local light, fogged like u_emission).
    vec3 surfaceEmission = vec3(0.0);
    // PHPOLYGON:SURFACE

    // ---- PBR Lighting (Cook-Torrance GGX) ----
    roughness = clamp(roughness, 0.04, 1.0);
    vec3 F0 = mix(vec3(0.04), albedo, metallic);
    float NdotV = max(dot(N, V), 0.001);
    float shadow = calcShadow(v_lightSpacePos, N);
#ifdef PHPOLYGON_RAY_QUERY
    if (u_rt_shadows > 0.5 && u_dir_light_count > 0) {
        vec3 rtL = normalize(-u_dir_lights[0].direction);
        if (dot(N, rtL) > 0.0) {
            rayQueryEXT rq;
            rayQueryInitializeEXT(rq, u_tlas, gl_RayFlagsTerminateOnFirstHitEXT | gl_RayFlagsOpaqueEXT, 0xFF,
                                  v_worldPos + N * 0.04, 0.02, rtL, 2000.0);
            while (rayQueryProceedEXT(rq)) {}
            shadow = rayQueryGetIntersectionTypeEXT(rq, true) == gl_RayQueryCommittedIntersectionNoneEXT ? 1.0 : 0.0;
        }
    }
#endif
    // Volumetric cloud shadow: dims the primary (sun) light where a cloud blocks
    // it, so the clouds drifting overhead cast moving shadow patches on the world.
    float cloudSh = cloudShadow(v_worldPos);

    float primaryIntensity = u_dir_light_count > 0 ? u_dir_lights[0].intensity : 0.0;
    float ambientShadow = mix(1.0, mix(0.5, 1.0, shadow), clamp(primaryIntensity, 0.0, 1.0) * cloudSh);
    vec3 F_ambient = fresnelSchlick(NdotV, F0);
    if (u_two_sided == 1) {
        // Rough foliage without environment reflection: plain Schlick drives the
        // ambient Fresnel to 1 at grazing angles, which zeroes the diffuse ambient
        // and leaves a black rim on every clump and frond silhouette. The
        // roughness-aware form caps it at max(1 - roughness, F0).
        F_ambient = F0 + (max(vec3(1.0 - roughness), F0) - F0) * pow(clamp(1.0 - NdotV, 0.0, 1.0), 5.0);
    }
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
#ifdef PHPOLYGON_MRT
    // Real SSAO is folded in by composite.frag (u_ssao_enabled says it WILL run);
    // only the curvature fallback is a per-fragment term here.
    ao = (u_ssao_enabled == 1) ? 1.0 : curvatureAO(N, u_ao_strength);
#else
    if (u_ssao_enabled == 1) {
        vec2 sUV = gl_FragCoord.xy / u_viewport_size;
        ao = clamp(texture(u_ssao_map, sUV).r, 0.0, 1.0);
    } else {
        ao = curvatureAO(N, u_ao_strength);
    }
#endif
    ao *= patternCavity;

    // Fieldtracing SDF trace-pass result (SdfOcclusion / SdfBounce): fold the
    // screen-space SDF AO into `ao` (contact darkening on the ambient terms) and
    // capture the soft sun shadow for the directional term below. Neutral (1.0)
    // when the pass didn't run (ProbesOnly, no volume, or non-D3D backend).
    float ftSunShadow = 1.0;
#ifndef PHPOLYGON_MRT
    if (u_sdf_ao_enabled > 0.5) {
        vec2 ftUV = gl_FragCoord.xy / u_viewport_size;
        vec2 ftAoSh = texture(u_sdf_ao_map, ftUV).rg;
        ao = min(ao, clamp(ftAoSh.r, 0.0, 1.0));
        ftSunShadow = clamp(ftAoSh.g, 0.0, 1.0);
    }
#endif

    // Three accumulators instead of one colour: the AO-modulated ambient terms, the
    // primary (sun) light and everything else. On the single-target path they are
    // summed at the end (same result as the former single `color`); on the MRT path
    // they leave as separate attachments so the composite can apply SSAO / SDF-AO to
    // the ambient part and the SDF sun shadow to the sun part only.
    vec3 ambientAcc = flatOrSkyAmbient(N) * albedo * kD_ambient * ambientShadow * ao;
#ifdef PHPOLYGON_RAY_QUERY
    // Ray-traced GI (shader plan 10.2): two cosine-distributed rays per pixel. A hit
    // brings the hit instance's albedo lit by the ambient and the sun (one more ray
    // for its visibility); a miss sees the sky. The flat ambient keeps only the share
    // of rays that reached the sky. The pattern turns per pixel and over time, so
    // temporal AA resolves the grain.
    if (u_rt_gi > 0.0) {
        vec3 up = abs(N.y) < 0.95 ? vec3(0.0, 1.0, 0.0) : vec3(1.0, 0.0, 0.0);
        vec3 tx = normalize(cross(up, N));
        vec3 ty = cross(N, tx);
        vec3 bounce = vec3(0.0);
        float sky = 0.0;
        for (int gi = 0; gi < 2; gi++) {
            vec2 seed = gl_FragCoord.xy + vec2(float(gi) * 37.0, fract(u_time * 7.31) * 113.0);
            float h1 = hash21(seed);
            float h2 = hash21(seed + 19.19);
            float rr = sqrt(h1);
            vec3 dir = normalize(tx * (rr * cos(6.2831853 * h2)) + ty * (rr * sin(6.2831853 * h2)) + N * sqrt(max(1.0 - h1, 0.0)));
            rayQueryEXT rg;
            rayQueryInitializeEXT(rg, u_tlas, gl_RayFlagsOpaqueEXT, 0xFF, v_worldPos + N * 0.04, 0.02, dir, 60.0);
            while (rayQueryProceedEXT(rg)) {}
            if (rayQueryGetIntersectionTypeEXT(rg, true) != gl_RayQueryCommittedIntersectionNoneEXT) {
                int id = rayQueryGetIntersectionInstanceIdEXT(rg, true);
                vec3 hp = v_worldPos + dir * rayQueryGetIntersectionTEXT(rg, true);
                vec3 alb = vec3(rtAlbedo[id * 4], rtAlbedo[id * 4 + 1], rtAlbedo[id * 4 + 2]);
                vec3 lit = u_ambient_color * u_ambient_intensity;
                if (u_dir_light_count > 0) {
                    vec3 sL = normalize(-u_dir_lights[0].direction);
                    rayQueryEXT gs;
                    rayQueryInitializeEXT(gs, u_tlas, gl_RayFlagsTerminateOnFirstHitEXT | gl_RayFlagsOpaqueEXT, 0xFF, hp - dir * 0.05, 0.02, sL, 2000.0);
                    while (rayQueryProceedEXT(gs)) {}
                    float vis = rayQueryGetIntersectionTypeEXT(gs, true) == gl_RayQueryCommittedIntersectionNoneEXT ? 1.0 : 0.0;
                    lit += u_dir_lights[0].color * u_dir_lights[0].intensity * vis * 0.5;
                }
                bounce += alb * lit + rtAlbedo[id * 4 + 3] * alb;
            } else {
                sky += 1.0;
            }
        }
        ambientAcc *= mix(1.0, 0.25 + 0.75 * sky * 0.5, u_rt_gi);
        ambientAcc += albedo * kD_ambient * ao * bounce * 0.5 * u_rt_gi;
    }
#endif
    vec3 sunAcc = vec3(0.0);
    vec3 localAcc = vec3(0.0);
    // Bent normal: in an occluded cavity the sky light arrives from its opening -
    // roughly the surface's own normal - not along the detail normal at its bottom.
    vec3 Nbent = u_micro_shadow > 0.5 ? normalize(mix(Nmacro, N, ao)) : N;
    // The cone aperture of the micro-shadow term: cavity and curvature AO reach 0 where
    // baked AO (the formula's source) rarely goes below 0.5, so map it into 0.5..1;
    // and at most 70 % of the light goes, as the cavity's ambient is already near 0.
    float msAo = mix(0.5, 1.0, ao);

    // Fieldtracing contribution (added before outputColor, per the engine's
    // "contribution before finalize" rule). ProbesOnly tier: a hemisphere
    // "probe" ambient — sky-tinted from above, dimmer ground bounce below —
    // layered over the flat ambient as a cheap diffuse-GI surrogate, modulated
    // by AO (which now includes the SDF occlusion above for the Sdf tiers).
    // mode 0 (Off) is a strict no-op so default rendering is unchanged. The
    // SDF soft sun-shadow is applied to the directional term further down.
    if (u_ft_mode >= 0.5) {
        // The analytic hemisphere: the whole ambient where no probe field is bound,
        // and what a field fades into toward its border.
        float ftHemi  = Nbent.y * 0.5 + 0.5;
        vec3  ftSky   = u_ambient_color * 1.2 + vec3(0.015, 0.03, 0.06);
        vec3  ftGround = u_ambient_color * 0.6;
        vec3  ftHemiProbe = mix(ftGround, ftSky, ftHemi) * u_ambient_intensity;
        vec3 ftProbe = ftHemiProbe;
#ifndef PHPOLYGON_OMIT_PROBE_GI
        if (u_probe_enabled > 0.5) {
            // Baked COLOURED directional GI: reconstruct RGB SH-L1 irradiance for
            // N (per channel: E = c0 + c1*N.x + c2*N.y + c3*N.z). The colour (sky
            // blue + warm 1-bounce) is baked in, so it's used directly, not tinted
            // by u_ambient_color. The SdfBounce tier (mode>=2.5) shows the full
            // bounce; lower SDF/Probe tiers dial it back so the tiers differ.
            vec3 puvw = clamp((v_worldPos - u_probe_origin) / u_probe_size, 0.0, 1.0);
            vec4 nd = vec4(1.0, Nbent.x, Nbent.y, Nbent.z);
            // Each channel's block is a third of the stacked texture; z stays between
            // the block's first and last texel centre, so filtering never reaches the
            // neighbouring channel.
            float pDepth = max(float(textureSize(u_probe_field, 0).z) / 3.0, 1.0);
            float pz = clamp(puvw.z, 0.5 / pDepth, 1.0 - 0.5 / pDepth) / 3.0;
            vec4 cr = (texture(u_probe_field, vec3(puvw.xy, pz)) * 2.0 - 1.0) * u_probe_range;
            vec4 cg = (texture(u_probe_field, vec3(puvw.xy, pz + 1.0 / 3.0)) * 2.0 - 1.0) * u_probe_range;
            vec4 cb = (texture(u_probe_field, vec3(puvw.xy, pz + 2.0 / 3.0)) * 2.0 - 1.0) * u_probe_range;
            vec3 E = max(vec3(dot(cr, nd), dot(cg, nd), dot(cb, nd)), vec3(0.0));
            float bounceGain = u_ft_mode >= 2.5 ? 1.0 : 0.7;
            // Toward the field's border the baked GI gives way to the hemisphere
            // (shader plan 3.3, GI blend between regions): outside the volume the
            // clamped edge texels no longer smear outward, and two regions meet on
            // the same neutral ambient, so switching the field shows no step.
            vec3 ftRel = abs((v_worldPos - u_probe_origin) / u_probe_size * 2.0 - 1.0);
            float ftEdge = 1.0 - smoothstep(0.85, 1.0, max(max(ftRel.x, ftRel.z), ftRel.y * 0.5));
            ftProbe = mix(ftHemiProbe, E * (0.45 * bounceGain) * u_ambient_intensity, ftEdge);
        }
#endif // PHPOLYGON_OMIT_PROBE_GI
        ambientAcc += albedo * kD_ambient * ftProbe * ao * (0.35 * u_ft_intensity);
    }

    // Clamp the loop bound to the array size: u_*_light_count is a GPU
    // uniform and a stale/garbage value would otherwise run the loop for
    // millions of iterations (and index out of bounds) → GPU TDR / device hang.
    // Light through thin shells against the sun (skin, foliage); grass blades
    // let most of it through near the tip, little at the root.
    float sssStrength = u_subsurface_strength;
#ifndef PHPOLYGON_OMIT_GRASS
    if (u_grass_shade == 1) {
        sssStrength *= mix(0.25, 1.0, clamp(v_uv.y, 0.0, 1.0));
    }
#endif // PHPOLYGON_OMIT_GRASS
    int dirCount = min(u_dir_light_count, 4);
    for (int dl = 0; dl < dirCount; dl++) {
        vec3 dL = normalize(-u_dir_lights[dl].direction);
        float rawNdotL = dot(N, dL);
        // When a cloud blocks the sun the sharp shadow must vanish (the light is
        // re-scattered as soft diffuse): fade the shadow map toward 1.0 as cloud
        // cover rises, and dim the direct beam only mildly — so the world stays
        // lit but shadowless under a cloud rather than going black.
        // Primary light also receives the Fieldtracing soft SDF sun-shadow
        // (ftSunShadow == 1.0 when the SDF trace pass is inactive).
        float dShadow = (dl == 0) ? mix(1.0, shadow, cloudSh) * ftSunShadow : 1.0;
        vec3 radiance = u_dir_lights[dl].color * u_dir_lights[dl].intensity;
        // Under a thick cloud the direct beam nearly vanishes (floor 0.1)
        // while ambient keeps the scene readable — tuned in-game: the strong
        // sweep is what makes passing clouds readable as weather.
        if (dl == 0) radiance *= mix(0.1, 1.0, cloudSh);
        vec3 lit = vec3(0.0);

#ifndef PHPOLYGON_OMIT_SUBSURFACE
        if (sssStrength > 0.0) {
            // Skin path: wrap-diffuse extends light past the terminator,
            // warm subsurface tint bleeds at grazing angles, and a small
            // back-transmission term lights up thin areas viewed against
            // the light (ear edges, nose tip, finger silhouettes).
            float wrap        = 0.5;
            float wrapNdotL   = clamp((rawNdotL + wrap) / (1.0 + wrap), 0.0, 1.0);
            float terminator  = (1.0 - clamp(rawNdotL, 0.0, 1.0)) * wrapNdotL;
            vec3  scatterTint = mix(vec3(1.0), u_subsurface_color, terminator * sssStrength);
            vec3  effAlbedo   = albedo * scatterTint;
            float backlight   = pow(clamp(dot(V, -dL), 0.0, 1.0), 3.0)
                              * clamp(-rawNdotL, 0.0, 1.0);

            vec3 F = fresnelSchlick(max(dot(normalize(V + dL), V), 0.0), F0);
            vec3 kD = (1.0 - F) * (1.0 - metallic);
            lit += (kD * effAlbedo / 3.14159265) * radiance * wrapNdotL * dShadow;
            if (rawNdotL > 0.0) {
                vec3 spec = cookTorranceSpecular(N, V, dL, roughness, F0);
                lit += spec * radiance * rawNdotL * dShadow;
            }
            lit += u_subsurface_color * albedo * backlight * sssStrength * radiance;
        } else
#endif // PHPOLYGON_OMIT_SUBSURFACE
        {
            float dNdotL = max(rawNdotL, 0.0);
            if (dNdotL > 0.0) {
                vec3 spec = cookTorranceSpecular(N, V, dL, roughness, F0);
                vec3 F = fresnelSchlick(max(dot(normalize(V + dL), V), 0.0), F0);
                vec3 kD = (1.0 - F) * (1.0 - metallic);
                lit += (kD * albedo / 3.14159265 + spec) * radiance * dNdotL * dShadow;
                // Brushed metal (shader plan 5.5): an extra highlight stretched along
                // the surface's horizontal (the brushing direction).
#ifndef PHPOLYGON_OMIT_MATERIAL_LOOK
                if (u_material_look.y > 0.0) {
                    vec3 bT = normalize(cross(vec3(0.0, 1.0, 0.0), N) + vec3(1e-5, 0.0, 0.0));
                    vec3 bB = normalize(cross(N, bT));
                    vec3 bH = normalize(dL + V);
                    float bx = dot(bH, bT) / 0.6;
                    float by = dot(bH, bB) / 0.06;
                    float aniso = exp(-(bx * bx + by * by)) * u_material_look.y;
                    lit += F0 * aniso * radiance * dNdotL * dShadow * 1.5;
                }
                // Micro glitter: tiny crystals flash where their facet mirrors the sun.
                if (u_material_look.z > 0.0) {
                    vec3 gc = floor(v_worldPos * 55.0);
                    vec3 gn = normalize(vec3(hash31(gc), hash31(gc + 1.7), hash31(gc + 3.3)) * 2.0 - 1.0);
                    float flash = pow(max(dot(reflect(-V, normalize(N + gn * 0.6)), dL), 0.0), 600.0);
                    flash *= step(0.82, hash31(gc + 9.1)) * (1.0 - smoothstep(6.0, 20.0, length(u_camera_pos - v_worldPos)));
                    lit += radiance * flash * u_material_look.z * 4.0 * dShadow;
                }
#endif // PHPOLYGON_OMIT_MATERIAL_LOOK
                // Hair shine (shader plan 6.2): strands run down the surface, the
                // highlight is a ring across them (Kajiya-Kay) - a sharp white
                // lobe and a broad one in the hair colour.
#ifndef PHPOLYGON_OMIT_FIGURE_LOOK
                if (u_figure_look.y > 0.0) {
                    vec3 hT = vec3(0.0, 1.0, 0.0) - N * N.y;
                    hT = dot(hT, hT) > 1e-5 ? normalize(hT) : vec3(1.0, 0.0, 0.0);
                    vec3 hH = normalize(dL + V);
                    float th = dot(hT, hH);
                    float sTH = sqrt(max(1.0 - th * th, 0.0));
                    float th2 = dot(hT, normalize(hH + hT * 0.12));
                    float sTH2 = sqrt(max(1.0 - th2 * th2, 0.0));
                    vec3 hairSpec = vec3(pow(sTH, 90.0) * 0.35) + albedo * pow(sTH2, 18.0) * 0.6;
                    lit += hairSpec * u_figure_look.y * radiance * dNdotL * dShadow;
                }
#endif // PHPOLYGON_OMIT_FIGURE_LOOK
            }
        }
        // Cloth sheen (shader plan 5.5): a soft rim of the albedo at grazing angles.
#ifndef PHPOLYGON_OMIT_MATERIAL_LOOK
        if (u_material_look.x > 0.0) {
            float sNV = clamp(dot(N, V), 0.0, 1.0);
            lit += albedo * pow(1.0 - sNV, 3.0) * u_material_look.x * radiance * clamp(dot(N, dL) * 0.5 + 0.5, 0.0, 1.0) * dShadow * 0.6;
        }
#endif // PHPOLYGON_OMIT_MATERIAL_LOOK
        // Micro-shadows (Chan 2018): light at a grazing angle does not reach into the
        // occluded cavity - the AO cone's aperture against the light's angle.
        if (u_micro_shadow > 0.5) {
            float msNdotL = abs(dot(N, normalize(-u_dir_lights[dl].direction)));
            lit *= mix(1.0, clamp(msNdotL + 2.0 * msAo * msAo - 1.0, 0.0, 1.0), 0.7);
        }
        // The primary light is the one the SDF soft sun shadow (composite) scales.
        if (dl == 0) sunAcc += lit; else localAcc += lit;
    }
    // Rim light for figures (shader plan 6.2): sky light round the silhouette,
    // stronger where the sun stands behind the figure; works in shade too.
#ifndef PHPOLYGON_OMIT_FIGURE_LOOK
    if (u_figure_look.x > 0.0) {
        float rNV = clamp(dot(N, V), 0.0, 1.0);
        float rim = pow(1.0 - rNV, 3.0) * smoothstep(-0.2, 0.4, N.y + 0.3);
        vec3 rimCol = u_sky_color * 0.35 + u_ambient_color * 0.35;
        if (u_dir_light_count > 0) {
            vec3 rL = normalize(-u_dir_lights[0].direction);
            float back = clamp(dot(-V, rL), 0.0, 1.0);
            rimCol += u_dir_lights[0].color * u_dir_lights[0].intensity * back * back * 0.5;
        }
        localAcc += rimCol * rim * u_figure_look.x * mix(vec3(1.0), albedo * 2.0, 0.3);
    }
#endif // PHPOLYGON_OMIT_FIGURE_LOOK

#ifdef PHPOLYGON_LIGHT_GRID
    // More than eight point lights: the lights of this fragment's grid cell.
    if (u_light_grid.w > 0.5) {
        // Shape from the buffer's own header, every index bounded by it.
        uint lgNx = lgCells[0];
        uint lgNz = lgCells[1];
        vec2 lgOrigin = vec2(uintBitsToFloat(lgCells[2]), uintBitsToFloat(lgCells[3]));
        float lgInv = uintBitsToFloat(lgCells[4]);
        uint lgLightCount = lgCells[5];
        uint lgIndexCount = lgCells[6];
        ivec2 lgc = ivec2(floor((v_worldPos.xz - lgOrigin) * lgInv));
        if (lgNx > 0u && lgc.x >= 0 && lgc.y >= 0 && uint(lgc.x) < lgNx && uint(lgc.y) < lgNz) {
            uint lgCell = uint(lgc.x) + uint(lgc.y) * lgNx;
            uint lgOff = lgCells[8u + lgCell * 2u];
            uint lgCount = min(lgCells[8u + lgCell * 2u + 1u], LIGHT_GRID_MAX);
            for (uint k = 0u; k < LIGHT_GRID_MAX; k++) {
                if (k >= lgCount || lgOff + k >= lgIndexCount) break;
                uint li = lgIndex[lgOff + k];
                if (li >= lgLightCount) continue;
                vec4 lgPos = lgLights[li * 2u];
                vec3 lgRad = lgLights[li * 2u + 1u].rgb;
                vec3 Lg = lgPos.xyz - v_worldPos;
                float lgDist = length(Lg);
                if (lgDist >= lgPos.w) continue;
                Lg /= max(lgDist, 1e-4);
                float lgAtten = clamp(1.0 - lgDist * lgDist / (lgPos.w * lgPos.w), 0.0, 1.0);
                lgAtten *= lgAtten;
                lgAtten *= pointShadowFor(lgPos.xyz, v_worldPos, N, Lg, lgDist);
                float NdotLG = max(dot(N, Lg), 0.0);
                if (NdotLG > 0.0) {
                    vec3 spec = cookTorranceSpecular(N, V, Lg, roughness, F0);
                    vec3 F = fresnelSchlick(max(dot(normalize(V + Lg), V), 0.0), F0);
                    vec3 kD = (1.0 - F) * (1.0 - metallic);
                    localAcc += (kD * albedo / 3.14159265 + spec) * lgRad * lgAtten * NdotLG;
                }
            }
        }
    }
    // Interiors: no sun through roofs and walls the cascades miss (shader plan 3.3).
    sunAcc *= 1.0 - interiorMask(v_worldPos);
    int pointCount = u_light_grid.w > 0.5 ? 0 : min(u_point_light_count, 8);
#else
    sunAcc *= 1.0 - interiorMask(v_worldPos);
    int pointCount = min(u_point_light_count, 8);
#endif
    for (int i = 0; i < pointCount; i++) {
        vec3 Lp = u_point_lights[i].position - v_worldPos;
        float dist = length(Lp);
        Lp /= dist;
        float r = max(u_point_lights[i].radius, 0.001);
        float atten = lightFalloff(dist, r);
        atten *= pointShadowFor(u_point_lights[i].position, v_worldPos, N, Lp, dist);
        float NdotPL = max(dot(N, Lp), 0.0);
        if (NdotPL > 0.0) {
            vec3 spec = cookTorranceSpecular(N, V, Lp, roughness, F0);
            vec3 F = fresnelSchlick(max(dot(normalize(V + Lp), V), 0.0), F0);
            vec3 kD = (1.0 - F) * (1.0 - metallic);

            vec3 radiance = u_point_lights[i].color * u_point_lights[i].intensity * atten;
            float msPoint = u_micro_shadow > 0.5 ? mix(1.0, clamp(NdotPL + 2.0 * msAo * msAo - 1.0, 0.0, 1.0), 0.7) : 1.0;
            localAcc += (kD * albedo / 3.14159265 + spec) * radiance * NdotPL * msPoint;
        }
    }

    // Spot lights — point-light falloff multiplied by a cone factor.
    int spotCount = min(u_spot_light_count, 4);
    for (int i = 0; i < spotCount; i++) {
        vec3 Ls = u_spot_lights[i].position - v_worldPos;
        float dist = length(Ls);
        Ls /= dist;
        float r = max(u_spot_lights[i].range, 0.001);
        float atten = lightFalloff(dist, r);

        float cosOuter = cos(u_spot_lights[i].angle);
        float cosInner = cos(u_spot_lights[i].angle * (1.0 - u_spot_lights[i].penumbra));
        float cd = dot(-Ls, normalize(u_spot_lights[i].direction));
        float cone = smoothstep(cosOuter, cosInner, cd);
        atten *= cone;
        if (i == u_spot_shadow_index && cone > 0.0) {
            atten *= spotShadow(v_worldPos, N, Ls, dist);
        }

        float NdotSL = max(dot(N, Ls), 0.0);
        if (NdotSL > 0.0 && cone > 0.0) {
            vec3 spec = cookTorranceSpecular(N, V, Ls, roughness, F0);
            vec3 F = fresnelSchlick(max(dot(normalize(V + Ls), V), 0.0), F0);
            vec3 kD = (1.0 - F) * (1.0 - metallic);

            vec3 radiance = u_spot_lights[i].color * u_spot_lights[i].intensity * atten;
            localAcc += (kD * albedo / 3.14159265 + spec) * radiance * NdotSL;
        }
    }

    // Rectangular area lights: diffuse from the exact form factor; specular at the
    // point of the rectangle nearest the reflection ray, with the roughness widened
    // by the rectangle's narrow side as seen from the point (shader plan 3.3).
    int rectCount = min(u_rect_light_count, 4);
    for (int i = 0; i < rectCount; i++) {
        vec3 rc = u_rect_lights[i].center;
        vec3 rx = u_rect_lights[i].axisX;
        vec3 ry = u_rect_lights[i].axisY;
        vec3 rn = normalize(cross(rx, ry));
        if (dot(v_worldPos - rc, rn) <= 0.0) continue;
        float rr = max(u_rect_lights[i].range, 0.001);
        float rcd = length(v_worldPos - rc);
        if (rcd >= rr) continue;
        float win = clamp(1.0 - rcd * rcd / (rr * rr), 0.0, 1.0);
        win *= win;
        float E = rectFormFactor(v_worldPos, N, rc, rx, ry);
        if (E <= 0.0) continue;
        // Like the point lights (windowed, no inverse square): the form factor
        // scaled by distance^2 / area - the distance the geometric mean of the ones to
        // the nearest point and to the centre - which far away is the panel's cosine
        // times N.L and near it keeps the rectangle's long shape.
        float hx = length(rx);
        float hy = length(ry);
        vec3 ux = rx / max(hx, 1e-4);
        vec3 uy = ry / max(hy, 1e-4);
        float rArea = max(4.0 * hx * hy, 1e-4);
        vec3 rq = v_worldPos - rc;
        vec3 rNear = rc + ux * clamp(dot(rq, ux), -hx, hx) + uy * clamp(dot(rq, uy), -hy, hy) - v_worldPos;
        E *= length(rNear) * rcd / rArea;
        vec3 radiance = u_rect_lights[i].color * win;

        vec3 Rr = reflect(-V, N);
        float rdn = dot(Rr, rn);
        vec3 hit = v_worldPos + Rr * (dot(rc - v_worldPos, rn) / (abs(rdn) > 1e-4 ? rdn : -1e-4));
        if (rdn >= -1e-4) hit = v_worldPos - rn * dot(v_worldPos - rc, rn);
        vec3 hd = hit - rc;
        vec3 Lpt = rc + ux * clamp(dot(hd, ux), -hx, hx) + uy * clamp(dot(hd, uy), -hy, hy);
        vec3 Lr = Lpt - v_worldPos;
        float ld = max(length(Lr), 1e-3);
        Lr /= ld;
        float NdotLR = max(dot(N, Lr), 0.0);
        vec3 Fr = fresnelSchlick(max(dot(normalize(V + Lr), V), 0.0), F0);
        vec3 kDr = (1.0 - Fr) * (1.0 - metallic);
        float aWide = clamp(roughness * roughness + 0.5 * min(hx, hy) / ld, 0.0, 1.0);
        vec3 specR = NdotLR > 0.0 ? cookTorranceSpecular(N, V, Lr, sqrt(aWide), F0) : vec3(0.0);
        localAcc += (kDr * albedo / 3.14159265 + specR) * radiance * E;
    }

    // ---- Image-Based Lighting (IBL) reflection ----
    // Skipped by materials that opt out (u_use_environment_map = 0); metals
    // reflect fully, dielectrics only through the Fresnel term.
    if (u_use_environment_map == 1) {
        vec3 R = reflect(-V, N);
        vec3 F_ibl = fresnelSchlick(NdotV, F0);
        vec3 envColor = sampleEnvironment(R, roughness);
        float envShadow = shadow;
#ifdef PHPOLYGON_HEMI_SPECULAR
        // Opt-in (VioRenderer3D::setHemisphereSpecular), so metals do not render black: with no
        // probe bound reflect the ambient light as a sky/ground hemisphere.
        if (u_has_environment_map == 0) {
            float hemiUp = mix(clamp(R.y * 0.5 + 0.5, 0.0, 1.0), 0.5, roughness * roughness);
            vec3 hemiAmb = u_ambient_color * u_ambient_intensity * (1.0 / 3.14159265);
            envColor = mix(hemiAmb * 0.6, hemiAmb * 1.2, hemiUp) * u_hemi_specular;
        }
        // Sky light, not the sun's: the key light's shadow must not black the reflection out
        // (with a probe bound too).
        envShadow = ambientShadow * ao;
#endif // PHPOLYGON_HEMI_SPECULAR
        float iblWeight = mix(0.15, 1.0, metallic) * (1.0 - roughness * 0.6) * (1.0 - 0.75 * interiorMask(v_worldPos));
        localAcc += envColor * F_ibl * iblWeight * envShadow;
    }

#ifndef PHPOLYGON_OMIT_CLEARCOAT
    // ---- Clearcoat lobe (carpaint, dielectric F0 ≈ 0.04) ----
    if (u_clearcoat > 0.0 && u_dir_light_count > 0) {
        float ccRough = clamp(u_clearcoat_roughness, 0.02, 1.0);
        vec3 ccF0 = vec3(0.04);
        vec3 ccL = normalize(-u_dir_lights[0].direction);
        float ccNdotL = max(dot(N, ccL), 0.0);
        if (ccNdotL > 0.0) {
            vec3 ccSpec = cookTorranceSpecular(N, V, ccL, ccRough, ccF0);
            localAcc += ccSpec * u_dir_lights[0].color * u_dir_lights[0].intensity
                   * ccNdotL * shadow * u_clearcoat;
        }
        // Clearcoat IBL: sharp, weakly roughness-modulated reflection of the
        // environment cube (sky/horizon tint when no map is bound). Skipped,
        // like the base IBL, by materials that opt out of the environment map
        // (an interior pane must not mirror the outdoor sky).
        if (u_use_environment_map == 1) {
            vec3 ccR = reflect(-V, N);
            vec3 ccEnv = sampleEnvironment(ccR, ccRough);
            vec3 ccFres = fresnelSchlick(NdotV, ccF0);
            localAcc += ccEnv * ccFres * u_clearcoat * (1.0 - ccRough * 0.5) * 0.4;
        }
    }
#endif // PHPOLYGON_OMIT_CLEARCOAT

    // Emissive looks (shader plan 5.4).
    vec3 emission = u_emission * particleRgb;
    // Applied in a branch: a multiply by 1 on every other material changed how
    // NVIDIA's D3D12 driver compiled the lighting (1 LSB on unrelated surfaces).
    if (u_emission_mask > 0.5) {
        emission *= emissionMask;
    }
#ifndef PHPOLYGON_OMIT_EMISSIVE_LOOK
    if (u_emissive_look.x > 0.0 && dot(emission, vec3(1.0)) > 0.0) {
        // Windows: a grid on the wall plane (u_window_grid, 2.2 m x 2.8 m by default), a share of them lit
        // in a warm or cool tone; behind each a room - back wall bright, side
        // walls, floor and ceiling darker - seen through the glass at the view
        // angle (interior mapping); a dark frame round each pane.
        vec3 wt = normalize(cross(vec3(0.0, 1.0, 0.0), N) + vec3(1e-5, 0.0, 0.0));
        vec2 wpos = u_emissive_look.z > 0.5 ? v_uv : vec2(dot(v_worldPos, wt), v_worldPos.y);
        vec2 cuv = (wpos - u_window_grid.zw) / u_window_grid.xy;
        vec2 cell = floor(cuv);
        vec2 f = fract(cuv);
        if (u_window_vary > 0.5 && u_emissive_look.z > 0.5) {
            // World position of this cell's centre (UV units are metres along wt and world Y), so identical
            // quads placed side by side draw different cells. Quantised to 10 cm: constant over the cell.
            vec2 centre = (cell + 0.5) * u_window_grid.xy + u_window_grid.zw;
            vec3 cw = v_worldPos + wt * (centre.x - v_uv.x) + vec3(0.0, centre.y - v_uv.y, 0.0);
            vec3 q = floor(cw * 10.0 + 0.5);
            cell += vec2(hash21(q.xz + q.y * 17.0 + 3.1), hash21(q.zx + q.y * 5.0 + 9.7)) * 97.0;
        }
        float h = hash21(cell + 31.7);
        float lit = step(h, u_emissive_look.x);
        vec3 tone = mix(vec3(1.0, 0.82, 0.6), vec3(0.75, 0.86, 1.0), step(0.7, hash21(cell + 5.1)));
        vec3 vt = vec3(dot(-V, wt), -V.y, dot(-V, -N));
        float depth = 0.8;
        vec2 back = f + vt.xy / max(vt.z, 0.05) * depth / u_window_grid.xy * 2.0;
        float room = (back.x > 0.0 && back.x < 1.0 && back.y > 0.0 && back.y < 1.0) ? 1.0 : (back.y < 0.0 ? 0.35 : 0.55);
        float frame = smoothstep(0.0, 0.06, f.x) * smoothstep(1.0, 0.94, f.x) * smoothstep(0.0, 0.08, f.y) * smoothstep(1.0, 0.92, f.y);
        emission *= mix(0.03, (0.6 + 0.6 * hash21(cell + 2.3)) * room, lit) * frame * tone;
    }
    if (u_emissive_look.y > 0.5) {
        // Neon tube: hot, whitish core facing the viewer, the colour at the rim.
        float tn = clamp(dot(N, V), 0.0, 1.0);
        float lum = max(max(emission.r, emission.g), emission.b);
        emission = mix(emission * (0.35 + 1.2 * pow(tn, 2.0)), vec3(lum * 1.6), pow(tn, 10.0) * 0.6);
    }
#endif // PHPOLYGON_OMIT_EMISSIVE_LOOK
    localAcc += emission + surfaceEmission;
    // Wet-leaf drops catch the sky (see above).
    localAcc += leafDrops * u_ambient_color * u_ambient_intensity * 0.9;
    localAcc += glassDrops * u_ambient_color * u_ambient_intensity * 0.6;
    localAcc += snowSparkle * (u_ambient_color * u_ambient_intensity + u_dir_light_color * 0.5) * 1.2;

    vec4 sceneFogV = sceneFog(v_worldPos);
    float fogT = sceneFogV.a;

    // Soft puffs (Material::$softParticle, shader plan 4.2): round on their quad and
    // fading where they meet geometry, so smoke and steam have no hard edges.
#ifndef PHPOLYGON_OMIT_SOFT_PARTICLE
    if (u_soft_particle > 0.0) {
        alpha *= 1.0 - smoothstep(0.18, 0.5, length(v_uv - 0.5));
#ifdef PHPOLYGON_SCENE_DEPTH
        alpha *= clamp(sceneDepthDelta() / u_soft_particle, 0.0, 1.0);
#endif
        // Close to the eye a puff thins out instead of filling the view.
        alpha *= smoothstep(0.4, 2.0, length(u_camera_pos - v_worldPos));
        // Smoke lighting (shader plan 7.1): a puff is darker on the side away from the
        // sun and glows where the sun shines through it towards the eye (forward
        // scattering, strongest at the thin rim).
        if (u_dir_light_count > 0) {
            vec3 sL = normalize(-u_dir_lights[0].direction);
            float rimT = smoothstep(0.1, 0.5, length(v_uv - 0.5));
            float facing = 0.5 + 0.5 * dot(sL, V);
            float through = pow(max(dot(-V, sL), 0.0), 6.0) * (0.35 + 0.65 * rimT);
            float smokeLit = mix(0.62, 1.08, facing) + through * 0.9;
            sunAcc *= smokeLit;
            ambientAcc *= mix(0.8, 1.05, facing);
        }
    }
#endif // PHPOLYGON_OMIT_SOFT_PARTICLE

    // Refraction (Material::$refraction): what lies behind, shifted along the
    // screen-parallel part of the normal and blurred for frosted glass, tinted by
    // the pane; the pane's own light covers it by its alpha, and the result is
    // opaque - the surface does the blending itself.
    vec3 refrBehind = vec3(0.0);
    float refrOn = 0.0;
#ifdef PHPOLYGON_SCENE_COLOR
#ifndef PHPOLYGON_OMIT_REFRACTION
    {
        vec3 rdx = dFdx(v_worldPos);
        vec3 rdy = dFdy(v_worldPos);
        vec2 rdir = vec2(dot(N, rdx / max(length(rdx), 1e-6)), dot(N, rdy / max(length(rdy), 1e-6)));
        if (u_refract.x > 0.0 && u_scene_color_ready > 0.5) {
            vec2 suv = gl_FragCoord.xy / u_viewport_size;
            vec2 ruv = clamp(suv + rdir * (0.035 * u_refract.x), vec2(0.001), vec2(0.999));
            float fr = u_refract.y * 0.012;
            vec3 acc = sceneColorAt(ruv);
            if (fr > 0.0) {
                vec2 asp = vec2(u_viewport_size.y / max(u_viewport_size.x, 1.0), 1.0);
                for (int k = 0; k < 8; k++) {
                    float a = float(k) * 0.785398 + 0.39;
                    acc += sceneColorAt(clamp(ruv + vec2(cos(a), sin(a)) * asp * fr, vec2(0.001), vec2(0.999)));
                    acc += sceneColorAt(clamp(ruv + vec2(cos(a), sin(a)) * asp * fr * 0.5, vec2(0.001), vec2(0.999)));
                }
                acc /= 17.0;
            }
            refrBehind = acc * mix(vec3(1.0), albedo, 0.35);
            refrOn = 1.0;
        }
    }
#endif // PHPOLYGON_OMIT_REFRACTION
#endif

#ifndef PHPOLYGON_OMIT_REFRACTION
    // Frost without a scene copy (no unit left for it, e.g. D3D11 with 16 units): the
    // pane cannot blur what lies behind it, so it clouds it - up to 55 % more cover
    // at frost 1, the pane's own light covering the backdrop.
    if (refrOn == 0.0 && u_refract.y > 0.0) {
        alpha = alpha + (1.0 - alpha) * 0.55 * clamp(u_refract.y, 0.0, 1.0);
    }
#endif // PHPOLYGON_OMIT_REFRACTION

#ifdef PHPOLYGON_MRT
    // mix(sum, fog, t) is affine: every part scales by (1 - t), the fog colour is
    // local light. Volumetrics are additive local light. The post-colour hook is
    // distributed as S * part (+ A on the local part), see applyPostColor().
    sunAcc     *= (1.0 - fogT);
    ambientAcc *= (1.0 - fogT);
    localAcc    = localAcc * (1.0 - fogT) + sceneFogV.rgb * fogT;
    localAcc   += volumetricScatter(v_worldPos);
    localAcc   += lightInscatter(v_worldPos) * (1.0 - 0.5 * fogT);
    if (refrOn > 0.0) {
        sunAcc *= alpha;
        ambientAcc *= alpha;
        localAcc = localAcc * alpha + refrBehind * (1.0 - alpha);
        alpha = 1.0;
    }
    vec3 pcA = applyPostColor(vec3(0.0));
    vec3 pcS = applyPostColor(vec3(1.0)) - pcA;
    float coverage = mrtCoverage(alpha);
    o_sun     = vec4(max(sunAcc * pcS, vec3(0.0)), coverage);
    o_local   = vec4(max(localAcc * pcS + pcA, vec3(0.0)), coverage);
    o_ambient = vec4(max(ambientAcc * pcS, vec3(0.0)), coverage);
#else
    vec3 color = sunAcc + localAcc + ambientAcc;
    color = mix(color, sceneFogV.rgb, fogT);
    color += volumetricScatter(v_worldPos);
    color += lightInscatter(v_worldPos) * (1.0 - 0.5 * fogT);
    if (refrOn > 0.0) {
        color = mix(refrBehind, color, alpha);
        alpha = 1.0;
    }
    color = applyPostColor(color);
    frag_color = outputColor(color, alpha);
#endif
}
