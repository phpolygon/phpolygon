#version 410 core
// Copy of the opaque depth as linear view depth, for the transparent pass: the depth
// buffer itself stays attached while transparent surfaces test against it. Depth is
// stored as (ndcZ + 1) / 2 on every backend. u_proj = projection[2][2], [3][2].
in vec2 v_uv;
uniform sampler2D u_depth;
uniform vec2 u_proj;
out vec4 frag_color;

void main() {
    float ndcZ = texture(u_depth, v_uv).r * 2.0 - 1.0;
    float linear = u_proj.y / (ndcZ + u_proj.x);
    frag_color = vec4(linear >= 0.0 ? min(linear, 60000.0) : 60000.0, 0.0, 0.0, 1.0);
}
