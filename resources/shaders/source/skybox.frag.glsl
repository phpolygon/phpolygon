#version 410 core

in vec3 v_texCoord;

uniform samplerCube u_skybox;

out vec4 frag_color;

void main() {
    frag_color = texture(u_skybox, v_texCoord);
}
