#version 410 core

// Screen-space reflections composite pass. Reads the resolved scene
// colour + depth, ray-marches reflections in screen space, and writes
// scene + reflection back to the bound framebuffer.
//
// World normals are reconstructed per-fragment from the world-position
// derivatives - cheap, no extra G-buffer required, but produces softer
// reflection edges than a true normal-attached path. Acceptable for
// the engine's "indie PBR" target tier.
//
// On miss the SSR contribution is zero and the wetness IBL lobe in the
// main shader continues to provide the reflection cue. The two lobes
// stack additively.

in vec2 v_uv;

uniform sampler2D u_color_texture;   // resolved scene colour
uniform sampler2D u_depth_texture;   // resolved scene depth
uniform mat4 u_inverse_view_projection;
uniform mat4 u_view_projection;
uniform vec3 u_camera_pos;
uniform vec2 u_inverse_resolution;
uniform float u_ssr_intensity;       // ScreenSpaceReflections::intensity()

out vec4 frag_color;

// Reconstruct world-space position from a UV + depth sample.
vec3 worldPosFromDepth(vec2 uv, float depth) {
    vec4 ndc = vec4(uv * 2.0 - 1.0, depth * 2.0 - 1.0, 1.0);
    vec4 world = u_inverse_view_projection * ndc;
    return world.xyz / world.w;
}

void main() {
    vec3 sceneColor = texture(u_color_texture, v_uv).rgb;
    float depth = texture(u_depth_texture, v_uv).r;

    // Sky / unwritten depth = no reflection. The named threshold makes
    // future skybox depth changes a deliberate change rather than a
    // silent regression here.
    const float SKY_DEPTH_THRESHOLD = 0.9999;
    if (u_ssr_intensity <= 0.0 || depth >= SKY_DEPTH_THRESHOLD) {
        frag_color = vec4(sceneColor, 1.0);
        return;
    }

    vec3 worldPos = worldPosFromDepth(v_uv, depth);

    // World normal from screen-space derivatives of world position.
    // dFdx/dFdy in a fullscreen pass give the per-fragment surface
    // gradient of the rendered scene without a normal G-buffer.
    vec3 dx = dFdx(worldPos);
    vec3 dy = dFdy(worldPos);
    vec3 N = normalize(cross(dx, dy));
    if (length(N) < 1e-4) {
        frag_color = vec4(sceneColor, 1.0);
        return;
    }

    vec3 V = normalize(worldPos - u_camera_pos);
    vec3 R = reflect(V, N);

    // Ray-march in world space. Adaptive step length keeps the march
    // cheap close to the surface where reflection detail matters most
    // and longer at the far end where geometric error dominates anyway.
    const int STEPS = 24;
    const float STEP_BASE = 0.15;
    vec3 sampleP = worldPos + N * 0.02;        // start slightly off-surface to avoid self-hit
    vec3 hitColor = vec3(0.0);
    float hitAlpha = 0.0;

    for (int i = 0; i < STEPS; i++) {
        sampleP += R * (STEP_BASE * (1.0 + float(i) * 0.05));

        // Project to clip space.
        vec4 clip = u_view_projection * vec4(sampleP, 1.0);
        if (clip.w <= 0.0) break;
        vec3 ndc = clip.xyz / clip.w;
        if (any(lessThan(ndc.xy, vec2(-1.0))) || any(greaterThan(ndc.xy, vec2(1.0)))) break;

        vec2 uv = ndc.xy * 0.5 + 0.5;
        float scenedepth = texture(u_depth_texture, uv).r;
        float sampleDepth = ndc.z * 0.5 + 0.5;

        // Hit when we've stepped past the depth surface. The 0.001
        // epsilon swallows acne from the screen-space step granularity.
        float diff = sampleDepth - scenedepth;
        if (diff > 0.0 && diff < 0.005) {
            hitColor = texture(u_color_texture, uv).rgb;
            // Edge falloff: fade towards the screen border so the
            // disocclusion at the edges doesn't pop.
            vec2 fade = smoothstep(vec2(0.0), vec2(0.1), uv) *
                        smoothstep(vec2(1.0), vec2(0.9), uv);
            hitAlpha = fade.x * fade.y;
            break;
        }
    }

    vec3 outColor = sceneColor + hitColor * hitAlpha * u_ssr_intensity;
    frag_color = vec4(outColor, 1.0);
}
