#version 410 core
layout(location = 0) in vec3 a_position;
layout(location = 1) in vec2 a_uv;
out vec2 v_ndc;
void main() {
    // Full clip-space quad at z = 1 (far plane). Depth test is disabled so
    // the actual depth value doesn't matter, but writing z = 1 keeps the
    // sky behind anything that uses this depth buffer later.
    gl_Position = vec4(a_position.xy, 1.0, 1.0);
    v_ndc = a_position.xy;
}
