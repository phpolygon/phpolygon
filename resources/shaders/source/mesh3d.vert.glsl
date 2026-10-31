#version 150 core
in vec3 a_position;
in vec3 a_normal;
in vec2 a_uv;

// Per-instance model matrix (4 vec4 columns, locations 3-6)
// When instancing is active, these replace u_model.
// When not instancing, u_model uniform is used instead.
in vec4 a_instance_model_col0;
in vec4 a_instance_model_col1;
in vec4 a_instance_model_col2;
in vec4 a_instance_model_col3;

uniform mat4 u_model;
uniform mat4 u_view;
uniform mat4 u_projection;
uniform mat3 u_normal_matrix;
uniform int  u_use_instancing; // 0 = use u_model, 1 = use per-instance attributes

// inverse()/transpose() are GLSL 1.40+. On a 1.30 (GL 3.0) context the
// instanced branch that needs them is never taken at runtime — instancing
// degrades to the CPU path there, so u_use_instancing is always 0 and the
// u_normal_matrix branch runs. The expression must still compile, so fall
// back to mat3(model) below 1.40 (correct for uniform scale, dead at runtime).
#if __VERSION__ >= 140
#define NORMAL_MATRIX(m) mat3(transpose(inverse(m)))
#else
#define NORMAL_MATRIX(m) mat3(m)
#endif

uniform float u_time;
uniform int   u_vertex_anim;
uniform float u_wave_amplitude;
uniform float u_wave_frequency;
uniform float u_wave_phase;

// Procedural cloth sway. Driven from Material::$cloth and the global
// SetWind command. Anchor weight is derived from the local Y of the
// vertex relative to the mesh's local AABB so the static end stays
// fixed while the loose end swings. No CPU simulation, no GPU compute
// pass - good enough for background characters / capes / banners.
uniform int   u_cloth;
uniform float u_cloth_strength;
uniform float u_cloth_frequency;
uniform float u_cloth_phase;
uniform int   u_cloth_anchor_top; // 1 = anchor at top, 0 = anchor at bottom
uniform vec3  u_wind_direction;
uniform float u_wind_intensity;
uniform vec3  u_mesh_local_aabb_min;
uniform vec3  u_mesh_local_aabb_max;

// Foliage wind (mirrors vio/mesh3d.vert.glsl foliageSway).
uniform int   u_foliage_wind;
uniform float u_wind_flutter;
uniform int   u_foliage_tufts;    // Material::$windTufts: the mesh carries a phase per leaf tuft
uniform float u_foliage_motion;
uniform vec2  u_foliage_fade;

// Grass blades (Material::$grass, SetGrass; mirrors every mesh vertex stage,
// GrassShaderConsistencyTest). uv.x = blade random, uv.y = 0 root .. 1 tip; the
// tuft hash rides in the instance matrix's unused m[0].w, read and cleared in main.
uniform int   u_grass;
uniform float u_grass_bend;       // tip displacement per unit wind, fraction of the blade height
uniform vec4  u_grass_range;      // full range, fade range, far height factor, push strength
uniform vec4  u_grass_push[4];    // benders: xyz position, w radius (0 = unused)

out vec3 v_normal;
out vec3 v_worldPos;
out vec2 v_uv;
out vec3 v_localPos;
out vec3 v_localNormal;
out vec3 v_objectScale;

// Grass tuft: thins out (the tuft hash against a density falling from full to
// fade range) and shrinks into the ground instead of popping, gets shorter
// toward the fade range, bends with the wind (gusts travel along the wind
// direction, the tip moves most) and away from benders, and keeps roughly its
// length (what bends sideways comes down).
vec3 grassBlade(vec3 pos, vec2 uv, mat4 model, float hash, float time) {
    vec3 origin = model[3].xyz;
    vec3 camPos = -(transpose(mat3(u_view)) * u_view[3].xyz);
    float fadeT = smoothstep(u_grass_range.x, u_grass_range.y, length(origin - camPos));
    float keep = clamp(((1.0 - fadeT) * 1.25 - hash) * 4.0, 0.0, 1.0);
    float k = keep * mix(1.0, u_grass_range.z, fadeT);
    if (k <= 0.0) {
        return vec3(pos.x, -0.05, pos.z);
    }
    pos.y = pos.y * k - (1.0 - keep) * 0.03;
    float s2 = max(dot(model[0].xyz, model[0].xyz), 1e-8);
    float t = clamp(uv.y, 0.0, 1.0);
    float hw = max(pos.y, 0.0) * sqrt(s2);
    vec3 d = vec3(0.0);
    float amp = u_wind_intensity * u_foliage_motion;
    if (amp > 0.0) {
        vec3 wind = vec3(u_wind_direction.x, 0.0, u_wind_direction.z);
        wind = length(wind) > 1e-4 ? normalize(wind) : vec3(0.0, 0.0, 1.0);
        float along = dot(origin.xz, wind.xz);
        float across = dot(origin.xz, vec2(-wind.z, wind.x));
        float gust = 0.7 + 0.3 * sin(along * 0.35 - time * 1.9 + uv.x * 0.6)
                   + 0.15 * sin(along * 0.9 + across * 0.4 - time * 3.1);
        float flick = sin(time * 4.7 + uv.x * 23.0 + along * 1.3) * 0.12;
        d += wind * (u_grass_bend * amp * hw * t * (gust + flick));
    }
    for (int i = 0; i < 4; i++) {
        vec4 b = u_grass_push[i];
        if (b.w <= 0.0) {
            continue;
        }
        vec2 away = origin.xz - b.xz;
        float dist = length(away);
        float f = (1.0 - smoothstep(b.w * 0.3, b.w, dist)) * (1.0 - smoothstep(0.6, 2.5, abs(origin.y - b.y)));
        if (f <= 0.0) {
            continue;
        }
        vec2 dir = dist > 1e-3 ? away / dist : vec2(1.0, 0.0);
        d.xz += dir * (f * u_grass_range.w * hw * t);
    }
    float lat = length(d.xz);
    d.y -= min(lat * lat / max(2.0 * hw, 0.02), hw * 0.6);
    return pos + (transpose(mat3(model)) * d) / s2;
}

// Foliage sway: bend with the square of the height, flutter toward the mesh's
// outer edge, phase from the instance origin, fade with camera distance.
vec3 foliageSway(vec3 pos, mat4 model, float time) {
    vec3 origin = model[3].xyz;
    vec3 camPos = -(transpose(mat3(u_view)) * u_view[3].xyz);
    float fade = 1.0 - smoothstep(u_foliage_fade.x, u_foliage_fade.y, length(origin - camPos));
    float amp = u_wind_intensity * u_foliage_motion * fade;
    if (amp <= 0.0) {
        return pos;
    }
    vec3 wind = vec3(u_wind_direction.x, 0.0, u_wind_direction.z);
    wind = length(wind) > 1e-4 ? normalize(wind) : vec3(0.0, 0.0, 1.0);
    float phase = dot(origin.xz, vec2(0.173, 0.291))
                + fract(sin(dot(origin.xz, vec2(12.9898, 78.233))) * 43758.5453) * 6.2831;
    float s2 = max(dot(model[0].xyz, model[0].xyz), 1e-8);
    float h = max(pos.y, 0.0) * sqrt(s2);
    float lean = 0.65 + 0.35 * sin(time * 0.9 + phase);
    float osc = sin(time * 1.7 + phase * 1.3) * 0.35 + sin(time * 2.9 + phase * 0.7) * 0.15;
    vec3 d = wind * (0.0009 * h * h * amp * (lean + osc));
    if (u_wind_flutter > 0.0) {
        vec3 c = (u_mesh_local_aabb_min + u_mesh_local_aabb_max) * 0.5;
        vec3 e = (u_mesh_local_aabb_max - u_mesh_local_aabb_min) * 0.5;
        float r = clamp(length(pos.xz - c.xz) / max(max(e.x, e.z), 1e-3), 0.0, 1.0);
        if (u_foliage_tufts == 1) {
            // Tuft mesh: uv.x = phase of the tuft, uv.y = 0 centre .. 1 leaf tip.
            r = 0.45 + 0.55 * clamp(a_uv.y, 0.0, 1.0);
            phase += a_uv.x * 6.2831;
        }
        vec3 f = vec3(sin(time * 4.1 + dot(pos, vec3(1.7, 0.9, 1.3)) + phase),
                      sin(time * 5.3 + dot(pos, vec3(0.8, 1.9, 1.1)) + phase) * 0.6,
                      cos(time * 3.7 + dot(pos, vec3(1.2, 0.6, 1.9)) + phase));
        d += f * (u_wind_flutter * r * amp);
    }
    return pos + (transpose(mat3(model)) * d) / s2;
}

void main() {
    // Select model matrix: per-instance attribute or uniform
    mat4 model;
    if (u_use_instancing == 1) {
        model = mat4(a_instance_model_col0, a_instance_model_col1,
                     a_instance_model_col2, a_instance_model_col3);
    } else {
        model = u_model;
    }

    // Grass: the tuft hash rides in m[0].w (see grassBlade); clear it before use.
    float grassHash = 0.0;
    if (u_grass == 1) {
        grassHash = model[0].w;
        model[0].w = 0.0;
    }
    vec3 pos = a_position;

    // Optional GPU wave animation
    if (u_vertex_anim == 1) {
        vec4 worldPosRaw = model * vec4(pos, 1.0);
        float wave = sin(worldPosRaw.x * u_wave_frequency + u_time + u_wave_phase)
                   * cos(worldPosRaw.z * u_wave_frequency * 0.7 + u_time * 0.8)
                   * u_wave_amplitude;
        pos.y += wave;
    }

    // Procedural cloth sway. Anchor weight maps the vertex's local-Y
    // position to [0, 1] across the mesh's AABB; (1 - weight) drives
    // how much sway the vertex gets, so the anchored end is rigid and
    // the free end is floppy. Default AABB (0..0) collapses to "no
    // sway" which is the desired no-op when the renderer hasn't pushed
    // a real AABB.
    if (u_cloth == 1) {
        float aabbHeight = max(u_mesh_local_aabb_max.y - u_mesh_local_aabb_min.y, 1e-4);
        float yNorm = clamp((pos.y - u_mesh_local_aabb_min.y) / aabbHeight, 0.0, 1.0);
        float anchorWeight = u_cloth_anchor_top == 1 ? yNorm : (1.0 - yNorm);
        float swayMask = 1.0 - anchorWeight;

        // Two sin waves at 90° offset give a richer "rippling fabric"
        // motion than a single one would. The local x/z coordinates
        // contribute so neighbouring vertices don't sway in lock-step.
        float t = u_time * u_cloth_frequency + u_cloth_phase;
        float wave = sin(t + pos.x * 2.0) * 0.7 + cos(t * 1.3 + pos.z * 1.5) * 0.3;

        vec3 windDir = length(u_wind_direction) > 1e-4 ? normalize(u_wind_direction) : vec3(0.0, 0.0, 1.0);
        vec3 sway = windDir * (wave * u_cloth_strength * u_wind_intensity * swayMask);
        // Wind is horizontal; vertical drift is tiny but not zero so
        // the cloth has a subtle "lifting" feel.
        sway.y *= 0.15;
        pos += sway;
    }

    if (u_grass == 1) {
        pos = grassBlade(pos, a_uv, model, grassHash, u_time);
    } else if (u_foliage_wind == 1) {
        pos = foliageSway(pos, model, u_time);
    }

    vec4 worldPos = model * vec4(pos, 1.0);
    v_worldPos = worldPos.xyz;
    v_localPos = pos;
    v_localNormal = a_normal;
    v_objectScale = vec3(length(model[0].xyz), length(model[1].xyz), length(model[2].xyz));

    // Normal matrix — compute from model matrix for instanced draws
    if (u_use_instancing == 1) {
        v_normal = NORMAL_MATRIX(model) * a_normal;
    } else {
        bool isZero = (u_normal_matrix[0] == vec3(0.0) &&
                       u_normal_matrix[1] == vec3(0.0) &&
                       u_normal_matrix[2] == vec3(0.0));
        if (isZero) {
            v_normal = NORMAL_MATRIX(model) * a_normal;
        } else {
            v_normal = u_normal_matrix * a_normal;
        }
    }

    v_uv = a_uv;
    gl_Position = u_projection * u_view * worldPos;
}
