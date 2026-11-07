#version 410 core
// Froxel fog 3/3: after the opaque pass, every pixel adds the light the fog scatters
// between the camera and the surface (or the sky) - one lookup into the integrated
// froxels at the pixel's direction and distance, two slices blended.
in vec2 v_ndc;
uniform mat4 u_sky_inv_vp;       // the froxel grid's inverse(projection * rotation-only view)
uniform vec3 u_camera_forward;
uniform sampler2D u_froxel_volume;
uniform sampler2D u_scene_depth; // linear view depth (scene_depth_copy)
uniform vec2 u_viewport_size;
uniform float u_froxel_far;
#ifdef PHPOLYGON_MRT
layout(location = 0) out vec4 o_sun;
layout(location = 1) out vec4 o_local;
layout(location = 2) out vec4 o_ambient;
layout(location = 3) out vec4 o_gbuffer;
#define frag_color o_local
#else
out vec4 frag_color;
#endif

const float TILES = 8.0;
const float SLICES = 64.0;

vec3 sliceScatter(vec2 fxy, float slice) {
    vec2 at = (vec2(mod(slice, TILES), floor(slice / TILES)) + fxy) / TILES;
    return texture(u_froxel_volume, at).rgb;
}

void main() {
    vec4 w = u_sky_inv_vp * vec4(v_ndc, 1.0, 1.0);
    vec3 dir = normalize(w.xyz / w.w);
    float viewDepth = texture(u_scene_depth, gl_FragCoord.xy / u_viewport_size).r;
    float dist = min(viewDepth / max(dot(dir, u_camera_forward), 1.0e-3), u_froxel_far);
    // Keep the lookup inside one froxel column of the atlas tile (no bleeding).
    vec2 fxy = clamp(v_ndc * 0.5 + 0.5, vec2(0.5 / 160.0, 0.5 / 90.0), vec2(1.0 - 0.5 / 160.0, 1.0 - 0.5 / 90.0));
    // Slice k holds the sum up to its far edge, far * ((k + 1) / 64)^2.
    // Half a slice of per-pixel dither (interleaved gradient noise) hides the slice steps.
    float dither = fract(52.9829189 * fract(dot(gl_FragCoord.xy, vec2(0.06711056, 0.00583715)))) - 0.5;
    float k = sqrt(dist / u_froxel_far) * SLICES - 1.0 + dither;
    vec3 s;
    if (k < 0.0) {
        s = sliceScatter(fxy, 0.0) * clamp(k + 1.0, 0.0, 1.0);
    } else {
        float k0 = floor(k);
        float k1 = min(k0 + 1.0, SLICES - 1.0);
        s = mix(sliceScatter(fxy, k0), sliceScatter(fxy, k1), k - k0);
    }
    // Saturating softly, so a sun-facing view never blows out while shafts and lamp
    // halos stay visible against the lit scene.
    s *= 0.6;
    s = s / (1.0 + s);
#ifdef PHPOLYGON_MRT
    o_sun = vec4(0.0);
    o_ambient = vec4(0.0);
    o_gbuffer = vec4(0.0);
#endif
    // Alpha 1: the additive blend weighs the source by its alpha.
    frag_color = vec4(s, 1.0);
}
