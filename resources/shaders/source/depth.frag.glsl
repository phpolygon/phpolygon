#version 410 core

uniform float u_fog_near;
uniform float u_fog_far;

out vec4 frag_color;

void main() {
    // Linearize depth from [0,1] to [near,far] range, then normalize to [0,1]
    float z = gl_FragCoord.z;
    float near = max(u_fog_near, 0.1);
    float far = max(u_fog_far, near + 1.0);
    float linearDepth = (2.0 * near * far) / (far + near - z * (far - near));
    float normalized = (linearDepth - near) / (far - near);

    vec3 color = vec3(1.0 - clamp(normalized, 0.0, 1.0));
    frag_color = vec4(color, 1.0);
}
