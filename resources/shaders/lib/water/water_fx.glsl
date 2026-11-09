// Small water looks (PHPolygon\Rendering\Water\WaterShading, always part of the
// library): a rising air bubble and a stretched water jet. Both return a linear
// colour and an alpha for outputColor(); the caller decides strength and tint.

// Air bubble on a sphere: almost clear in the middle, a bright thin rim where the
// surface turns away (total reflection inside the air), a small sun highlight and
// a faint mirrored sky. N, V, L: world normal, toward the eye, toward the sun.
vec4 waterBubble(vec3 N, vec3 V, vec3 L, vec3 sky, vec3 sunColor) {
    float NdotV = clamp(dot(N, V), 0.0, 1.0);
    float rim = pow(1.0 - NdotV, 3.0);
    vec3 H = normalize(V + L);
    float spec = pow(max(dot(N, H), 0.0), 180.0) * 3.0;
    // A second, dimmer highlight on the far side: light through the bubble.
    float back = pow(max(dot(-N, H), 0.0), 40.0) * 0.4;
    vec3 c = sky * (0.25 + 0.9 * rim) + sunColor * (spec + back);
    float a = clamp(0.08 + rim * 0.75 + spec, 0.0, 0.95);
    return vec4(c, a);
}

// Water jet on a velocity-stretched particle (uv.y along the stretch, 0 at the
// head): bands of sheen running along the stream, a bright core where the
// surface faces the eye, edges that let the background through, glints.
vec4 waterJet(vec3 N, vec3 V, vec3 L, vec2 uv, float time, vec3 body, vec3 sky, vec3 sunColor) {
    float NdotV = clamp(dot(N, V), 0.0, 1.0);
    float fresnel = mix(0.04, 1.0, pow(1.0 - NdotV, 4.0));
    float bands = 0.5 + 0.5 * sin(uv.y * 18.0 - time * 9.0 + uv.x * 3.0);
    bands = smoothstep(0.35, 1.0, bands);
    vec3 H = normalize(V + L);
    float spec = pow(max(dot(N, H), 0.0), 90.0) * 2.0;
    vec3 c = mix(body, sky, fresnel) * (0.85 + 0.35 * bands) + sunColor * spec;
    float a = clamp(0.35 + 0.4 * fresnel + 0.2 * bands + spec, 0.0, 0.9);
    return vec4(c, a);
}
