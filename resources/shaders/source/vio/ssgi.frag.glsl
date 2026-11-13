#version 410 core

// Screen-space radiosity gather (GraphicsSettings::$screenSpaceGi), half resolution.
//
// One bounce of the direct light between pixels: neighbours within the gather radius that face
// this point send their sun + local light, weighted by both cosines and the distance. Twelve
// samples on a turning spiral, rotated per pixel (interleaved gradient noise): the noise that
// leaves is removed by ssgi_blur.frag, a bilateral blur over normals and depth, before the
// composite adds the result. Each sample is clamped, so one bright texel (a specular
// highlight, a neon tube, the sun disc in a window) cannot become a speckle.
//
// The receiving albedo is NOT applied here (the composite takes it out of the ambient attachment).

in vec2 v_uv;

uniform sampler2D u_gbuffer;   // rg view normal (octahedral), b reflectivity, a linear view depth
uniform sampler2D u_sun;
uniform sampler2D u_local;
uniform vec4  u_ssgi;          // y gather radius (m), z per-sample radiance clamp
uniform float u_proj00;
uniform float u_proj11;
uniform float u_uv_flip_y;
uniform float u_frame_angle;   // turns the pattern per frame when the result is accumulated; 0 = fixed

out vec4 frag_color;

vec3 viewPos(vec2 uv, float d) {
    vec2 ndc = uv * 2.0 - 1.0;
    ndc.y *= u_uv_flip_y;
    return vec3(ndc.x * d / u_proj00, ndc.y * d / u_proj11, -d);
}

vec2 viewUV(vec3 p) {
    vec2 ndc = vec2(p.x * u_proj00, p.y * u_proj11) / max(-p.z, 1e-4);
    ndc.y *= u_uv_flip_y;
    return ndc * 0.5 + 0.5;
}

vec3 decodeViewNormal(vec2 e) {
    vec3 n = vec3(e, 1.0 - abs(e.x) - abs(e.y));
    float t = max(-n.z, 0.0);
    n.x += n.x >= 0.0 ? -t : t;
    n.y += n.y >= 0.0 ? -t : t;
    return normalize(n);
}

void main() {
    vec4 g = textureLod(u_gbuffer, v_uv, 0.0);
    float depth = g.a;
    if (depth <= 0.0 || depth > 80.0) {
        frag_color = vec4(0.0);
        return;
    }
    vec3 P = viewPos(v_uv, depth);
    vec3 N = decodeViewNormal(g.rg);
    float spin = 6.2831853 * fract(52.9829189 * fract(dot(gl_FragCoord.xy, vec2(0.06711056, 0.00583715)))) + u_frame_angle;
    vec3 acc = vec3(0.0);
    const int SAMPLES = 12;
    for (int i = 0; i < SAMPLES; i++) {
        float f = (float(i) + 0.5) / float(SAMPLES);
        float a = float(i) * 2.39996 + spin;
        vec3 offset = vec3(cos(a), sin(a), 0.0) * (u_ssgi.y * sqrt(f));
        vec2 suv = viewUV(P + offset);
        if (suv.x < 0.0 || suv.x > 1.0 || suv.y < 0.0 || suv.y > 1.0) {
            continue;
        }
        vec4 sg = textureLod(u_gbuffer, suv, 0.0);
        if (sg.a <= 0.0) {
            continue;
        }
        vec3 S = viewPos(suv, sg.a);
        vec3 d = S - P;
        float dist = length(d);
        if (dist < 0.02 || dist > u_ssgi.y * 2.0) {
            continue;
        }
        vec3 dir = d / dist;
        float w = max(dot(N, dir), 0.0) * max(dot(decodeViewNormal(sg.rg), -dir), 0.0) / max(dist * dist, 0.09);
        vec3 radiance = textureLod(u_sun, suv, 0.0).rgb + textureLod(u_local, suv, 0.0).rgb;
        acc += min(radiance, vec3(u_ssgi.z)) * w;
    }
    frag_color = vec4(acc * (u_ssgi.y * u_ssgi.y / float(SAMPLES)), 1.0);
}
