#version 410 core
// Temporal SSAO (shader plan 9.1, GraphicsSettings::$temporalSsao): the blurred AO of
// this frame (its noise rotated by a per-frame angle) blended into the history while
// the camera holds still, so the rotation noise averages out over frames. u_blend =
// how much of this frame goes in (1 after the camera moved: no history).
in vec2 v_uv;
uniform sampler2D u_current;
uniform sampler2D u_history;
uniform float u_blend;
out vec4 frag_color;

void main() {
    float cur = texture(u_current, v_uv).r;
    float hist = texture(u_history, v_uv).r;
    frag_color = vec4(mix(hist, cur, clamp(u_blend, 0.0, 1.0)), 0.0, 0.0, 1.0);
}
