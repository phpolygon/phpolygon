#version 410 core
in vec2 v_uv;
uniform sampler2D u_scene;
uniform float u_threshold;
out vec4 frag_color;
void main() {
    vec3 c = texture(u_scene, v_uv).rgb;
    // Bound the extracted energy. The inverse-tonemapped sky/clouds emit very
    // large LINEAR values near display-white (a near-white cloud reaches linear
    // ~4-6), which would otherwise dump huge unbounded energy into the blurred
    // bloom buffer and wash the whole upper frame. Cap each channel at a ceiling
    // that still sits well above genuine highlights (the plaza lantern peaks at
    // linear ~3.6, the sun disc higher) so real glints/sun keep glowing, but a
    // bright overcast sheet can't blow out. Cap before the threshold test so the
    // weight is computed on the clamped value too.
    c = min(c, vec3(6.0));
    float brightness = dot(c, vec3(0.2126, 0.7152, 0.0722));
    frag_color = vec4(c * smoothstep(u_threshold, u_threshold + 0.5, brightness), 1.0);
}
