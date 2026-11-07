#version 410 core
// Froxel fog 2/3: for every froxel the light scattered towards the camera along the
// view ray up to the far edge of its slice, and how much of what lies behind still
// gets through - summed slice by slice from the camera out of the injected atlas.
in vec2 v_uv;
uniform sampler2D u_froxel_inject;
uniform float u_froxel_far;
out vec4 frag_color;

const float TILES = 8.0;
const float SLICES = 64.0;

void main() {
    vec2 cell = v_uv * TILES;
    vec2 tile = floor(cell);
    vec2 fxy = fract(cell);
    int last = int(tile.y * TILES + tile.x);
    vec3 scatter = vec3(0.0);
    float transmittance = 1.0;
    for (int i = 0; i < 64; i++) {
        if (i > last) break;
        vec2 at = (vec2(mod(float(i), TILES), floor(float(i) / TILES)) + fxy) / TILES;
        vec4 s = texture(u_froxel_inject, at);
        float a = float(i) / SLICES;
        float b = float(i + 1) / SLICES;
        float dz = u_froxel_far * (b * b - a * a);
        scatter += s.rgb * transmittance * dz;
        transmittance *= exp(-s.a * dz);
    }
    frag_color = vec4(scatter, transmittance);
}
