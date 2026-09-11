#version 410 core
// AMD FidelityFX Super Resolution 1 - EASU (Edge Adaptive Spatial Upsampling).
//
// Port of ffx_fsr1.h (FidelityFX-FSR 1.0) to the engine's post-process GLSL.
// Copyright (c) 2021 Advanced Micro Devices, Inc. All rights reserved.
//
// Permission is hereby granted, free of charge, to any person obtaining a copy
// of this software and associated documentation files (the "Software"), to deal
// in the Software without restriction, including without limitation the rights
// to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
// copies of the Software, and to permit persons to whom the Software is
// furnished to do so, subject to the following conditions:
// The above copyright notice and this permission notice shall be included in
// all copies or substantial portions of the Software.
// THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
// IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
// FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
// AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
// LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
// OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
// THE SOFTWARE.
//
// Input: the display-referred (tonemapped, graded) image at render resolution.
// The 12 taps are texelFetch'ed around the source pixel under v_uv, so the pass
// follows the same orientation as every other fullscreen pass. Divisions that
// ffx_fsr1.h leaves to saturate(inf) are guarded explicitly.

in vec2 v_uv;
uniform sampler2D u_source;
uniform vec2 u_input_size; // source image size in pixels
out vec4 frag_color;

vec3 fetchSource(ivec2 p) {
    ivec2 last = ivec2(u_input_size) - ivec2(1);
    return texelFetch(u_source, clamp(p, ivec2(0), last), 0).rgb;
}

// Luma times two (g + (r + b) / 2), as ffx_fsr1.h.
float luma2(vec3 c) {
    return c.g + 0.5 * (c.r + c.b);
}

// Direction and length from the '+' around c: a above, b left, d right, e below.
void easuSet(inout vec2 dir, inout float len, float w,
             float lA, float lB, float lC, float lD, float lE) {
    float lenX = max(abs(lD - lC), abs(lC - lB));
    float dirX = lD - lB;
    dir.x += dirX * w;
    lenX = lenX > 0.0 ? clamp(abs(dirX) / lenX, 0.0, 1.0) : 0.0;
    lenX *= lenX;
    len += lenX * w;

    float lenY = max(abs(lE - lC), abs(lC - lA));
    float dirY = lE - lA;
    dir.y += dirY * w;
    lenY = lenY > 0.0 ? clamp(abs(dirY) / lenY, 0.0, 1.0) : 0.0;
    lenY *= lenY;
    len += lenY * w;
}

// One tap of the approximated, direction-stretched Lanczos 2 window.
void easuTap(inout vec3 aC, inout float aW, vec2 off, vec2 dir, vec2 len,
             float lob, float clp, vec3 c) {
    vec2 v = vec2(dot(off, dir), dot(off, vec2(-dir.y, dir.x)));
    v *= len;
    float d2 = min(dot(v, v), clp);
    float wB = 0.4 * d2 - 1.0;
    float wA = lob * d2 - 1.0;
    wB *= wB;
    wA *= wA;
    wB = 1.5625 * wB - 0.5625;
    float w = wB * wA;
    aC += c * w;
    aW += w;
}

void main() {
    vec2 pp = v_uv * u_input_size - vec2(0.5);
    vec2 fp = floor(pp);
    pp -= fp;
    ivec2 f0 = ivec2(fp);

    //    b c
    //  e f g h
    //  i j k l
    //    n o
    vec3 bC = fetchSource(f0 + ivec2( 0, -1));
    vec3 cC = fetchSource(f0 + ivec2( 1, -1));
    vec3 eC = fetchSource(f0 + ivec2(-1,  0));
    vec3 fC = fetchSource(f0);
    vec3 gC = fetchSource(f0 + ivec2( 1,  0));
    vec3 hC = fetchSource(f0 + ivec2( 2,  0));
    vec3 iC = fetchSource(f0 + ivec2(-1,  1));
    vec3 jC = fetchSource(f0 + ivec2( 0,  1));
    vec3 kC = fetchSource(f0 + ivec2( 1,  1));
    vec3 lC = fetchSource(f0 + ivec2( 2,  1));
    vec3 nC = fetchSource(f0 + ivec2( 0,  2));
    vec3 oC = fetchSource(f0 + ivec2( 1,  2));

    float bL = luma2(bC);
    float cL = luma2(cC);
    float eL = luma2(eC);
    float fL = luma2(fC);
    float gL = luma2(gC);
    float hL = luma2(hC);
    float iL = luma2(iC);
    float jL = luma2(jC);
    float kL = luma2(kC);
    float lL = luma2(lC);
    float nL = luma2(nC);
    float oL = luma2(oC);

    // Bilinear blend of the direction/length estimates of f, g, j and k.
    vec2 dir = vec2(0.0);
    float len = 0.0;
    easuSet(dir, len, (1.0 - pp.x) * (1.0 - pp.y), bL, eL, fL, gL, jL);
    easuSet(dir, len, pp.x * (1.0 - pp.y), cL, fL, gL, hL, kL);
    easuSet(dir, len, (1.0 - pp.x) * pp.y, fL, iL, jL, kL, nL);
    easuSet(dir, len, pp.x * pp.y, gL, jL, kL, lL, oL);

    // Normalise the direction; near zero it becomes the x axis.
    vec2 dir2 = dir * dir;
    float dirR = dir2.x + dir2.y;
    bool zro = dirR < (1.0 / 32768.0);
    dirR = zro ? 1.0 : inversesqrt(dirR);
    dir.x = zro ? 1.0 : dir.x;
    dir *= dirR;

    // {0..2} -> {0..1}, shaped with a square.
    len = len * 0.5;
    len *= len;
    // Stretch the kernel from 1 (axis-aligned) to sqrt(2) on the diagonal.
    float stretch = dot(dir, dir) / max(abs(dir.x), abs(dir.y));
    vec2 len2 = vec2(1.0 + (stretch - 1.0) * len, 1.0 - 0.5 * len);
    // The window moves from +/-sqrt(2) to slightly beyond 2 with the edge amount.
    float lob = 0.5 + ((1.0 / 4.0 - 0.04) - 0.5) * len;
    float clp = 1.0 / lob;

    vec3 aC = vec3(0.0);
    float aW = 0.0;
    easuTap(aC, aW, vec2( 0.0, -1.0) - pp, dir, len2, lob, clp, bC);
    easuTap(aC, aW, vec2( 1.0, -1.0) - pp, dir, len2, lob, clp, cC);
    easuTap(aC, aW, vec2(-1.0,  1.0) - pp, dir, len2, lob, clp, iC);
    easuTap(aC, aW, vec2( 0.0,  1.0) - pp, dir, len2, lob, clp, jC);
    easuTap(aC, aW, vec2( 0.0,  0.0) - pp, dir, len2, lob, clp, fC);
    easuTap(aC, aW, vec2(-1.0,  0.0) - pp, dir, len2, lob, clp, eC);
    easuTap(aC, aW, vec2( 1.0,  1.0) - pp, dir, len2, lob, clp, kC);
    easuTap(aC, aW, vec2( 2.0,  1.0) - pp, dir, len2, lob, clp, lC);
    easuTap(aC, aW, vec2( 2.0,  0.0) - pp, dir, len2, lob, clp, hC);
    easuTap(aC, aW, vec2( 1.0,  0.0) - pp, dir, len2, lob, clp, gC);
    easuTap(aC, aW, vec2( 1.0,  2.0) - pp, dir, len2, lob, clp, oC);
    easuTap(aC, aW, vec2( 0.0,  2.0) - pp, dir, len2, lob, clp, nC);

    // Normalise and de-ring against the four nearest source pixels.
    vec3 min4 = min(min(fC, gC), min(jC, kC));
    vec3 max4 = max(max(fC, gC), max(jC, kC));
    float safeW = abs(aW) < 1e-5 ? 1e-5 : aW;
    frag_color = vec4(min(max4, max(min4, aC / safeW)), 1.0);
}
