#version 410 core
// Minimap (shader plan 8.1): flat material colour, shaded by slope and height so
// roofs, slopes and terraces read from above; water keeps its colour.
in vec3 v_normal;
in float v_height;
uniform vec3  u_albedo;
uniform vec2  u_height_range;   // y of the lowest and highest ground shown
out vec4 frag_color;

void main() {
    vec3 n = normalize(v_normal);
    float slope = 0.55 + 0.45 * clamp(abs(n.y), 0.0, 1.0);
    float side = 0.85 + 0.15 * clamp(dot(n, normalize(vec3(-0.5, 1.0, -0.3))), 0.0, 1.0);
    float h = clamp((v_height - u_height_range.x) / max(u_height_range.y - u_height_range.x, 0.01), 0.0, 1.0);
    vec3 c = pow(max(u_albedo, vec3(0.0)), vec3(1.0 / 2.2));
    c *= slope * side * mix(0.85, 1.15, h);
    frag_color = vec4(c, 1.0);
}
