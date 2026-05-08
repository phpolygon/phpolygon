#version 450

// Fullscreen-triangle FXAA vertex shader (Vulkan / SPIR-V variant).
// Drawn with vkCmdDraw(commandBuffer, 3, 1, 0, 0) and gl_VertexIndex -> NDC corners.
// No vertex buffer / vertex input required.

layout(location = 0) out vec2 v_uv;

void main()
{
    // Standard fullscreen-triangle trick:
    //   id 0 -> (-1, -1)  uv (0, 0)
    //   id 1 -> ( 3, -1)  uv (2, 0)
    //   id 2 -> (-1,  3)  uv (0, 2)
    // The triangle covers the screen and the rasterizer clips to [-1,1].
    vec2 ndc = vec2((gl_VertexIndex == 1) ? 3.0 : -1.0,
                    (gl_VertexIndex == 2) ? 3.0 : -1.0);
    // Vulkan UV origin is top-left in framebuffer space; the resolved
    // off-screen image is laid out top-down so we flip Y when computing
    // the sample UV to match GL's bottom-up convention used by FXAA.
    v_uv = vec2((ndc.x + 1.0) * 0.5, (ndc.y + 1.0) * 0.5);
    gl_Position = vec4(ndc, 0.0, 1.0);
}
