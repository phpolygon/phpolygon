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

    /**
     * @implements \ArrayAccess<int, float>
     */
    class FloatBuffer implements \ArrayAccess, \Countable, BufferInterface {
        /** @param array<float|int> $data */
        public function __construct(array $data = []) {}
        public function offsetExists(mixed $offset): bool {}
        public function offsetGet(mixed $offset): float {}
        public function offsetSet(mixed $offset, mixed $value): void {}
        public function offsetUnset(mixed $offset): void {}
        public function count(): int {}
        public function size(): int {}
        /** @param array<float|int> $data */
        public function push(array $data): void {}
        public function clear(): void {}
        public function reserve(int $count): void {}
    }
}

namespace {
    function glGenTextures(int $n, int &$textures): void {}
    function glDeleteTextures(int $n, int $textures): void {}
    function glGenFramebuffers(int $n, int &$framebuffers): void {}
    function glDeleteFramebuffers(int $n, int $framebuffers): void {}
    function glGenRenderbuffers(int $n, int &$renderbuffers): void {}
    function glDeleteRenderbuffers(int $n, int $renderbuffers): void {}
    function glRenderbufferStorage(int $target, int $internalformat, int $width, int $height): void {}
    function glRenderbufferStorageMultisample(int $target, int $samples, int $internalformat, int $width, int $height): void {}
    function glBindRenderbuffer(int $target, int $renderbuffer): void {}
    function glBindFramebuffer(int $target, int $framebuffer): void {}
    function glFramebufferTexture2D(int $target, int $attachment, int $textarget, int $texture, int $level): void {}
    function glFramebufferRenderbuffer(int $target, int $attachment, int $renderbuffertarget, int $renderbuffer): void {}
    function glCheckFramebufferStatus(int $target): int {}
    function glBlitFramebuffer(int $srcX0, int $srcY0, int $srcX1, int $srcY1, int $dstX0, int $dstY0, int $dstX1, int $dstY1, int $mask, int $filter): void {}
    function glDrawBuffer(int $buf): void {}
    function glDrawBuffers(int $n, \GL\Buffer\BufferInterface $bufs): void {}
    function glReadBuffer(int $src): void {}

    /**
     * @param int<0, max> $x
     * @param int<0, max> $y
     * @param positive-int $width
     * @param positive-int $height
     */
    function glReadPixels(int $x, int $y, int $width, int $height, int $format, int $type, \GL\Buffer\UByteBuffer $data): void {}
    function glTexImage2D(int $target, int $level, int $internalformat, int $width, int $height, int $border, int $format, int $type, \GL\Buffer\BufferInterface|null $data): void {}
    function glTexImage2DMultisample(int $target, int $samples, int $internalformat, int $width, int $height, bool $fixedsamplelocations): void {}
    function glBindTexture(int $target, int $texture): void {}
    function glTexParameteri(int $target, int $pname, int $param): void {}
    function glViewport(int $x, int $y, int $width, int $height): void {}
    function glClear(int $mask): void {}
    function glClearColor(float $r, float $g, float $b, float $a): void {}
    function glDisable(int $cap): void {}
    function glEnable(int $cap): void {}
    function glActiveTexture(int $texture): void {}
    function glUseProgram(int $program): void {}
    function glDrawArrays(int $mode, int $first, int $count): void {}
    function glBindVertexArray(int $array): void {}
    function glGenVertexArrays(int $n, int &$arrays): void {}
    function glDeleteVertexArrays(int $n, int $arrays): void {}
    function glGetUniformLocation(int $program, string $name): int {}
    function glUniform1i(int $location, int $v0): void {}
    function glUniform2f(int $location, float $v0, float $v1): void {}

    function glFinish(): void {}

    /** Returns the NSWindow pointer for a GLFW window (macOS only). */
    function glfwGetCocoaWindow(object $window): int {}

    const GL_RGBA = 0x1908;
    const GL_RGBA8 = 0x8058;
    const GL_UNSIGNED_BYTE = 0x1401;
    const GL_FRAMEBUFFER = 0x8D40;
    const GL_DRAW_FRAMEBUFFER = 0x8CA9;
    const GL_READ_FRAMEBUFFER = 0x8CA8;
    const GL_RENDERBUFFER = 0x8D41;
    const GL_COLOR_ATTACHMENT0 = 0x8CE0;
    const GL_DEPTH_ATTACHMENT = 0x8D00;
    const GL_DEPTH_COMPONENT24 = 0x81A6;
    const GL_FRAMEBUFFER_COMPLETE = 0x8CD5;
    const GL_TEXTURE_2D = 0x0DE1;
    const GL_TEXTURE_2D_MULTISAMPLE = 0x9100;
    const GL_TEXTURE_MIN_FILTER = 0x2801;
    const GL_TEXTURE_MAG_FILTER = 0x2800;
    const GL_TEXTURE_WRAP_S = 0x2802;
    const GL_TEXTURE_WRAP_T = 0x2803;
    const GL_LINEAR = 0x2601;
    const GL_NEAREST = 0x2600;
    const GL_CLAMP_TO_EDGE = 0x812F;
    const GL_COLOR_BUFFER_BIT = 0x4000;
    const GL_DEPTH_BUFFER_BIT = 0x100;
    const GL_DEPTH_TEST = 0x0B71;
    const GL_BLEND = 0x0BE2;
    const GL_CULL_FACE = 0x0B44;
    const GL_TRIANGLES = 4;
    const GL_TEXTURE0 = 0x84C0;
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
