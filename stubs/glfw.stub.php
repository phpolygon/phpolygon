<?php

/**
 * PHPStan stubs for ext-glfw (php-glfw).
 */

namespace {
    function glGenTextures(int $n, int &$textures): void {}
    function glGenFramebuffers(int $n, int &$framebuffers): void {}
    function glGenRenderbuffers(int $n, int &$renderbuffers): void {}

    /** Returns the NSWindow pointer for a GLFW window (macOS only). */
    function glfwGetCocoaWindow(object $window): int {}
}

namespace GL\Math {
    class Vec4 {
        public float $x;
        public float $y;
        public float $z;
        public float $w;
        public function __construct(float $x = 0.0, float $y = 0.0, float $z = 0.0, float $w = 0.0) {}
    }
}

namespace GL\VectorGraphics {
    class VGContext {
        public function textBounds(float $x, float $y, string $text, \GL\Math\Vec4 &$bounds): float {}
        public function textBoxBounds(float $x, float $y, float $breakWidth, string $text, \GL\Math\Vec4 &$bounds): void {}
    }
}
