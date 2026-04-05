#version 410 core

layout(location = 0) in vec3 a_position;
layout(location = 1) in vec3 a_normal;
layout(location = 2) in vec2 a_uv;

layout(location = 3) in vec4 a_instance_model_col0;
layout(location = 4) in vec4 a_instance_model_col1;
layout(location = 5) in vec4 a_instance_model_col2;
layout(location = 6) in vec4 a_instance_model_col3;

uniform mat4 u_model;
uniform mat4 u_view;
uniform mat4 u_projection;
uniform mat3 u_normal_matrix;
uniform int  u_use_instancing;

out vec3 v_normal;

void main() {
    mat4 model;
    if (u_use_instancing == 1) {
        model = mat4(a_instance_model_col0, a_instance_model_col1,
                     a_instance_model_col2, a_instance_model_col3);
    } else {
        model = u_model;
    }

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

    gl_Position = u_projection * u_view * model * vec4(a_position, 1.0);
}
