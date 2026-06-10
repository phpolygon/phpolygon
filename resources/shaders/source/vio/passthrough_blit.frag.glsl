#version 410 core
in vec2 v_uv;
uniform sampler2D u_source;
// Additive bloom composited in the same pass (u_bloom_intensity == 0 → off).
uniform sampler2D u_bloom;
uniform float     u_bloom_intensity;
// Full-screen finishing (identity when grade is Neutral + vignette 0).
uniform vec3  u_grade_lift;
uniform vec3  u_grade_gamma;
uniform vec3  u_grade_gain;
uniform float u_grade_saturation;
uniform float u_vignette_intensity;
uniform vec2  u_viewport_size;
out vec4 frag_color;

vec3 applyColorGrade(vec3 color) {
    color = color + u_grade_lift;
    vec3 gammaSafe = max(u_grade_gamma, vec3(1e-3));
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
    if (u_bloom_intensity > 0.0) {
        c += texture(u_bloom, v_uv).rgb * u_bloom_intensity;
    }
    c = applyVignette(applyColorGrade(c));
    frag_color = vec4(c, 1.0);
}
