#version 410 core
in vec2 v_ndc;

uniform mat4 u_sky_inv_vp;        // inverse(projection * view_without_translation)
uniform vec3 u_camera_pos;        // for cloud-plane ray intersection
uniform vec3 u_sun_direction;     // normalized, toward the sun
uniform vec3 u_sun_color;
uniform float u_sun_intensity;
uniform vec3 u_zenith_color;
uniform vec3 u_horizon_color;
uniform vec3 u_ground_color;
uniform float u_sun_size;         // angular radius of the sun disc (rad)
uniform float u_sun_glow_size;    // angular extent of the glow halo (rad)
uniform float u_sun_glow_intensity;
uniform vec3 u_moon_direction;
uniform vec3 u_moon_color;
uniform float u_moon_intensity;
uniform float u_star_brightness;

// Cloud layer
uniform float u_cloud_cover;      // 0..1 — fraction of sky with clouds
uniform float u_cloud_altitude;   // world-Y of the cloud plane
uniform float u_cloud_density;    // 0..1 — contrast / opacity of clouds
uniform float u_cloud_wind_speed; // world units / sec
uniform vec2  u_cloud_wind_dir;   // normalized XZ wind direction

// Horizon haze / humidity fog
uniform float u_fog_density;      // 0..1

uniform float u_time;

out vec4 frag_color;

float smoothstep01(float e0, float e1, float x) {
    float t = clamp((x - e0) / (e1 - e0), 0.0, 1.0);
    return t * t * (3.0 - 2.0 * t);
}

// Small hash used for twinkly starfield — no texture needed.
float hash31(vec3 p) {
    return fract(sin(dot(p, vec3(443.897, 441.423, 437.195))) * 43758.5453);
}

// 2D value noise for cloud shaping.
float hash21(vec2 p) {
    return fract(sin(dot(p, vec2(127.1, 311.7))) * 43758.5453);
}

float noise2d(vec2 p) {
    vec2 i = floor(p);
    vec2 f = fract(p);
    vec2 u = f * f * (3.0 - 2.0 * f);
    return mix(
        mix(hash21(i),                hash21(i + vec2(1.0, 0.0)), u.x),
        mix(hash21(i + vec2(0.0, 1.0)), hash21(i + vec2(1.0, 1.0)), u.x),
        u.y
    );
}

float fbm2(vec2 p) {
    float total = 0.0;
    float amp = 0.5;
    for (int i = 0; i < 4; i++) {
        total += noise2d(p) * amp;
        p *= 2.07;
        amp *= 0.5;
    }
    return total;
}

void main() {
    // Reconstruct the world-space view direction for this pixel.
    vec4 world = u_sky_inv_vp * vec4(v_ndc, 1.0, 1.0);
    vec3 dir = normalize(world.xyz / world.w);

    float elevation = dir.y;

    // Base gradient: horizon toward zenith above, toward ground below.
    vec3 color;
    if (elevation >= 0.0) {
        float t = smoothstep01(0.0, 1.0, elevation);
        color = mix(u_horizon_color, u_zenith_color, t);
    } else {
        float t = smoothstep01(0.0, -0.3, elevation);
        color = mix(u_horizon_color, u_ground_color, t);
    }

    // Sun disc + glow halo + horizon scatter.
    if (u_sun_intensity > 0.0) {
        float cosA = dot(dir, u_sun_direction);
        float angle = acos(clamp(cosA, -1.0, 1.0));

        // Soft sun disc
        float disc = 1.0 - smoothstep01(u_sun_size * 0.5, u_sun_size, angle);
        color = mix(color, u_sun_color * u_sun_intensity, disc);

        // Glow halo
        if (angle < u_sun_glow_size) {
            float g = 1.0 - angle / u_sun_glow_size;
            g = g * g * u_sun_glow_intensity;
            color += u_sun_color * u_sun_intensity * g;
        }

        // Warm horizon scattering near the sun direction (sunset band).
        if (elevation > -0.05 && elevation < 0.25) {
            float band = 1.0 - abs(elevation - 0.05) / 0.20;
            band = max(0.0, band);
            float s = max(0.0, cosA) * band * 0.35 * u_sun_intensity;
            color += u_sun_color * s;
        }
    }

    // Moon (below-horizon sun opposite): faint disc + soft cool glow.
    if (u_moon_intensity > 0.0) {
        float cosM = dot(dir, u_moon_direction);
        float angle = acos(clamp(cosM, -1.0, 1.0));
        float disc = 1.0 - smoothstep01(u_sun_size * 0.7, u_sun_size * 1.4, angle);
        color = mix(color, u_moon_color * u_moon_intensity, disc);
        if (angle < u_sun_glow_size * 0.6) {
            float g = 1.0 - angle / (u_sun_glow_size * 0.6);
            g = g * g * 0.35 * u_moon_intensity;
            color += u_moon_color * g;
        }
    }

    // Stars — only above the horizon and only when bright enough.
    if (u_star_brightness > 0.0 && elevation > 0.0) {
        vec3 cell = floor(dir * 200.0);
        float n = hash31(cell);
        if (n > 0.9975) {
            float twinkle = (n - 0.9975) * 400.0;
            // Fade stars near horizon (atmospheric extinction).
            float fadeEdge = smoothstep01(0.0, 0.15, elevation);
            color += vec3(twinkle) * u_star_brightness * fadeEdge;
        }
    }

    // Cloud layer — project ray onto a horizontal plane at cloud altitude
    // and sample 2D fBm noise. Clouds are visible only when looking up and
    // the ray actually crosses the plane above the camera.
    if (u_cloud_cover > 0.0 && elevation > 0.001) {
        float t = (u_cloud_altitude - u_camera_pos.y) / elevation;
        if (t > 0.0) {
            vec2 cloudPos = (u_camera_pos.xz + dir.xz * t) * 0.003;
            cloudPos += u_cloud_wind_dir * (u_time * u_cloud_wind_speed * 0.003);
            float n = fbm2(cloudPos);

            // Threshold-based coverage with soft edge. Full coverage => the
            // threshold drops so almost everything becomes cloudy.
            float thresh = 1.0 - u_cloud_cover * 0.95;
            float edge = 0.12;
            float cloudMask = smoothstep01(thresh - edge, thresh + edge, n);

            // Shade clouds: brighter where view ray aligns with sun (forward
            // scattering), slightly darker underside.
            float sunAlign = max(0.0, dot(dir, u_sun_direction));
            vec3 cloudLit = mix(vec3(0.78, 0.80, 0.86), u_sun_color,
                                 0.4 * u_sun_intensity * sunAlign);
            vec3 cloudShadow = vec3(0.50, 0.52, 0.58);
            vec3 cloudColor = mix(cloudShadow, cloudLit,
                                   clamp(u_sun_intensity, 0.0, 1.0));

            // Clouds fade toward the horizon (perspective + atmospheric
            // extinction). Heavy clouds don't fade as fast.
            float perspFade = smoothstep01(0.0, 0.15, elevation);
            float alpha = cloudMask * u_cloud_density * perspFade;
            color = mix(color, cloudColor, alpha);
        }
    }

    // Horizon haze — pushes colour toward the horizon tint when visibility
    // is low. Peaks at the horizon, fades toward zenith and toward ground.
    if (u_fog_density > 0.0) {
        float hazeBand = 1.0 - smoothstep01(0.0, 0.35, abs(elevation));
        color = mix(color, u_horizon_color, hazeBand * u_fog_density);
    }

    frag_color = vec4(color, 1.0);
}
