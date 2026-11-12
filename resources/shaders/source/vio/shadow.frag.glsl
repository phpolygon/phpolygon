#version 410 core

in vec3 v_shadow_world;
#ifdef PHPOLYGON_SHADOW_CUTOUT
in vec2 v_shadow_uv;
uniform sampler2D u_albedo_texture;
uniform float u_alpha_cutoff;
#endif

// Dappled shadow (shader plan 6.1, Material::$shadowDapple): fragments of the caster
// drop out in leaf-cluster-sized gaps that drift with the wind, so its shadow lets
// spots of sun through. 0 = solid (the common case: the branch is uniform per draw).
uniform float u_shadow_dapple;
// Own names: shadow.vert declares u_time / u_wind_direction for the foliage sway, and
// a name declared in both stages reaches only the vertex stage on D3D / Vulkan / Metal.
uniform float u_frag_time;
uniform vec3  u_frag_wind; // xz direction (normalised), intensity

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
        vec3 wind = vec3(u_frag_wind.x, 0.0, u_frag_wind.y);
        float drift = u_frag_time * (0.05 + 0.25 * clamp(u_frag_wind.z, 0.0, 2.0));
        vec3 p = v_shadow_world / 0.45 + wind * drift;
        float n = dappleNoise(p) * 0.65 + dappleNoise(p * 2.3 + 7.1) * 0.35;
        // n centres on 0.5: map the dapple amount onto the threshold so 0.5 opens
        // roughly half of the crown.
        if (n < 0.28 + 0.44 * u_shadow_dapple) {
            discard;
        }
    }
#ifdef PHPOLYGON_SHADOW_CUTOUT
    // Cut-out casters (Material::$alphaCutoff, hair cards): the same albedo-alpha test as the lit pass.
    if (texture(u_albedo_texture, v_shadow_uv).a < u_alpha_cutoff) {
        discard;
    }
#endif
    frag_color = vec4(gl_FragCoord.z, gl_FragCoord.z, gl_FragCoord.z, 1.0);
}
