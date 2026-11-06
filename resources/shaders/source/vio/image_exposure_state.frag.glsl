#version 410 core
// State of the image chain's auto exposure, one FP16 texel, ping-ponged between frames:
//   r = adapted mean log2 luminance
//   g = exposure the finish multiplies the scene with (AutoExposure::exposureFor())
// The adapted value moves towards this frame's metered value exponentially, faster
// towards bright than towards dark; without history (first frame, cut, pinned clock) it
// takes the metered value at once.
in vec2 v_uv;
uniform sampler2D u_mean;      // 1x1, end of the metering
uniform sampler2D u_previous;  // last frame's state
uniform float u_dt;
uniform float u_reset;         // 1 = no usable history
uniform float u_speed_up;
uniform float u_speed_down;
uniform float u_log_reference; // log2 of AutoExposure::$reference
uniform float u_strength;
uniform float u_min_ev;
uniform float u_max_ev;
out vec4 frag_color;

void main() {
    vec2 m = texture(u_mean, vec2(0.5)).rg;
    float metered = m.y > 1e-6 ? m.x / m.y : u_log_reference;
    float adapted = texture(u_previous, vec2(0.5)).r;
    if (u_reset > 0.5) {
        adapted = metered;
    } else {
        float speed = metered > adapted ? u_speed_up : u_speed_down;
        adapted += (metered - adapted) * (1.0 - exp(-max(u_dt, 0.0) * speed));
    }
    float ev = clamp(u_strength * (u_log_reference - adapted), u_min_ev, u_max_ev);
    frag_color = vec4(adapted, exp2(ev), 0.0, 1.0);
}
