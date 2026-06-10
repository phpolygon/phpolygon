#version 410 core

// Screen-space reflections — view-space ray-march against the FP16 G-buffer
// depth+normal, sampling the HDR scene colour at the hit.
//
// Inputs:
//   u_gbuffer : RGBA16F G-buffer (see gbuffer.frag.glsl)
//       rg = VIEW-space normal, octahedral-encoded (full sphere; decodeViewNormal)
//       b  = reflectivity in [0,1]   (0 = matte, skip)
//       a  = LINEAR view depth (world units, 0 == sky)
//   u_scene   : HDR scene colour (the FP16 offscreen target, PRE-tonemap), so
//               reflected highlights stay >1 and bloom naturally downstream.
//
// Output (to a separate FP16 SSR target, alpha-blended back over the scene by
// the composite pass):
//   rgb = reflected scene colour at the hit
//   a   = reflection weight = reflectivity * u_strength * fresnel * edgeFade
//         (0 on miss / sky / backface / off-screen → composite leaves the
//         scene untouched, never smears).
//
// Conventions match ssao.frag exactly: view-space reconstruction from the
// perspective focal lengths (u_proj00/11), and u_uv_flip_y (+1 GL / -1 D3D)
// reconciles the render-target UV.v with view +Y. v_uv is the screen quad's
// already-V-flipped UV (postprocess.vert), so we sample upright.

in vec2 v_uv;

uniform sampler2D u_gbuffer;
uniform sampler2D u_scene;

uniform float u_proj00;     // projection[0][0]
uniform float u_proj11;     // projection[1][1]
uniform float u_uv_flip_y;  // +1 GL, -1 D3D

uniform int   u_steps;      // linear march steps (tier-driven)
uniform int   u_refine;     // binary-search refine iterations after a coarse hit
uniform float u_thickness;  // intersection thickness, view-space units
uniform float u_max_dist;   // max ray reach, view-space units
uniform float u_strength;   // overall reflection strength (tier intensity)

out vec4 frag_color;

// Compile-time loop caps so DXC (D3D12) can unroll the march/refine loops — the
// tier uniforms (u_steps/u_refine) only ever bound the loop with a runtime
// `break`, never the loop count, otherwise "loop does not appear to terminate"
// (varying iteration count → unroll fails). Keep these >= the highest tier.
const int MAX_STEPS  = 64;
const int MAX_REFINE = 8;

// Sample with an explicit LOD so the texture fetch inside the loop needs no
// implicit screen-space gradient (gradient ops in a varying-iteration loop are
// what break the D3D12 unroll). The G-buffer has no mips, so LOD 0 is exact.
float sampleViewDepth(vec2 uv) {
    return textureLod(u_gbuffer, uv, 0.0).a;
}

// Decode the VIEW-space normal from the octahedral pair gbuffer.frag wrote into
// rg. Full-sphere inverse of octEncode — returns the true normal with any z sign,
// so the reflection ray is built from the correct (un-flipped) surface normal.
vec3 decodeViewNormal(vec2 e) {
    vec3 n = vec3(e, 1.0 - abs(e.x) - abs(e.y));
    float t = max(-n.z, 0.0);
    n.x += n.x >= 0.0 ? -t : t;
    n.y += n.y >= 0.0 ? -t : t;
    return normalize(n);
}

// VIEW-space position from UV + linear depth (inverse perspective xy mapping).
vec3 viewPosFromUV(vec2 uv, float linearDepth) {
    vec2 ndc = uv * 2.0 - 1.0;
    ndc.y *= u_uv_flip_y;
    return vec3(ndc.x * linearDepth / u_proj00,
                ndc.y * linearDepth / u_proj11,
                -linearDepth);
}

// Project a VIEW-space position to UV (forward perspective xy mapping). Returns
// uv in [0,1]; caller range-checks. linearDepth (= -p.z) returned in .z slot via
// the out param so the caller can compare against the stored depth.
vec2 viewToUV(vec3 p) {
    float d = -p.z;
    vec2 ndc = vec2(p.x * u_proj00, p.y * u_proj11) / max(d, 1e-4);
    ndc.y *= u_uv_flip_y;
    return ndc * 0.5 + 0.5;
}

void main() {
    vec4 g = texture(u_gbuffer, v_uv);
    float depth = g.a;
    float reflectivity = g.b;

    // Sky / no geometry, or matte surface → no reflection.
    if (depth <= 0.0 || reflectivity <= 0.001) {
        frag_color = vec4(0.0);
        return;
    }

    vec3 N = decodeViewNormal(g.rg);
    vec3 P = viewPosFromUV(v_uv, depth);   // view-space fragment position
    vec3 V = normalize(-P);                 // toward the eye (eye at origin)
    vec3 R = reflect(-V, N);               // reflection of the view ray

    // Reject rays pointing back toward / nearly parallel to the camera plane:
    // those reflect off-screen content or graze, producing smears. A ray with
    // R.z >= 0 heads toward/behind the eye — skip it.
    if (R.z >= 0.0) {
        frag_color = vec4(0.0);
        return;
    }

    // Fresnel (Schlick) — grazing angles reflect more (key for water realism).
    float cosTheta = clamp(dot(V, N), 0.0, 1.0);
    float fresnel = 0.04 + 0.96 * pow(1.0 - cosTheta, 5.0);

    // March in view space. Step length scales with reach / step count.
    float stepLen = u_max_dist / float(max(u_steps, 1));

    vec3 hitColor = vec3(0.0);
    float hit = 0.0;
    vec2 hitUV = vec2(0.0);

    vec3 prevPos = P;

    for (int i = 1; i <= MAX_STEPS; i++) {
        if (i > u_steps) break;
        vec3 samplePos = P + R * (stepLen * float(i));
        float rayDepth = -samplePos.z;          // linear view depth of the ray
        if (rayDepth <= 0.0) break;             // crossed behind the eye

        vec2 uv = viewToUV(samplePos);
        if (uv.x < 0.0 || uv.x > 1.0 || uv.y < 0.0 || uv.y > 1.0) break; // off screen

        float storedDepth = sampleViewDepth(uv);
        if (storedDepth <= 0.0) {               // marched into the sky
            prevPos = samplePos;
            continue;
        }

        float delta = rayDepth - storedDepth;   // >0: ray is BEHIND the surface

        // Intersection: the ray went from in-front (delta<=0) to behind
        // (delta>0) the stored surface, within the thickness slab.
        if (delta > 0.0 && delta < u_thickness) {
            vec2 refineUV = uv;

            // Binary-search refine between prevPos (in front) and samplePos
            // (behind) for a crisper hit (High tier only).
            vec3 lo = prevPos;
            vec3 hi = samplePos;
            for (int r = 0; r < MAX_REFINE; r++) {
                if (r >= u_refine) break;
                vec3 mid = (lo + hi) * 0.5;
                vec2 muv = viewToUV(mid);
                float msd = sampleViewDepth(muv);
                if (msd <= 0.0) { lo = mid; continue; }
                float md = (-mid.z) - msd;
                if (md > 0.0) { hi = mid; refineUV = muv; }
                else          { lo = mid; }
            }

            hitColor = textureLod(u_scene, refineUV, 0.0).rgb;
            hitUV = refineUV;
            hit = 1.0;
            break;
        }

        prevPos = samplePos;
    }

    if (hit < 0.5) {
        frag_color = vec4(0.0);
        return;
    }

    // Edge fade: reflections sampled near the screen border have no off-screen
    // source data, so fade them out to avoid a hard seam at the edge.
    vec2 edge = smoothstep(vec2(0.0), vec2(0.12), hitUV)
              * (1.0 - smoothstep(vec2(0.88), vec2(1.0), hitUV));
    float edgeFade = edge.x * edge.y;

    float weight = reflectivity * u_strength * fresnel * edgeFade;
    frag_color = vec4(hitColor, clamp(weight, 0.0, 1.0));
}
