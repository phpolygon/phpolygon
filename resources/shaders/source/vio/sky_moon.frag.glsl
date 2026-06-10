#version 410 core
// Sky element 3/6: moon disc + soft cool glow (ADDITIVE).
in vec2 v_ndc;
uniform mat4 u_sky_inv_vp;
uniform vec3 u_moon_direction;
uniform vec3 u_moon_color;
uniform float u_moon_intensity;
uniform float u_sun_size;       // moon disc sized relative to the sun disc
uniform float u_sun_glow_size;
out vec4 frag_color;

float smoothstep01(float e0, float e1, float x) {
    float t = clamp((x - e0) / (e1 - e0), 0.0, 1.0);
    return t * t * (3.0 - 2.0 * t);
}

void main() {
    vec4 world = u_sky_inv_vp * vec4(v_ndc, 1.0, 1.0);
    vec3 dir = normalize(world.xyz / world.w);

    float angle = acos(clamp(dot(dir, u_moon_direction), -1.0, 1.0));

    vec3 add = vec3(0.0);
    float disc = 1.0 - smoothstep01(u_sun_size * 0.7, u_sun_size * 1.4, angle);
    add += u_moon_color * u_moon_intensity * disc;

    if (angle < u_sun_glow_size * 0.6) {
        float g = 1.0 - angle / (u_sun_glow_size * 0.6);
        g = g * g * 0.35 * u_moon_intensity;
        add += u_moon_color * g;
    }

    frag_color = vec4(add, 1.0);
}
