#version 410 core
// Sky element 2/6: sun disc + glow halo + warm horizon scatter band (ADDITIVE).
in vec2 v_ndc;
uniform mat4 u_sky_inv_vp;
uniform vec3 u_sun_direction;
uniform vec3 u_sun_color;
uniform float u_sun_intensity;
uniform float u_sun_size;
uniform float u_sun_glow_size;
uniform float u_sun_glow_intensity;
out vec4 frag_color;

float smoothstep01(float e0, float e1, float x) {
    float t = clamp((x - e0) / (e1 - e0), 0.0, 1.0);
    return t * t * (3.0 - 2.0 * t);
}

void main() {
    vec4 world = u_sky_inv_vp * vec4(v_ndc, 1.0, 1.0);
    vec3 dir = normalize(world.xyz / world.w);
    float elevation = dir.y;

    float cosA = dot(dir, u_sun_direction);
    float angle = acos(clamp(cosA, -1.0, 1.0));

    vec3 add = vec3(0.0);

    // Soft sun disc.
    float disc = 1.0 - smoothstep01(u_sun_size * 0.5, u_sun_size, angle);
    add += u_sun_color * u_sun_intensity * disc;

    // Glow halo.
    if (angle < u_sun_glow_size) {
        float g = 1.0 - angle / u_sun_glow_size;
        g = g * g * u_sun_glow_intensity;
        add += u_sun_color * u_sun_intensity * g;
    }

    // Warm horizon scattering near the sun direction (sunset band).
    if (elevation > -0.05 && elevation < 0.25) {
        float band = max(0.0, 1.0 - abs(elevation - 0.05) / 0.20);
        add += u_sun_color * (max(0.0, cosA) * band * 0.35 * u_sun_intensity);
    }

    // Additive blend (SrcBlend=SRC_ALPHA, DestBlend=ONE): alpha=1 → adds add.rgb.
    frag_color = vec4(add, 1.0);
}
