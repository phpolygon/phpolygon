#version 410 core
// Minimap (shader plan 8.1): the world from above, orthographic, for a map texture.
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
uniform int  u_use_instancing;

out vec3 v_normal;
out float v_height;

void main() {
    mat4 model = u_model;
    if (u_use_instancing == 1) {
        model = mat4(a_instance_col0, a_instance_col1, a_instance_col2, a_instance_col3);
    }
    model[0].w = 0.0;
    model[1].w = 0.0;
    model[2].w = 0.0;
    vec4 world = model * vec4(a_position, 1.0);
    v_normal = normalize(mat3(model) * a_normal);
    v_height = world.y;
    gl_Position = u_projection * u_view * world;
}
