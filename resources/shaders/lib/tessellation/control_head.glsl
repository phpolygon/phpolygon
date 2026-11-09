
float tessEdgeLevel(vec3 a, vec3 b, vec3 eye) {
    float d = distance((a + b) * 0.5, eye);
    float t = clamp((d - u_tess.y) / max(u_tess.z - u_tess.y, 1e-3), 0.0, 1.0);
    return max(1.0, mix(u_tess.x, 1.0, t));
}

void main() {
