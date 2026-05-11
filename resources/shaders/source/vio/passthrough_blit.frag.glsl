#version 410 core
in vec2 v_uv;
uniform sampler2D u_source;
out vec4 frag_color;
void main() {
    frag_color = vec4(texture(u_source, v_uv).rgb, 1.0);
}
