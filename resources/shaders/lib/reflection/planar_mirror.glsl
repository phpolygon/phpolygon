// Planar mirror (PHPolygon\Rendering\Reflection\PlanarMirrors).
// u_mirror_texture: the scene mirrored at the mirror rectangle, alpha 1 where geometry
// was drawn, 0 where the mirror shows nothing. u_mirror_viewproj is the view-projection
// it was drawn with, so the lookup projects the fragment's world position and stays
// right while the texture is a few frames old. u_mirror_info: x = 1 while an image is
// bound, y = 1 when the target's v runs the other way, zw = blur radius in uv.
vec4 mirrorFetch(vec2 uv) {
    return textureLod(u_mirror_texture, clamp(uv, vec2(0.001), vec2(0.999)), 0.0);
}

vec3 mirrorSample(vec3 worldPos, vec3 N, float blurScale, vec3 fallback) {
    if (u_mirror_info.x < 0.5) {
        return fallback;
    }
    vec4 clip = u_mirror_viewproj * vec4(worldPos, 1.0);
    // The mirror image of a point in front of the mirror lies beyond the mirror, in front
    // of the mirrored camera: w > 0. Elsewhere (behind it) there is nothing to show.
    float w = max(clip.w, 1e-4);
    vec2 uv = clip.xy / w * 0.5 + 0.5;
    if (u_mirror_info.y > 0.5) {
        uv.y = 1.0 - uv.y;
    }
    vec4 centre = mirrorFetch(uv);
    vec4 acc = vec4(centre.rgb * centre.a, centre.a);
    float count = 1.0;
    vec2 radius = u_mirror_info.zw * blurScale;
    if (radius.x + radius.y > 1e-6) {
        for (int i = 0; i < 8; i++) {
            float a = 2.39996323 * float(i);
            float r = sqrt((float(i) + 0.5) / 8.0);
            vec4 s = mirrorFetch(uv + vec2(cos(a), sin(a)) * r * radius);
            acc += vec4(s.rgb * s.a, s.a);
            count += 1.0;
        }
    }
    float coverage = clamp(acc.a / count, 0.0, 1.0);
    vec3 rgb = acc.rgb / max(acc.a, 1e-4);
    return mix(fallback, rgb, coverage);
}
