#version 410 core

// Fieldtracing fullscreen pass — OpenGL variant. Uses the classic
// gl_VertexID fullscreen-triangle trick (drawn with glDrawArrays(TRIANGLES,0,3),
// no VBO). Outputs v_uv in [0,1] so the fragment shader is byte-identical to the
// vio copy (resources/shaders/source/vio/fieldtrace.frag.glsl).

out vec2 v_uv;

void main() {
    vec2 ndc = vec2((gl_VertexID == 1) ? 3.0 : -1.0,
                    (gl_VertexID == 2) ? 3.0 : -1.0);
    v_uv = (ndc + 1.0) * 0.5;
    gl_Position = vec4(ndc, 0.0, 1.0);
}
