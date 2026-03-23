<?php

/**
 * PHPolygon — Vulkan Triangle (Windowed)
 *
 * Renders a rotating colored 3D pyramid in a real-time GLFW window using the
 * Vulkan graphics pipeline with swapchain presentation.
 *
 * Demonstrates the full windowed Vulkan pipeline:
 *   GLFW Window → Vk\Surface → Vk\Swapchain → acquire/render/present loop
 *   Shaders (GLSL → SPIR-V) → RenderPass → Graphics Pipeline → Push Constants
 *
 * Controls:
 *   SPACE  — Toggle rotation
 *   ESC    — Quit
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// ---------------------------------------------------------------------------
// Config
// ---------------------------------------------------------------------------
$width  = 1280;
$height = 720;

// ---------------------------------------------------------------------------
// 1. Compile shaders
// ---------------------------------------------------------------------------

$vertGlsl = <<<'GLSL'
#version 450

layout(location = 0) in vec3 aPos;
layout(location = 1) in vec3 aColor;

layout(location = 0) out vec3 vColor;

layout(push_constant) uniform PushConstants {
    mat4 mvp;
} pc;

void main() {
    gl_Position = pc.mvp * vec4(aPos, 1.0);
    vColor = aColor;
}
GLSL;

$fragGlsl = <<<'GLSL'
#version 450

layout(location = 0) in vec3 vColor;
layout(location = 0) out vec4 FragColor;

void main() {
    FragColor = vec4(vColor, 1.0);
}
GLSL;

$shaderDir = __DIR__ . '/../resources/shaders/compiled';
$vertSpv = "{$shaderDir}/triangle.vert.spv";
$fragSpv = "{$shaderDir}/triangle.frag.spv";

foreach ([['vert', $vertGlsl, $vertSpv], ['frag', $fragGlsl, $fragSpv]] as [$stage, $src, $spv]) {
    if (!file_exists($spv)) {
        $tmp = sys_get_temp_dir() . "/phpolygon_tri.{$stage}.glsl";
        file_put_contents($tmp, $src);
        exec(sprintf('glslangValidator -V --target-env vulkan1.0 -S %s -o %s %s 2>&1',
            $stage, escapeshellarg($spv), escapeshellarg($tmp)), $out, $rc);
        unlink($tmp);
        if ($rc !== 0) { echo "Shader compile failed ({$stage}):\n" . implode("\n", $out) . "\n"; exit(1); }
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

$window = glfwCreateWindow($width, $height, 'PHPolygon — Vulkan Triangle');
if (!$window) {
    echo "Failed to create GLFW window\n";
    exit(1);
}

// ---------------------------------------------------------------------------
// 3. Vulkan setup + surface + swapchain
// ---------------------------------------------------------------------------

echo "=== PHPolygon Vulkan Triangle (Windowed) ===\n\n";

$instance = new Vk\Instance('PHPolygon Triangle', 1, 'PHPolygon', 1, null, false, [
    'VK_KHR_surface',
    'VK_EXT_metal_surface',
    'VK_KHR_portability_enumeration',
]);
$gpu = $instance->getPhysicalDevices()[0];
echo "GPU: {$gpu->getName()}\n";

// Create surface from GLFW window
$surface = new Vk\Surface($instance, $window);

// Find a queue family that supports graphics + presentation
$queueFamilies = $gpu->getQueueFamilies();
$graphicsFamily = null;
foreach ($queueFamilies as $qf) {
    if ($qf['graphics'] && $gpu->getSurfaceSupport($qf['index'], $surface)) {
        $graphicsFamily = $qf['index'];
        break;
    }
}
if ($graphicsFamily === null) {
    echo "No graphics+present queue family found!\n";
    exit(1);
}

$device = new Vk\Device($gpu, [['familyIndex' => $graphicsFamily, 'count' => 1]],
    ['VK_KHR_swapchain'], null);
$queue = $device->getQueue($graphicsFamily, 0);
$memProps = $gpu->getMemoryProperties();

// Query surface capabilities and pick format
$caps = $surface->getCapabilities($gpu);
$formats = $surface->getFormats($gpu);
$presentModes = $surface->getPresentModes($gpu);

$surfaceFormat = $formats[0]['format'];
$colorSpace = $formats[0]['colorSpace'];
// Prefer FIFO (vsync)
$presentMode = in_array(2, $presentModes) ? 2 : $presentModes[0]; // VK_PRESENT_MODE_FIFO_KHR = 2

$imageCount = max($caps['minImageCount'], min(3, $caps['maxImageCount'] ?: 3));

$swapchain = new Vk\Swapchain($device, $surface, [
    'minImageCount' => $imageCount,
    'imageFormat' => $surfaceFormat,
    'imageColorSpace' => $colorSpace,
    'imageExtent' => ['width' => $width, 'height' => $height],
    'imageArrayLayers' => 1,
    'imageUsage' => 0x10, // VK_IMAGE_USAGE_COLOR_ATTACHMENT_BIT
    'imageSharingMode' => 0,
    'preTransform' => $caps['currentTransform'],
    'compositeAlpha' => 1, // VK_COMPOSITE_ALPHA_OPAQUE_BIT_KHR
    'presentMode' => $presentMode,
    'clipped' => true,
]);

$swapImages = $swapchain->getImages();
echo "Swapchain: {$imageCount} images, format {$surfaceFormat}\n";

// ---------------------------------------------------------------------------
// 4. Render pass + image views + framebuffers (per swapchain image)
// ---------------------------------------------------------------------------

$renderPass = new Vk\RenderPass($device,
    [[
        'format' => $surfaceFormat, 'samples' => 1,
        'loadOp' => 1, 'storeOp' => 0,           // CLEAR, STORE
        'stencilLoadOp' => 2, 'stencilStoreOp' => 1, // DONT_CARE
        'initialLayout' => 0,                       // UNDEFINED
        'finalLayout' => Vk\Vk::IMAGE_LAYOUT_PRESENT_SRC_KHR,
    ]],
    [['pipelineBindPoint' => 0, 'colorAttachments' => [['attachment' => 0, 'layout' => 2]]]],
    []
);

$imageViews = [];
$framebuffers = [];
foreach ($swapImages as $img) {
    $view = new Vk\ImageView($device, $img, $surfaceFormat, 1, 1);
    $imageViews[] = $view;
    $framebuffers[] = new Vk\Framebuffer($device, $renderPass, [$view], $width, $height, 1);
}

// ---------------------------------------------------------------------------
// 5. Graphics pipeline
// ---------------------------------------------------------------------------

$vertModule = Vk\ShaderModule::createFromFile($device, $vertSpv);
$fragModule = Vk\ShaderModule::createFromFile($device, $fragSpv);

$pipelineLayout = new Vk\PipelineLayout($device, [], [
    ['stageFlags' => 0x01, 'offset' => 0, 'size' => 64], // mat4 push constant
]);

$pipeline = Vk\Pipeline::createGraphics($device, [
    'renderPass'       => $renderPass,
    'layout'           => $pipelineLayout,
    'vertexShader'     => $vertModule,
    'fragmentShader'   => $fragModule,
    'vertexBindings'   => [['binding' => 0, 'stride' => 24, 'inputRate' => 0]],
    'vertexAttributes' => [
        ['location' => 0, 'binding' => 0, 'format' => 106, 'offset' => 0],  // vec3 pos (R32G32B32_SFLOAT)
        ['location' => 1, 'binding' => 0, 'format' => 106, 'offset' => 12], // vec3 color
    ],
    'cullMode'  => 0,
    'frontFace' => 1,
]);
echo "Pipeline created\n";

// ---------------------------------------------------------------------------
// 6. Vertex buffer — 3D pyramid (4 faces)
// ---------------------------------------------------------------------------

/** Find a memory type index matching requirements. */
function findMemory(array $memProps, array $memReqs, bool $hostVisible): int {
    foreach ($memProps['types'] as $i => $t) {
        if (!($memReqs['memoryTypeBits'] & (1 << $i))) continue;
        if ($hostVisible && (!$t['hostVisible'] || !$t['hostCoherent'])) continue;
        if (!$hostVisible && !$t['deviceLocal']) continue;
        return $i;
    }
    throw new RuntimeException('No suitable memory type');
}

$verts = '';
$faces = [
    // Front face
    [[ 0.0,  -0.6,  0.4], [1.0, 0.2, 0.3]],
    [[-0.6,   0.5,  0.4], [0.2, 1.0, 0.3]],
    [[ 0.6,   0.5,  0.4], [0.3, 0.2, 1.0]],
    // Right face
    [[ 0.0,  -0.6,  0.4], [1.0, 0.2, 0.3]],
    [[ 0.6,   0.5,  0.4], [0.3, 0.2, 1.0]],
    [[ 0.0,   0.0, -0.5], [1.0, 0.8, 0.1]],
    // Left face
    [[ 0.0,  -0.6,  0.4], [1.0, 0.2, 0.3]],
    [[ 0.0,   0.0, -0.5], [1.0, 0.8, 0.1]],
    [[-0.6,   0.5,  0.4], [0.2, 1.0, 0.3]],
    // Bottom face
    [[-0.6,   0.5,  0.4], [0.2, 1.0, 0.3]],
    [[ 0.0,   0.0, -0.5], [1.0, 0.8, 0.1]],
    [[ 0.6,   0.5,  0.4], [0.3, 0.2, 1.0]],
];
$vertexCount = count($faces);
foreach ($faces as [$pos, $col]) {
    $verts .= pack('f6', $pos[0], $pos[1], $pos[2], $col[0], $col[1], $col[2]);
}

$vertBuf = new Vk\Buffer($device, strlen($verts), 0x80, 0); // VERTEX_BUFFER_BIT
$vbMR = $vertBuf->getMemoryRequirements();
$vbMem = new Vk\DeviceMemory($device, $vbMR['size'], findMemory($memProps, $vbMR, true));
$vertBuf->bindMemory($vbMem, 0);
$vbMem->map(0, null);
$vbMem->write($verts, 0);

// ---------------------------------------------------------------------------
// 7. Sync objects + command pool
// ---------------------------------------------------------------------------

$imageAvailableSem = new Vk\Semaphore($device, false);
$renderFinishedSem = new Vk\Semaphore($device, false);
$inFlightFence = new Vk\Fence($device, true); // Start signaled

$cmdPool = new Vk\CommandPool($device, $graphicsFamily, 0x02); // RESET_COMMAND_BUFFER_BIT
$cmds = $cmdPool->allocateBuffers(1, true);
$cmd = $cmds[0];

// ---------------------------------------------------------------------------
// 8. Matrix helpers
// ---------------------------------------------------------------------------

function mat4Multiply(array $a, array $b): array {
    $r = array_fill(0, 16, 0.0);
    for ($i = 0; $i < 4; $i++)
        for ($j = 0; $j < 4; $j++)
            for ($k = 0; $k < 4; $k++)
                $r[$j * 4 + $i] += $a[$k * 4 + $i] * $b[$j * 4 + $k];
    return $r;
}

function perspectiveMatrix(float $fovDeg, float $aspect, float $near, float $far): array {
    $f = 1.0 / tan(deg2rad($fovDeg) * 0.5);
    $nf = 1.0 / ($near - $far);
    return [$f/$aspect,0,0,0, 0,$f,0,0, 0,0,($far+$near)*$nf,-1, 0,0,2*$far*$near*$nf,0];
}

function lookAtMatrix(array $eye, array $center, array $up): array {
    $fx=$center[0]-$eye[0]; $fy=$center[1]-$eye[1]; $fz=$center[2]-$eye[2];
    $fl=sqrt($fx*$fx+$fy*$fy+$fz*$fz); $fx/=$fl; $fy/=$fl; $fz/=$fl;
    $sx=$fy*$up[2]-$fz*$up[1]; $sy=$fz*$up[0]-$fx*$up[2]; $sz=$fx*$up[1]-$fy*$up[0];
    $sl=sqrt($sx*$sx+$sy*$sy+$sz*$sz); $sx/=$sl; $sy/=$sl; $sz/=$sl;
    $ux=$sy*$fz-$sz*$fy; $uy=$sz*$fx-$sx*$fz; $uz=$sx*$fy-$sy*$fx;
    return [$sx,$ux,-$fx,0, $sy,$uy,-$fy,0, $sz,$uz,-$fz,0,
        -($sx*$eye[0]+$sy*$eye[1]+$sz*$eye[2]),
        -($ux*$eye[0]+$uy*$eye[1]+$uz*$eye[2]),
        $fx*$eye[0]+$fy*$eye[1]+$fz*$eye[2], 1];
}

function rotationY(float $angle): array {
    $c=cos($angle); $s=sin($angle);
    return [$c,0,$s,0, 0,1,0,0, -$s,0,$c,0, 0,0,0,1];
}

$proj = perspectiveMatrix(60.0, $width / $height, 0.1, 100.0);
$view = lookAtMatrix([0, 0, 3], [0, 0, 0], [0, 1, 0]);

// ---------------------------------------------------------------------------
// 9. Render loop
// ---------------------------------------------------------------------------

echo "Rendering — SPACE toggle rotation, ESC quit\n\n";

$angle = 0.0;
$rotating = true;
$lastTime = microtime(true);

glfwSetKeyCallback($window, function ($key, $scancode, $action, $mods) use (&$rotating) {
    if ($action === GLFW_PRESS && $key === GLFW_KEY_SPACE) {
        $rotating = !$rotating;
    }
});

while (!glfwWindowShouldClose($window)) {
    glfwPollEvents();

    // ESC to quit
    if (glfwGetKey($window, GLFW_KEY_ESCAPE) === GLFW_PRESS) {
        break;
    }

    $now = microtime(true);
    $dt = $now - $lastTime;
    $lastTime = $now;

    if ($rotating) {
        $angle += 1.5 * $dt;
    }

    // Wait for previous frame
    $inFlightFence->wait(1_000_000_000);
    $inFlightFence->reset();

    // Acquire next swapchain image
    $imageIndex = $swapchain->acquireNextImage($imageAvailableSem, null, 1_000_000_000);

    // Build MVP
    $model = rotationY($angle);
    $mvp = mat4Multiply($proj, mat4Multiply($view, $model));
    $mvpBinary = pack('f16', ...$mvp);

    // Record command buffer
    $cmd->reset(0);
    $cmd->begin(0x01);
    $cmd->beginRenderPass($renderPass, $framebuffers[$imageIndex], 0, 0, $width, $height,
        [[0.06, 0.06, 0.09, 1.0]]);
    $cmd->setViewport(0.0, 0.0, (float)$width, (float)$height, 0.0, 1.0);
    $cmd->setScissor(0, 0, $width, $height);
    $cmd->bindPipeline(0, $pipeline);
    $cmd->bindVertexBuffers(0, [$vertBuf], [0]);
    $cmd->pushConstants($pipelineLayout, 0x01, 0, $mvpBinary);
    $cmd->draw($vertexCount, 1, 0, 0);
    $cmd->endRenderPass();
    $cmd->end();

    // Submit
    $queue->submit([$cmd], $inFlightFence, [$imageAvailableSem], [$renderFinishedSem]);

    // Present
    $queue->present([$swapchain], [$imageIndex], [$renderFinishedSem]);
}

$device->waitIdle();
glfwDestroyWindow($window);
glfwTerminate();

echo "Done.\n";
