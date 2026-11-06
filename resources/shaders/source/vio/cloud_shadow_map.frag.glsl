#version 410 core
// Cloud shadow map (shader plan 3.3): the sun's transmittance through the cloud slab,
// seen from straight above, for a square around the camera. The mesh shader projects a
// fragment along the sun onto y = 0 and reads one texel instead of marching the slab
// itself. Same density field and five-step march as mesh3d.frag's cloud shadow, so the
// patches match the clouds in the sky. r = transmittance (1 = clear).
in vec2 v_uv;
uniform float u_cloud_cover;
uniform float u_cloud_altitude;
uniform float u_cloud_density;
uniform vec2  u_cloud_wind_dir;
uniform float u_cloud_offset;
uniform vec3  u_sun_to;          // toward the sun, normalised
uniform vec4  u_cloud_map;       // centre x, centre z, half size in m, unused
out vec4 frag_color;

float cloudHash13(vec3 p) {
    p = fract(p * 0.1031);
    p += dot(p, p.yzx + 33.33);
    return fract((p.x + p.y) * p.z);
}

float cloudVnoise3(vec3 p) {
    vec3 i = floor(p);
    vec3 f = fract(p);
    f = f * f * (3.0 - 2.0 * f);
    float n000 = cloudHash13(i),               n100 = cloudHash13(i + vec3(1, 0, 0));
    float n010 = cloudHash13(i + vec3(0,1,0)), n110 = cloudHash13(i + vec3(1, 1, 0));
    float n001 = cloudHash13(i + vec3(0,0,1)), n101 = cloudHash13(i + vec3(1, 0, 1));
    float n011 = cloudHash13(i + vec3(0,1,1)), n111 = cloudHash13(i + vec3(1, 1, 1));
    return mix(mix(mix(n000, n100, f.x), mix(n010, n110, f.x), f.y),
               mix(mix(n001, n101, f.x), mix(n011, n111, f.x), f.y), f.z);
}

float cloudFbm3(vec3 p) {
    float total = 0.0, amp = 0.5;
    for (int i = 0; i < 4; i++) { total += cloudVnoise3(p) * amp; p *= 2.03; amp *= 0.5; }
    return total;
}

float cloudDensityAt(vec3 p) {
    float h = (p.y - u_cloud_altitude) / 30.0;   // SLAB_THICK matches the sky pass
    if (h < 0.0 || h > 1.0) return 0.0;
    float vGrad = smoothstep(0.0, 0.18, h) * smoothstep(1.0, 0.55, h);
    vec3 wp = p * 0.0026;
    wp.xz += u_cloud_wind_dir * (u_cloud_offset * 0.0026);
    float n = cloudFbm3(wp);
    float cov = 1.0 - u_cloud_cover * 0.9;
    return smoothstep(cov, cov + 0.22, n) * vGrad * u_cloud_density;
}
void main() {
    vec3 L = u_sun_to;
    if (u_cloud_cover <= 0.0 || L.y <= 0.05) {
        frag_color = vec4(1.0);
        return;
    }
    // The ground point of this texel; a point higher up reads the texel its sun ray
    // passes through y = 0 (the mesh shader shifts it).
    vec2 xz = u_cloud_map.xy + (v_uv * 2.0 - 1.0) * u_cloud_map.z;
    vec3 p = vec3(xz.x, 0.0, xz.y);
    float base = u_cloud_altitude;
    float tEnter = max(base / L.y, 0.0);
    float tExit = (base + 30.0) / L.y;
    float stepLen = (tExit - tEnter) / 5.0;
    float dens = 0.0;
    for (int i = 0; i < 5; i++) {
        dens += cloudDensityAt(p + L * (tEnter + (float(i) + 0.5) * stepLen));
    }
    float t = clamp(exp(-dens * stepLen * 1.2), 0.0, 1.0);
    frag_color = vec4(t, t, t, 1.0);
}