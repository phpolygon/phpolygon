#version 410 core

// Visible spot-light beam (DrawLightCone): the unit cone mesh (apex at the origin,
// axis +Z, base at z = 1) placed by LightCones::modelMatrix(). The fragment stage
// works on the world position only; normal and uv stay declared so the input layout
// matches the engine's mesh layout on every backend.

layout(location = 0) in vec3 a_position;
layout(location = 1) in vec3 a_normal;
layout(location = 2) in vec2 a_uv;

uniform mat4 u_model;
uniform mat4 u_view;
uniform mat4 u_projection;

out vec3 v_world_pos;

void main() {
    vec4 world = u_model * vec4(a_position, 1.0);
    v_world_pos = world.xyz;
    gl_Position = u_projection * u_view * world;
}
