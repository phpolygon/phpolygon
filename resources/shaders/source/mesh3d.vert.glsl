#version 410 core

layout(location = 0) in vec3 a_position;
layout(location = 1) in vec3 a_normal;
layout(location = 2) in vec2 a_uv;

uniform mat4 u_model;
uniform mat4 u_view;
uniform mat4 u_projection;
uniform mat3 u_normal_matrix; // precomputed transpose(inverse(u_model))

// Optional: time for GPU-side vertex animation (waves, palm sway)
// If not set by engine, defaults to 0.0 and has no effect
uniform float u_time;
uniform int   u_vertex_anim; // 0 = none, 1 = wave
uniform float u_wave_amplitude;
uniform float u_wave_frequency;
uniform float u_wave_phase;

out vec3 v_normal;
out vec3 v_worldPos;
out vec2 v_uv;

void main() {
    vec3 pos = a_position;

    // --- Optional GPU wave animation ---
    // Displaces the vertex in Y using a sine wave based on world X position and time.
    // Activate by setting u_vertex_anim = 1 on water-plane materials.
    // This runs in object space before the model transform so the wave shape
    // is consistent regardless of object position.
    if (u_vertex_anim == 1) {
        vec4 worldPosRaw = u_model * vec4(pos, 1.0);
        float wave = sin(worldPosRaw.x * u_wave_frequency + u_time + u_wave_phase)
                   * cos(worldPosRaw.z * u_wave_frequency * 0.7 + u_time * 0.8)
                   * u_wave_amplitude;
        pos.y += wave;
    }

    vec4 worldPos = u_model * vec4(pos, 1.0);
    v_worldPos = worldPos.xyz;

    // Use precomputed normal matrix when provided (faster), fall back to runtime inverse.
    // The engine can pass mat3(transpose(inverse(u_model))) as u_normal_matrix.
    // If u_normal_matrix is identity (default when not set), we compute it here.
    // To keep this shader valid when the uniform is not uploaded, we check
    // by comparing to identity — but for performance just always pass it from PHP.
    // u_normal_matrix defaults to mat3(0) when not uploaded by the engine.
    // Detect this by checking if it's zero and fall back to runtime computation.
    // Once the engine uploads it, the branch is never taken.
    bool isZero = (u_normal_matrix[0] == vec3(0.0) &&
                   u_normal_matrix[1] == vec3(0.0) &&
                   u_normal_matrix[2] == vec3(0.0));
    if (isZero) {
        v_normal = mat3(transpose(inverse(u_model))) * a_normal;
    } else {
        v_normal = u_normal_matrix * a_normal;
    }

    v_uv = a_uv;
    gl_Position = u_projection * u_view * worldPos;
}
