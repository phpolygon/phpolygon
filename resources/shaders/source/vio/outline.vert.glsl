#version 410 core

// Outline pass (DrawOutline). With u_outline_width_px == 0 the mesh is drawn
// unchanged - that is the stencil mask. Otherwise every vertex is pushed away
// from the projected centre of the mesh's local AABB by a constant number of
// pixels, so the ring keeps its width at any distance. The AABB centre is used
// instead of the vertex normal: boxes carry split normals per face, and
// extruding along them tears the silhouette open at every corner.

layout(location = 0) in vec3 a_position;
layout(location = 1) in vec3 a_normal;
layout(location = 2) in vec2 a_uv;

uniform mat4 u_model;
uniform mat4 u_view;
uniform mat4 u_projection;
uniform vec3 u_mesh_local_aabb_min;
uniform vec3 u_mesh_local_aabb_max;
uniform vec2 u_viewport_size;
uniform float u_outline_width_px;

void main() {
    mat4 mvp = u_projection * u_view * u_model;
    vec4 clip = mvp * vec4(a_position, 1.0);

    if (u_outline_width_px > 0.0 && clip.w > 1e-5) {
        vec3 centre = 0.5 * (u_mesh_local_aabb_min + u_mesh_local_aabb_max);
        vec4 centreClip = mvp * vec4(centre, 1.0);
        if (centreClip.w > 1e-5) {
            vec2 toVertexPx = (clip.xy / clip.w - centreClip.xy / centreClip.w) * u_viewport_size;
            float lenPx = length(toVertexPx);
            if (lenPx > 1e-4) {
                vec2 offsetNdc = (toVertexPx / lenPx) * u_outline_width_px * 2.0 / u_viewport_size;
                clip.xy += offsetNdc * clip.w;
            }
        }
    }

    gl_Position = clip;
}
