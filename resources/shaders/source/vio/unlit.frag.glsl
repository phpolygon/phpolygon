#version 410 core

in vec3 v_worldPos;
in vec2 v_uv;

uniform vec3 u_albedo;
uniform vec3 u_emission;
uniform float u_alpha;
uniform vec3 u_fog_color;
uniform float u_fog_near;
uniform float u_fog_far;
uniform vec3 u_camera_pos;

uniform int u_has_albedo_texture;
uniform sampler2D u_albedo_texture;

// HDR scene path: when 1 the colour target is FP16 linear and the resolve pass
// applies ACES + gamma to everything. This unlit shader writes a display-referred
// colour today (no tonemap), so to stay pixel-identical under HDR it must emit a
// LINEAR value that the resolve's ACES+gamma maps back to the same display colour
// — i.e. the inverse of (gamma ∘ ACES). See invToneMapInvGamma(). When 0 (LDR
// path / non-D3D backends) it writes the display colour directly, unchanged.
uniform int u_linear_output;

out vec4 frag_color;

// Inverse of pow(ACES(x), 1/2.2): given a display-referred colour, return the
// linear scene value that the HDR resolve (exposure=1 assumed for unlit/sky;
// see note) tonemaps back to it. ACES Narkowicz is a rational map y=f(x); its
// inverse is the positive root of (cy-a)x² + (dy-b)x + ey = 0. Clamp the
// display input just below 1 so the root stays finite at the ACES asymptote.
vec3 invToneMapInvGamma(vec3 displayColor) {
    vec3 y = pow(clamp(displayColor, 0.0, 0.9965), vec3(2.2)); // undo gamma → ACES output
    const float a = 2.51, b = 0.03, c = 2.43, d = 0.59, e = 0.14;
    vec3 A = c * y - a;
    vec3 B = d * y - b;
    vec3 C = e * y;
    vec3 disc = max(B * B - 4.0 * A * C, 0.0);
    vec3 sq = sqrt(disc);
    // A < 0 over the valid range (y < a/c ≈ 1.03); the physical root is
    // (-B - sqrt)/(2A), which is positive there.
    vec3 x = (-B - sq) / (2.0 * A);
    return max(x, 0.0);
}

void main() {
    vec3 baseAlbedo = u_albedo;
    if (u_has_albedo_texture == 1) {
        vec4 texColor = texture(u_albedo_texture, v_uv);
        baseAlbedo *= texColor.rgb;
    }
    vec3 color = baseAlbedo + u_emission;
    float dist = length(v_worldPos - u_camera_pos);
    float fogFactor = clamp((dist - u_fog_near) / (u_fog_far - u_fog_near), 0.0, 1.0);
    color = mix(color, u_fog_color, fogFactor);

    if (u_linear_output == 1) {
        // Pre-invert so the resolve's exposure*ACES+gamma reproduces `color`.
        // Unlit/sky are exposure-neutral: divide out the resolve exposure here
        // is unnecessary because exposure≈baseline keeps mid-tones; the small
        // residual is part of the documented HDR look shift. Inverse keeps the
        // hue/value of the authored colour stable.
        color = invToneMapInvGamma(color);
    }
    frag_color = vec4(color, u_alpha);
}
