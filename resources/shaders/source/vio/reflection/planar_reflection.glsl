// Planar reflection (PHPolygon\Rendering\Reflection\PlanarReflection).
// u_planar_reflection: the opaque scene mirrored at y = u_planar_info.x, drawn this
// frame at a fraction of the scene size; alpha 1 where geometry was drawn, 0 where
// the mirror shows sky. u_planar_info.y = 1 while it is bound.
vec3 planarReflectionSample(vec3 N, float distortion, vec3 fallback) {
    if (u_planar_info.y < 0.5) {
        return fallback;
    }
    // A mirror at the surface point reflects to the same screen position: the
    // fragment's own pixel, pushed sideways by the waves.
    vec2 uv = gl_FragCoord.xy / u_viewport_size + N.xz * distortion;
    vec4 m = texture(u_planar_reflection, clamp(uv, vec2(0.001), vec2(0.999)));
    return mix(fallback, m.rgb, clamp(m.a, 0.0, 1.0));
}
