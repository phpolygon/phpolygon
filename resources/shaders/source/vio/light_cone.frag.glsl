#version 410 core

// Visible spot-light beam (DrawLightCone), added over the opaque scene before the
// transparent pass. Every covered pixel finds the stretch of its view ray inside the
// exact cone (the mesh only has to cover it), cuts it at the opaque surface behind
// (the linear scene depth copy, where the frame has one) and adds the light scattered
// along that stretch: the spot light's cone factor and range falloff, sampled at 12
// jittered points. Where the beam meets a wall the stretch shrinks to nothing, so the
// beam fades into the wall instead of ending in a hard line. Seen along its axis the
// beam dims by u_cone_view.x (looking into the lamp, or down a long beam).

in vec3 v_world_pos;

uniform vec3 u_cone_apex;
uniform vec3 u_cone_axis;        // unit
uniform vec4 u_cone_shape;       // x cos(angle), y cos(inner angle), z drawn length, w range
uniform vec3 u_cone_radiance;    // light colour x intensity x density (x distance fade)
uniform vec4 u_cone_view;        // x axis fade, y scene depth bound (1 / 0), z linear output (1 / 0)
uniform vec3 u_cone_eye;         // camera position
uniform vec3 u_cone_forward;     // camera forward, unit
uniform vec2 u_cone_viewport;    // scene target size in pixels
uniform sampler2D u_cone_depth;  // linear view depth (scene_depth_copy)

#ifdef PHPOLYGON_MRT
layout(location = 0) out vec4 o_sun;
layout(location = 1) out vec4 o_local;
layout(location = 2) out vec4 o_ambient;
layout(location = 3) out vec4 o_gbuffer;
#define frag_color o_local
#else
out vec4 frag_color;
#endif

const int STEPS = 12;
const float NO_HIT = 1.0e30;

vec3 toneMapACES(vec3 x) {
    const float a = 2.51;
    const float b = 0.03;
    const float c = 2.43;
    const float d = 0.59;
    const float e = 0.14;
    return clamp((x * (a * x + b)) / (x * (c * x + d) + e), 0.0, 1.0);
}

// [t0, t1] of the ray eye + t * dir inside the cone: the forward nappe of
// (h)^2 >= cos^2 * |p|^2 (h = height along the axis, p relative to the apex),
// cut to 0 <= h <= length. False when the ray misses it.
bool coneInterval(vec3 dir, out float t0, out float t1) {
    vec3 co = u_cone_eye - u_cone_apex;
    float c2 = u_cone_shape.x * u_cone_shape.x;
    float dv = dot(dir, u_cone_axis);
    float cv = dot(co, u_cone_axis);
    float a = dv * dv - c2;
    float b = dv * cv - c2 * dot(dir, co);   // half the linear coefficient
    float c = cv * cv - c2 * dot(co, co);
    t0 = -NO_HIT;
    t1 = NO_HIT;
    if (abs(a) < 1.0e-6) {
        // Ray parallel to the cone's surface: the quadratic is linear.
        if (abs(b) < 1.0e-9) {
            return false;
        }
        float t = -c / (2.0 * b);
        if (b > 0.0) {
            t0 = t;
        } else {
            t1 = t;
        }
    } else {
        float disc = b * b - a * c;
        if (disc < 0.0) {
            if (a < 0.0) {
                return false;
            }
        } else {
            float s = sqrt(disc);
            float r0 = (-b - s) / a;
            float r1 = (-b + s) / a;
            float lo = min(r0, r1);
            float hi = max(r0, r1);
            if (a < 0.0) {
                // Steeper than the cone: inside between the two crossings.
                t0 = lo;
                t1 = hi;
            } else if (dv > 0.0) {
                // Flatter than the cone: inside beyond the crossing on the forward nappe.
                t0 = hi;
            } else {
                t1 = lo;
            }
        }
    }
    // 0 <= h <= length: the forward nappe only, and the drawn part of the beam.
    if (abs(dv) < 1.0e-6) {
        if (cv < 0.0 || cv > u_cone_shape.z) {
            return false;
        }
    } else {
        float ta = -cv / dv;
        float tb = (u_cone_shape.z - cv) / dv;
        t0 = max(t0, min(ta, tb));
        t1 = min(t1, max(ta, tb));
    }
    t0 = max(t0, 0.0);
    return t1 > t0;
}

void main() {
    vec3 dir = normalize(v_world_pos - u_cone_eye);
    float t0;
    float t1;
    vec3 scatter = vec3(0.0);
    if (coneInterval(dir, t0, t1)) {
        if (u_cone_view.y > 0.5) {
            // The opaque surface behind this pixel ends the stretch.
            float viewDepth = texture(u_cone_depth, gl_FragCoord.xy / u_cone_viewport).r;
            t1 = min(t1, viewDepth / max(dot(dir, u_cone_forward), 1.0e-3));
        }
        if (t1 > t0) {
            float range2 = max(u_cone_shape.w * u_cone_shape.w, 1.0e-4);
            float st = (t1 - t0) / float(STEPS);
            // Per-pixel jitter (interleaved gradient noise) turns the steps into grain.
            float jit = fract(52.9829189 * fract(dot(gl_FragCoord.xy, vec2(0.06711056, 0.00583715))));
            float acc = 0.0;
            for (int j = 0; j < STEPS; j++) {
                vec3 q = u_cone_eye + dir * (t0 + (float(j) + jit) * st) - u_cone_apex;
                float dq = length(q);
                float cd = dot(q, u_cone_axis) / max(dq, 1.0e-4);
                float cone = smoothstep(u_cone_shape.x, u_cone_shape.y, cd);
                // The engine's light falloff (a window, no inverse square), as on surfaces.
                float at = clamp(1.0 - dq * dq / range2, 0.0, 1.0);
                acc += cone * at * at;
            }
            float along = abs(dot(dir, u_cone_axis));
            float axisFade = 1.0 - u_cone_view.x * pow(along, 6.0);
            scatter = u_cone_radiance * (acc * st * axisFade);
        }
    }
    if (u_cone_view.z < 0.5) {
        // LDR scene target: the mesh shader tonemapped and encoded the scene already;
        // the beam gets the same curve, so it adds over black what the HDR path shows.
        scatter = pow(toneMapACES(scatter), vec3(1.0 / 2.2));
    }
#ifdef PHPOLYGON_MRT
    o_sun = vec4(0.0);
    o_ambient = vec4(0.0);
    o_gbuffer = vec4(0.0);
#endif
    // Alpha 1: the additive blend weighs the source by its alpha.
    frag_color = vec4(scatter, 1.0);
}
