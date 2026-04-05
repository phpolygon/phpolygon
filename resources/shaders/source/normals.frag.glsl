#version 410 core

in vec3 v_normal;

out vec4 frag_color;

void main() {
    // Map normal components from [-1,1] to [0,1] for visualization
    vec3 color = normalize(v_normal) * 0.5 + 0.5;
    frag_color = vec4(color, 1.0);
}
