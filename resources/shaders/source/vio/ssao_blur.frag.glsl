#version 410 core

// SSAO blur — 4x4 box blur over the raw AO target.
//
// The SSAO pass rotates its hemisphere kernel by a 4x4-tiled per-pixel noise
// vector, which trades banding for high-frequency noise. A box blur exactly the
// size of the noise tile (4x4) averages that noise back out without needing a
// bilateral/depth-aware kernel — cheap and sufficient for the soft contact
// shadows SSAO contributes here. Reads R, writes R (the forward pass samples R).

in vec2 v_uv;

uniform sampler2D u_source;
uniform vec2 u_texel; // 1.0 / AO-target resolution

out vec4 frag_color;

void main() {
    float result = 0.0;
    // Offsets -2..+1 give a symmetric 4x4 footprint around the texel centre.
    for (int x = -2; x < 2; x++) {
        for (int y = -2; y < 2; y++) {
            vec2 off = vec2(float(x), float(y)) * u_texel;
            result += texture(u_source, v_uv + off).r;
        }
    }
    result /= 16.0;
    frag_color = vec4(result, 0.0, 0.0, 1.0);
}
