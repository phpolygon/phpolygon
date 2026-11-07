#version 410 core
// Built-in 2D effect 'visor_overlay' (shader plan 8.1): a helmet visor / CRT look over
// the screen - fine scanlines, a slow scan band, a curved vignette and a tinted rim.
// u_strength 0..1 fades it in; reduced motion stills the band (u_motion).
in vec2 v_uv;
uniform vec2  u_resolution;
uniform float u_time;
uniform float u_motion;
uniform float u_strength;
uniform vec4  u_tint;       // rim tint, a = how strong the rim is
out vec4 frag_color;

void main() {
    float s = clamp(u_strength, 0.0, 1.0);
    float line = 0.5 + 0.5 * sin(v_uv.y * u_resolution.y * 3.14159);
    float scan = 0.06 * (1.0 - line);
    float band = exp(-pow((fract(v_uv.y - u_time * 0.07 * u_motion) - 0.5) * 9.0, 2.0)) * 0.05;
    vec2 c = v_uv - 0.5;
    float vig = smoothstep(0.38, 0.75, length(c * vec2(1.0, 1.15)));
    float a = (scan + band) + vig * 0.55;
    vec3 col = mix(vec3(0.0), u_tint.rgb, clamp(u_tint.a, 0.0, 1.0) * vig);
    col += vec3(0.6, 0.9, 1.0) * band;
    frag_color = vec4(col, clamp(a * s, 0.0, 1.0));
}
