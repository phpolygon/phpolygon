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

    /**
     * @implements \ArrayAccess<int, int>
     */
    class IntBuffer implements \ArrayAccess, \Countable, BufferInterface {
        /** @param array<int> $data */
        public function __construct(array $data = []) {}
        public function offsetExists(mixed $offset): bool {}
        public function offsetGet(mixed $offset): int {}
        public function offsetSet(mixed $offset, mixed $value): void {}
        public function offsetUnset(mixed $offset): void {}
        public function count(): int {}
        public function size(): int {}
        /** @param array<int> $data */
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

    // ── Additional GL functions referenced by the 3D renderer / passes ──────
    function glBindBuffer(int $target, int $buffer): void {}
    // Out-param left untyped: callers defensively is_int()-check the handle, so a
    // precise &int would make those guards "always true" under PHPStan.
    function glGenBuffers(int $n, mixed &$buffers): void {}
    function glDeleteShader(int $shader): void {}
    function glBufferData(int $target, \GL\Buffer\BufferInterface $data, int $usage): void {}
    function glDrawElements(int $mode, int $count, int $type, int $offset): void {}
    function glDrawArraysInstanced(int $mode, int $first, int $count, int $instancecount): void {}
    function glBlendFunc(int $sfactor, int $dfactor): void {}
    function glDepthFunc(int $func): void {}
    function glDepthMask(bool $flag): void {}
    function glFrontFace(int $mode): void {}
    function glGetError(): int {}
    // Out-param left untyped: callers defensively is_int()-check the result, so a
    // precise &int would make those guards "always true" under PHPStan.
    function glGetIntegerv(int $pname, mixed &$data): void {}
    function glTexParameterf(int $target, int $pname, float $param): void {}
    function glTexParameterfv(int $target, int $pname, \GL\Buffer\FloatBuffer $params): void {}
    function glUniform1f(int $location, float $v0): void {}
    function glUniform3f(int $location, float $v0, float $v1, float $v2): void {}
    function glUniformMatrix4fv(int $location, bool $transpose, \GL\Buffer\FloatBuffer $value): void {}
    function glCreateShader(int $type): int {}
    function glShaderSource(int $shader, string $source): void {}
    function glCompileShader(int $shader): void {}
    function glGetShaderiv(int $shader, int $pname, int &$params): void {}
    function glGetShaderInfoLog(int $shader, int $maxLength): string {}
    function glCreateProgram(): int {}
    function glAttachShader(int $program, int $shader): void {}
    function glLinkProgram(int $program): void {}
    function glGetProgramiv(int $program, int $pname, int &$params): void {}
    function glGetProgramInfoLog(int $program, int $maxLength): string {}
    function glDeleteProgram(int $program): void {}
    function glEnableVertexAttribArray(int $index): void {}
    function glVertexAttribPointer(int $index, int $size, int $type, bool $normalized, int $stride, int $offset): void {}
    function glVertexAttribDivisor(int $index, int $divisor): void {}

    // ── GLFW windowing (php-glfw) ───────────────────────────────────────────
    function glfwInit(): bool {}
    function glfwTerminate(): void {}
    function glfwPollEvents(): void {}
    function glfwSwapInterval(int $interval): void {}
    function glfwWindowHint(int $hint, int $value): void {}
    function glfwCreateWindow(int $width, int $height, string $title, ?object $monitor = null, ?object $share = null): \GLFWwindow {}
    function glfwDestroyWindow(\GLFWwindow $window): void {}
    function glfwMakeContextCurrent(\GLFWwindow $window): void {}
    function glfwSwapBuffers(\GLFWwindow $window): void {}
    function glfwShowWindow(\GLFWwindow $window): void {}
    function glfwMaximizeWindow(\GLFWwindow $window): void {}
    function glfwRestoreWindow(\GLFWwindow $window): void {}
    function glfwWindowShouldClose(\GLFWwindow $window): int {}
    function glfwSetWindowShouldClose(\GLFWwindow $window, int $value): void {}
    function glfwSetWindowTitle(\GLFWwindow $window, string $title): void {}
    function glfwSetWindowSize(\GLFWwindow $window, int $width, int $height): void {}
    function glfwSetWindowPos(\GLFWwindow $window, int $xpos, int $ypos): void {}
    // Out-params left untyped: callers pre-seed the locals and defensively
    // is_int()/is_float()-check the results, so precise &int/&float types would
    // make those guards "always true" under PHPStan.
    function glfwGetWindowSize(\GLFWwindow $window, mixed &$width, mixed &$height): void {}
    function glfwGetWindowPos(\GLFWwindow $window, mixed &$xpos, mixed &$ypos): void {}
    function glfwGetFramebufferSize(\GLFWwindow $window, mixed &$width, mixed &$height): void {}
    function glfwGetWindowContentScale(\GLFWwindow $window, mixed &$xscale, mixed &$yscale): void {}
    function glfwSetWindowAttrib(\GLFWwindow $window, int $attrib, int $value): void {}
    function glfwSetInputMode(\GLFWwindow $window, int $mode, int $value): void {}
    function glfwSetWindowMonitor(\GLFWwindow $window, ?object $monitor, int $xpos, int $ypos, int $width, int $height, int $refreshRate): void {}
    function glfwGetPrimaryMonitor(): object {}
    function glfwGetVideoMode(object $monitor): object {}
    function glfwSetKeyCallback(\GLFWwindow $window, callable $callback): void {}
    function glfwSetCharCallback(\GLFWwindow $window, callable $callback): void {}
    function glfwSetMouseButtonCallback(\GLFWwindow $window, callable $callback): void {}
    function glfwSetCursorPosCallback(\GLFWwindow $window, callable $callback): void {}
    function glfwSetScrollCallback(\GLFWwindow $window, callable $callback): void {}

    // ── Additional GL constants ─────────────────────────────────────────────
    const GL_FALSE = 0;
    const GL_TRUE = 1;
    const GL_NONE = 0;
    const GL_ONE = 1;
    const GL_LESS = 0x0201;
    const GL_LEQUAL = 0x0203;
    const GL_CCW = 0x0901;
    const GL_FLOAT = 0x1406;
    const GL_UNSIGNED_INT = 0x1405;
    const GL_RED = 0x1903;
    const GL_RGB = 0x1907;
    const GL_R8 = 0x8229;
    const GL_DEPTH_COMPONENT = 0x1902;
    const GL_SRC_ALPHA = 0x0302;
    const GL_ONE_MINUS_SRC_ALPHA = 0x0303;
    const GL_MULTISAMPLE = 0x809D;
    const GL_STENCIL_BUFFER_BIT = 0x00000400;
    const GL_ARRAY_BUFFER = 0x8892;
    const GL_ELEMENT_ARRAY_BUFFER = 0x8893;
    const GL_DYNAMIC_DRAW = 0x88E8;
    const GL_STATIC_DRAW = 0x88E4;
    const GL_VERTEX_SHADER = 0x8B31;
    const GL_FRAGMENT_SHADER = 0x8B30;
    const GL_COMPILE_STATUS = 0x8B81;
    const GL_LINK_STATUS = 0x8B82;
    const GL_TEXTURE5 = 0x84C5;
    const GL_TEXTURE6 = 0x84C6;
    const GL_TEXTURE7 = 0x84C7;
    const GL_TEXTURE_WRAP_R = 0x8072;
    const GL_TEXTURE_BORDER_COLOR = 0x1004;
    const GL_CLAMP_TO_BORDER = 0x812D;
    const GL_TEXTURE_COMPARE_MODE = 0x884C;
    const GL_TEXTURE_COMPARE_FUNC = 0x884D;
    const GL_COMPARE_REF_TO_TEXTURE = 0x884E;
    const GL_TEXTURE_CUBE_MAP = 0x8513;
    const GL_TEXTURE_CUBE_MAP_POSITIVE_X = 0x8515;

    // ── GLFW constants ──────────────────────────────────────────────────────
    const GLFW_NO_API = 0;
    const GLFW_CLIENT_API = 0x00022001;
    const GLFW_CONTEXT_VERSION_MAJOR = 0x00022002;
    const GLFW_CONTEXT_VERSION_MINOR = 0x00022003;
    const GLFW_OPENGL_FORWARD_COMPAT = 0x00022006;
    const GLFW_OPENGL_PROFILE = 0x00022008;
    const GLFW_OPENGL_CORE_PROFILE = 0x00032001;
    const GLFW_RESIZABLE = 0x00020003;
    const GLFW_VISIBLE = 0x00020004;
    const GLFW_DECORATED = 0x00020005;
    const GLFW_SAMPLES = 0x0002100D;
    const GLFW_CURSOR = 0x00033001;
    const GLFW_CURSOR_NORMAL = 0x00034001;
    const GLFW_CURSOR_DISABLED = 0x00034003;
}

namespace {
    /** Opaque php-glfw window handle (GLFWwindow*). */
    class GLFWwindow {}
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

namespace GL\Texture {
    /** Decoded image, as loaded by the TextureManager for GL upload. */
    class Texture2D {
        public static function fromDisk(string $path, bool $flipVertically = false): self {}
        public function width(): int {}
        public function height(): int {}
        public function channels(): int {}
        public function buffer(): \GL\Buffer\UByteBuffer {}
    }
}

namespace GL\Audio {
    /** php-glfw bundled audio engine (miniaudio). */
    class Engine {
        /** @param array<mixed> $config */
        public function __construct(array $config = []) {}
        public function start(): void {}
        public function stop(): void {}
        public function setMasterVolume(float $volume): void {}
        public function soundFromDisk(string $path): Sound {}
    }

    class Sound {
        public function play(): void {}
        public function stop(): void {}
        public function setVolume(float $volume): void {}
        public function setLoop(bool $loop): void {}
        public function isPlaying(): bool {}
    }
}
