
// ================================================================
//  Water library: coast field (PHPolygon\Rendering\Water\WaterShading)
//  u_water_coast holds a Terrain\CoastTexture: R = signed shoreline
//  distance, G = water depth, B = coverage. u_water_coast_map maps
//  world xz to its uv, u_water_coast_range decodes R and G.
// ================================================================

// x = signed distance to the shoreline in metres (> 0 open water, < 0 land),
// y = water depth in metres, z = coverage (0 = no coast raster speaks here:
// far, deep water). Without a coast set: open water 10 km from any shore.
vec3 waterCoast(vec2 xz) {
    vec4 range = u_water_coast_range;
    if (range.y <= 0.0) {
        return vec3(1.0e4, 1.0e4, 0.0);
    }
    vec2 uv = clamp((xz - u_water_coast_map.xy) * u_water_coast_map.zw, 0.0, 1.0);
    vec3 t = textureLod(u_water_coast, uv, 0.0).rgb;
    float d = t.r * (range.x + range.y) - range.x;
    return vec3(mix(range.y, d, t.b), mix(range.z, t.g * range.z, t.b), t.b);
}

// Wet band on land above a shoreline, 0..1: 1 at the water's edge, 0 beyond
// `reach` metres inland. `runup` metres move the edge with the swash: it climbs
// and falls back once per `period` seconds, offset along the coast so the line
// is not one straight front (runup 0 = a still band). `landDistance` is the
// distance inland (the coast field's distance, negated).
float waterShoreWetness(vec2 xz, float landDistance, float t, float reach, float runup, float period) {
    if (landDistance > reach + runup) {
        return 0.0;
    }
    float swash = 0.0;
    if (runup > 0.0 && period > 0.0) {
        float phase = t / period + noise(mod(xz, 512.0) * 0.06) * 2.0;
        // A quick climb, a slower retreat.
        float f = fract(phase);
        swash = f < 0.3 ? smoothstep(0.0, 0.3, f) : 1.0 - smoothstep(0.3, 1.0, f);
    }
    float edge = reach + runup * swash;
    float jitter = (noise(mod(xz, 512.0) * 0.9) - 0.5) * 0.25 * reach;
    return 1.0 - smoothstep(edge * 0.35, edge, landDistance + jitter);
}

// Foam along a shoreline, 0..1, from the signed distance d (metres, > 0 water):
// a broken line at the water's edge plus bands that run shoreward and break.
// look: x = width of the edge line, y = spacing of the bands, z = their speed
// (m/s), w = how far out the bands reach (metres). Grain is wrapped every
// 256 m so value noise keeps its precision far from the origin.
float waterShoreFoam(vec2 xz, float d, float t, vec4 look) {
    if (d > max(look.x, look.w)) {
        return 0.0;
    }
    vec2 g = mod(xz, 256.0);
    float grain = noise(g * 15.0 + t * 0.3) * 0.5
                + noise(g * 30.0 - t * 0.5) * 0.3
                + noise(g * 60.0 + t * 0.8) * 0.2;
    float line = (1.0 - smoothstep(0.0, look.x, max(d, 0.0))) * smoothstep(0.25, 0.55, grain);
    // Bands: a constant phase moves toward smaller d as t grows.
    float phase = fract((d + t * look.z) / look.y);
    float band = smoothstep(0.78, 0.92, phase) * (1.0 - smoothstep(0.92, 1.0, phase));
    float breakUp = smoothstep(0.35, 0.7, noise(vec2(g.x * 0.35 + g.y * 0.2, d * 0.15 - t * 0.2)));
    float bands = band * breakUp * (1.0 - smoothstep(0.0, look.w, d)) * smoothstep(0.2, 0.5, grain);
    return clamp(max(line, bands), 0.0, 1.0);
}
