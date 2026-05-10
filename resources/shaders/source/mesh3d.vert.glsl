#version 410 core

layout(location = 0) in vec3 a_position;
layout(location = 1) in vec3 a_normal;
layout(location = 2) in vec2 a_uv;

// Per-instance model matrix (4 vec4 columns, locations 3-6)
// When instancing is active, these replace u_model.
// When not instancing, u_model uniform is used instead.
layout(location = 3) in vec4 a_instance_model_col0;
layout(location = 4) in vec4 a_instance_model_col1;
layout(location = 5) in vec4 a_instance_model_col2;
layout(location = 6) in vec4 a_instance_model_col3;

uniform mat4 u_model;
uniform mat4 u_view;
uniform mat4 u_projection;
uniform mat3 u_normal_matrix;
uniform int  u_use_instancing; // 0 = use u_model, 1 = use per-instance attributes

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

out vec3 v_normal;
out vec3 v_worldPos;
out vec2 v_uv;
out vec3 v_localPos;
out vec3 v_localNormal;
out vec3 v_objectScale;

void main() {
    // Select model matrix: per-instance attribute or uniform
    mat4 model;
    if (u_use_instancing == 1) {
        model = mat4(a_instance_model_col0, a_instance_model_col1,
                     a_instance_model_col2, a_instance_model_col3);
    } else {
        model = u_model;
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

    vec4 worldPos = model * vec4(pos, 1.0);
    v_worldPos = worldPos.xyz;
    v_localPos = pos;
    v_localNormal = a_normal;
    v_objectScale = vec3(length(model[0].xyz), length(model[1].xyz), length(model[2].xyz));

    // Normal matrix — compute from model matrix for instanced draws
    if (u_use_instancing == 1) {
        v_normal = mat3(transpose(inverse(model))) * a_normal;
    } else {
        bool isZero = (u_normal_matrix[0] == vec3(0.0) &&
                       u_normal_matrix[1] == vec3(0.0) &&
                       u_normal_matrix[2] == vec3(0.0));
        if (isZero) {
            v_normal = mat3(transpose(inverse(model))) * a_normal;
        } else {
            v_normal = u_normal_matrix * a_normal;
        }
    }

    v_uv = a_uv;
    gl_Position = u_projection * u_view * worldPos;
}
