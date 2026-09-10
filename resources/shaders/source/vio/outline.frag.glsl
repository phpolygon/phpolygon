#version 410 core

// Flat outline colour (DrawOutline). The colour is display-referred like the
// unlit shader; into a linear FP16 scene target emit the value the ACES+gamma
// resolve maps back to it (same inverse as unlit.frag / skybox.frag).

uniform vec4 u_outline_color;
uniform int u_linear_output;

out vec4 frag_color;

vec3 invToneMapInvGamma(vec3 displayColor) {
    vec3 y = pow(clamp(displayColor, 0.0, 0.9965), vec3(2.2));
    const float a = 2.51, b = 0.03, c = 2.43, d = 0.59, e = 0.14;
    vec3 A = c * y - a;
    vec3 B = d * y - b;
    vec3 C = e * y;
    vec3 sq = sqrt(max(B * B - 4.0 * A * C, 0.0));
    return max((-B - sq) / (2.0 * A), 0.0);
}

void main() {
    vec3 c = u_outline_color.rgb;
    if (u_linear_output == 1) {
        c = invToneMapInvGamma(c);
    }
    frag_color = vec4(c, u_outline_color.a);
}
