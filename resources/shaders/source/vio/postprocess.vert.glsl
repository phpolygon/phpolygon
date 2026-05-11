#version 410 core
layout(location = 0) in vec3 a_position;
layout(location = 1) in vec2 a_uv;
out vec2 v_uv;
void main() {
    v_uv = a_uv;
    gl_Position = vec4(a_position.xy, 0.0, 1.0);
}
