#version 410 core

// SSAO G-buffer vertex stage.
//
// Mirrors the geometry transform of mesh3d.vert.glsl (instancing + the same
// procedural water/cloth vertex animation) so the G-buffer's depth and normal
// line up EXACTLY with what the forward opaque pass rasterises — any mismatch
// (e.g. skipping the water wave) would make AO sample stale positions and
// produce haloing at animated surfaces. We only emit what the SSAO frag needs:
// the VIEW-space normal and the VIEW-space position (for linear depth).
layout(location = 0) in vec3 a_position;
layout(location = 1) in vec3 a_normal;
layout(location = 2) in vec2 a_uv;
layout(location = 3) in vec4 a_instance_col0;
layout(location = 4) in vec4 a_instance_col1;
layout(location = 5) in vec4 a_instance_col2;
layout(location = 6) in vec4 a_instance_col3;

uniform mat4 u_model;
uniform mat4 u_view;
uniform mat4 u_projection;
uniform mat3 u_normal_matrix;
uniform int  u_use_instancing;

// Vertex-animation uniforms (kept byte-for-byte identical to mesh3d.vert so
// animated water/cloth surfaces write the SAME deformed position here).
uniform float u_time;
uniform int   u_vertex_anim;
uniform float u_wave_amplitude;
uniform float u_wave_frequency;
uniform float u_wave_phase;

uniform int   u_cloth;
uniform float u_cloth_strength;
uniform float u_cloth_frequency;
uniform float u_cloth_phase;
uniform int   u_cloth_anchor_top;
uniform vec3  u_wind_direction;
uniform float u_wind_intensity;
uniform vec3  u_mesh_local_aabb_min;
uniform vec3  u_mesh_local_aabb_max;

out vec3 v_viewNormal; // surface normal in view space
out vec3 v_viewPos;    // fragment position in view space
out vec2 v_worldXZ;    // world-space XZ (for the ocean shoreline reflectivity fade)

void main() {
    mat4 model = u_model;
    if (u_use_instancing == 1) {
        model = mat4(a_instance_col0, a_instance_col1, a_instance_col2, a_instance_col3);
    }
    vec3 pos = a_position;

    // Water swell — identical math to mesh3d.vert.glsl.
    if (u_vertex_anim == 1) {
        vec4 worldPosRaw = model * vec4(pos, 1.0);
        float t = u_time + u_wave_phase;
        float f = u_wave_frequency;
        float a = u_wave_amplitude;
        float wave = sin(worldPosRaw.x * f * 0.15 + worldPosRaw.z * f * 0.08 + t * 0.7) * a * 0.6;
        wave += sin(worldPosRaw.x * f * 0.1 - worldPosRaw.z * f * 0.12 + t * 0.5 + 1.3) * a * 0.3;
        wave += sin(worldPosRaw.x * f * 0.5 + worldPosRaw.z * f * 0.3 + t * 1.4) * a * 0.08;
        wave += sin(worldPosRaw.x * f * 0.7 - worldPosRaw.z * f * 0.6 + t * 1.8 + 2.7) * a * 0.04;
        pos.y += wave;
    }

    // Procedural cloth sway — identical math to mesh3d.vert.glsl.
    if (u_cloth == 1) {
        float aabbHeight = max(u_mesh_local_aabb_max.y - u_mesh_local_aabb_min.y, 1e-4);
        float yNorm = clamp((pos.y - u_mesh_local_aabb_min.y) / aabbHeight, 0.0, 1.0);
        float anchorWeight = u_cloth_anchor_top == 1 ? yNorm : (1.0 - yNorm);
        float swayMask = 1.0 - anchorWeight;
        float ct = u_time * u_cloth_frequency + u_cloth_phase;
        float cwave = sin(ct + pos.x * 2.0) * 0.7 + cos(ct * 1.3 + pos.z * 1.5) * 0.3;
        vec3 windDir = length(u_wind_direction) > 1e-4 ? normalize(u_wind_direction) : vec3(0.0, 0.0, 1.0);
        vec3 sway = windDir * (cwave * u_cloth_strength * u_wind_intensity * swayMask);
        sway.y *= 0.15;
        pos += sway;
    }

    vec4 worldPos = model * vec4(pos, 1.0);
    vec4 viewPos  = u_view * worldPos;
    v_viewPos = viewPos.xyz;
    v_worldXZ = worldPos.xz;

    // World normal, then rotate into view space. We rebuild the world normal the
    // same way mesh3d.vert does (per-instance vs precomputed matrix) so flat /
    // instanced geometry agree, then apply the view rotation (mat3 of u_view).
    vec3 worldNormal;
    if (u_use_instancing == 1) {
        worldNormal = mat3(transpose(inverse(model))) * a_normal;
    } else {
        bool isZero = (u_normal_matrix[0] == vec3(0.0) &&
                       u_normal_matrix[1] == vec3(0.0) &&
                       u_normal_matrix[2] == vec3(0.0));
        worldNormal = isZero ? (mat3(transpose(inverse(model))) * a_normal)
                             : (u_normal_matrix * a_normal);
    }
    v_viewNormal = mat3(u_view) * worldNormal;

    gl_Position = u_projection * viewPos;
}
