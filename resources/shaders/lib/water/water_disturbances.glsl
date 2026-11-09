
// ================================================================
//  Water library: wakes and rings (PHPolygon\Rendering\Water\WaterShading)
//  System\WaterDisturbanceSystem uploads them each frame:
//   u_water_wake_0..3   16 trail points, columns (x, z, age s, strength);
//                       a negative strength starts a new trail, 0 = unused.
//   u_water_ripple_0..1 8 rings, columns (x, z, age s, strength); 0 = unused.
// ================================================================

// One trail segment from the newer point a to the older point b, `s0` metres
// behind the hull at a. look: x = half width of the churned lane at the hull,
// y = tangent of the arm angle (0.354 = Kelvin's 19.5 degrees), z = life (s),
// w = how fast an arm widens per metre behind the hull. Accumulates foam (max)
// and the slope of the arm ridges.
void waterWakeSegment(vec2 xz, vec4 a, vec4 b, float s0, vec4 look, inout float foam, inout vec2 slope) {
    vec2 ab = b.xy - a.xy;
    float len2 = dot(ab, ab);
    float u = len2 > 1.0e-6 ? clamp(dot(xz - a.xy, ab) / len2, 0.0, 1.0) : 0.0;
    vec2 c = a.xy + ab * u;
    vec2 off = xz - c;
    float lat = length(off);
    float s = s0 + sqrt(len2) * u;
    float age = mix(a.z, b.z, u);
    float strength = mix(abs(a.w), abs(b.w), u);
    float fade = 1.0 - smoothstep(0.35 * look.z, look.z, age);
    if (fade <= 0.0 || strength <= 0.0) {
        return;
    }
    float armLat = look.x * 0.8 + look.y * s;
    float armW = 0.5 + look.w * s;
    if (lat > armLat + 3.0 * armW && lat > 3.0 * (look.x + 0.06 * s)) {
        return;
    }
    // Churned lane behind the stern: widest and whitest close to the hull.
    float laneW = look.x + 0.06 * s;
    float lane = exp(-(lat * lat) / (laneW * laneW)) * (1.0 - smoothstep(0.0, 0.55 * look.z, age));
    // The two arms, broken up along their length.
    float q = (lat - armLat) / armW;
    float arm = exp(-q * q) * (0.55 + 0.45 * noise(vec2(s * 0.35, lat * 0.6)));
    foam = max(foam, (lane * 0.7 + arm * 0.5) * fade * strength);
    if (lat > 1.0e-4) {
        slope += (off / lat) * (-q * exp(-q * q)) * 0.35 * fade * strength;
    }
}

void waterWakeColumn(vec2 xz, vec4 p, inout vec4 prev, inout float s, vec4 look, inout float foam, inout vec2 slope) {
    if (p.w == 0.0) {
        prev = vec4(0.0);
        return;
    }
    if (p.w > 0.0 && prev.w != 0.0) {
        waterWakeSegment(xz, prev, p, s, look, foam, slope);
        s += distance(prev.xy, p.xy);
    } else {
        s = 0.0;
    }
    prev = p;
}

// x = foam 0..1, yz = slope to add to the surface normal (x, z).
vec3 waterWake(vec2 xz, vec4 look) {
    float foam = 0.0;
    vec2 slope = vec2(0.0);
    vec4 prev = vec4(0.0);
    float s = 0.0;
    for (int c = 0; c < 4; c++) { waterWakeColumn(xz, u_water_wake_0[c], prev, s, look, foam, slope); }
    for (int c = 0; c < 4; c++) { waterWakeColumn(xz, u_water_wake_1[c], prev, s, look, foam, slope); }
    for (int c = 0; c < 4; c++) { waterWakeColumn(xz, u_water_wake_2[c], prev, s, look, foam, slope); }
    for (int c = 0; c < 4; c++) { waterWakeColumn(xz, u_water_wake_3[c], prev, s, look, foam, slope); }
    return vec3(clamp(foam, 0.0, 1.0), slope);
}

void waterRing(vec2 xz, vec4 r, vec4 look, inout float foam, inout vec2 slope) {
    if (r.w <= 0.0) {
        return;
    }
    vec2 off = xz - r.xy;
    float dist = length(off);
    float front = look.x * r.z;
    float delta = dist - front;
    // A short wave train behind the front, nothing ahead of it.
    if (delta > look.z || delta < -3.0 * look.w || dist < 1.0e-4) {
        return;
    }
    float life = 1.0 - clamp(r.z / look.y, 0.0, 1.0);
    float amp = r.w * life * life / (1.0 + dist * 0.6);
    float train = exp(-max(-delta, 0.0) / look.w) * (1.0 - smoothstep(0.0, look.z, delta));
    float wave = cos(delta / look.w * 6.2831853);
    slope += (off / dist) * wave * train * amp * 0.5;
    foam = max(foam, smoothstep(0.6, 1.0, wave) * train * amp * 0.5);
}

// Rings spreading from swimmers and splashes. look: x = speed (m/s), y = life
// (s), z = width of the leading crest (m), w = wavelength behind it (m).
// x = foam 0..1, yz = slope to add to the surface normal (x, z).
vec3 waterRipples(vec2 xz, vec4 look) {
    float foam = 0.0;
    vec2 slope = vec2(0.0);
    for (int c = 0; c < 4; c++) { waterRing(xz, u_water_ripple_0[c], look, foam, slope); }
    for (int c = 0; c < 4; c++) { waterRing(xz, u_water_ripple_1[c], look, foam, slope); }
    return vec3(clamp(foam, 0.0, 1.0), slope);
}
