#version 410 core

// Raw shadow-map depth visualiser.
//
// Samples the cascade depth texture with a PLAIN sampler2D (NOT
// sampler2DShadow) so it returns the stored depth value itself, not a
// PCF comparison result. This is the whole point of the debug pass: the
// dark "disc" you see in-game is the *comparison* output; this shows the
// *stored depth*, which decides storage-side vs compare-side as the bug.
//
// Output:
//   - left  ~2/3 of each tile: raw depth as grayscale (black=0/near,
//     white=1/far). A smooth ramp => storage is fine. Uniform black or
//     white, or garbage => storage/sampling is broken.
//   - right ~1/3 (u_uv.x > 0.66): the same depth contrast-stretched around
//     its own value so a near-planar map (which is almost all ~white)
//     still reveals whether it carries any gradient at all.

in vec2 v_uv;
out vec4 fragColor;

uniform sampler2D u_depth_map;

void main() {
    float d = texture(u_depth_map, v_uv).r;

    vec3 col;
    if (v_uv.x > 0.66) {
        // Contrast stretch: amplify deviation from mid so a tight depth
        // range is still visible. 0.5 maps to grey, small deltas to colour.
        float s = clamp((d - 0.5) * 8.0 + 0.5, 0.0, 1.0);
        // Cheap blue->red ramp to make any gradient pop.
        col = vec3(s, 0.15, 1.0 - s);
    } else {
        col = vec3(d);
    }
    fragColor = vec4(col, 1.0);
}
