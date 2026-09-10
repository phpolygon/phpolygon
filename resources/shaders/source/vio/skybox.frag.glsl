#version 410 core

in vec3 v_texCoord;

uniform samplerCube u_skybox;
// The cubemap is authored display-referred. Into a linear scene target (FP16
// resolve or the MRT composite) emit the linear value the ACES+gamma resolve
// maps back to it — same inverse as the layered sky (sky_gradient.frag).
uniform int u_linear_output;

#ifdef PHPOLYGON_MRT
// Drawn into the MRT scene target: sky is local (unlit) light in attachment 1; the
// renderer's pipeline masks the sun / ambient / G-buffer attachments off, so the
// G-buffer keeps its cleared 0 = "sky" for the AO and reflection passes.
layout(location = 0) out vec4 o_sun;
layout(location = 1) out vec4 o_local;
layout(location = 2) out vec4 o_ambient;
layout(location = 3) out vec4 o_gbuffer;
#define frag_color o_local
#else
out vec4 frag_color;
#endif

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
    vec4 c = texture(u_skybox, v_texCoord);
    if (u_linear_output == 1) {
        c.rgb = invToneMapInvGamma(c.rgb);
    }
    frag_color = c;
}
