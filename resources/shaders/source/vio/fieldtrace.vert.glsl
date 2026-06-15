#version 410 core

// Fieldtracing fullscreen pass — vio variant (compiles GLSL -> SPIR-V ->
// backend via glslang/SPIRV-Cross, so it runs on D3D11/D3D12/Vulkan/Metal/GL).
// Driven by a fullscreen quad mesh (loc 0 = position, loc 1 = uv), matching the
// convention used by fxaa.vert.glsl. No VBO tricks (gl_VertexID is not portable
// across vio's transpile targets).

layout(location = 0) in vec3 a_position;
layout(location = 1) in vec2 a_uv;

out vec2 v_uv;

void main() {
    v_uv = a_uv;
    gl_Position = vec4(a_position.xy, 0.0, 1.0);
}
