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
uniform mat3 u_normal_matrix;
uniform int  u_use_instancing;

// inverse()/transpose() are GLSL 1.40+. The instanced branch that needs them
// is dead at runtime on a 1.30 (GL 3.0) context (instancing degrades to the
// CPU path, u_use_instancing == 0), but the expression must still compile.
#if __VERSION__ >= 140
#define NORMAL_MATRIX(m) mat3(transpose(inverse(m)))
#else
#define NORMAL_MATRIX(m) mat3(m)
#endif

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

    gl_Position = u_projection * u_view * model * vec4(a_position, 1.0);
}
