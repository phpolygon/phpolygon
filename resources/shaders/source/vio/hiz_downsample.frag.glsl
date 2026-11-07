#version 410 core
// Hi-Z level 0 (shader plan 9.1, occlusion culling): the farthest stored depth of each
// 4x4 block of the scene depth, written as this target's depth. Mip generation with
// VIO_DEPTH_REDUCE_MAX builds the rest of the pyramid. Quarter resolution keeps the
// build cheap; the test stays conservative (a coarser texel only holds a farther
// depth). Both are render targets of the same backend, so gl_FragCoord and texelFetch
// address rows the same way.
// v_uv stays in the signature: without it D3D12 places SV_Position in another
// register than postprocess.vert writes it to (VS / PS linkage error).
in vec2 v_uv;
uniform sampler2D u_depth;

void main() {
    ivec2 last = textureSize(u_depth, 0) - 1;
    ivec2 base = ivec2(gl_FragCoord.xy) * 4;
    float far = 0.0;
    for (int y = 0; y < 4; y++) {
        for (int x = 0; x < 4; x++) {
            far = max(far, texelFetch(u_depth, min(base + ivec2(x, y), last), 0).r);
        }
    }
    if (v_uv.x < -8.0) {
        far = 1.0; // never: keeps v_uv a live input
    }
    gl_FragDepth = far;
}
