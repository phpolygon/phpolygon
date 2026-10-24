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
uniform mat3 u_normal_matrix;
uniform int  u_use_instancing;

uniform float u_time;
uniform int   u_vertex_anim;
uniform float u_wave_amplitude;
uniform float u_wave_frequency;
uniform float u_wave_phase;

// Procedural cloth (mirrors mesh3d.vert.glsl). See Material::cloth()
// and Command\SetWind for the engine-side surface.
uniform int   u_cloth;
uniform float u_cloth_strength;
uniform float u_cloth_frequency;
uniform float u_cloth_phase;
uniform int   u_cloth_anchor_top;
uniform vec3  u_wind_direction;
uniform float u_wind_intensity;
uniform vec3  u_mesh_local_aabb_min;
uniform vec3  u_mesh_local_aabb_max;

// Foliage wind (Material::$foliageWind / $windFlutter, SetWind foliage fields).
// Kept identical in gbuffer.vert so SSAO sees the swayed surface.
uniform int   u_foliage_wind;
uniform float u_wind_flutter;
uniform float u_foliage_motion;   // global scale: 1 normal, 0 still (reduced motion)
uniform vec2  u_foliage_fade;     // camera distance where the sway fades out (start, end)

uniform mat4 u_light_space_matrix;

out vec3 v_normal;
out vec3 v_worldPos;
out vec2 v_uv;
out vec4 v_lightSpacePos;
out vec3 v_localPos;
out vec3 v_localNormal;
out vec3 v_objectScale;

#ifdef PHPOLYGON_MOTION
// Motion vectors (temporal AA / upscaling). Both clip positions are UNJITTERED:
// the surface point projected with this frame's and with last frame's camera,
// moved by last frame's model matrix (u_has_prev; non-instanced draws only) and
// animated at last frame's clock. The fragment stage turns them into a texture-
// space offset. Both go through the same expression, so a static surface under
// a still camera has exactly zero motion.
uniform mat4  u_curr_view_proj;
uniform mat4  u_prev_view_proj;
uniform mat4  u_prev_model;
uniform int   u_has_prev;
uniform float u_time_prev;
out vec4 v_currClip;
out vec4 v_prevClip;
#endif

// Foliage sway of an object-space vertex. The bend grows with the square of
// the height above the plant's foot (0.09 m at 10 m per unit wind), so bark
// and foliage of one plant move together and a trunk foot never slides; leaf
// flutter adds motion toward the mesh's outer edge. The phase comes from the
// instance origin (model[3]), so neighbours never sway in lockstep, and the
// sway fades out with the camera distance.
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
        vec3 f = vec3(sin(time * 4.1 + dot(pos, vec3(1.7, 0.9, 1.3)) + phase),
                      sin(time * 5.3 + dot(pos, vec3(0.8, 1.9, 1.1)) + phase) * 0.6,
                      cos(time * 3.7 + dot(pos, vec3(1.2, 0.6, 1.9)) + phase));
        d += f * (u_wind_flutter * r * amp);
    }
    // World offset back into object space (rotation + uniform scale).
    return pos + (transpose(mat3(model)) * d) / s2;
}

// Wave + cloth + foliage displacement of an object-space vertex at a given clock.
vec3 animatePosition(vec3 pos, mat4 model, float time) {
    if (u_vertex_anim == 1) {
        vec4 worldPosRaw = model * vec4(pos, 1.0);
        float t = time + u_wave_phase;
        float f = u_wave_frequency;
        float a = u_wave_amplitude;
        // Long rolling swell (dominant wave direction)
        float wave = sin(worldPosRaw.x * f * 0.15 + worldPosRaw.z * f * 0.08 + t * 0.7) * a * 0.6;
        // Secondary cross-swell
        wave += sin(worldPosRaw.x * f * 0.1 - worldPosRaw.z * f * 0.12 + t * 0.5 + 1.3) * a * 0.3;
        // Short choppy waves on top (smaller amplitude, higher frequency)
        wave += sin(worldPosRaw.x * f * 0.5 + worldPosRaw.z * f * 0.3 + t * 1.4) * a * 0.08;
        wave += sin(worldPosRaw.x * f * 0.7 - worldPosRaw.z * f * 0.6 + t * 1.8 + 2.7) * a * 0.04;
        pos.y += wave;
    }

    // Procedural cloth sway (mirrors mesh3d.vert.glsl)
    if (u_cloth == 1) {
        float aabbHeight = max(u_mesh_local_aabb_max.y - u_mesh_local_aabb_min.y, 1e-4);
        float yNorm = clamp((pos.y - u_mesh_local_aabb_min.y) / aabbHeight, 0.0, 1.0);
        float anchorWeight = u_cloth_anchor_top == 1 ? yNorm : (1.0 - yNorm);
        float swayMask = 1.0 - anchorWeight;
        float ct = time * u_cloth_frequency + u_cloth_phase;
        float cwave = sin(ct + pos.x * 2.0) * 0.7 + cos(ct * 1.3 + pos.z * 1.5) * 0.3;
        vec3 windDir = length(u_wind_direction) > 1e-4 ? normalize(u_wind_direction) : vec3(0.0, 0.0, 1.0);
        vec3 sway = windDir * (cwave * u_cloth_strength * u_wind_intensity * swayMask);
        sway.y *= 0.15;
        pos += sway;
    }

    if (u_foliage_wind == 1) {
        pos = foliageSway(pos, model, time);
    }
    return pos;
}

void main() {
    mat4 model = u_model;
    if (u_use_instancing == 1) {
        model = mat4(a_instance_col0, a_instance_col1, a_instance_col2, a_instance_col3);
    }
    vec3 pos = animatePosition(a_position, model, u_time);

    vec4 worldPos = model * vec4(pos, 1.0);
    v_worldPos = worldPos.xyz;
    v_localPos = pos;
    v_localNormal = a_normal;
    v_objectScale = vec3(length(model[0].xyz), length(model[1].xyz), length(model[2].xyz));

    if (u_use_instancing == 1) {
        // Per-instance model: always compute normal matrix from instance transform
        v_normal = mat3(transpose(inverse(model))) * a_normal;
    } else {
        // Per-object: use precomputed normal matrix, fall back to model if zero
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
    v_lightSpacePos = u_light_space_matrix * worldPos;
    gl_Position = u_projection * u_view * worldPos;

#ifdef PHPOLYGON_MOTION
    mat4 prevModel = (u_has_prev == 1 && u_use_instancing == 0) ? u_prev_model : model;
    vec3 prevPos = animatePosition(a_position, prevModel, u_time_prev);
    v_currClip = u_curr_view_proj * (model * vec4(pos, 1.0));
    v_prevClip = u_prev_view_proj * (prevModel * vec4(prevPos, 1.0));
#endif
}
