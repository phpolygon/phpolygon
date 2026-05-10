#version 410 core

// Fullscreen triangle, identical to ssr.vert.glsl - kept separate so
// shader-tooling that diffs the post-process stack stays readable.
out vec2 v_uv;

void main() {
    vec2 pos = vec2(
        float((gl_VertexID & 1) << 2),
        float((gl_VertexID & 2) << 1)
    ) - 1.0;
    gl_Position = vec4(pos, 0.0, 1.0);
    v_uv = pos * 0.5 + 0.5;
}
