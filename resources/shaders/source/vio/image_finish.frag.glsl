#version 410 core
// Finishing draw of the image chain (ImageLook): scene colour + bloom -> exposure ->
// tonemap -> encode -> LUT -> saturation pulse -> grain -> deband, into an RGBA8 image the
// present takes as display-referred colour (it adds the player's grade and vignette, then
// FXAA / FSR 1 / the temporal present). Chromatic aberration moves the scene taps of red and
// blue. On an LDR scene target the source is already tonemapped and encoded: bloom is added
// to it, exposure and tonemap are skipped, the rest runs the same.
in vec2 v_uv;
uniform sampler2D u_source;          // scene colour (linear HDR when u_hdr = 1)
uniform sampler2D u_bloom;           // finest bloom level
uniform sampler2D u_exposure_state;  // 1x1: g = auto exposure
uniform sampler3D u_lut_a;
uniform sampler3D u_lut_b;

uniform float u_hdr;
uniform float u_bloom_intensity;     // includes the 1 / sum(scatter^i) of the chain; 0 = no bloom
uniform float u_exposure;            // 2^exposureEv
uniform float u_auto_exposure;       // 1 = multiply by the state
uniform float u_tonemapper;          // Tonemapper::code(): 0 ACES, 1 sigmoid
uniform float u_lut_strength;        // 0 = no table
uniform float u_lut_blend;           // weight of u_lut_b over u_lut_a
uniform vec2  u_lut_a_map;           // (N-1)/N, 0.5/N of table a
uniform vec2  u_lut_b_map;
uniform float u_saturation_boost;
uniform float u_grain;
uniform float u_deband;              // in 8-bit steps
uniform float u_ca;                  // offset at the image edge, fraction of the width
uniform float u_night_vision;        // 0..1 Purkinje shift of the dark parts (HDR only)
uniform float u_time;
out vec4 frag_color;

const vec3 LUMA = vec3(0.2126, 0.7152, 0.0722);

// ACES filmic (Narkowicz), the curve of mesh3d.frag and passthrough_blit.
vec3 toneMapACES(vec3 x) {
    const float a = 2.51;
    const float b = 0.03;
    const float c = 2.43;
    const float d = 0.59;
    const float e = 0.14;
    return clamp((x * (a * x + b)) / (x * (c * x + d) + e), 0.0, 1.0);
}

// Log-encoded sigmoid (Tonemapper::Sigmoid). Constants are this engine's own: 16.5 stops
// from 2^-10 to 2^6.5, mid grey 0.18 lands on 0.548 encoded (where ACES puts it), slope 3
// at mid grey (ACES's slope there in encoded units per stop, 0.18), 12 % gamut inset before
// the curve, given back after it. Returns the encoded colour.
const float SIG_MIN_EV = -10.0;
const float SIG_RANGE = 16.5;
const float SIG_SLOPE = 3.0;
const float SIG_PIVOT = 0.456072;   // (log2(0.18) + 10) / 16.5
const float SIG_GREY = 0.548;
const float SIG_INSET = 0.12;
vec3 toneMapSigmoid(vec3 c) {
    float l = dot(max(c, vec3(0.0)), LUMA);
    c = mix(vec3(l), max(c, vec3(0.0)), 1.0 - SIG_INSET);
    vec3 x = clamp((log2(max(c, vec3(1e-10))) - SIG_MIN_EV) / SIG_RANGE, 0.0, 1.0);
    float k = pow(SIG_PIVOT, SIG_SLOPE) * (1.0 - SIG_GREY) / (pow(1.0 - SIG_PIVOT, SIG_SLOPE) * SIG_GREY);
    vec3 xa = pow(x, vec3(SIG_SLOPE));
    vec3 y = xa / max(xa + k * pow(1.0 - x, vec3(SIG_SLOPE)), vec3(1e-10));
    vec3 lin = pow(y, vec3(2.2));
    float ly = dot(lin, LUMA);
    lin = max(mix(vec3(ly), lin, 1.0 / (1.0 - SIG_INSET)), vec3(0.0));
    return clamp(pow(lin, vec3(1.0 / 2.2)), 0.0, 1.0);
}

float hash(vec2 p) {
    vec3 p3 = fract(vec3(p.xyx) * 0.1031);
    p3 += dot(p3, p3.yzx + 33.33);
    return fract((p3.x + p3.y) * p3.z);
}

vec3 lut(sampler3D table, vec2 map, vec3 c) {
    return texture(table, clamp(c, 0.0, 1.0) * map.x + map.y).rgb;
}

void main() {
    vec3 c;
    if (u_ca > 0.0) {
        vec2 off = (v_uv - 0.5) * 2.0 * u_ca;
        c = vec3(texture(u_source, v_uv - off).r, texture(u_source, v_uv).g, texture(u_source, v_uv + off).b);
    } else {
        c = texture(u_source, v_uv).rgb;
    }
    if (u_bloom_intensity > 0.0) {
        c += texture(u_bloom, v_uv).rgb * u_bloom_intensity;
    }
    if (u_hdr > 0.5) {
        float exposure = u_exposure;
        if (u_auto_exposure > 0.5) {
            exposure *= texture(u_exposure_state, vec2(0.5)).g;
        }
        c *= exposure;
        if (u_night_vision > 0.0) {
            // Rods take over below ~0.2 of mid grey: less colour, shifted to blue.
            float nl = dot(max(c, vec3(0.0)), LUMA);
            float nw = (1.0 - smoothstep(0.004, 0.08, nl)) * u_night_vision;
            c = mix(c, vec3(nl) * vec3(0.62, 0.80, 1.25), nw);
        }
        c = u_tonemapper > 0.5 ? toneMapSigmoid(c) : pow(toneMapACES(c), vec3(1.0 / 2.2));
    } else {
        c = clamp(c, 0.0, 1.0);
    }

    if (u_lut_strength > 0.0) {
        vec3 graded = lut(u_lut_a, u_lut_a_map, c);
        if (u_lut_blend > 0.0) {
            graded = mix(graded, lut(u_lut_b, u_lut_b_map, c), u_lut_blend);
        }
        c = mix(c, graded, u_lut_strength);
    }
    if (u_saturation_boost > 0.0) {
        vec3 lin = pow(max(c, vec3(0.0)), vec3(2.2));
        float l = dot(lin, LUMA);
        c = pow(max(mix(vec3(l), lin, 1.0 + u_saturation_boost), vec3(0.0)), vec3(1.0 / 2.2));
    }
    if (u_grain > 0.0) {
        // Film grain: strongest in the mids, a new pattern 24 times a second.
        float frame = floor(u_time * 24.0);
        float n = hash(gl_FragCoord.xy + frame * vec2(37.0, 17.0)) - 0.5;
        float l = dot(c, LUMA);
        c += n * u_grain * 4.0 * l * (1.0 - l);
    }
    if (u_deband > 0.0) {
        // Triangular dither of +-1 step: hides the 8-bit bands of smooth gradients.
        float d = hash(gl_FragCoord.xy) + hash(gl_FragCoord.yx + 19.19) - 1.0;
        c += d * (u_deband / 255.0);
    }
    frag_color = vec4(clamp(c, 0.0, 1.0), 1.0);
}
