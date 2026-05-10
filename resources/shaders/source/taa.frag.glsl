#version 410 core

// Temporal anti-aliasing composite.
//
//   - u_color_texture   : the current frame, rendered with a sub-pixel
//                         jittered projection matrix
//   - u_history_texture : the previous frame's TAA output
//   - u_blend_factor    : history weight, typically 0.9. Larger = more
//                         stable but more ghost-prone.
//
// Neighborhood clamping (Karis 2014) suppresses ghosting: the history
// sample is clamped into the bounding box of the 3x3 neighborhood of
// the current frame. When the scene changes locally the history value
// gets snapped back into the new range so trails fade in 1-2 frames.

in vec2 v_uv;

uniform sampler2D u_color_texture;
uniform sampler2D u_history_texture;
uniform vec2 u_inverse_resolution;
uniform float u_blend_factor;

out vec4 frag_color;

void main() {
    vec3 current = texture(u_color_texture, v_uv).rgb;

    // 3x3 neighborhood min/max - the AABB the history sample is clamped to.
    vec3 neighMin = current;
    vec3 neighMax = current;
    for (int x = -1; x <= 1; x++) {
        for (int y = -1; y <= 1; y++) {
            if (x == 0 && y == 0) continue;
            vec2 offset = vec2(float(x), float(y)) * u_inverse_resolution;
            vec3 s = texture(u_color_texture, v_uv + offset).rgb;
            neighMin = min(neighMin, s);
            neighMax = max(neighMax, s);
        }
    }

    vec3 history = texture(u_history_texture, v_uv).rgb;
    history = clamp(history, neighMin, neighMax);

    vec3 result = mix(current, history, clamp(u_blend_factor, 0.0, 0.99));
    frag_color = vec4(result, 1.0);
}
