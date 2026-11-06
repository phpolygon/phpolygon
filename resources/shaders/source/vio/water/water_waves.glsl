
// ================================================================
//  Water library: waves (PHPolygon\Rendering\Water\WaterShading)
// ================================================================

// Metres of water surface one pixel covers: the larger screen-space step of
// the world position. Fine wave layers and grain fade out below a few pixels.
float waterFootprint(vec3 worldPos) {
    return max(length(dFdx(worldPos.xz)), length(dFdy(worldPos.xz)));
}

// How much of a pattern with `scale` cycles per metre survives at a footprint
// of `footprint` m per pixel: 1 from 8 pixels per cycle, 0 at 4 and below.
float waterLayerFade(float scale, float footprint) {
    return 1.0 - smoothstep(0.125, 0.25, scale * footprint);
}

// Slope (h(p) - h(p + eps), per axis) of one value-noise layer.
vec2 waterNoiseSlope(vec2 p, float eps) {
    float h = noise(p);
    return vec2(h - noise(p + vec2(eps, 0.0)), h - noise(p + vec2(0.0, eps)));
}

// Two detail layers over a broad wave normal, each faded out once its cycles
// get too small on screen (and then not evaluated: far water costs less).
// a / b: x = scale (cycles per metre), y = weight, zw = drift (m/s, in the
// layer's own units). The layers are wrapped every 512 m for noise precision.
vec2 waterDetailSlope(vec2 xz, float t, vec4 a, vec4 b, float footprint) {
    vec2 g = mod(xz, 512.0);
    vec2 slope = vec2(0.0);
    float fa = waterLayerFade(a.x, footprint);
    if (fa > 0.0) {
        slope += waterNoiseSlope(g * a.x + t * a.zw, 0.08) * a.y * fa;
    }
    float fb = waterLayerFade(b.x, footprint);
    if (fb > 0.0) {
        slope += waterNoiseSlope(g * b.x + t * b.zw, 0.08) * b.y * fb;
    }
    return slope;
}

// Whitecaps on open water, 0..1: foam where a slow, broad swell field crests,
// broken up by grain. `amount` 0..1 (wind) widens the caps. Far away, where
// the grain is below a pixel, the caps blur into a faint, even brightening
// instead of sparkling.
float waterCrestFoam(vec2 xz, float t, float amount, float footprint) {
    if (amount <= 0.0) {
        return 0.0;
    }
    vec2 g = mod(xz, 512.0);
    float swell = fbm2(g * 0.045 + t * vec2(0.020, 0.012));
    float crest = smoothstep(1.0 - 0.32 * amount, 1.0 - 0.12 * amount, swell);
    if (crest <= 0.0) {
        return 0.0;
    }
    float grainFade = waterLayerFade(0.9, footprint);
    float grain = grainFade > 0.0 ? noise(g * 0.9 - t * vec2(0.35, 0.2)) : 0.5;
    grain = mix(0.5, grain, grainFade);
    return crest * smoothstep(0.35, 0.75, grain) * mix(0.35, 1.0, grainFade);
}
