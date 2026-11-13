#version 410 core

// Bilateral blur of the screen-space GI gather (ssgi.frag.glsl), one direction per pass: nine taps
// of a Gaussian, each weighted by how well its depth and normal agree with the centre's, so the
// per-pixel rotation noise is averaged over a surface and never across an edge.

in vec2 v_uv;

uniform sampler2D u_source;     // the gather (rgb), half resolution
uniform sampler2D u_gbuffer;    // rg view normal (octahedral), a linear view depth
uniform vec2 u_step;            // one texel of u_source along the blur direction, in uv

out vec4 frag_color;

vec3 decodeViewNormal(vec2 e) {
    vec3 n = vec3(e, 1.0 - abs(e.x) - abs(e.y));
    float t = max(-n.z, 0.0);
    n.x += n.x >= 0.0 ? -t : t;
    n.y += n.y >= 0.0 ? -t : t;
    return normalize(n);
}

void main() {
    vec4 gc = textureLod(u_gbuffer, v_uv, 0.0);
    if (gc.a <= 0.0) {
        frag_color = vec4(0.0);
        return;
    }
    vec3 nc = decodeViewNormal(gc.rg);
    vec3 sum = textureLod(u_source, v_uv, 0.0).rgb;
    float wSum = 1.0;
    for (int i = -4; i <= 4; i++) {
        if (i == 0) {
            continue;
        }
        vec2 uv = v_uv + u_step * float(i);
        vec4 g = textureLod(u_gbuffer, uv, 0.0);
        if (g.a <= 0.0) {
            continue;
        }
        float dz = abs(g.a - gc.a) / (0.03 + gc.a * 0.02);
        float wn = pow(max(dot(nc, decodeViewNormal(g.rg)), 0.0), 8.0);
        float w = exp(-float(i * i) / 10.0) * exp(-dz * dz) * wn;
        sum += textureLod(u_source, uv, 0.0).rgb * w;
        wSum += w;
    }
    frag_color = vec4(sum / wSum, 1.0);
}
