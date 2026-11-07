#version 410 core
// Froxel fog 1/3 (shader plan 4.2, GraphicsSettings::$froxelFog): one fragment per
// froxel of a 160 x 90 x 64 grid over the view frustum, stored as an 8 x 8 atlas of
// depth slices. Each froxel gets the fog density at its centre and the light it
// scatters towards the camera: the sun through the shadow cascades (forward-biased
// phase, as the per-pixel march had) and the nearest lamps. Slices are spaced
// quadratically up to u_froxel_far, dense near the camera.
in vec2 v_uv;
uniform mat4 u_froxel_inv_vp;   // inverse(projection * rotation-only view): ndc -> direction
uniform vec3 u_camera_pos;
uniform float u_froxel_far;
uniform float u_fog_density;    // density at sea level per metre
uniform float u_fog_falloff;    // exponential height falloff per metre
uniform vec3 u_sun_dir;         // towards the sun
uniform vec3 u_sun_radiance;    // colour * intensity * volumetric scale
uniform int u_has_shadow_map;
uniform int u_csm_count;
uniform mat4 u_csm_matrix_0;
uniform mat4 u_csm_matrix_1;
uniform mat4 u_csm_matrix_2;
uniform float u_csm_far_0;
uniform float u_csm_far_1;
uniform sampler2DShadow u_csm_map_0;
uniform sampler2DShadow u_csm_map_1;
uniform sampler2DShadow u_csm_map_2;
uniform int u_lamp_count;
uniform vec4 u_lamps[16];       // [2i] position.xyz, radius; [2i+1] colour * intensity
out vec4 frag_color;

const float TILES = 8.0;
const float SLICES = 64.0;

float shadowAt(vec3 p) {
    if (u_has_shadow_map == 0) return 1.0;
    float dist = length(p - u_camera_pos);
    vec4 lsp;
    vec3 pc;
    if (u_csm_count >= 3 && dist > u_csm_far_1) {
        lsp = u_csm_matrix_2 * vec4(p, 1.0);
        pc = lsp.xyz / lsp.w * 0.5 + 0.5;
        if (pc.x < 0.0 || pc.x > 1.0 || pc.y < 0.0 || pc.y > 1.0 || pc.z > 1.0) return 1.0;
        return texture(u_csm_map_2, vec3(pc.xy, pc.z - 0.002));
    }
    if (u_csm_count >= 2 && dist > u_csm_far_0) {
        lsp = u_csm_matrix_1 * vec4(p, 1.0);
        pc = lsp.xyz / lsp.w * 0.5 + 0.5;
        if (pc.x < 0.0 || pc.x > 1.0 || pc.y < 0.0 || pc.y > 1.0 || pc.z > 1.0) return 1.0;
        return texture(u_csm_map_1, vec3(pc.xy, pc.z - 0.002));
    }
    lsp = u_csm_matrix_0 * vec4(p, 1.0);
    pc = lsp.xyz / lsp.w * 0.5 + 0.5;
    if (pc.x < 0.0 || pc.x > 1.0 || pc.y < 0.0 || pc.y > 1.0 || pc.z > 1.0) return 1.0;
    return texture(u_csm_map_0, vec3(pc.xy, pc.z - 0.002));
}

void main() {
    vec2 cell = v_uv * TILES;
    vec2 tile = floor(cell);
    vec2 fxy = fract(cell);
    float slice = tile.y * TILES + tile.x;
    vec4 w = u_froxel_inv_vp * vec4(fxy * 2.0 - 1.0, 1.0, 1.0);
    vec3 dir = normalize(w.xyz / w.w);
    float t = (slice + 0.5) / SLICES;
    vec3 p = u_camera_pos + dir * (u_froxel_far * t * t);

    float density = u_fog_density * exp(-max(p.y, 0.0) * u_fog_falloff);
    float cosTheta = dot(dir, u_sun_dir);
    // Strongly forward-biased: a faint floor, the glow concentrated towards the sun.
    float phase = 0.08 + pow(max(cosTheta, 0.0), 7.0) * 4.0;
    // Four shadow taps spread over the froxel (along the ray and across it): a shadow
    // edge inside the cell becomes a soft step instead of a hard block.
    vec3 side = normalize(cross(dir, vec3(0.0, 1.0, 0.0)) + vec3(1.0e-4));
    vec3 up = cross(side, dir);
    float a0 = (slice + 0.25) / SLICES;
    float a1 = (slice + 0.75) / SLICES;
    float across = u_froxel_far * t * t / 90.0; // about half a froxel's width there
    float lit = 0.25 * (shadowAt(u_camera_pos + dir * (u_froxel_far * a0 * a0) + side * across)
                      + shadowAt(u_camera_pos + dir * (u_froxel_far * a0 * a0) - side * across)
                      + shadowAt(u_camera_pos + dir * (u_froxel_far * a1 * a1) + up * across)
                      + shadowAt(u_camera_pos + dir * (u_froxel_far * a1 * a1) - up * across));
    vec3 light = u_sun_radiance * phase * lit;
    for (int i = 0; i < 8; i++) {
        if (i >= u_lamp_count) break;
        vec4 lp = u_lamps[i * 2];
        vec3 d = lp.xyz - p;
        float d2 = dot(d, d);
        float r = max(lp.w, 0.01);
        float fall = clamp(1.0 - d2 / (r * r), 0.0, 1.0);
        // Isotropic, falling off with distance and to zero at the light's radius.
        light += u_lamps[i * 2 + 1].rgb * (fall * fall) / (1.0 + d2);
    }
    frag_color = vec4(light * density, density);
}
