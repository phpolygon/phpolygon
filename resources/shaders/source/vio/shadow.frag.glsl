#version 410 core

out vec4 frag_color;

void main() {
    frag_color = vec4(gl_FragCoord.z, gl_FragCoord.z, gl_FragCoord.z, 1.0);
}
