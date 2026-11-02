#version 410 core
// Accessibility pass (AccessibilitySettings, VioAccessibilityPass): the last step on
// the finished image, after the Final-stage passes and before the HDR10 encoding and
// the UI. Input and output carry the engine's 2.2 power encoding; colour work runs in
// linear light. Every option is neutral at its default value.
in vec2 v_uv;
uniform sampler2D u_source;
uniform float u_cvd_mode;      // ColorVisionMode::code(): 0 off, 1 protan, 2 deutan, 3 tritan
uniform float u_cvd_strength;  // 0..1
uniform float u_cvd_simulate;  // 1 = show what the viewer sees instead of correcting
out vec4 frag_color;

// Machado, Oliveira & Fernandes 2009, severity 1.0, rows of the linear-RGB matrix
// (same numbers as Accessibility\ColorVision::SIMULATION).
vec3 cvdSimulate(vec3 c) {
    if (u_cvd_mode < 1.5) {
        return vec3(dot(vec3(0.152286, 1.052583, -0.204868), c),
                    dot(vec3(0.114503, 0.786281, 0.099216), c),
                    dot(vec3(-0.003882, -0.048116, 1.051998), c));
    }
    if (u_cvd_mode < 2.5) {
        return vec3(dot(vec3(0.367322, 0.860646, -0.227968), c),
                    dot(vec3(0.280085, 0.672501, 0.047413), c),
                    dot(vec3(-0.011820, 0.042940, 0.968881), c));
    }
    return vec3(dot(vec3(1.255528, -0.076749, -0.178779), c),
                dot(vec3(-0.078411, 0.930809, 0.147602), c),
                dot(vec3(0.004733, 0.691367, 0.303900), c));
}

// Daltonisation: the difference the viewer cannot see goes into channels they can
// (protan / deutan: into green and blue, tritan: into red and green).
vec3 colourVision(vec3 lin) {
    if (u_cvd_mode < 0.5) {
        return lin;
    }
    vec3 sim = cvdSimulate(lin);
    if (u_cvd_simulate > 0.5) {
        return clamp(mix(lin, sim, u_cvd_strength), 0.0, 1.0);
    }
    vec3 e = lin - sim;
    vec3 shift = u_cvd_mode < 2.5
        ? vec3(0.0, 0.7 * e.r + e.g, 0.7 * e.r + e.b)
        : vec3(e.r + 0.7 * e.b, e.g + 0.7 * e.b, 0.0);
    return clamp(lin + shift * u_cvd_strength, 0.0, 1.0);
}

void main() {
    vec3 c = texture(u_source, v_uv).rgb;
    vec3 lin = pow(max(c, vec3(0.0)), vec3(2.2));
    lin = colourVision(lin);
    frag_color = vec4(pow(clamp(lin, 0.0, 1.0), vec3(1.0 / 2.2)), 1.0);
}
