<?php

/**
 * PHPStan stubs for ext-glfw (php-glfw).
 *
 * After the switch to php-vio as the primary dependency, ext-glfw is no longer
 * installed in CI. These stubs declare all symbols that the OpenGL-based
 * Renderer2D and MetalRenderer3D still reference so that PHPStan can analyse
 * them without the extension present.
 */

namespace GL\Buffer {
    interface BufferInterface {}

    /**
     * @implements \ArrayAccess<int, int>
     */
    class UByteBuffer implements \ArrayAccess, \Countable, BufferInterface {
        /** @param array<int> $data */
        public function __construct(array $data) {}
        public function offsetExists(mixed $offset): bool {}
        public function offsetGet(mixed $offset): int {}
        public function offsetSet(mixed $offset, mixed $value): void {}
        public function offsetUnset(mixed $offset): void {}
        public function count(): int {}
    }
}

namespace {
    function glGenTextures(int $n, int &$textures): void {}
    function glGenFramebuffers(int $n, int &$framebuffers): void {}
    function glGenRenderbuffers(int $n, int &$renderbuffers): void {}

    /**
     * @param int<0, max> $x
     * @param int<0, max> $y
     * @param positive-int $width
     * @param positive-int $height
     */
    function glReadPixels(int $x, int $y, int $width, int $height, int $format, int $type, \GL\Buffer\UByteBuffer $data): void {}
    function glTexImage2D(int $target, int $level, int $internalformat, int $width, int $height, int $border, int $format, int $type, \GL\Buffer\BufferInterface|null $data): void {}

    function glFinish(): void {}

    /** Returns the NSWindow pointer for a GLFW window (macOS only). */
    function glfwGetCocoaWindow(object $window): int {}

    const GL_RGBA = 0x1908;
    const GL_UNSIGNED_BYTE = 0x1401;
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
    class VGColor {
        public function __construct(float $r, float $g, float $b, float $a) {}
    }

    class VGPaint {}

    class VGImage {
        public function makePaint(float $ox, float $oy, float $ex, float $ey, float $angle, float $alpha): VGPaint {}
    }

    class VGAlign {
        public const LEFT   = 1;
        public const CENTER = 2;
        public const RIGHT  = 4;
        public const TOP    = 8;
        public const MIDDLE = 16;
        public const BOTTOM = 32;
    }

    class VGContext {
        public const ANTIALIAS       = 1;
        public const STENCIL_STROKES = 2;

        public function __construct(int $flags = 0) {}

        // Frame lifecycle
        public function beginFrame(float $windowWidth, float $windowHeight, float $devicePixelRatio): void {}
        public function endFrame(): void {}

        // Path
        public function beginPath(): void {}
        public function rect(float $x, float $y, float $w, float $h): void {}
        public function roundedRect(float $x, float $y, float $w, float $h, float $r): void {}
        public function circle(float $cx, float $cy, float $r): void {}
        public function arc(float $cx, float $cy, float $r, float $a0, float $a1, int $dir): void {}
        public function moveTo(float $x, float $y): void {}
        public function lineTo(float $x, float $y): void {}

        // Fill & stroke
        public function fill(): void {}
        public function stroke(): void {}
        public function fillColor(VGColor $color): void {}
        public function strokeColor(VGColor $color): void {}
        public function fillPaint(VGPaint $paint): void {}
        public function strokeWidth(float $width): void {}

        // Text
        public function fontFace(string $font): void {}
        public function fontSize(float $size): void {}
        public function textAlign(int $align): void {}
        public function text(float $x, float $y, string $string): float { return 0.0; }
        public function textBox(float $x, float $y, float $breakRowWidth, string $string): void {}
        public function textBounds(float $x, float $y, string $string, \GL\Math\Vec4 &$bounds): float { return 0.0; }
        public function textBoxBounds(float $x, float $y, float $breakWidth, string $string, \GL\Math\Vec4 &$bounds): void {}
        public function createFont(string $name, string $filename): int { return 0; }
        public function addFallbackFont(string $baseFont, string $fallbackFont): void {}

        // Transform & state
        public function save(): void {}
        public function restore(): void {}
        public function transform(float $a, float $b, float $c, float $d, float $e, float $f): void {}
        public function scissor(float $x, float $y, float $w, float $h): void {}
        public function resetScissor(): void {}
        public function globalAlpha(float $alpha): void {}

        // Images
        public function imageFromHandle(int $textureId, int $w, int $h, int $imageFlags, int $flags): VGImage {}
    }
}
