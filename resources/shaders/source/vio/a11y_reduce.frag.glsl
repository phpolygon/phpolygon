#version 410 core
// Luminance reduction of the accessibility pass: each output texel is the mean of a
// 4x4 block of the source, from four bilinear taps. The first step reads the finished
// image (2.2 encoded) and turns it into linear Rec. 709 luminance; later steps read
// the previous step's luminance in r. Ends at 1x1, the mean of the whole frame.
in vec2 v_uv;
uniform sampler2D u_source;
uniform vec2  u_texel;   // 1 / size of u_source
uniform float u_decode;  // 1 = u_source is the encoded image
out vec4 frag_color;

float lum(vec2 uv) {
    vec3 c = texture(u_source, uv).rgb;
    if (u_decode > 0.5) {
        return dot(pow(max(c, vec3(0.0)), vec3(2.2)), vec3(0.2126, 0.7152, 0.0722));
    }
    return c.r;
}

void main() {
    float m = 0.25 * (lum(v_uv + u_texel * vec2(-1.0, -1.0)) + lum(v_uv + u_texel * vec2(1.0, -1.0))
                    + lum(v_uv + u_texel * vec2(-1.0, 1.0)) + lum(v_uv + u_texel * vec2(1.0, 1.0)));
    frag_color = vec4(m, m, m, 1.0);
}
