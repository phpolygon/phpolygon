// Local reflection probes (PHPolygon\Rendering\Reflection\ReflectionProbes).
// Slot k: u_refl_probe_k (samplerCube), u_refl_probe_min_k (box min, w = 1 bound),
// u_refl_probe_max_k (box max, w = blend in m), u_refl_probe_pos_k (capture point).
// Slot 0 is the probe nearest the camera and lies on top.

// Box projection, horizontal only: where the ray from p along R leaves the box
// sets the compass direction seen from the capture point c (reflected buildings
// stay anchored to the world), while the ray keeps its own elevation. A full
// projection would turn a flat ray that meets a wall below the capture height
// into a downward lookup, and calm water would mirror the lower half of the cube
// instead of the sky near the horizon.
vec3 reflectionProbeBoxDir(vec3 p, vec3 R, vec3 bmin, vec3 bmax, vec3 c) {
    vec3 r = vec3(abs(R.x) < 1e-4 ? 1e-4 : R.x, abs(R.y) < 1e-4 ? 1e-4 : R.y, abs(R.z) < 1e-4 ? 1e-4 : R.z);
    vec3 tFar = max((bmax - p) / r, (bmin - p) / r);
    float t = max(min(min(tFar.x, tFar.y), tFar.z), 0.0);
    vec2 across = (p.xz + R.xz * t) - c.xz;
    float horizontal = length(R.xz);
    if (horizontal < 1e-4) {
        return R;
    }
    float rise = R.y / horizontal * max(length(across), 1e-3);
    return vec3(across.x, rise, across.y);
}

// 1 well inside the box, falling to 0 at its faces over blend metres.
float reflectionProbeWeight(vec3 p, vec4 bmin, vec4 bmax) {
    if (bmin.w < 0.5) {
        return 0.0;
    }
    vec3 d = min(p - bmin.xyz, bmax.xyz - p);
    return clamp(min(min(d.x, d.y), d.z) / max(bmax.w, 1e-3), 0.0, 1.0);
}

vec3 reflectionProbeSample(vec3 worldPos, vec3 R, vec3 fallback) {
    vec3 c = fallback;
    float w1 = reflectionProbeWeight(worldPos, u_refl_probe_min_1, u_refl_probe_max_1);
    if (w1 > 0.0) {
        vec3 d1 = reflectionProbeBoxDir(worldPos, R, u_refl_probe_min_1.xyz, u_refl_probe_max_1.xyz, u_refl_probe_pos_1.xyz);
        c = mix(c, texture(u_refl_probe_1, d1).rgb, w1);
    }
    float w0 = reflectionProbeWeight(worldPos, u_refl_probe_min_0, u_refl_probe_max_0);
    if (w0 > 0.0) {
        vec3 d0 = reflectionProbeBoxDir(worldPos, R, u_refl_probe_min_0.xyz, u_refl_probe_max_0.xyz, u_refl_probe_pos_0.xyz);
        c = mix(c, texture(u_refl_probe_0, d0).rgb, w0);
    }
    return c;
}
