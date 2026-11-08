#version 410 core

in vec3 v_shadow_world;

// Dappled shadow (shader plan 6.1, Material::$shadowDapple): fragments of the caster
// drop out in leaf-cluster-sized gaps that drift with the wind, so its shadow lets
// spots of sun through. 0 = solid (the common case: the branch is uniform per draw).
uniform float u_shadow_dapple;
uniform float u_time;
uniform vec3  u_wind_direction;
uniform float u_wind_intensity;

out vec4 frag_color;

// Sine-free hash (Hoskins): the same bits on every driver.
float dappleHash(vec3 p) {
    p = fract(p * 0.1031);
    p += dot(p, p.zyx + 31.32);
    return fract((p.x + p.y) * p.z);
}

float dappleNoise(vec3 p) {
    vec3 i = floor(p);
    vec3 f = fract(p);
    f = f * f * (3.0 - 2.0 * f);
    float a = mix(dappleHash(i), dappleHash(i + vec3(1, 0, 0)), f.x);
    float b = mix(dappleHash(i + vec3(0, 1, 0)), dappleHash(i + vec3(1, 1, 0)), f.x);
    float c = mix(dappleHash(i + vec3(0, 0, 1)), dappleHash(i + vec3(1, 0, 1)), f.x);
    float d = mix(dappleHash(i + vec3(0, 1, 1)), dappleHash(i + vec3(1, 1, 1)), f.x);
    return mix(mix(a, b, f.y), mix(c, d, f.y), f.z);
}

void main() {
    if (u_shadow_dapple > 0.0) {
        vec3 wind = vec3(u_wind_direction.x, 0.0, u_wind_direction.z);
        float drift = u_time * (0.05 + 0.25 * clamp(u_wind_intensity, 0.0, 2.0));
        vec3 p = v_shadow_world / 0.45 + wind * drift;
        float n = dappleNoise(p) * 0.65 + dappleNoise(p * 2.3 + 7.1) * 0.35;
        // n centres on 0.5: map the dapple amount onto the threshold so 0.5 opens
        // roughly half of the crown.
        if (n < 0.28 + 0.44 * u_shadow_dapple) {
            discard;
        }
    }
    frag_color = vec4(gl_FragCoord.z, gl_FragCoord.z, gl_FragCoord.z, 1.0);
}
