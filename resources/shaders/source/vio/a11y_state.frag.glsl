#version 410 core
// State of the accessibility pass, one FP16 texel, ping-ponged between frames:
//   r = gain the apply step multiplies the image with (1 without the flash limit)
//   g = mean linear luminance of this frame's input
//   b = mean luminance shown after the gain; the next frame may move it by at most
//       u_rate * u_dt (relative luminance per second)
in vec2 v_uv;
uniform sampler2D u_mean;      // 1x1, end of the reduction
uniform sampler2D u_previous;  // last frame's state
uniform float u_dt;
uniform float u_rate;
uniform float u_gain_max;
uniform float u_reset;         // 1 = no usable history: show the frame as it is
uniform float u_limit;         // 1 = flash limit on
out vec4 frag_color;

void main() {
    float m = max(texture(u_mean, vec2(0.5)).r, 0.0);
    float shown = texture(u_previous, vec2(0.5)).b;
    if (u_reset > 0.5 || u_limit < 0.5) {
        shown = m;
    }
    float step = u_rate * u_dt;
    float target = clamp(m, shown - step, shown + step);
    // A black frame cannot be lifted to what was shown before: the gain stops at
    // u_gain_max, so a cut to black still darkens fast; brightening is held to the rate.
    float gain = m > 1e-5 ? clamp(target / m, 0.0, u_gain_max) : 1.0;
    frag_color = vec4(gain, m, gain * m, 1.0);
}
