#version 410 core
// Built-in 2D effect 'screen_wipe' (shader plan 8.1): a transition over the screen.
// u_progress 0 -> 1 covers it, u_style 0 = diagonal band with a soft edge, 1 = iris
// (a closing circle), 2 = dissolve (noise); u_color is the cover.
in vec2 v_uv;
uniform vec2  u_resolution;
uniform float u_progress;
uniform float u_style;
uniform vec4  u_color;
out vec4 frag_color;

float hash(vec2 p) { return fract(sin(dot(p, vec2(12.9898, 78.233))) * 43758.5453); }

void main() {
    float t = clamp(u_progress, 0.0, 1.0);
    float cover;
    if (u_style < 0.5) {
        float x = (v_uv.x + v_uv.y * 0.35) / 1.35;
        cover = smoothstep(x - 0.04, x + 0.04, t * 1.1 - 0.05);
    } else if (u_style < 1.5) {
        vec2 c = (v_uv - 0.5) * vec2(u_resolution.x / max(u_resolution.y, 1.0), 1.0);
        float r = (1.0 - t) * 1.2;
        cover = smoothstep(r - 0.01, r + 0.01, length(c));
    } else {
        float n = hash(floor(v_uv * u_resolution / 6.0));
        cover = step(n, t);
    }
    frag_color = vec4(u_color.rgb, u_color.a * cover);
}
