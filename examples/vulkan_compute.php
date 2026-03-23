<?php

/**
 * PHPolygon — Vulkan Compute Example (Windowed)
 *
 * Uses a compute shader to generate a colorful animated pattern on the GPU,
 * then presents it in a GLFW window via swapchain. Demonstrates the full
 * Vulkan compute → graphics pipeline:
 *
 *   Compute shader fills a storage buffer with pixel data
 *   → copy to swapchain image → present
 *
 * Controls:
 *   SPACE  — Toggle animation
 *   ESC    — Quit
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// ---------------------------------------------------------------------------
// Config
// ---------------------------------------------------------------------------
$width  = 800;
$height = 600;

// ---------------------------------------------------------------------------
// 1. Compile compute shader
// ---------------------------------------------------------------------------

$computeGlsl = <<<'GLSL'
#version 450

layout(local_size_x = 16, local_size_y = 16) in;

layout(std430, binding = 0) buffer OutputBuffer {
    uint pixels[];
};

layout(push_constant) uniform PushConstants {
    float time;
    uint width;
    uint height;
};

void main() {
    uvec2 pos = gl_GlobalInvocationID.xy;
    if (pos.x >= width || pos.y >= height) return;

    uint idx = pos.y * width + pos.x;

    float u = float(pos.x) / float(width);
    float v = float(pos.y) / float(height);

    // Animated plasma pattern
    float t = time * 0.8;
    float val = sin(u * 10.0 + t)
              + sin(v * 8.0 - t * 0.7)
              + sin((u + v) * 6.0 + t * 1.3)
              + sin(length(vec2(u - 0.5, v - 0.5)) * 12.0 - t * 2.0);
    val = val * 0.25 + 0.5;

    float r = sin(val * 3.14159 * 2.0) * 0.5 + 0.5;
    float g = sin(val * 3.14159 * 2.0 + 2.094) * 0.5 + 0.5;
    float b = sin(val * 3.14159 * 2.0 + 4.189) * 0.5 + 0.5;

    uint ir = uint(clamp(r * 255.0, 0.0, 255.0));
    uint ig = uint(clamp(g * 255.0, 0.0, 255.0));
    uint ib = uint(clamp(b * 255.0, 0.0, 255.0));

    pixels[idx] = ir | (ig << 8) | (ib << 16) | (255u << 24); // RGBA
}
GLSL;

$shaderDir = __DIR__ . '/../resources/shaders/compiled';
if (!is_dir($shaderDir)) {
    mkdir($shaderDir, 0755, true);
}
$computeSpv = "{$shaderDir}/plasma.comp.spv";

if (!file_exists($computeSpv)) {
    $tmp = sys_get_temp_dir() . '/phpolygon_plasma.comp.glsl';
    file_put_contents($tmp, $computeGlsl);
    exec(sprintf('glslangValidator -V --target-env vulkan1.0 -S comp -o %s %s 2>&1',
        escapeshellarg($computeSpv), escapeshellarg($tmp)), $out, $rc);
    unlink($tmp);
    if ($rc !== 0) {
        echo "Shader compile failed:\n" . implode("\n", $out) . "\n";
        exit(1);
    }
}

// ---------------------------------------------------------------------------
// 2. GLFW window (no OpenGL context)
// ---------------------------------------------------------------------------

// macOS: GLFW needs DYLD_LIBRARY_PATH set before process starts to find libvulkan.
// If not set, re-exec ourselves with the correct environment.
if (PHP_OS_FAMILY === 'Darwin' && !getenv('DYLD_LIBRARY_PATH')) {
    foreach (['/opt/homebrew/lib', '/usr/local/lib'] as $libDir) {
        if (file_exists("{$libDir}/libvulkan.dylib")) {
            $icd = dirname($libDir) . '/etc/vulkan/icd.d/MoltenVK_icd.json';
            $env = "DYLD_LIBRARY_PATH={$libDir} VK_ICD_FILENAMES={$icd}";
            $cmd = $env . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__);
            passthru($cmd, $exitCode);
            exit($exitCode);
        }
    }
}

glfwInit();
glfwWindowHint(GLFW_CLIENT_API, GLFW_NO_API);
glfwWindowHint(GLFW_RESIZABLE, GLFW_FALSE);

$window = glfwCreateWindow($width, $height, 'PHPolygon — Vulkan Compute Plasma');
if (!$window) {
    echo "Failed to create GLFW window\n";
    exit(1);
}

// ---------------------------------------------------------------------------
// 3. Vulkan setup + surface + swapchain
// ---------------------------------------------------------------------------

echo "=== PHPolygon Vulkan Compute (Windowed) ===\n\n";

$instance = new Vk\Instance('PHPolygon Compute', 1, 'PHPolygon', 1, null, false, [
    'VK_KHR_surface',
    'VK_EXT_metal_surface',
    'VK_KHR_portability_enumeration',
]);
$gpu = $instance->getPhysicalDevices()[0];
echo "GPU: {$gpu->getName()}\n";

$surface = new Vk\Surface($instance, $window);

// Find queue family with compute + graphics + present support
$queueFamilies = $gpu->getQueueFamilies();
$queueFamily = null;
foreach ($queueFamilies as $qf) {
    if ($qf['compute'] && $qf['graphics'] && $gpu->getSurfaceSupport($qf['index'], $surface)) {
        $queueFamily = $qf['index'];
        break;
    }
}
if ($queueFamily === null) {
    echo "No suitable queue family found!\n";
    exit(1);
}

$device = new Vk\Device($gpu, [['familyIndex' => $queueFamily, 'count' => 1]],
    ['VK_KHR_swapchain'], null);
$queue = $device->getQueue($queueFamily, 0);
$memProps = $gpu->getMemoryProperties();

// Swapchain
$caps = $surface->getCapabilities($gpu);
$formats = $surface->getFormats($gpu);
$surfaceFormat = $formats[0]['format'];
$imageCount = max($caps['minImageCount'], min(3, $caps['maxImageCount'] ?: 3));

$swapchain = new Vk\Swapchain($device, $surface, [
    'minImageCount' => $imageCount,
    'imageFormat' => $surfaceFormat,
    'imageColorSpace' => $formats[0]['colorSpace'],
    'imageExtent' => ['width' => $width, 'height' => $height],
    'imageArrayLayers' => 1,
    'imageUsage' => 0x10 | 0x02, // COLOR_ATTACHMENT | TRANSFER_DST (for copy from compute buffer)
    'imageSharingMode' => 0,
    'preTransform' => $caps['currentTransform'],
    'compositeAlpha' => 1,
    'presentMode' => 2, // FIFO
    'clipped' => true,
]);

$swapImages = $swapchain->getImages();
echo "Swapchain: {$imageCount} images\n";

// ---------------------------------------------------------------------------
// 4. Compute pipeline
// ---------------------------------------------------------------------------

/** Find a memory type index. */
function findMemory(array $memProps, array $memReqs, bool $hostVisible): int {
    foreach ($memProps['types'] as $i => $t) {
        if (!($memReqs['memoryTypeBits'] & (1 << $i))) continue;
        if ($hostVisible && (!$t['hostVisible'] || !$t['hostCoherent'])) continue;
        if (!$hostVisible && !$t['deviceLocal']) continue;
        return $i;
    }
    throw new RuntimeException('No suitable memory type');
}

$bufferSize = $width * $height * 4; // RGBA pixels

// Storage buffer for compute output
$storageBuffer = new Vk\Buffer($device, $bufferSize, 0x20 | 0x02, 0); // STORAGE | TRANSFER_SRC
$sbMR = $storageBuffer->getMemoryRequirements();
// Prefer device-local, fall back to host-visible
$sbMemType = null;
foreach ($memProps['types'] as $i => $t) {
    if (!($sbMR['memoryTypeBits'] & (1 << $i))) continue;
    if ($t['deviceLocal']) { $sbMemType = $i; break; }
}
if ($sbMemType === null) {
    $sbMemType = findMemory($memProps, $sbMR, true);
}
$sbMem = new Vk\DeviceMemory($device, $sbMR['size'], $sbMemType);
$storageBuffer->bindMemory($sbMem, 0);

// Descriptor set layout + pool + set
$descLayout = new Vk\DescriptorSetLayout($device, [
    ['binding' => 0, 'descriptorType' => 7, 'descriptorCount' => 1, 'stageFlags' => 0x20],
]);
$descPool = new Vk\DescriptorPool($device, 1, [['type' => 7, 'descriptorCount' => 1]], 0);
$descSets = $descPool->allocateSets([$descLayout]);
$descSet = $descSets[0];
$descSet->writeBuffer(0, $storageBuffer, 0, $bufferSize, 7);

// Pipeline layout with push constants (time, width, height)
$computeLayout = new Vk\PipelineLayout($device, [$descLayout], [
    ['stageFlags' => 0x20, 'offset' => 0, 'size' => 12], // float + uint + uint = 12 bytes
]);

$shaderModule = Vk\ShaderModule::createFromFile($device, $computeSpv);
$computePipeline = Vk\Pipeline::createCompute($device, $computeLayout, $shaderModule, 'main');
echo "Compute pipeline created\n";

// ---------------------------------------------------------------------------
// 5. Sync + command pool
// ---------------------------------------------------------------------------

$imageAvailableSem = new Vk\Semaphore($device, false);
$renderFinishedSem = new Vk\Semaphore($device, false);
$fence = new Vk\Fence($device, true);

$cmdPool = new Vk\CommandPool($device, $queueFamily, 0x02); // RESET_COMMAND_BUFFER_BIT
$cmds = $cmdPool->allocateBuffers(1, true);
$cmd = $cmds[0];

// ---------------------------------------------------------------------------
// 6. Render loop
// ---------------------------------------------------------------------------

echo "Rendering — SPACE toggle animation, ESC quit\n\n";

$time = 0.0;
$animating = true;
$lastTime = microtime(true);

$workGroupsX = (int)ceil($width / 16);
$workGroupsY = (int)ceil($height / 16);

glfwSetKeyCallback($window, function ($key, $scancode, $action, $mods) use (&$animating) {
    if ($action === GLFW_PRESS && $key === GLFW_KEY_SPACE) {
        $animating = !$animating;
    }
});

while (!glfwWindowShouldClose($window)) {
    glfwPollEvents();

    if (glfwGetKey($window, GLFW_KEY_ESCAPE) === GLFW_PRESS) {
        break;
    }

    $now = microtime(true);
    $dt = $now - $lastTime;
    $lastTime = $now;

    if ($animating) {
        $time += $dt;
    }

    // Wait for previous frame
    $fence->wait(1_000_000_000);
    $fence->reset();

    $imageIndex = $swapchain->acquireNextImage($imageAvailableSem, null, 1_000_000_000);

    // Push constant data: time (float) + width (uint32) + height (uint32)
    $pushData = pack('fVV', $time, $width, $height);

    $cmd->reset(0);
    $cmd->begin(0x01);

    // 1. Dispatch compute shader
    $cmd->bindPipeline(1, $computePipeline); // COMPUTE bind point
    $cmd->bindDescriptorSets(1, $computeLayout, 0, [$descSet]);
    $cmd->pushConstants($computeLayout, 0x20, 0, $pushData);
    $cmd->dispatch($workGroupsX, $workGroupsY, 1);

    // 2. Barrier: compute write → transfer read
    $cmd->pipelineBarrier(
        0x00000800, // VK_PIPELINE_STAGE_COMPUTE_SHADER_BIT
        0x00001000, // VK_PIPELINE_STAGE_TRANSFER_BIT
        0x00000020, // VK_ACCESS_SHADER_WRITE_BIT
        0x00000800, // VK_ACCESS_TRANSFER_READ_BIT
    );

    // 3. Transition swapchain image: UNDEFINED → TRANSFER_DST
    $cmd->imageMemoryBarrier(
        $swapImages[$imageIndex],
        0,  // VK_IMAGE_LAYOUT_UNDEFINED
        7,  // VK_IMAGE_LAYOUT_TRANSFER_DST_OPTIMAL
        0,
        0x00000400, // VK_ACCESS_TRANSFER_WRITE_BIT
        0x00000001, // VK_PIPELINE_STAGE_TOP_OF_PIPE_BIT
        0x00001000, // VK_PIPELINE_STAGE_TRANSFER_BIT
        1,          // VK_IMAGE_ASPECT_COLOR_BIT
    );

    // 4. Copy storage buffer → swapchain image
    $cmd->copyBufferToImage(
        $storageBuffer,
        $swapImages[$imageIndex],
        7,  // TRANSFER_DST_OPTIMAL
        $width,
        $height,
    );

    // 5. Transition swapchain image: TRANSFER_DST → PRESENT_SRC
    $cmd->imageMemoryBarrier(
        $swapImages[$imageIndex],
        7,  // VK_IMAGE_LAYOUT_TRANSFER_DST_OPTIMAL
        Vk\Vk::IMAGE_LAYOUT_PRESENT_SRC_KHR,
        0x00000400,
        0,
        0x00001000, // VK_PIPELINE_STAGE_TRANSFER_BIT
        0x00002000, // VK_PIPELINE_STAGE_BOTTOM_OF_PIPE_BIT
        1,
    );

    $cmd->end();

    $queue->submit([$cmd], $fence, [$imageAvailableSem], [$renderFinishedSem]);
    $queue->present([$swapchain], [$imageIndex], [$renderFinishedSem]);
}

$device->waitIdle();
glfwDestroyWindow($window);
glfwTerminate();

echo "Done.\n";
