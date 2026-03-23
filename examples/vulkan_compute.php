<?php

/**
 * PHPolygon — Vulkan Compute Example
 *
 * Runs a compute shader on the GPU via php-vulkan. The shader doubles every
 * element in a buffer of 1024 floats. The result is read back to PHP and
 * verified on the CPU.
 *
 * This demonstrates the full Vulkan compute pipeline:
 *   Instance → PhysicalDevice → Device → Queue
 *   Buffer → DeviceMemory → bind
 *   ShaderModule (SPIR-V) → DescriptorSetLayout → PipelineLayout → Pipeline
 *   CommandPool → CommandBuffer → dispatch → fence wait → readback
 *
 * No window or surface required — pure headless GPU compute.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// ---------------------------------------------------------------------------
// 1. SPIR-V compute shader (compiled from GLSL offline)
//
// The GLSL source this was compiled from:
//
//   #version 450
//   layout(local_size_x = 64) in;
//   layout(std430, binding = 0) buffer DataBuffer {
//       float data[];
//   };
//   void main() {
//       uint idx = gl_GlobalInvocationID.x;
//       data[idx] = data[idx] * 2.0;
//   }
//
// Compile with: glslangValidator -V --target-env vulkan1.0 -S comp -o double.comp.spv double.comp.glsl
// ---------------------------------------------------------------------------

$spirvPath = __DIR__ . '/../resources/shaders/compiled/double.comp.spv';

// Check if pre-compiled SPIR-V exists, otherwise compile from embedded GLSL
if (!file_exists($spirvPath)) {
    $glslSource = <<<'GLSL'
#version 450
layout(local_size_x = 64) in;

layout(std430, binding = 0) buffer DataBuffer {
    float data[];
};

void main() {
    uint idx = gl_GlobalInvocationID.x;
    data[idx] = data[idx] * 2.0;
}
GLSL;

    // Write temporary GLSL file
    $tmpGlsl = sys_get_temp_dir() . '/phpolygon_double.comp.glsl';
    file_put_contents($tmpGlsl, $glslSource);

    echo "Compiling GLSL → SPIR-V...\n";
    $cmd = sprintf(
        'glslangValidator -V --target-env vulkan1.0 -S comp -o %s %s 2>&1',
        escapeshellarg($spirvPath),
        escapeshellarg($tmpGlsl)
    );
    $output = [];
    exec($cmd, $output, $exitCode);
    unlink($tmpGlsl);

    if ($exitCode !== 0) {
        echo "Shader compilation failed:\n" . implode("\n", $output) . "\n";
        exit(1);
    }
    echo "Compiled successfully → {$spirvPath}\n\n";
}

// ---------------------------------------------------------------------------
// 2. Vulkan initialization
// ---------------------------------------------------------------------------

echo "=== PHPolygon Vulkan Compute Example ===\n\n";

// Create instance (no validation layers for portability)
$instance = new Vk\Instance(
    appName: 'PHPolygon Compute',
    appVersion: 1,
    engineName: 'PHPolygon',
    engineVersion: 1,
    apiVersion: null,
    enableValidation: false,
    extensions: [],
);
echo "Vulkan {$instance->getVersion()}\n";

// Pick first physical device
$physicalDevices = $instance->getPhysicalDevices();
if (empty($physicalDevices)) {
    echo "No Vulkan devices found!\n";
    exit(1);
}

$gpu = $physicalDevices[0];
echo "GPU: {$gpu->getName()} ({$gpu->getTypeName()})\n";
echo "API: {$gpu->getApiVersion()}\n";

// Find a queue family that supports compute
$queueFamilies = $gpu->getQueueFamilies();
$computeFamily = null;
foreach ($queueFamilies as $qf) {
    if ($qf['compute']) {
        $computeFamily = $qf['index'];
        break;
    }
}

if ($computeFamily === null) {
    echo "No compute-capable queue family found!\n";
    exit(1);
}
echo "Compute queue family: {$computeFamily}\n\n";

// Create logical device (queue families as array of {familyIndex, count})
$device = new Vk\Device(
    physicalDevice: $gpu,
    queueFamilies: [['familyIndex' => $computeFamily, 'count' => 1]],
    extensions: [],
    features: null,
);

$queue = $device->getQueue($computeFamily, 0);

// ---------------------------------------------------------------------------
// 3. Create buffer and upload data
// ---------------------------------------------------------------------------

$elementCount = 1024;
$bufferSize   = $elementCount * 4; // 4 bytes per float

echo "Buffer: {$elementCount} floats ({$bufferSize} bytes)\n";

// Create a storage buffer
// Usage flags: storage buffer (0x20) | transfer src/dst (0x01 | 0x02)
$buffer = new Vk\Buffer($device, $bufferSize, 0x20 | 0x01 | 0x02, 0);

// Find a host-visible memory type
$memReqs  = $buffer->getMemoryRequirements();
$memProps = $gpu->getMemoryProperties();

$memTypeIndex = null;
foreach ($memProps['types'] as $i => $type) {
    if (($memReqs['memoryTypeBits'] & (1 << $i)) &&
        $type['hostVisible'] && $type['hostCoherent']) {
        $memTypeIndex = $i;
        break;
    }
}

if ($memTypeIndex === null) {
    echo "No suitable memory type found!\n";
    exit(1);
}

$memory = new Vk\DeviceMemory($device, $memReqs['size'], $memTypeIndex);
$buffer->bindMemory($memory, 0);

// Write input data: [1.0, 2.0, 3.0, ..., 1024.0]
$inputData = '';
for ($i = 0; $i < $elementCount; $i++) {
    $inputData .= pack('f', (float)($i + 1));
}
$memory->map(0, null);
$memory->write($inputData, 0);

echo "Input:  [1.0, 2.0, 3.0, ..., {$elementCount}.0]\n";

// ---------------------------------------------------------------------------
// 4. Create compute pipeline
// ---------------------------------------------------------------------------

// Descriptor set layout: one storage buffer at binding 0
$descriptorSetLayout = new Vk\DescriptorSetLayout($device, [
    [
        'binding' => 0,
        'descriptorType' => 7, // VK_DESCRIPTOR_TYPE_STORAGE_BUFFER
        'descriptorCount' => 1,
        'stageFlags' => 0x20,  // VK_SHADER_STAGE_COMPUTE_BIT
    ],
]);

// Pipeline layout
$pipelineLayout = new Vk\PipelineLayout($device, [$descriptorSetLayout], []);

// Shader module from SPIR-V
$shaderModule = Vk\ShaderModule::createFromFile($device, $spirvPath);

// Compute pipeline
$pipeline = Vk\Pipeline::createCompute($device, $pipelineLayout, $shaderModule, 'main');

echo "Pipeline created ✓\n";

// ---------------------------------------------------------------------------
// 5. Descriptor set: bind the buffer
// ---------------------------------------------------------------------------

$descriptorPool = new Vk\DescriptorPool($device, 1, [
    ['type' => 7, 'descriptorCount' => 1], // STORAGE_BUFFER
], 0);

$descriptorSets = $descriptorPool->allocateSets([$descriptorSetLayout]);
$descriptorSet  = $descriptorSets[0];

// Write buffer descriptor
$descriptorSet->writeBuffer(
    binding: 0,
    buffer: $buffer,
    offset: 0,
    range: $bufferSize,
    type: 7, // VK_DESCRIPTOR_TYPE_STORAGE_BUFFER
);

// ---------------------------------------------------------------------------
// 6. Record and submit command buffer
// ---------------------------------------------------------------------------

$commandPool = new Vk\CommandPool($device, $computeFamily, 0);
$commandBuffers = $commandPool->allocateBuffers(1, true);
$cmd = $commandBuffers[0];

$cmd->begin(0x01); // VK_COMMAND_BUFFER_USAGE_ONE_TIME_SUBMIT_BIT

$cmd->bindPipeline(1, $pipeline); // VK_PIPELINE_BIND_POINT_COMPUTE = 1
$cmd->bindDescriptorSets(1, $pipelineLayout, 0, [$descriptorSet]);

// Dispatch: 1024 elements / 64 local_size_x = 16 work groups
$workGroups = (int)ceil($elementCount / 64);
$cmd->dispatch($workGroups, 1, 1);

$cmd->end();

echo "Dispatching {$workGroups} work groups ({$elementCount} invocations)...\n";

$fence = new Vk\Fence($device, false);
$queue->submit([$cmd], $fence, [], []);

// Wait for GPU to finish
$fence->wait(1_000_000_000); // 1 second timeout

echo "GPU compute finished ✓\n\n";

// ---------------------------------------------------------------------------
// 7. Read back and verify results
// ---------------------------------------------------------------------------

$resultData = $memory->read($bufferSize, 0);
$memory->unmap();

$results = unpack('f*', $resultData);
$results = array_values($results); // Re-index from 0

// Verify: each element should be doubled
$errors = 0;
for ($i = 0; $i < $elementCount; $i++) {
    $expected = ($i + 1) * 2.0;
    if (abs($results[$i] - $expected) > 0.001) {
        echo "MISMATCH at [{$i}]: expected {$expected}, got {$results[$i]}\n";
        $errors++;
    }
}

// Print first/last few results
echo "Output: [{$results[0]}, {$results[1]}, {$results[2]}, ..., {$results[$elementCount - 1]}]\n";

if ($errors === 0) {
    echo "\n✓ All {$elementCount} elements verified — GPU compute correct!\n";
} else {
    echo "\n✗ {$errors} mismatches found.\n";
    exit(1);
}

// Cleanup is automatic via destructors
echo "\nDone.\n";
