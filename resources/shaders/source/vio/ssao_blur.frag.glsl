#version 410 core

// SSAO blur — bilateral, background- and depth-aware box blur over the raw AO
// target.
//
// The SSAO pass rotates its hemisphere kernel by a 4x4-tiled per-pixel noise
// vector, which trades banding for high-frequency noise. A plain box blur the
// size of the noise tile (4x4) averages that noise out ON A SOLID SURFACE — but
// it FAILS on thin geometry silhouetted against the background: there the blur
// footprint is mostly background, so only the handful of real foreground texels
// enter the average and their per-pixel noise survives un-averaged, reading as a
// mottled/speckled pattern on the thin surface.
//
// The fix: make the blur bilateral. The SSAO pass now writes, per texel,
//   R = AO, G = foreground flag (1 = real geometry, 0 = sky/background),
//   B = compressed linear view depth in 0..1 (monotonic, convention-independent).
// We only average neighbours that are (a) foreground and (b) on the SAME surface
// (|dB| small). A background centre passes straight through (AO stays 1). A
// thin-sliver foreground texel — one with few valid foreground neighbours —
// can't be denoised by averaging, so we fade its AO toward 1 (unoccluded)
// proportional to how isolated it is: AO is unreliable on a thin foreground
// silhouette, so we trust it less there rather than smear noise. Reads RGB,
// writes R (the forward pass samples R).

in vec2 v_uv;

uniform sampler2D u_source;
uniform vec2 u_texel; // 1.0 / AO-target resolution

out vec4 frag_color;

void main() {
    vec3 c = texture(u_source, v_uv).rgb; // centre: r=AO, g=fg flag, b=depth
    float centreAO    = c.r;
    float centreFg    = c.g;
    float centreDepth = c.b;

    // Background centre (sky): nothing to occlude, pass through unblurred.
    if (centreFg < 0.5) {
        frag_color = vec4(1.0, 0.0, 0.0, 1.0);
        return;
    }

    // Bilateral box accumulation over the 4x4 noise tile. A neighbour only
    // contributes if it is foreground AND within a depth band of the centre, so
    // we never mix the sky (or a different surface behind the silhouette) into a
    // foreground texel's AO. depthTol is in the compressed-depth domain; ~0.02
    // spans a generous slab of the same surface while still rejecting the step
    // to the background / a far occluder.
    const float depthTol = 0.02;
    float result = 0.0;
    float wsum   = 0.0;
    for (int x = -2; x < 2; x++) {
        for (int y = -2; y < 2; y++) {
            vec2 off = vec2(float(x), float(y)) * u_texel;
            vec3 s = texture(u_source, v_uv + off).rgb;
            float fgW    = step(0.5, s.g);
            float depthW = step(abs(s.b - centreDepth), depthTol);
            float w = fgW * depthW;
            result += s.r * w;
            wsum   += w;
        }
    }

    // The centre is always a valid sample (w=1), so wsum >= 1; max 16 (a fully
    // solid neighbourhood). validFrac is how much of the footprint is the same
    // surface: 1 = solid interior (fully denoised), small = thin foreground
    // sliver against the background (only itself + a neighbour or two).
    float blurred   = result / max(wsum, 1.0);
    float validFrac = wsum / 16.0;

    // Confidence ramp: below ~1/3 of the footprint valid we can't denoise the
    // sliver, so fade its AO toward 1 (unoccluded). smoothstep keeps the
    // transition from solid interior to thin edge gentle (no hard outline).
    float conf  = smoothstep(0.18, 0.45, validFrac);
    float final = mix(1.0, blurred, conf);

    frag_color = vec4(final, 0.0, 0.0, 1.0);
}
