#version 410 core

// SSR composite — blends the ray-marched reflection over the HDR scene colour.
//
// Bound with VIO_BLEND_ALPHA, drawing INTO the scene offscreen target while
// sampling the SEPARATE ssr target (so we never read+write the same resource —
// a D3D12 hazard). The blend computes:
//     scene' = ssr.rgb * ssr.a + scene * (1 - ssr.a)
// i.e. mix(scene, reflectedColor, weight), with weight already folded with
// reflectivity * strength * fresnel * edgeFade in ssr.frag. A zero-weight
// (miss / sky / matte) texel leaves the scene untouched.
//
// Runs in the HDR (linear, pre-tonemap) scene space so bright reflected
// highlights survive into bloom and the final ACES tonemap.

in vec2 v_uv;

uniform sampler2D u_ssr;

out vec4 frag_color;

void main() {
    frag_color = texture(u_ssr, v_uv);
}
