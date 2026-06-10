#version 410 core
// Sky element 5/6: VOLUMETRIC clouds (ALPHA blend over the sky).
// Raymarches a horizontal cloud slab [u_cloud_altitude, +SLAB_THICK]: a 3D-fbm
// density field shaped by coverage + a vertical rounding falloff, lit by a short
// light-march toward the sun (self-shadowing) with Beer-Lambert + powder. Soft,
// fluffy, edge-free — unlike the old flat-plane projection. Perf-tunable via
// STEPS / LIGHT_STEPS / fbm octaves; empty space + low transmittance early-out.
in vec2 v_ndc;
uniform mat4 u_sky_inv_vp;
uniform vec3 u_camera_pos;
uniform vec3 u_sun_direction;   // toward the sun
uniform vec3 u_sun_color;
uniform float u_sun_intensity;
uniform float u_cloud_cover;
uniform float u_cloud_altitude;  // slab base Y
uniform float u_cloud_density;
uniform float u_cloud_wind_speed;
uniform vec2  u_cloud_wind_dir;
uniform float u_cloud_darkness;  // 0 = white cumulus, 1 = dark rain/thunder/snow
uniform float u_time;
out vec4 frag_color;

const float SLAB_THICK  = 30.0;
const int   STEPS       = 24;   // view-ray march
const int   LIGHT_STEPS = 4;    // sun-ray march (self-shadow)

float hash13(vec3 p) {
    p = fract(p * 0.1031);
    p += dot(p, p.yzx + 33.33);
    return fract((p.x + p.y) * p.z);
}

float vnoise3(vec3 p) {
    vec3 i = floor(p);
    vec3 f = fract(p);
    f = f * f * (3.0 - 2.0 * f);
    float n000 = hash13(i),               n100 = hash13(i + vec3(1, 0, 0));
    float n010 = hash13(i + vec3(0,1,0)), n110 = hash13(i + vec3(1, 1, 0));
    float n001 = hash13(i + vec3(0,0,1)), n101 = hash13(i + vec3(1, 0, 1));
    float n011 = hash13(i + vec3(0,1,1)), n111 = hash13(i + vec3(1, 1, 1));
    return mix(mix(mix(n000, n100, f.x), mix(n010, n110, f.x), f.y),
               mix(mix(n001, n101, f.x), mix(n011, n111, f.x), f.y), f.z);
}

float fbm3(vec3 p) {
    float total = 0.0, amp = 0.5;
    for (int i = 0; i < 4; i++) { total += vnoise3(p) * amp; p *= 2.03; amp *= 0.5; }
    return total;
}

// Cloud density at a world point inside the slab (0 outside).
float cloudDensity(vec3 p) {
    float base = u_cloud_altitude;
    float h = (p.y - base) / SLAB_THICK;          // 0..1 through the slab
    if (h < 0.0 || h > 1.0) return 0.0;
    // Rounded vertical profile: thin at top/bottom, full in the middle.
    float vGrad = smoothstep(0.0, 0.18, h) * smoothstep(1.0, 0.55, h);

    vec3 wp = p * 0.0026;
    wp.xz += u_cloud_wind_dir * (u_time * u_cloud_wind_speed * 0.0026);
    float n = fbm3(wp);

    float cov = 1.0 - u_cloud_cover * 0.9;        // more cover → lower threshold
    float d = smoothstep(cov, cov + 0.22, n) * vGrad;
    return d * u_cloud_density;
}

void main() {
    vec4 world = u_sky_inv_vp * vec4(v_ndc, 1.0, 1.0);
    vec3 dir = normalize(world.xyz / world.w);

    // Only march upward into the slab.
    if (dir.y <= 0.02) { frag_color = vec4(0.0); return; }

    float base = u_cloud_altitude;
    float top  = base + SLAB_THICK;
    float tEnter = max((base - u_camera_pos.y) / dir.y, 0.0);
    float tExit  = (top - u_camera_pos.y) / dir.y;
    float marchLen = tExit - tEnter;
    if (marchLen <= 0.0) { frag_color = vec4(0.0); return; }

    float stepLen = marchLen / float(STEPS);
    vec3  L = normalize(u_sun_direction);
    float lightStep = SLAB_THICK / float(LIGHT_STEPS) / 1.5;

    vec3  sunLit  = u_sun_color * max(u_sun_intensity, 0.4);   // bright white sunlit tops
    vec3  ambient = vec3(0.78, 0.82, 0.90);                    // light sky-fill
    // Storm darkening: fair-weather white cumulus → heavy grey rain/thunderhead.
    sunLit  = mix(sunLit,  vec3(0.46, 0.48, 0.54), u_cloud_darkness);
    ambient = mix(ambient, vec3(0.20, 0.22, 0.26), u_cloud_darkness);

    float transmittance = 1.0;
    vec3  scatter = vec3(0.0);

    for (int i = 0; i < STEPS; i++) {
        vec3 p = u_camera_pos + dir * (tEnter + (float(i) + 0.5) * stepLen);
        float dens = cloudDensity(p);
        if (dens > 0.001) {
            // Self-shadow: accumulate density toward the sun (softer than before
            // so thick clouds read as white cumulus, not dark thunderheads).
            float lightDens = 0.0;
            for (int j = 1; j <= LIGHT_STEPS; j++) {
                lightDens += cloudDensity(p + L * (float(j) * lightStep));
            }
            float lightT = exp(-lightDens * lightStep * 0.8);
            vec3  col = mix(ambient, sunLit, lightT);  // light undersides → bright tops
            // Powder: dim the thin low-density edges (which otherwise catch full
            // light and read as a too-bright fringe); dense cores stay bright.
            col *= mix(0.6, 1.0, 1.0 - exp(-dens * 3.0));

            float dT = exp(-dens * stepLen * 1.1);
            scatter += transmittance * (1.0 - dT) * col;
            transmittance *= dT;
            if (transmittance < 0.02) break;          // fully opaque — stop
        }
    }

    // Un-premultiply: `scatter` was accumulated already weighted by opacity, but
    // the ALPHA blend multiplies by alpha AGAIN → double-darkening (the cause of
    // the near-black clouds). Divide it back out so the blend composites as
    // out = cloudColour*alpha + sky*(1-alpha).
    float opacity = 1.0 - transmittance;
    vec3  cloudColor = scatter / max(opacity, 0.0001);
    float alpha = opacity * smoothstep(0.02, 0.22, dir.y);
    frag_color = vec4(cloudColor, alpha);
}
