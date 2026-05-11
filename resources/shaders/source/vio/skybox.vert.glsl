#version 410 core

layout(location = 0) in vec3 a_position;

uniform mat4 u_view;
uniform mat4 u_projection;

out vec3 v_texCoord;

void main() {
    v_texCoord = a_position;
    // Strip translation so the cube follows the camera.
    mat4 rotView = mat4(mat3(u_view));
    gl_Position = u_projection * rotView * vec4(a_position, 1.0);
}
