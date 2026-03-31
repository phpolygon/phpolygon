<?php

/**
 * PHPStan stubs for ext-metal (php-metal).
 * Native Apple Metal GPU API for PHP — macOS/Apple Silicon only.
 * Namespace: Mt\ (mirrors Vk\ convention from php-vulkan)
 */

namespace Mt;

/**
 * Wraps id<MTLDevice> — the GPU. Entry point for all Metal objects.
 * Obtain via Device::createSystemDefault().
 */
class Device
{
    /** Returns the default Metal GPU on this machine. */
    public static function createSystemDefault(): self {}

    /** @return array{name: string, hasUnifiedMemory: bool, maxThreadsPerThreadgroup: array{width:int,height:int,depth:int}} */
    public function getProperties(): array { return []; }

    public function newCommandQueue(): CommandQueue {}
    public function newBuffer(int $length, int $options = 0): Buffer {}
    public function newBufferWithBytes(string $bytes, int $length, int $options = 0): Buffer {}
    public function newLibraryWithSource(string $mslSource): Library {}
    public function newLibraryWithFile(string $airFilePath): Library {}
    public function newRenderPipelineState(RenderPipelineDescriptor $descriptor): RenderPipelineState {}
    public function newDepthStencilState(DepthStencilDescriptor $descriptor): DepthStencilState {}
    public function newTexture(TextureDescriptor $descriptor): Texture {}
}

/**
 * Wraps CAMetalLayer — the drawable surface attached to a GLFW NSView.
 * Must be attached to the window before the first frame.
 */
class Layer
{
    /**
     * Attaches a CAMetalLayer to the NSView of a GLFW window.
     * Call once after glfwCreateWindow(), before the render loop.
     *
     * @param \GLFWwindow $windowHandle  Result of glfwCreateWindow()
     * @param Device      $device        Metal device to bind the layer to
     * @param int         $pixelFormat   Mt\PixelFormat::BGRA8Unorm (default)
     */
    public function __construct(\GLFWwindow $windowHandle, Device $device, int $pixelFormat = 80) {}

    /**
     * Returns the next drawable from the layer.
     * Blocks until a drawable is available (respects vsync).
     */
    public function nextDrawable(): Drawable {}

    public function setDrawableSize(int $width, int $height): void {}
    public function getDrawableSize(): array { return ['width' => 0, 'height' => 0]; }
}

/**
 * Wraps id<CAMetalDrawable> — one swapchain image for the current frame.
 */
class Drawable
{
    public function getTexture(): Texture {}
}

/**
 * Wraps id<MTLCommandQueue> — serialized queue of command buffers sent to GPU.
 */
class CommandQueue
{
    public function commandBuffer(): CommandBuffer {}
}

/**
 * Wraps id<MTLCommandBuffer> — one frame's worth of GPU commands.
 */
class CommandBuffer
{
    public function renderCommandEncoder(RenderPassDescriptor $descriptor): RenderCommandEncoder {}
    public function commit(): void {}
    public function presentDrawable(Drawable $drawable): void {}
    /** Blocks CPU until this command buffer has finished executing on GPU. */
    public function waitUntilCompleted(): void {}
}

/**
 * Wraps id<MTLRenderCommandEncoder> — encodes draw calls into the command buffer.
 */
class RenderCommandEncoder
{
    public function setRenderPipelineState(RenderPipelineState $state): void {}
    public function setDepthStencilState(DepthStencilState $state): void {}

    /** @param int $index Vertex buffer index (matches [[buffer(N)]] in MSL) */
    public function setVertexBuffer(Buffer $buffer, int $offset, int $index): void {}
    /** @param int $index Fragment buffer index */
    public function setFragmentBuffer(Buffer $buffer, int $offset, int $index): void {}

    public function setVertexBytes(string $bytes, int $length, int $index): void {}
    public function setFragmentBytes(string $bytes, int $length, int $index): void {}

    public function setFragmentTexture(Texture $texture, int $index): void {}

    public function setCullMode(int $cullMode): void {}
    public function setFrontFacingWinding(int $winding): void {}
    public function setViewport(float $originX, float $originY, float $width, float $height, float $znear, float $zfar): void {}
    public function setScissorRect(int $x, int $y, int $width, int $height): void {}

    /**
     * Indexed draw call (equivalent to glDrawElements).
     * @param int $primitiveType  Mt\PrimitiveType::Triangle
     * @param int $indexType      Mt\IndexType::UInt32
     */
    public function drawIndexedPrimitives(
        int $primitiveType,
        int $indexCount,
        int $indexType,
        Buffer $indexBuffer,
        int $indexBufferOffset = 0,
        int $instanceCount = 1,
    ): void {}

    public function endEncoding(): void {}
}

/**
 * Wraps id<MTLBuffer> — GPU memory for vertices, indices, uniforms.
 */
class Buffer
{
    /** Write raw bytes into the buffer (only for shared/managed storage mode). */
    public function write(string $bytes, int $offset = 0): void {}
    public function getLength(): int { return 0; }
}

/**
 * Wraps MTLRenderPassDescriptor — describes attachments (color, depth) for one render pass.
 */
class RenderPassDescriptor
{
    public function __construct() {}

    /** Set the color attachment from a drawable texture. */
    public function setColorAttachment(
        int $index,
        Texture $texture,
        int $loadAction = 2,   // Mt\LoadAction::Clear
        int $storeAction = 1,  // Mt\StoreAction::Store
        float $clearR = 0.0,
        float $clearG = 0.0,
        float $clearB = 0.0,
        float $clearA = 1.0,
    ): void {}

    public function setDepthAttachment(
        Texture $texture,
        int $loadAction = 2,   // Mt\LoadAction::Clear
        int $storeAction = 1,  // Mt\StoreAction::Store
        float $clearDepth = 1.0,
    ): void {}
}

/**
 * Wraps MTLRenderPipelineDescriptor — describes vertex layout, shaders, pixel format.
 */
class RenderPipelineDescriptor
{
    public function __construct() {}
    public function setVertexFunction(ShaderFunction $function): void {}
    public function setFragmentFunction(ShaderFunction $function): void {}
    public function setColorAttachmentPixelFormat(int $index, int $pixelFormat): void {}
    public function setDepthAttachmentPixelFormat(int $pixelFormat): void {}
    public function setVertexDescriptor(VertexDescriptor $descriptor): void {}
}

/**
 * Wraps MTLVertexDescriptor — describes vertex buffer layout (stride, attributes).
 */
class VertexDescriptor
{
    public function __construct() {}

    /**
     * Define a vertex attribute.
     * @param int $index    Matches [[attribute(N)]] in MSL vertex shader
     * @param int $format   Mt\VertexFormat::Float3 etc.
     * @param int $offset   Byte offset within the vertex struct
     * @param int $bufferIndex  Which vertex buffer binding slot
     */
    public function setAttribute(int $index, int $format, int $offset, int $bufferIndex): void {}

    /**
     * Define a buffer binding (stride between vertices).
     * @param int $bufferIndex  Matches setAttribute() bufferIndex
     * @param int $stride       Bytes per vertex
     */
    public function setLayout(int $bufferIndex, int $stride): void {}
}

/**
 * Wraps id<MTLRenderPipelineState> — immutable compiled pipeline.
 * Created once, reused every frame.
 */
class RenderPipelineState {}

/**
 * Wraps MTLDepthStencilDescriptor + id<MTLDepthStencilState>.
 */
class DepthStencilDescriptor
{
    public function __construct() {}
    /** @param int $compareFunction  Mt\CompareFunction::Less */
    public function setDepthCompareFunction(int $compareFunction): void {}
    public function setDepthWriteEnabled(bool $enabled): void {}
}

class DepthStencilState {}

/**
 * Wraps id<MTLLibrary> — compiled MSL shader library.
 */
class Library
{
    public function newFunction(string $name): ShaderFunction {}
}

/**
 * Wraps id<MTLFunction> — one vertex or fragment function from a Library.
 * Named "ShaderFunction" because "Function" is a reserved word in PHP.
 */
class ShaderFunction {}

/**
 * Wraps id<MTLTexture>.
 */
class Texture
{
    public function __construct() {}
    public function getWidth(): int { return 0; }
    public function getHeight(): int { return 0; }
}

/**
 * Wraps MTLTextureDescriptor.
 */
class TextureDescriptor
{
    public function __construct() {}
    public function setPixelFormat(int $pixelFormat): void {}
    public function setWidth(int $width): void {}
    public function setHeight(int $height): void {}
    public function setUsage(int $usage): void {}
    public function setStorageMode(int $storageMode): void {}
}

// ─── Constants ─────────────────────────────────────────────────────────────

/** MTLPixelFormat */
final class PixelFormat
{
    public const BGRA8Unorm      = 80;
    public const RGBA8Unorm      = 70;
    public const Depth32Float    = 252;
}

/** MTLPrimitiveType */
final class PrimitiveType
{
    public const Triangle     = 3;
    public const TriangleStrip = 4;
    public const Line         = 1;
    public const Point        = 0;
}

/** MTLIndexType */
final class IndexType
{
    public const UInt16 = 0;
    public const UInt32 = 1;
}

/** MTLLoadAction */
final class LoadAction
{
    public const DontCare = 0;
    public const Load     = 1;
    public const Clear    = 2;
}

/** MTLStoreAction */
final class StoreAction
{
    public const DontCare = 0;
    public const Store    = 1;
}

/** MTLCullMode */
final class CullMode
{
    public const None  = 0;
    public const Front = 1;
    public const Back  = 2;
}

/** MTLWinding */
final class Winding
{
    public const Clockwise        = 0;
    public const CounterClockwise = 1;
}

/** MTLCompareFunction */
final class CompareFunction
{
    public const Never        = 0;
    public const Less         = 1;
    public const Equal        = 2;
    public const LessEqual    = 3;
    public const Greater      = 4;
    public const NotEqual     = 5;
    public const GreaterEqual = 6;
    public const Always       = 7;
}

/** MTLVertexFormat */
final class VertexFormat
{
    public const Float    = 28;
    public const Float2   = 29;
    public const Float3   = 30;
    public const Float4   = 31;
}

/** MTLResourceOptions (storage modes) */
final class ResourceOptions
{
    public const StorageModeShared   = 0;    // CPU+GPU access — for UBOs, upload buffers
    public const StorageModePrivate  = 0x10; // GPU only — fastest, for static meshes
    public const StorageModeManaged  = 0x20; // Manually synced (Intel Macs)
}

/** MTLTextureUsage */
final class TextureUsage
{
    public const ShaderRead       = 0x01;
    public const ShaderWrite      = 0x02;
    public const RenderTarget     = 0x04;
}
