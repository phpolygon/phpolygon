#version 150 core
// Fullscreen triangle, screen-space (no VBO required - gl_VertexID drives the
// vertex positions). Same trick as fxaa.vert.glsl. v_uv covers [0, 1] over
// the visible part of the triangle that intersects the [-1, 1] clip square.
out vec2 v_uv;

void main() {
    vec2 pos = vec2(
        float((gl_VertexID & 1) << 2),
        float((gl_VertexID & 2) << 1)
    ) - 1.0;
    gl_Position = vec4(pos, 0.0, 1.0);
    v_uv = pos * 0.5 + 0.5;
}
