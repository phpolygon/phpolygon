#version 410 core
in vec2 v_uv;
uniform sampler2D u_source;
// Additive bloom composited in the same pass (u_bloom_intensity == 0 → off).
uniform sampler2D u_bloom;
uniform float     u_bloom_intensity;
// HDR resolve: when 1 the scene texture holds LINEAR HDR colour (the FP16
// offscreen path) and this pass must add bloom in linear, apply exposure + ACES
// tonemap + gamma BEFORE grade/vignette. When 0 the source is already
// display-referred LDR (legacy direct path / non-D3D backends) — bloom is added
// post-tonemap and no tonemap is applied, so behaviour is byte-for-byte as
// before. See VioRenderer3D::setPostFinishUniforms().
uniform int   u_hdr_resolve;
uniform float u_exposure;
// HDR10 output (u_output_pq == 1): the backbuffer is 10-bit ST 2084. The finished
// display-referred colour (sRGB-encoded 0..1) is linearised, mapped to BT.2020 and
// PQ-encoded with u_paper_white nits as the luminance of display white — the same
// transform php-vio's 2D batch applies to the UI, so both layers match.
uniform int   u_output_pq;
uniform float u_paper_white;
vec3 outputEncode(vec3 c) {
    if (u_output_pq != 1) return c;
    vec3 lin = pow(max(c, vec3(0.0)), vec3(2.2));
    // BT.709 -> BT.2020 (columns: red, green, blue primaries).
    mat3 toBT2020 = mat3(0.6274, 0.0691, 0.0164,
                         0.3293, 0.9195, 0.0880,
                         0.0433, 0.0114, 0.8956);
    vec3 nits = (toBT2020 * lin) * (max(u_paper_white, 1.0) / 10000.0);
    vec3 y = pow(max(nits, vec3(0.0)), vec3(0.1593017578125));
    return pow((0.8359375 + 18.8515625 * y) / (1.0 + 18.6875 * y), vec3(78.84375));
}
// Full-screen finishing (identity when grade is Neutral + vignette 0).
uniform vec3  u_grade_lift;
uniform vec3  u_grade_gamma;
uniform vec3  u_grade_gain;
uniform float u_grade_saturation;
uniform float u_vignette_intensity;
uniform vec2  u_viewport_size;
out vec4 frag_color;

// ACES filmic tonemap (Narkowicz). Must match mesh3d.frag's toneMapACES so the
// HDR resolve reproduces exactly what the inline-tonemap LDR path produced for
// geometry (geometry writes linear under HDR; this pass tonemaps it identically).
vec3 toneMapACES(vec3 x) {
    const float a = 2.51;
    const float b = 0.03;
    const float c = 2.43;
    const float d = 0.59;
    const float e = 0.14;
    return clamp((x * (a * x + b)) / (x * (c * x + d) + e), 0.0, 1.0);
}

vec3 applyColorGrade(vec3 color) {
    color = color + u_grade_lift;
    vec3 gammaSafe = max(u_grade_gamma, vec3(1e-3)); // guard div-by-zero → black
    color = pow(max(color, vec3(0.0)), vec3(1.0) / gammaSafe);
    color = color * u_grade_gain;
    float luma = dot(color, vec3(0.2126, 0.7152, 0.0722));
    return mix(vec3(luma), color, u_grade_saturation);
}

vec3 applyVignette(vec3 color) {
    if (u_vignette_intensity <= 0.0 || u_viewport_size.x <= 0.0) return color;
    vec2 uv = gl_FragCoord.xy / u_viewport_size;
    float v = smoothstep(0.45, 0.85, length(uv - 0.5));
    return color * (1.0 - v * u_vignette_intensity);
}

void main() {
    vec3 c = texture(u_source, v_uv).rgb;
    if (u_hdr_resolve == 1) {
        // HDR: add bloom in linear, expose, tonemap + gamma, then grade.
        if (u_bloom_intensity > 0.0) {
            c += texture(u_bloom, v_uv).rgb * u_bloom_intensity;
        }
        c *= u_exposure;
        c = toneMapACES(c);
        c = pow(c, vec3(1.0 / 2.2));
    } else if (u_bloom_intensity > 0.0) {
        // LDR legacy: bloom is already display-referred, add post-tonemap.
        c += texture(u_bloom, v_uv).rgb * u_bloom_intensity;
    }
    c = outputEncode(applyVignette(applyColorGrade(c)));
    frag_color = vec4(c, 1.0);
}
