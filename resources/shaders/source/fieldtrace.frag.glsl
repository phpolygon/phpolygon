#version 410 core

// =============================================================================
// Fieldtracing — SDF sphere-trace pass (OpenGL variant, Tier B fragment fallback)
// =============================================================================
//
// Byte-identical body to resources/shaders/source/vio/fieldtrace.frag.glsl —
// keep the two copies in sync (same discipline as the mesh3d shader copies).
// See the vio copy for the full design notes. The SDF scene mirrors the analytic
// primitives in src/Fieldtracing/Sdf and examples/fieldtracing_bake.php.

in  vec2 v_uv;
out vec4 frag_color;

uniform vec2  u_resolution;
uniform float u_time;
uniform vec3  u_cam_pos;
uniform vec3  u_cam_target;
uniform vec3  u_sun_dir;      // direction TOWARD the sun (normalized)
uniform float u_intensity;    // Fieldtracing contribution scale (GraphicsSettings)
uniform float u_ao_radius;    // SDF AO sample reach
uniform float u_mode;         // 0=Off 1=ProbesOnly 2=SdfOcclusion 3=SdfBounce (float: int-in-UBO unreliable across SPIRV-Cross targets)

const int   MAX_STEPS = 128;
const float MAX_DIST  = 60.0;
const float SURF_EPS  = 0.0008;

float sdSphere(vec3 p, vec3 c, float r) {
    return length(p - c) - r;
}

float sdBox(vec3 p, vec3 c, vec3 halfExtents) {
    vec3 q = abs(p - c) - halfExtents;
    return length(max(q, 0.0)) + min(max(q.x, max(q.y, q.z)), 0.0);
}

float sdPlane(vec3 p, vec3 n, float offset) {
    return dot(p, n) - offset;
}

float smin(float a, float b, float k) {
    float h = clamp(0.5 + 0.5 * (b - a) / k, 0.0, 1.0);
    return mix(b, a, h) - k * h * (1.0 - h);
}

float mapScene(vec3 p) {
    float ground = sdPlane(p, vec3(0.0, 1.0, 0.0), 0.0);

    float t = u_time * 0.6;
    vec3 c0 = vec3(-1.1, 1.0 + 0.15 * sin(t),       0.0);
    vec3 c1 = vec3( 1.1, 1.0 + 0.15 * sin(t + 2.1), 0.0);
    float s0 = sdSphere(p, c0, 1.0);
    float s1 = sdSphere(p, c1, 0.8);
    float blob = smin(s0, s1, 0.6);

    float box = sdBox(p, vec3(0.0, 0.7, -1.8), vec3(0.9, 0.7, 0.9));
    blob = smin(blob, box, 0.4);

    return min(ground, blob);
}

vec3 calcNormal(vec3 p) {
    const vec2 e = vec2(1.0, -1.0) * 0.0006;
    return normalize(
        e.xyy * mapScene(p + e.xyy) +
        e.yyx * mapScene(p + e.yyx) +
        e.yxy * mapScene(p + e.yxy) +
        e.xxx * mapScene(p + e.xxx)
    );
}

float raymarch(vec3 ro, vec3 rd) {
    float t = 0.0;
    for (int i = 0; i < MAX_STEPS; i++) {
        vec3 p = ro + rd * t;
        float d = mapScene(p);
        if (d < SURF_EPS * t) return t;
        t += d;
        if (t > MAX_DIST) break;
    }
    return -1.0;
}

float softShadow(vec3 ro, vec3 rd, float k) {
    float res = 1.0;
    float t = 0.04;
    for (int i = 0; i < 48; i++) {
        float h = mapScene(ro + rd * t);
        if (h < 0.0008) return 0.0;
        res = min(res, k * h / t);
        t += clamp(h, 0.02, 0.4);
        if (t > 20.0) break;
    }
    return clamp(res, 0.0, 1.0);
}

float ambientOcclusion(vec3 p, vec3 n, float radius) {
    float occ = 0.0;
    float sca = 1.0;
    for (int i = 0; i < 5; i++) {
        float hr = 0.02 + radius * float(i) / 4.0;
        float d = mapScene(p + n * hr);
        occ += (hr - d) * sca;
        sca *= 0.6;
    }
    return clamp(1.0 - 2.5 * occ, 0.0, 1.0);
}

vec3 sky(vec3 rd, vec3 sun) {
    float up = clamp(rd.y * 0.5 + 0.5, 0.0, 1.0);
    vec3 horizon = vec3(0.62, 0.70, 0.82);
    vec3 zenith  = vec3(0.18, 0.34, 0.62);
    vec3 col = mix(horizon, zenith, up);
    float sd = max(dot(rd, sun), 0.0);
    col += vec3(1.0, 0.85, 0.6) * pow(sd, 220.0);
    col += vec3(1.0, 0.75, 0.45) * 0.25 * pow(sd, 6.0);
    return col;
}

void main() {
    vec2 uv = v_uv * 2.0 - 1.0;
    float aspect = u_resolution.x / max(u_resolution.y, 1.0);
    uv.x *= aspect;

    vec3 ro = u_cam_pos;
    vec3 fwd = normalize(u_cam_target - ro);
    vec3 right = normalize(cross(fwd, vec3(0.0, 1.0, 0.0)));
    vec3 up = cross(right, fwd);
    vec3 rd = normalize(uv.x * right + uv.y * up + 1.6 * fwd);

    vec3 sun = normalize(u_sun_dir);
    vec3 sunColor = vec3(1.0, 0.93, 0.82);

    float t = raymarch(ro, rd);
    vec3 col;

    if (t < 0.0) {
        col = sky(rd, sun);
    } else {
        vec3 p = ro + rd * t;
        vec3 n = calcNormal(p);

        vec3 albedo = (n.y > 0.92 && p.y < 0.05)
            ? vec3(0.42, 0.40, 0.38)
            : vec3(0.78, 0.32, 0.24);

        float ao = 1.0;
        float sh = 1.0;
        if (u_mode >= 2) {
            ao = ambientOcclusion(p, n, u_ao_radius);
            sh = softShadow(p, sun, 8.0);
        }

        vec3 skyAmbient = mix(vec3(0.35), sky(n, sun), float(u_mode >= 1));
        vec3 ambient = albedo * skyAmbient * (0.35 * ao);

        float ndl = max(dot(n, sun), 0.0);
        vec3 direct = albedo * sunColor * ndl * sh;

        vec3 bounce = vec3(0.0);
        if (u_mode >= 3) {
            vec3 fillDir = normalize(reflect(-sun, n) * 0.2 + n);
            float openness = softShadow(p, fillDir, 4.0);
            bounce = albedo * sky(fillDir, sun) * 0.18 * openness * ao;
        }

        col = direct + (ambient + bounce) * u_intensity;

        float fog = 1.0 - exp(-0.012 * t);
        col = mix(col, sky(rd, sun), fog);
    }

    const float a = 2.51, b = 0.03, c = 2.43, d = 0.59, e = 0.14;
    col = clamp((col * (a * col + b)) / (col * (c * col + d) + e), 0.0, 1.0);
    col = pow(col, vec3(1.0 / 2.2));

    frag_color = vec4(col, 1.0);
}
