#version 410 core

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

// Foliage sway in the shadow (shader plan 6.1): the same trunk-and-crown bend as
// mesh3d.vert's foliageSway (without leaf flutter), so a tree's shadow moves with
// the tree. The fade uses the player camera, not the light view.
uniform int   u_foliage_wind;
uniform float u_time;
uniform vec3  u_wind_direction;
uniform float u_wind_intensity;
uniform float u_foliage_motion;
uniform vec2  u_foliage_fade;
uniform vec3  u_shadow_camera_pos;

vec3 foliageSway(vec3 pos, mat4 model) {
    vec3 origin = model[3].xyz;
    float fade = 1.0 - smoothstep(u_foliage_fade.x, u_foliage_fade.y, length(origin - u_shadow_camera_pos));
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
    float lean = 0.65 + 0.35 * sin(u_time * 0.9 + phase);
    float osc = sin(u_time * 1.7 + phase * 1.3) * 0.35 + sin(u_time * 2.9 + phase * 0.7) * 0.15;
    vec3 d = wind * (0.0009 * h * h * amp * (lean + osc));
    return pos + (transpose(mat3(model)) * d) / s2;
}

void main() {
    mat4 model = u_model;
    if (u_use_instancing == 1) {
        model = mat4(a_instance_col0, a_instance_col1, a_instance_col2, a_instance_col3);
    }
#ifdef PHPOLYGON_INSTANCE_PARAMS
    model[0].w = 0.0;
    model[1].w = 0.0;
    model[2].w = 0.0;
#endif // PHPOLYGON_INSTANCE_PARAMS
    vec3 pos = a_position;
    if (u_foliage_wind == 1) {
        pos = foliageSway(pos, model);
    }
    gl_Position = u_projection * u_view * model * vec4(pos, 1.0);
}
