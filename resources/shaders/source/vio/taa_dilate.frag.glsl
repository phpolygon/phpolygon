#version 410 core

// Temporal resolve, pass 1 of 2 (render resolution; VioTaaPass).
//
// Per render pixel:
//   rg  motion of the NEAREST surface in the 3x3 neighbourhood (prevUv - uv,
//       texture space). Dilating to the closest depth keeps silhouettes of
//       moving objects from dragging the background's history across their
//       edge. Where nothing wrote a vector (sky: depth still at the clear value)
//       the camera motion is reconstructed from the depth with u_reprojection.
//   b   the FARTHEST linear view depth of the 3x3 neighbourhood. Next frame
//       compares it with the depth its surface had here: only when even the
//       farthest neighbour was nearer was that surface really hidden
//       (disocclusion) - a single thin occluder that the jitter moves in and
//       out of the neighbourhood is not.
//   a   the depth this pixel's own surface had last frame (camera reprojection
//       of the centre depth), for the resolve's disocclusion test.
//
// Depth is stored as (ndcZ + 1) / 2 on every backend (GL directly, D3D/Metal/
// Vulkan via the depth-convention fixup), so ndcZ = d * 2 - 1 here.

in vec2 v_uv;
out vec4 frag_color;

uniform sampler2D u_motion;        // MRT motion attachment (RG16F)
uniform sampler2D u_depth;         // MRT depth
uniform vec2  u_texel;             // 1 / render size
uniform vec2  u_jitter_uv;         // this frame's jitter, texture space
uniform float u_uv_flip_y;         // +1 GL, -1 where row 0 is NDC top
uniform mat4  u_reprojection;      // prevViewProj * inverse(viewProj), unjittered
uniform mat4  u_inv_proj;          // inverse unjittered projection

const float MAX_DEPTH = 60000.0;   // fits FP16

vec2 toNdc(vec2 uv) {
    vec2 ndc = (uv - u_jitter_uv) * 2.0 - 1.0;
    ndc.y *= u_uv_flip_y;
    return ndc;
}

// Linear view depth of a stored depth at uv; also returns the reprojected
// clip position (last frame) through prev.
float linearDepth(vec2 uv, float d, out vec4 prev) {
    vec2 ndc = toNdc(uv);
    float ndcZ = min(d, 1.0) * 2.0 - 1.0;
    prev = u_reprojection * vec4(ndc, ndcZ, 1.0);
    // inverse(viewProj) = inverse(view) * inverse(proj) and the view is affine,
    // so the homogeneous w u_reprojection carries is view.w: dividing it out
    // turns prev.w into last frame's view depth.
    vec4 view = u_inv_proj * vec4(ndc, ndcZ, 1.0);
    prev /= view.w;
    return d >= 1.0 ? MAX_DEPTH : clamp(-view.z / view.w, 0.0, MAX_DEPTH);
}

void main() {
    float nearest = 2.0;
    float farthest = -1.0;
    vec2 nearestUv = v_uv;
    for (int y = -1; y <= 1; y++) {
        for (int x = -1; x <= 1; x++) {
            vec2 uv = v_uv + vec2(float(x), float(y)) * u_texel;
            float d = texture(u_depth, uv).r;
            if (d < nearest) {
                nearest = d;
                nearestUv = uv;
            }
            farthest = max(farthest, d);
        }
    }

    vec4 prevNearest;
    linearDepth(nearestUv, nearest, prevNearest);
    vec2 motion;
    if (nearest >= 1.0) {
        // Sky: no geometry, no vector - the camera's own motion at the far plane.
        vec2 ndc = toNdc(nearestUv);
        motion = (prevNearest.xy / prevNearest.w - ndc) * vec2(0.5, 0.5 * u_uv_flip_y);
    } else {
        motion = texture(u_motion, nearestUv).rg;
    }

    vec4 unused;
    float farthestDepth = linearDepth(v_uv, farthest, unused);
    vec4 prevCentre;
    float centre = texture(u_depth, v_uv).r;
    linearDepth(v_uv, centre, prevCentre);
    float expectedPrev = centre >= 1.0 ? MAX_DEPTH : clamp(prevCentre.w, 0.0, MAX_DEPTH);

    frag_color = vec4(motion, farthestDepth, expectedPrev);
}
