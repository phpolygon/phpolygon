#version 150 core
in vec3 a_position;
in vec3 a_normal;
in vec2 a_uv;

in vec4 a_instance_model_col0;
in vec4 a_instance_model_col1;
in vec4 a_instance_model_col2;
in vec4 a_instance_model_col3;

uniform mat4 u_model;
uniform mat4 u_view;
uniform mat4 u_projection;
uniform int  u_use_instancing;

void main() {
    mat4 model;
    if (u_use_instancing == 1) {
        model = mat4(a_instance_model_col0, a_instance_model_col1,
                     a_instance_model_col2, a_instance_model_col3);
    } else {
        model = u_model;
    }

    gl_Position = u_projection * u_view * model * vec4(a_position, 1.0);
}
