<?php

/**
 * PHPolygon — Vulkan 3D Triangle (Offscreen)
 *
 * Renders a colored 3D triangle with perspective projection via the Vulkan
 * graphics pipeline into an offscreen framebuffer, then reads the pixels
 * back and writes a PPM image file.
 *
 * Demonstrates the complete Vulkan graphics pipeline:
 *   Shaders (GLSL → SPIR-V) → RenderPass → Framebuffer → Graphics Pipeline
 *   → Vertex Buffer → Push Constants (MVP matrix) → Draw → Readback → PPM
 *
 * No window required — pure headless GPU rendering.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// ---------------------------------------------------------------------------
// Config
// ---------------------------------------------------------------------------
$width  = 1280;
$height = 720;
$format = 37; // VK_FORMAT_R8G8B8A8_UNORM
$outputPath = __DIR__ . '/vulkan_triangle_output.ppm';

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

// Compile if needed
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
echo "Shaders ready\n";

// ---------------------------------------------------------------------------
// 2. Vulkan setup
// ---------------------------------------------------------------------------

echo "=== PHPolygon Vulkan 3D Triangle ===\n\n";

$instance = new Vk\Instance('PHPolygon Triangle', 1, 'PHPolygon', 1, null, false, []);
$gpu = $instance->getPhysicalDevices()[0];
echo "GPU: {$gpu->getName()}\n";

$device = new Vk\Device($gpu, [['familyIndex' => 0, 'count' => 1]], [], null);
$queue  = $device->getQueue(0, 0);
$memProps = $gpu->getMemoryProperties();

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

// ---------------------------------------------------------------------------
// 3. Offscreen image + framebuffer
// ---------------------------------------------------------------------------

// Color attachment image
$colorImage = new Vk\Image($device, $width, $height, $format,
    0x10 | 0x01, // COLOR_ATTACHMENT | TRANSFER_SRC
    0, 1);       // TILING_OPTIMAL, 1 sample
$cMR = $colorImage->getMemoryRequirements();
$cMem = new Vk\DeviceMemory($device, $cMR['size'], findMemory($memProps, $cMR, false));
$colorImage->bindMemory($cMem, 0);

$colorView = new Vk\ImageView($device, $colorImage, $format, 1, 1);

// Render pass
$renderPass = new Vk\RenderPass($device,
    [[
        'format' => $format, 'samples' => 1,
        'loadOp' => 1, 'storeOp' => 0,         // CLEAR, STORE
        'stencilLoadOp' => 2, 'stencilStoreOp' => 1, // DONT_CARE
        'initialLayout' => 0, 'finalLayout' => 6,      // UNDEFINED → TRANSFER_SRC
    ]],
    [['pipelineBindPoint' => 0, 'colorAttachments' => [['attachment' => 0, 'layout' => 2]]]],
    []
);

$framebuffer = new Vk\Framebuffer($device, $renderPass, [$colorView], $width, $height, 1);
echo "Framebuffer: {$width}x{$height}\n";

// ---------------------------------------------------------------------------
// 4. Graphics pipeline
// ---------------------------------------------------------------------------

$vertModule = Vk\ShaderModule::createFromFile($device, $vertSpv);
$fragModule = Vk\ShaderModule::createFromFile($device, $fragSpv);

$pipelineLayout = new Vk\PipelineLayout($device, [], [
    ['stageFlags' => 0x01, 'offset' => 0, 'size' => 64], // VK_SHADER_STAGE_VERTEX_BIT, mat4
]);

$pipeline = Vk\Pipeline::createGraphics($device, [
    'renderPass'       => $renderPass,
    'layout'           => $pipelineLayout,
    'vertexShader'     => $vertModule,
    'fragmentShader'   => $fragModule,
    'vertexBindings'   => [['binding' => 0, 'stride' => 24, 'inputRate' => 0]],
    'vertexAttributes' => [
        ['location' => 0, 'binding' => 0, 'format' => 106, 'offset' => 0],  // vec3 pos
        ['location' => 1, 'binding' => 0, 'format' => 106, 'offset' => 12], // vec3 color
    ],
    'cullMode'  => 0, // VK_CULL_MODE_NONE
    'frontFace' => 1, // VK_FRONT_FACE_CLOCKWISE
]);
echo "Pipeline created\n";

// ---------------------------------------------------------------------------
// 5. Vertex buffer — 3D pyramid (4 faces)
// ---------------------------------------------------------------------------

$verts = '';
$faces = [
    // Each face: 3 vertices with pos(xyz) + color(rgb)
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
echo "Vertices: {$vertexCount} ({$vertexCount} / 3 = " . ($vertexCount / 3) . " triangles)\n";

// ---------------------------------------------------------------------------
// 6. Build MVP matrix (perspective * view * model)
// ---------------------------------------------------------------------------

function mat4Multiply(array $a, array $b): array {
    $r = array_fill(0, 16, 0.0);
    for ($i = 0; $i < 4; $i++) {
        for ($j = 0; $j < 4; $j++) {
            for ($k = 0; $k < 4; $k++) {
                $r[$j * 4 + $i] += $a[$k * 4 + $i] * $b[$j * 4 + $k];
            }
        }
    }
    return $r;
}

function perspectiveMatrix(float $fovDeg, float $aspect, float $near, float $far): array {
    $f = 1.0 / tan(deg2rad($fovDeg) * 0.5);
    $nf = 1.0 / ($near - $far);
    return [
        $f/$aspect, 0,  0,                    0,
        0,          $f, 0,                    0,
        0,          0, ($far+$near)*$nf,     -1,
        0,          0,  2*$far*$near*$nf,     0,
    ];
}

function lookAtMatrix(array $eye, array $center, array $up): array {
    $fx=$center[0]-$eye[0]; $fy=$center[1]-$eye[1]; $fz=$center[2]-$eye[2];
    $fl=sqrt($fx*$fx+$fy*$fy+$fz*$fz); $fx/=$fl; $fy/=$fl; $fz/=$fl;
    $sx=$fy*$up[2]-$fz*$up[1]; $sy=$fz*$up[0]-$fx*$up[2]; $sz=$fx*$up[1]-$fy*$up[0];
    $sl=sqrt($sx*$sx+$sy*$sy+$sz*$sz); $sx/=$sl; $sy/=$sl; $sz/=$sl;
    $ux=$sy*$fz-$sz*$fy; $uy=$sz*$fx-$sx*$fz; $uz=$sx*$fy-$sy*$fx;
    return [
        $sx,$ux,-$fx,0, $sy,$uy,-$fy,0, $sz,$uz,-$fz,0,
        -($sx*$eye[0]+$sy*$eye[1]+$sz*$eye[2]),
        -($ux*$eye[0]+$uy*$eye[1]+$uz*$eye[2]),
        $fx*$eye[0]+$fy*$eye[1]+$fz*$eye[2], 1,
    ];
}

function rotationY(float $angle): array {
    $c=cos($angle); $s=sin($angle);
    return [$c,0,$s,0, 0,1,0,0, -$s,0,$c,0, 0,0,0,1];
}

$proj  = perspectiveMatrix(60.0, $width / $height, 0.1, 100.0);
$view  = lookAtMatrix([0, 0, 3], [0, 0, 0], [0, 1, 0]);
$model = rotationY(0.7); // Slight rotation so we see 3D depth
$mvp   = mat4Multiply($proj, mat4Multiply($view, $model));
$mvpBinary = pack('f16', ...$mvp);

// ---------------------------------------------------------------------------
// 7. Record command buffer and render
// ---------------------------------------------------------------------------

$cmdPool = new Vk\CommandPool($device, 0, 0);
$cmds = $cmdPool->allocateBuffers(1, true);
$cmd = $cmds[0];

$cmd->begin(0x01); // ONE_TIME_SUBMIT
$cmd->beginRenderPass($renderPass, $framebuffer, 0, 0, $width, $height,
    [[0.06, 0.06, 0.09, 1.0]]); // Dark background

$cmd->setViewport(0.0, 0.0, (float)$width, (float)$height, 0.0, 1.0);
$cmd->setScissor(0, 0, $width, $height);
$cmd->bindPipeline(0, $pipeline); // VK_PIPELINE_BIND_POINT_GRAPHICS
$cmd->bindVertexBuffers(0, [$vertBuf], [0]);
$cmd->pushConstants($pipelineLayout, 0x01, 0, $mvpBinary);
$cmd->draw($vertexCount, 1, 0, 0);

$cmd->endRenderPass();
$cmd->end();

echo "Rendering...\n";
$fence = new Vk\Fence($device, false);
$queue->submit([$cmd], $fence, [], []);
$fence->wait(5_000_000_000);
echo "GPU render complete\n";

// ---------------------------------------------------------------------------
// 8. Readback: copy rendered image to host-visible staging buffer
// ---------------------------------------------------------------------------

$pixelSize = $width * $height * 4;
$readBuf = new Vk\Buffer($device, $pixelSize, 0x02, 0); // TRANSFER_DST_BIT
$rbMR = $readBuf->getMemoryRequirements();
$rbMem = new Vk\DeviceMemory($device, $rbMR['size'], findMemory($memProps, $rbMR, true));
$readBuf->bindMemory($rbMem, 0);

// The render pass finalLayout is already TRANSFER_SRC_OPTIMAL (6),
// so we can copy directly without an extra barrier.
$cmdPool->reset(0);
$cmds2 = $cmdPool->allocateBuffers(1, true);
$cmd2 = $cmds2[0];

$cmd2->begin(0x01);
$cmd2->copyImageToBuffer(
    $colorImage,
    Vk\Vk::IMAGE_LAYOUT_TRANSFER_SRC_OPTIMAL,
    $readBuf,
    $width,
    $height,
);
$cmd2->end();

echo "Copying image to buffer...\n";
$fence2 = new Vk\Fence($device, false);
$queue->submit([$cmd2], $fence2, [], []);
$fence2->wait(5_000_000_000);

// Read pixels from host-visible staging buffer
$rbMem->map(0, null);
$pixelData = $rbMem->read($pixelSize, 0);
$rbMem->unmap();

// ---------------------------------------------------------------------------
// 9. Write PPM image
// ---------------------------------------------------------------------------

$ppm = "P6\n{$width} {$height}\n255\n";
for ($i = 0; $i < $width * $height; $i++) {
    $offset = $i * 4;
    $ppm .= $pixelData[$offset];     // R
    $ppm .= $pixelData[$offset + 1]; // G
    $ppm .= $pixelData[$offset + 2]; // B
}

file_put_contents($outputPath, $ppm);
$sizeMB = number_format(strlen($ppm) / 1024 / 1024, 1);
echo "\nImage saved: {$outputPath} ({$width}x{$height}, {$sizeMB} MB)\n";
echo "Open with: open {$outputPath}\n";
echo "\nDone.\n";
