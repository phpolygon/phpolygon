#version 410 core
// AMD FidelityFX Super Resolution 1 - RCAS (Robust Contrast Adaptive Sharpening).
//
// Port of ffx_fsr1.h (FidelityFX-FSR 1.0) to the engine's post-process GLSL,
// with the noise-removal (FSR_RCAS_DENOISE) path enabled.
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
// Runs on the EASU output at display resolution and is the last pass before the
// swapchain, so it also applies the HDR10 PQ encoding (same transform as
// passthrough_blit.frag.glsl).

in vec2 v_uv;
uniform sampler2D u_source;
uniform vec2  u_size;      // source (= output) size in pixels
uniform float u_sharpness; // in stops: 0 = strongest, 2 = mild (ffx_fsr1.h convention)
uniform int   u_output_pq;
uniform float u_paper_white;
out vec4 frag_color;

// Limit of the negative lobe, 0.25 - 1/16 (ffx_fsr1.h FSR_RCAS_LIMIT).
const float RCAS_LIMIT = 0.1875;

vec3 outputEncode(vec3 c) {
    if (u_output_pq != 1) return c;
    vec3 lin = pow(max(c, vec3(0.0)), vec3(2.2));
    mat3 toBT2020 = mat3(0.6274, 0.0691, 0.0164,
                         0.3293, 0.9195, 0.0880,
                         0.0433, 0.0114, 0.8956);
    vec3 nits = (toBT2020 * lin) * (max(u_paper_white, 1.0) / 10000.0);
    vec3 y = pow(max(nits, vec3(0.0)), vec3(0.1593017578125));
    return pow((0.8359375 + 18.8515625 * y) / (1.0 + 18.6875 * y), vec3(78.84375));
}

vec3 fetchSource(ivec2 p) {
    ivec2 last = ivec2(u_size) - ivec2(1);
    return texelFetch(u_source, clamp(p, ivec2(0), last), 0).rgb;
}

float luma2(vec3 c) {
    return c.g + 0.5 * (c.r + c.b);
}

void main() {
    ivec2 ip = ivec2(v_uv * u_size);
    //    b
    //  d e f
    //    h
    vec3 b = fetchSource(ip + ivec2( 0, -1));
    vec3 d = fetchSource(ip + ivec2(-1,  0));
    vec3 e = fetchSource(ip);
    vec3 f = fetchSource(ip + ivec2( 1,  0));
    vec3 h = fetchSource(ip + ivec2( 0,  1));

    // Noise detection: an isolated pixel is sharpened less.
    float bL = luma2(b);
    float dL = luma2(d);
    float eL = luma2(e);
    float fL = luma2(f);
    float hL = luma2(h);
    float nz = 0.25 * (bL + dL + fL + hL) - eL;
    float range = max(max(max(bL, dL), max(eL, fL)), hL) - min(min(min(bL, dL), min(eL, fL)), hL);
    nz = range > 0.0 ? clamp(abs(nz) / range, 0.0, 1.0) : 0.0;
    nz = 1.0 - 0.5 * nz;

    // The strongest lobe that neither clips the ring's minimum below 0 nor its
    // maximum above 1.
    vec3 mn4 = min(min(b, d), min(f, h));
    vec3 mx4 = max(max(b, d), max(f, h));
    vec3 hitMin = min(mn4, e) / max(4.0 * mx4, vec3(1e-5));
    vec3 hitMax = (vec3(1.0) - max(mx4, e)) / min(4.0 * mn4 - vec3(4.0), vec3(-1e-5));
    vec3 lobeRGB = max(-hitMin, hitMax);
    float lobe = max(-RCAS_LIMIT, min(max(lobeRGB.r, max(lobeRGB.g, lobeRGB.b)), 0.0))
               * pow(2.0, -u_sharpness);
    lobe *= nz;

    vec3 c = (lobe * (b + d + f + h) + e) / (4.0 * lobe + 1.0);
    frag_color = vec4(outputEncode(clamp(c, vec3(0.0), vec3(1.0))), 1.0);
}
