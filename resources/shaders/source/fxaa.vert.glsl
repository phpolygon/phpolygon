#version 410 core

// Fullscreen-triangle FXAA vertex shader.
// Drawn with glDrawArrays(GL_TRIANGLES, 0, 3) and gl_VertexID -> NDC corners.
// No vertex buffer required.

out vec2 v_uv;

void main()
{
    // Standard fullscreen-triangle trick:
    //   id 0 -> (-1, -1)  uv (0, 0)
    //   id 1 -> ( 3, -1)  uv (2, 0)
    //   id 2 -> (-1,  3)  uv (0, 2)
    // The triangle covers the screen and the rasterizer clips to [-1,1].
    vec2 ndc = vec2((gl_VertexID == 1) ? 3.0 : -1.0,
                    (gl_VertexID == 2) ? 3.0 : -1.0);
    v_uv = (ndc + 1.0) * 0.5;
    gl_Position = vec4(ndc, 0.0, 1.0);
}
