<?php

/**
 * PHPStan stubs for ext-metal (php-metal-gpu).
 * Native Apple Metal GPU API for PHP — macOS/Apple Silicon only.
 * Namespace: Metal\ (matches actual extension namespace)
 */

namespace Metal;

class Device
{
    public function createCommandQueue(): CommandQueue {}
    public function createBuffer(int $length, int $options = 0): Buffer {}
    public function createTexture(TextureDescriptor $descriptor): Texture {}
    public function createLibraryWithFile(string $path): Library {}
    public function createRenderPipelineState(RenderPipelineDescriptor $descriptor): RenderPipelineState {}
    public function createDepthStencilState(DepthStencilDescriptor $descriptor): DepthStencilState {}
}

class Layer
{
    public function __construct(int $nsWindowPtr, Device $device, int $pixelFormat = 80) {}
    public function nextDrawable(): Drawable {}
    public function setDrawableSize(int $width, int $height): void {}
    public function getDrawableSize(): array { return ['width' => 0, 'height' => 0]; }
}

class Drawable
{
    public function getTexture(): Texture {}
}

class CommandQueue
{
    public function createCommandBuffer(): CommandBuffer {}
}

class CommandBuffer
{
    public function createRenderCommandEncoder(RenderPassDescriptor $descriptor): RenderCommandEncoder {}
    public function presentDrawable(Drawable $drawable): void {}
    public function commit(): void {}
    public function waitUntilCompleted(): void {}
}

class RenderCommandEncoder
{
    public function setRenderPipelineState(RenderPipelineState $state): void {}
    public function setDepthStencilState(DepthStencilState $state): void {}
    public function setCullMode(int $cullMode): void {}
    public function setFrontFacingWinding(int $winding): void {}
    public function setViewport(float $originX, float $originY, float $width, float $height, float $znear, float $zfar): void {}
    public function setScissorRect(int $x, int $y, int $width, int $height): void {}
    public function setVertexBuffer(Buffer $buffer, int $offset, int $index): void {}
    public function setFragmentBuffer(Buffer $buffer, int $offset, int $index): void {}
    public function setVertexBytes(string $bytes, int $index): void {}
    public function setFragmentBytes(string $bytes, int $index): void {}
    public function drawIndexedPrimitives(int $primitiveType, int $indexCount, int $indexType, Buffer $indexBuffer, int $indexBufferOffset = 0, int $instanceCount = 1): void {}
    public function endEncoding(): void {}
}

class Buffer
{
    public function writeRawContents(string $bytes, int $offset = 0): void {}
    public function getLength(): int { return 0; }
}

class Texture
{
    public function getWidth(): int { return 0; }
    public function getHeight(): int { return 0; }
}

class TextureDescriptor
{
    public function __construct() {}
    public function setPixelFormat(int $pixelFormat): void {}
    public function setWidth(int $width): void {}
    public function setHeight(int $height): void {}
    public function setUsage(int $usage): void {}
    public function setStorageMode(int $storageMode): void {}
}

class RenderPassDescriptor
{
    public function __construct() {}
    public function setColorAttachmentTexture(int $index, Texture $texture): void {}
    public function setColorAttachmentLoadAction(int $index, int $loadAction): void {}
    public function setColorAttachmentStoreAction(int $index, int $storeAction): void {}
    public function setColorAttachmentClearColor(int $index, float $r, float $g, float $b, float $a): void {}
    public function setDepthAttachmentTexture(Texture $texture): void {}
    public function setDepthAttachmentLoadAction(int $loadAction): void {}
    public function setDepthAttachmentStoreAction(int $storeAction): void {}
    public function setDepthAttachmentClearDepth(float $depth): void {}
}

class RenderPipelineDescriptor
{
    public function __construct() {}
    public function setVertexFunction(ShaderFunction $function): void {}
    public function setFragmentFunction(ShaderFunction $function): void {}
    public function getColorAttachment(int $index): ColorAttachment {}
    public function setDepthAttachmentPixelFormat(int $pixelFormat): void {}
    public function setVertexDescriptor(VertexDescriptor $descriptor): void {}
}

class ColorAttachment
{
    public function setPixelFormat(int $pixelFormat): void {}
}

class VertexDescriptor
{
    public function __construct() {}
    public function setAttribute(int $index, int $format, int $offset, int $bufferIndex): void {}
    public function setLayout(int $bufferIndex, int $stride): void {}
}

class RenderPipelineState {}
class DepthStencilState {}

class DepthStencilDescriptor
{
    public function __construct() {}
    public function setDepthCompareFunction(int $compareFunction): void {}
    public function setDepthWriteEnabled(bool $enabled): void {}
}

class Library
{
    public function getFunction(string $name): ShaderFunction {}
}

class ShaderFunction {}

// ── Namespace-level function ──────────────────────────────────────────────

function createSystemDefaultDevice(): Device {}

// ── Namespace-level constants ─────────────────────────────────────────────

const PixelFormatBGRA8Unorm   = 80;
const PixelFormatDepth32Float = 252;

const StorageModeShared  = 0;
const StorageModePrivate = 16;

const TextureUsageRenderTarget = 4;

const LoadActionClear  = 2;
const StoreActionStore = 1;

const CullModeBack            = 2;
const WindingCounterClockwise = 1;

const PrimitiveTypeTriangle = 3;

const IndexTypeUInt32 = 1;

const VertexFormatFloat2 = 29;
const VertexFormatFloat3 = 30;

const CompareFunctionLess = 1;
