#version 410 core

in vec3 v_normal;
in vec3 v_worldPos;
in vec2 v_uv;

uniform vec3 u_ambient_color;
uniform float u_ambient_intensity;

uniform vec3 u_dir_light_direction;
uniform vec3 u_dir_light_color;
uniform float u_dir_light_intensity;

struct PointLight {
    vec3 position;
    vec3 color;
    float intensity;
    float radius;
};
uniform PointLight u_point_lights[8];
uniform int u_point_light_count;

uniform vec3 u_albedo;
uniform vec3 u_emission;
uniform float u_roughness;
uniform float u_metallic;
uniform float u_alpha;
uniform vec3 u_fog_color;
uniform float u_fog_near;
uniform float u_fog_far;

uniform vec3 u_camera_pos;
uniform float u_time;
// Procedural material modes: 0=standard, 1=sand terrain, 2=water, 3=rock, 4=palm trunk, 5=palm leaf
uniform int u_proc_mode;

out vec4 frag_color;

// ================================================================
//  Noise functions
// ================================================================

float hash21(vec2 p) {
    p = fract(p * vec2(127.1, 311.7));
    p += dot(p, p + 19.19);
    return fract(p.x * p.y);
}

float hash31(vec3 p) {
    p = fract(p * vec3(443.897, 441.423, 437.195));
    p += dot(p, p.yzx + 19.19);
    return fract((p.x + p.y) * p.z);
}

float noise(vec2 p) {
    vec2 i = floor(p);
    vec2 f = fract(p);
    f = f * f * (3.0 - 2.0 * f);
    float a = hash21(i);
    float b = hash21(i + vec2(1.0, 0.0));
    float c = hash21(i + vec2(0.0, 1.0));
    float d = hash21(i + vec2(1.0, 1.0));
    return mix(mix(a, b, f.x), mix(c, d, f.x), f.y);
}

// Fractal Brownian Motion — layered noise
float fbm(vec2 p, int octaves) {
    float value = 0.0;
    float amp = 0.5;
    float freq = 1.0;
    for (int i = 0; i < octaves; i++) {
        value += amp * noise(p * freq);
        freq *= 2.0;
        amp *= 0.5;
    }
    return value;
}

// ================================================================
//  PBR helpers
// ================================================================

vec3 fresnelSchlick(float cosTheta, vec3 F0) {
    return F0 + (1.0 - F0) * pow(clamp(1.0 - cosTheta, 0.0, 1.0), 5.0);
}

// GGX Normal Distribution Function
float distributionGGX(float NdotH, float rough) {
    float a = rough * rough;
    float a2 = a * a;
    float denom = NdotH * NdotH * (a2 - 1.0) + 1.0;
    return a2 / (3.14159 * denom * denom + 0.0001);
}

// ================================================================
//  Procedural Sand
// ================================================================

vec3 computeSand(vec3 N, vec3 V, vec3 L, out float roughOut) {
    float zone = v_uv.x;    // 0.0=damp, 0.25=mid, 0.5=dry, 0.75=dune
    float variant = v_uv.y;

    // Zone color palettes — warm natural beach tones
    const vec3 damp[4] = vec3[](
        vec3(0.478, 0.369, 0.165), vec3(0.408, 0.306, 0.125),
        vec3(0.541, 0.408, 0.188), vec3(0.290, 0.220, 0.094)
    );
    const vec3 mid[4] = vec3[](
        vec3(0.722, 0.565, 0.314), vec3(0.627, 0.471, 0.220),
        vec3(0.784, 0.596, 0.345), vec3(0.420, 0.333, 0.157)
    );
    const vec3 dry[4] = vec3[](
        vec3(0.831, 0.722, 0.478), vec3(0.769, 0.643, 0.384),
        vec3(0.878, 0.769, 0.549), vec3(0.545, 0.451, 0.251)
    );
    const vec3 dune[4] = vec3[](
        vec3(0.863, 0.753, 0.502), vec3(0.910, 0.800, 0.565),
        vec3(0.816, 0.706, 0.439), vec3(0.604, 0.502, 0.282)
    );

    // Blend between zones smoothly
    vec3 colors[4];
    if (zone < 0.125)      colors = damp;
    else if (zone < 0.375) colors = mid;
    else if (zone < 0.625) colors = dry;
    else                   colors = dune;

    // Smooth variant blending
    float vi = variant * 3.0;
    int idx = int(floor(vi));
    vec3 baseColor = mix(colors[clamp(idx, 0, 3)], colors[clamp(idx + 1, 0, 3)], fract(vi));

    // Multi-scale noise — creates natural organic sand pattern
    float n1 = fbm(v_worldPos.xz * 1.5, 3);          // large color patches
    float n2 = noise(v_worldPos.xz * 6.0);             // medium grain clumps
    float n3 = noise(v_worldPos.xz * 25.0);            // individual grains
    float n4 = noise(v_worldPos.xz * 80.0);            // micro detail

    vec3 sandColor = baseColor;
    sandColor *= 0.82 + n1 * 0.36;                     // broad variation
    sandColor *= 0.92 + (n2 - 0.5) * 0.16;             // clump variation
    sandColor += vec3(0.02) * (n3 - 0.5);              // grain-level color shift
    sandColor += vec3(0.01, 0.008, 0.005) * (n4 - 0.5); // warm micro detail

    // Wind ripple patterns — diagonal lines across the beach
    float ripple = sin(v_worldPos.x * 3.0 + v_worldPos.z * 1.5 + n1 * 2.0) * 0.5 + 0.5;
    ripple = smoothstep(0.3, 0.7, ripple);
    float rippleStrength = smoothstep(0.3, 0.8, zone); // stronger on dry/dune
    sandColor *= 1.0 - ripple * 0.06 * rippleStrength;

    // Subsurface scattering approximation — warm glow when backlit
    float scatter = max(dot(V, L), 0.0);
    scatter = pow(scatter, 4.0) * 0.08;
    sandColor += vec3(0.15, 0.10, 0.04) * scatter;

    // Sparkle / glint — individual grains catching sunlight
    vec3 grainPos = floor(v_worldPos * 40.0);
    float glint = hash31(grainPos);
    vec3 grainNormal = normalize(vec3(
        hash21(grainPos.xz) - 0.5,
        1.0,
        hash21(grainPos.xz + 100.0) - 0.5
    ));
    float glintSpec = pow(max(dot(reflect(-L, grainNormal), V), 0.0), 80.0);
    if (glint > 0.96) {
        sandColor += vec3(0.4, 0.35, 0.25) * glintSpec * max(dot(N, L), 0.0);
    }

    // Roughness per zone — wet sand is shinier
    roughOut = mix(0.45, 0.95, smoothstep(0.0, 0.3, zone));
    // Wet sand also gets slight specular tint
    if (zone < 0.15) {
        sandColor = mix(sandColor, sandColor * 1.15, 0.3);
    }

    return sandColor;
}

// ================================================================
//  Procedural Water
// ================================================================

vec3 computeWater(vec3 N, vec3 V, vec3 L, out float alphaOut, out float roughOut) {
    // Animated wave normals — multiple layers at different speeds and scales
    vec2 uv1 = v_worldPos.xz * 0.8 + u_time * vec2(0.03, 0.02);
    vec2 uv2 = v_worldPos.xz * 1.6 + u_time * vec2(-0.02, 0.04);
    vec2 uv3 = v_worldPos.xz * 4.0 + u_time * vec2(0.05, -0.03);
    vec2 uv4 = v_worldPos.xz * 8.0 + u_time * vec2(-0.04, 0.06);

    // Compute normal perturbation from noise derivatives
    float eps = 0.05;
    float h1a = fbm(uv1, 3); float h1b = fbm(uv1 + vec2(eps, 0), 3); float h1c = fbm(uv1 + vec2(0, eps), 3);
    float h2a = fbm(uv2, 2); float h2b = fbm(uv2 + vec2(eps, 0), 2); float h2c = fbm(uv2 + vec2(0, eps), 2);
    float h3a = noise(uv3);  float h3b = noise(uv3 + vec2(eps, 0));   float h3c = noise(uv3 + vec2(0, eps));
    float h4a = noise(uv4);  float h4b = noise(uv4 + vec2(eps, 0));   float h4c = noise(uv4 + vec2(0, eps));

    vec3 waveNormal = vec3(0.0, 1.0, 0.0);
    // Large swell
    waveNormal.x += (h1a - h1b) * 1.5 + (h2a - h2b) * 0.8;
    waveNormal.z += (h1a - h1c) * 1.5 + (h2a - h2c) * 0.8;
    // Detail ripples
    waveNormal.x += (h3a - h3b) * 0.3 + (h4a - h4b) * 0.15;
    waveNormal.z += (h3a - h3c) * 0.3 + (h4a - h4c) * 0.15;
    waveNormal = normalize(waveNormal);

    // Blend wave normal with geometry normal
    N = normalize(N + waveNormal * vec3(1.0, 0.0, 1.0));

    // Fresnel — more reflective at shallow angles (realistic water!)
    float NdotV = max(dot(N, V), 0.0);
    float fresnel = pow(1.0 - NdotV, 5.0);
    fresnel = mix(0.02, 1.0, fresnel); // water F0 ≈ 0.02

    // Depth-based coloring
    float depth = max(0.0, -8.0 - v_worldPos.z) / 70.0; // 0 at shore, 1 at deep
    depth = clamp(depth, 0.0, 1.0);

    vec3 shallowColor = vec3(0.15, 0.55, 0.50);  // turquoise
    vec3 deepColor    = vec3(0.02, 0.08, 0.15);   // dark navy
    vec3 waterColor   = mix(shallowColor, deepColor, depth);

    // Sky reflection color (simplified — sample from sun direction)
    vec3 skyReflect = vec3(0.55, 0.70, 0.85); // blue sky
    vec3 sunReflect = vec3(1.0, 0.95, 0.8);   // sun highlight area
    vec3 R = reflect(-V, N);
    float sunCatch = pow(max(dot(R, L), 0.0), 256.0);
    vec3 reflectColor = mix(skyReflect, sunReflect, sunCatch * 2.0);

    // Combine: fresnel blends between water body color and reflection
    vec3 finalColor = mix(waterColor, reflectColor, fresnel);

    // Sun specular hotspot on water
    vec3 Hw = normalize(V + L);
    float specWater = pow(max(dot(N, Hw), 0.0), 512.0);
    finalColor += u_dir_light_color * u_dir_light_intensity * specWater * 2.0;

    // Shore foam — white noise patches where water is shallow
    float foamLine = smoothstep(0.02, 0.0, depth);
    float foamNoise = fbm(v_worldPos.xz * 6.0 + u_time * 0.5, 3);
    float foam = foamLine * smoothstep(0.35, 0.65, foamNoise);
    finalColor = mix(finalColor, vec3(0.9, 0.95, 1.0), foam * 0.7);

    // Caustic light pattern on shallow water (subtle)
    if (depth < 0.3) {
        float caustic1 = noise(v_worldPos.xz * 3.0 + u_time * 0.8);
        float caustic2 = noise(v_worldPos.xz * 3.0 - u_time * 0.6 + 50.0);
        float caustic = pow(min(caustic1, caustic2), 3.0) * 2.0;
        finalColor += vec3(0.1, 0.15, 0.1) * caustic * (1.0 - depth / 0.3);
    }

    // Transparency: shallow = more transparent, deep = more opaque
    alphaOut = mix(0.5, 0.92, depth);
    // Foam areas are opaque
    alphaOut = mix(alphaOut, 1.0, foam * 0.8);

    roughOut = 0.05; // water is very smooth

    return finalColor;
}

// ================================================================
//  Procedural Rock
// ================================================================

vec3 computeRock(vec3 N, vec3 worldPos, vec3 baseAlbedo, out float roughOut) {
    vec3 p = worldPos * 2.5;

    // Base rock color with large-scale variation
    float n1 = fbm(p.xz, 4);
    float n2 = fbm(p.xz * 3.0 + 50.0, 3);
    float n3 = noise(p.xz * 12.0);

    // Mix between dark and light stone
    vec3 darkStone  = baseAlbedo * 0.6;
    vec3 lightStone = baseAlbedo * 1.3;
    vec3 rockColor = mix(darkStone, lightStone, n1);

    // Veins / cracks — dark lines
    float crack = noise(p.xz * 8.0 + vec2(p.y * 2.0));
    crack = smoothstep(0.48, 0.52, crack);
    rockColor = mix(rockColor, rockColor * 0.5, crack * 0.4);

    // Strata layers — horizontal bands common in sedimentary rock
    float strata = sin(worldPos.y * 15.0 + n1 * 3.0) * 0.5 + 0.5;
    strata = smoothstep(0.4, 0.6, strata);
    rockColor *= 0.9 + strata * 0.2;

    // Moss patches — green on top-facing surfaces
    float upFacing = max(dot(N, vec3(0.0, 1.0, 0.0)), 0.0);
    float mossNoise = fbm(worldPos.xz * 4.0, 3);
    float moss = upFacing * smoothstep(0.4, 0.7, mossNoise) * smoothstep(0.5, 0.9, upFacing);
    vec3 mossColor = vec3(0.15, 0.25, 0.08);
    rockColor = mix(rockColor, mossColor, moss * 0.6);

    // Lichen spots — orange/yellow patches
    float lichenNoise = noise(worldPos.xz * 10.0 + 200.0);
    if (lichenNoise > 0.85) {
        vec3 lichenColor = vec3(0.6, 0.5, 0.2);
        rockColor = mix(rockColor, lichenColor, (lichenNoise - 0.85) * 4.0 * 0.3);
    }

    // Surface roughness variation
    roughOut = 0.75 + n2 * 0.2;
    roughOut = mix(roughOut, 0.6, moss * 0.5); // moss is smoother

    return rockColor;
}

// ================================================================
//  Procedural Palm Trunk
// ================================================================

vec3 computePalmTrunk(vec3 worldPos, vec3 baseAlbedo, out float roughOut) {
    // Bark rings — horizontal bands around the trunk
    float ringFreq = 12.0;
    float ring = sin(worldPos.y * ringFreq) * 0.5 + 0.5;
    ring = smoothstep(0.3, 0.7, ring);

    // Fiber texture — vertical streaks
    float fiber = noise(vec2(worldPos.x * 20.0 + worldPos.z * 20.0, worldPos.y * 3.0));
    float fiberFine = noise(vec2(worldPos.x * 50.0 + worldPos.z * 50.0, worldPos.y * 8.0));

    // Base bark color with warm brown variation
    vec3 darkBark  = baseAlbedo * 0.65;
    vec3 lightBark = baseAlbedo * 1.2;
    vec3 barkColor = mix(darkBark, lightBark, ring * 0.6 + fiber * 0.4);

    // Ring shadows — darker in the grooves
    barkColor *= 0.85 + ring * 0.3;

    // Fiber detail
    barkColor *= 0.95 + (fiberFine - 0.5) * 0.15;

    // Slight green/grey weathering
    float weather = fbm(worldPos.xz * 5.0, 2);
    barkColor = mix(barkColor, barkColor * vec3(0.85, 0.9, 0.8), weather * 0.2);

    roughOut = 0.85 + ring * 0.1;
    return barkColor;
}

// ================================================================
//  Procedural Palm Leaf
// ================================================================

vec3 computePalmLeaf(vec3 worldPos, vec3 N, vec3 V, vec3 L, vec3 baseAlbedo, out float roughOut) {
    // Leaf vein pattern — runs along the frond length
    float vein = abs(sin(worldPos.x * 30.0 + worldPos.z * 30.0));
    vein = smoothstep(0.0, 0.15, vein);

    // Base green with variation
    float n = fbm(worldPos.xz * 8.0, 3);
    vec3 leafColor = baseAlbedo * (0.8 + n * 0.4);

    // Central vein is lighter
    leafColor = mix(leafColor * 1.3, leafColor, vein);

    // Tip browning — leaves get yellow/brown at edges
    float edgeNoise = noise(worldPos.xz * 12.0);
    leafColor = mix(leafColor, vec3(0.4, 0.35, 0.15), edgeNoise * 0.15);

    // Translucency — light shining through leaf
    float translucency = max(dot(-N, L), 0.0);
    translucency = pow(translucency, 2.0) * 0.3;
    leafColor += vec3(0.1, 0.2, 0.02) * translucency;

    // Subsurface scattering
    float scatter = pow(max(dot(V, L), 0.0), 3.0) * 0.1;
    leafColor += vec3(0.05, 0.1, 0.02) * scatter;

    roughOut = 0.6 + edgeNoise * 0.15;
    return leafColor;
}

// ================================================================
//  Procedural Cloud
// ================================================================

vec3 computeCloud(vec3 N, vec3 V, vec3 L, vec3 baseAlbedo, out float alphaOut) {
    // Cloud color based on sun-facing
    float NdotL = max(dot(N, L), 0.0);

    // Bright top, darker base
    vec3 sunColor = vec3(1.0, 0.98, 0.95);
    vec3 shadowColor = vec3(0.6, 0.65, 0.72);
    vec3 cloudColor = mix(shadowColor, sunColor, NdotL * 0.7 + 0.3);

    // Subsurface scattering — light passes through cloud edges
    float scatter = pow(max(dot(V, L), 0.0), 3.0);
    cloudColor += vec3(0.3, 0.25, 0.15) * scatter * 0.4;

    // Silver lining — bright rim when backlit
    float rim = pow(1.0 - max(dot(N, V), 0.0), 3.0);
    cloudColor += vec3(0.5, 0.5, 0.4) * rim * scatter * 0.6;

    // Soft noise variation
    float n = fbm(v_worldPos.xz * 0.3, 3);
    cloudColor *= 0.9 + n * 0.2;

    // Edge transparency — clouds are more transparent at edges
    float edgeFade = pow(max(dot(N, V), 0.0), 0.8);
    alphaOut = edgeFade * 0.85;

    return cloudColor;
}

// ================================================================
//  Main
// ================================================================

void main() {
    vec3 N = normalize(v_normal);
    if (!gl_FrontFacing) N = -N;

    vec3 V = normalize(u_camera_pos - v_worldPos);
    vec3 L = normalize(-u_dir_light_direction);
    vec3 H = normalize(V + L);

    float roughness = clamp(u_roughness, 0.04, 1.0);
    float alpha = u_alpha;
    vec3 albedo;

    // ---- Material selection ----
    if (u_proc_mode == 2) {
        // Water — full procedural with reflections, foam, caustics
        albedo = computeWater(N, V, L, alpha, roughness);

        // Water handles its own lighting — skip PBR, go to fog
        float fogDist = length(v_worldPos - u_camera_pos);
        float fogFactor = clamp((fogDist - u_fog_near) / (u_fog_far - u_fog_near), 0.0, 1.0);
        fogFactor = 1.0 - exp(-fogFactor * fogFactor * 3.0);
        vec3 color = mix(albedo, u_fog_color, fogFactor);
        color = pow(max(color, vec3(0.0)), vec3(1.0 / 2.2));
        frag_color = vec4(color, alpha);
        return;

    } else if (u_proc_mode == 1) {
        // Sand terrain
        albedo = computeSand(N, V, L, roughness);
        float nx_p = noise(v_worldPos.xz * 20.0 + vec2(0.1, 0.0));
        float nz_p = noise(v_worldPos.xz * 20.0 + vec2(0.0, 0.1));
        N = normalize(N + vec3((nx_p - 0.5) * 0.05, 0.0, (nz_p - 0.5) * 0.05));

    } else if (u_proc_mode == 3) {
        // Rock
        albedo = computeRock(N, v_worldPos, u_albedo, roughness);
        // Rock normal perturbation for surface roughness
        float rnx = noise(v_worldPos.xz * 15.0 + vec2(0.1, 0.0));
        float rnz = noise(v_worldPos.xz * 15.0 + vec2(0.0, 0.1));
        float rny = noise(v_worldPos.yz * 15.0);
        N = normalize(N + vec3((rnx - 0.5) * 0.12, (rny - 0.5) * 0.08, (rnz - 0.5) * 0.12));

    } else if (u_proc_mode == 4) {
        // Palm trunk
        albedo = computePalmTrunk(v_worldPos, u_albedo, roughness);
        float tnx = noise(vec2(v_worldPos.x * 30.0, v_worldPos.y * 5.0));
        N = normalize(N + vec3((tnx - 0.5) * 0.08, 0.0, (tnx - 0.5) * 0.08));

    } else if (u_proc_mode == 5) {
        // Palm leaf
        albedo = computePalmLeaf(v_worldPos, N, V, L, u_albedo, roughness);

    } else if (u_proc_mode == 6) {
        // Cloud — self-lit, skip PBR
        albedo = computeCloud(N, V, L, u_albedo, alpha);

        float fogDist = length(v_worldPos - u_camera_pos);
        float fogFactor = clamp((fogDist - u_fog_near) / (u_fog_far - u_fog_near), 0.0, 1.0);
        fogFactor = 1.0 - exp(-fogFactor * fogFactor * 3.0);
        vec3 color = mix(albedo, u_fog_color, fogFactor);
        color = pow(max(color, vec3(0.0)), vec3(1.0 / 2.2));
        frag_color = vec4(color, alpha);
        return;

    } else {
        // Standard material
        float nse = noise(v_worldPos.xz * 0.4);
        float noiseMask = smoothstep(0.3, 0.9, roughness);
        albedo = u_albedo * (1.0 + (nse - 0.5) * 0.12 * noiseMask);
    }

    // ---- PBR Lighting (sand + standard materials) ----
    float shininess = exp2(10.0 * (1.0 - roughness) + 1.0);
    H = normalize(V + L);

    vec3 F0 = mix(vec3(0.04), albedo, u_metallic);
    float NdotL = max(dot(N, L), 0.0);

    // Ambient
    vec3 color = u_ambient_color * u_ambient_intensity * albedo * (1.0 - u_metallic * 0.9);

    // Directional light
    if (NdotL > 0.0) {
        color += albedo * u_dir_light_color * u_dir_light_intensity * NdotL * (1.0 - u_metallic);
        float NdotH = max(dot(N, H), 0.0);
        float spec = pow(NdotH, shininess) * (shininess + 2.0) / 8.0;
        vec3 F = fresnelSchlick(max(dot(H, V), 0.0), F0);
        color += F * u_dir_light_color * u_dir_light_intensity * spec * NdotL;
    }

    // Point lights
    for (int i = 0; i < u_point_light_count; i++) {
        vec3 Lp = u_point_lights[i].position - v_worldPos;
        float dist = length(Lp);
        Lp = normalize(Lp);
        vec3 Hp = normalize(V + Lp);

        float radius = max(u_point_lights[i].radius, 0.001);
        float atten = clamp(1.0 - (dist * dist) / (radius * radius), 0.0, 1.0);
        atten *= atten;

        float NdotPL = max(dot(N, Lp), 0.0);
        if (NdotPL > 0.0) {
            color += albedo * u_point_lights[i].color * u_point_lights[i].intensity
                     * NdotPL * atten * (1.0 - u_metallic);
            float NdotHP = max(dot(N, Hp), 0.0);
            float specP = pow(NdotHP, shininess) * (shininess + 2.0) / 8.0;
            vec3 FP = fresnelSchlick(max(dot(Hp, V), 0.0), F0);
            color += FP * u_point_lights[i].color * u_point_lights[i].intensity
                     * specP * NdotPL * atten;
        }
    }

    // Emission
    color += u_emission;

    // Fog
    float fogDist = length(v_worldPos - u_camera_pos);
    float fogFactor = clamp((fogDist - u_fog_near) / (u_fog_far - u_fog_near), 0.0, 1.0);
    fogFactor = 1.0 - exp(-fogFactor * fogFactor * 3.0);
    color = mix(color, u_fog_color, fogFactor);

    // Gamma correction
    color = pow(max(color, vec3(0.0)), vec3(1.0 / 2.2));

    frag_color = vec4(color, alpha);
}
