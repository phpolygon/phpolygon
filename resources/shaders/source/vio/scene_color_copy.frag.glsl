#version 410 core
// Copy of the opaque scene colour for refracting glass (Material::$refraction): the
// sum of the MRT light parts (sun, local, ambient) or the single scene colour.
// The transparent pass draws into the scene target while it samples this copy.
in vec2 v_uv;
uniform sampler2D u_part0;
uniform sampler2D u_part1;
uniform sampler2D u_part2;
uniform float u_parts;   // 1: one colour target, 3: the MRT light parts
out vec4 frag_color;

void main() {
    vec3 c = texture(u_part0, v_uv).rgb;
    if (u_parts > 1.5) {
        c += texture(u_part1, v_uv).rgb + texture(u_part2, v_uv).rgb;
    }
    frag_color = vec4(c, 1.0);
}
