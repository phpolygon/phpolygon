<?php

/**
 * PHPStan stubs for ext-vulkan (php-vulkan).
 * These stubs allow static analysis without the extension installed.
 */

namespace Vk;

class Instance
{
    /** @param list<string> $extensionNames */
    public function __construct(string $appName, int $appVersion, string $engineName, int $engineVersion, bool $enableValidation, array $extensionNames) {}
    /** @return list<PhysicalDevice> */
    public function getPhysicalDevices(): array { return []; }
}

class Surface
{
    public function __construct(Instance $instance, mixed $windowHandle) {}
    /** @return array<string, mixed> */
    public function getCapabilities(PhysicalDevice $gpu): array { return []; }
    /** @return list<array<string, mixed>> */
    public function getFormats(PhysicalDevice $gpu): array { return []; }
    /** @return list<int> */
    public function getPresentModes(PhysicalDevice $gpu): array { return []; }
}

class PhysicalDevice
{
    /** @return array{types: list<array<string, mixed>>} */
    public function getMemoryProperties(): array { return ['types' => []]; }
    /** @return list<array{index: int, graphics: bool}> */
    public function getQueueFamilies(): array { return []; }
    public function getSurfaceSupport(int $queueFamilyIndex, Surface $surface): bool { return false; }
}

class Device
{
    /** @param list<array<string, mixed>> $queueCreateInfos @param list<string> $extensionNames @param array<string, mixed> $enabledFeatures */
    public function __construct(PhysicalDevice $physicalDevice, array $queueCreateInfos, array $extensionNames, array $enabledFeatures) {}
    public function getQueue(int $queueFamilyIndex, int $queueIndex): Queue { return new Queue(); }
}

class Queue
{
    /** @param list<CommandBuffer> $commandBuffers @param list<Semaphore> $waitSemaphores @param list<Semaphore> $signalSemaphores */
    public function submit(array $commandBuffers, ?Fence $fence = null, array $waitSemaphores = [], array $signalSemaphores = []): void {}
    /** @param list<Swapchain> $swapchains @param list<int> $imageIndices @param list<Semaphore> $waitSemaphores */
    public function present(array $swapchains, array $imageIndices, array $waitSemaphores = []): void {}
    public function waitIdle(): void {}
}

class Swapchain
{
    /** @param array<string, mixed> $createInfo */
    public function __construct(Device $device, Surface $surface, array $createInfo) {}
    /** @return list<Image> */
    public function getImages(): array { return []; }
    public function acquireNextImage(?Semaphore $semaphore = null, ?Fence $fence = null, int $timeout = 0): int { return 0; }
}

class Image
{
    public function __construct(Device $device, int $width, int $height, int $format, int $usage, int $flags = 0, int $sampleCount = 1) {}
    /** @return array{size: int, memoryTypeBits: int} */
    public function getMemoryRequirements(): array { return ['size' => 0, 'memoryTypeBits' => 0]; }
    public function bindMemory(DeviceMemory $memory, int $memoryOffset = 0): void {}
}

class ImageView
{
    public function __construct(Device $device, Image $image, int $format, int $aspectFlags = 0, int $mipLevels = 1) {}
}

class RenderPass
{
    /** @param list<array<string, mixed>> $attachmentDescriptions @param list<array<string, mixed>> $subpassDescriptions @param list<array<string, mixed>> $subpassDependencies */
    public function __construct(Device $device, array $attachmentDescriptions, array $subpassDescriptions, array $subpassDependencies) {}
}

class Framebuffer
{
    /** @param list<ImageView> $attachments */
    public function __construct(Device $device, RenderPass $renderPass, array $attachments, int $width, int $height, int $layers = 1) {}
}

class Buffer
{
    public function __construct(Device $device, int $size, int $usage, int $sharingMode = 0) {}
    /** @return array{size: int, memoryTypeBits: int} */
    public function getMemoryRequirements(): array { return ['size' => 0, 'memoryTypeBits' => 0]; }
    public function bindMemory(DeviceMemory $memory, int $memoryOffset = 0): void {}
}

class DeviceMemory
{
    public function __construct(Device $device, int $size, int $memoryTypeIndex) {}
    public function map(int $offset = 0, int $size = 0): void {}
    public function write(string $data, int $offset = 0): void {}
}

class ShaderModule
{
    public static function createFromFile(Device $device, string $filePath): self { return new self(); }
}

class DescriptorSetLayout
{
    /** @param list<array<string, mixed>> $bindings */
    public function __construct(Device $device, array $bindings) {}
}

class PipelineLayout
{
    /** @param list<DescriptorSetLayout> $descriptorSetLayouts @param list<array<string, mixed>> $pushConstantRanges */
    public function __construct(Device $device, array $descriptorSetLayouts, array $pushConstantRanges = []) {}
}

class Pipeline
{
    /** @param array<string, mixed> $createInfo */
    public static function createGraphics(Device $device, array $createInfo): self { return new self(); }
}

class DescriptorPool
{
    /** @param list<array<string, mixed>> $poolSizes */
    public function __construct(Device $device, int $maxSets, array $poolSizes) {}
    /** @param list<DescriptorSetLayout> $layouts @return list<DescriptorSet> */
    public function allocateSets(array $layouts): array { return []; }
}

class DescriptorSet
{
    public function writeBuffer(int $dstBinding, Buffer $buffer, int $offset, int $range, int $descriptorType): void {}
    public function writeImage(int $dstBinding, ImageView $imageView, Sampler $sampler, int $imageLayout, int $descriptorType): void {}
}

class Sampler
{
    /** @param array<string, mixed> $createInfo */
    public function __construct(Device $device, array $createInfo = []) {}
}

class CommandPool
{
    public function __construct(Device $device, int $queueFamilyIndex, int $flags = 0) {}
    /** @return list<CommandBuffer> */
    public function allocateBuffers(int $commandBufferCount, bool $primary = true): array { return []; }
}

class CommandBuffer
{
    public function reset(int $flags = 0): void {}
    public function begin(int $flags = 0): void {}
    public function end(): void {}
    /** @param list<array<string, mixed>> $clearValues */
    public function beginRenderPass(RenderPass $renderPass, Framebuffer $framebuffer, int $renderAreaX, int $renderAreaY, int $renderAreaWidth, int $renderAreaHeight, array $clearValues = []): void {}
    public function endRenderPass(): void {}
    public function setViewport(float $x, float $y, float $width, float $height, float $minDepth = 0.0, float $maxDepth = 1.0): void {}
    public function setScissor(int $x, int $y, int $width, int $height): void {}
    public function bindPipeline(int $pipelineBindPoint, Pipeline $pipeline): void {}
    /** @param list<DescriptorSet> $descriptorSets */
    public function bindDescriptorSets(int $pipelineBindPoint, PipelineLayout $pipelineLayout, int $firstSet, array $descriptorSets): void {}
    public function pushConstants(PipelineLayout $pipelineLayout, int $stageFlags, int $offset, string $data): void {}
    /** @param list<Buffer> $buffers @param list<int> $offsets */
    public function bindVertexBuffers(int $firstBinding, array $buffers, array $offsets): void {}
    public function bindIndexBuffer(Buffer $buffer, int $offset, int $indexType): void {}
    public function drawIndexed(int $indexCount, int $instanceCount = 1, int $firstIndex = 0, int $vertexOffset = 0, int $firstInstance = 0): void {}
    public function draw(int $vertexCount, int $instanceCount = 1, int $firstVertex = 0, int $firstInstance = 0): void {}
    public function imageMemoryBarrier(Image $image, int $oldLayout, int $newLayout, int $srcAccessMask, int $dstAccessMask, int $srcStageMask, int $dstStageMask, int $aspectMask): void {}
    public function blitImage(Image $srcImage, int $srcLayout, Image $dstImage, int $dstLayout, int $srcWidth, int $srcHeight, int $dstWidth, int $dstHeight, int $filter): void {}
}

class Fence
{
    public function __construct(Device $device, bool $signaled = false) {}
    public function wait(int $timeout = 0): void {}
    public function reset(): void {}
}

class Semaphore
{
    public function __construct(Device $device, bool $isTimeline = false, int $initialValue = 0) {}
}
