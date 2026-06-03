#version 410 core

layout(location = 0) in vec3 a_position;
layout(location = 1) in vec2 a_uv;

// Tile transform applied to the fullscreen quad so each cascade lands in its
// own screen-space tile WITHOUT relying on per-draw viewport state (vio may
// defer viewport changes to flush time, which would collapse all tiles onto
// the last one). xy = NDC scale, zw = NDC offset of the tile centre.
uniform vec4 u_tile;

out vec2 v_uv;

void main() {
    v_uv = a_uv;
    vec2 ndc = a_position.xy * u_tile.xy + u_tile.zw;
    gl_Position = vec4(ndc, 0.0, 1.0);
}
