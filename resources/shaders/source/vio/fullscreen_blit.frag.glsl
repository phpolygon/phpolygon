#version 410 core
// Copies an image of the fullscreen pass chain to where it goes on: back into the
// scene target, or into the swapchain with the HDR10 encoding of passthrough_blit.
in vec2 v_uv;
uniform sampler2D u_source;
uniform int   u_output_pq;
uniform float u_paper_white;
out vec4 frag_color;

vec3 outputEncode(vec3 c) {
    if (u_output_pq != 1) return c;
    vec3 lin = pow(max(c, vec3(0.0)), vec3(2.2));
    mat3 toBT2020 = mat3(0.6274, 0.0691, 0.0164,
                         0.3293, 0.9195, 0.0880,
                         0.0433, 0.0114, 0.8956);
    vec3 nits = (toBT2020 * lin) * (max(u_paper_white, 1.0) / 10000.0);
    vec3 y = pow(max(nits, vec3(0.0)), vec3(0.1593017578125));
    return pow((0.8359375 + 18.8515625 * y) / (1.0 + 18.6875 * y), vec3(78.84375));
}

void main() {
    frag_color = vec4(outputEncode(texture(u_source, v_uv).rgb), 1.0);
}
