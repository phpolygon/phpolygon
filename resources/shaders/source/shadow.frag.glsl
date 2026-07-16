#version 150 core
// Depth-only shadow pass: no color output needed.
// The GPU writes depth automatically; this shader exists only because
// OpenGL 4.1 core profile requires a fragment shader to be attached.

void main() {
    // intentionally empty — depth is written by the fixed-function pipeline
}
