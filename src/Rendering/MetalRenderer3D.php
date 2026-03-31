<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

use Metal\Buffer;
use Metal\CommandQueue;
use Metal\DepthStencilDescriptor;
use Metal\DepthStencilState;
use Metal\Device;
use Metal\Layer;
use Metal\RenderPassDescriptor;
use Metal\RenderPipelineDescriptor;
use Metal\RenderPipelineState;
use Metal\TextureDescriptor;
use Metal\VertexDescriptor;
use PHPolygon\Geometry\MeshData;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Mat4;
use PHPolygon\Rendering\Command\AddPointLight;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\DrawMeshInstanced;
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetDirectionalLight;
use PHPolygon\Rendering\Command\SetFog;
use PHPolygon\Rendering\Command\SetSkybox;

/**
 * Native Apple Metal 3D renderer.
 * Translates a RenderCommandList into Metal draw calls via ext-metal (php-metal-gpu).
 *
 * Advantages over VulkanRenderer3D (via MoltenVK):
 *  - No Vulkan→Metal translation layer — direct Metal API calls
 *  - No SPIR-V→MSL compilation at pipeline creation time
 *  - Access to MetalFX upscaling, tile-based deferred rendering (future)
 *  - Simpler synchronisation model (Metal manages most frame sync internally)
 *
 * Requires:
 *  - ext-metal (php-metal-gpu) installed
 *  - GLFW window created with GLFW_CLIENT_API = GLFW_NO_API
 *  - macOS 12+ (Monterey) for full Metal 3 support
 *
 * Metal NDC vs OpenGL/Vulkan:
 *  - Y points UP (same as OpenGL — no Y-flip needed, unlike Vulkan)
 *  - Z range: 0..1 (same as Vulkan — Z correction matrix still required)
 */
class MetalRenderer3D implements Renderer3DInterface
{
    private int $width;
    private int $height;

    private Device $device;
    private Layer $layer;
    private CommandQueue $commandQueue;
    private RenderPipelineState $pipeline;
    private DepthStencilState $depthStencilState;

    private const FRAME_UBO_SIZE    = 128;  // mat4 view + mat4 projection
    private const LIGHTING_UBO_SIZE = 384;  // mirrors VulkanRenderer3D

    /** Per-frame uniform buffers (StorageModeShared → CPU writes, GPU reads) */
    private Buffer $frameUbo;
    private Buffer $lightingUbo;

    /** Pre-compiled .metallib (xcrun metal at build time) */
    private const METALLIB_PATH = __DIR__ . '/../../resources/shaders/compiled/mesh3d.metallib';

    /** @var float[] */
    private array $viewMatrix = [];
    /** @var float[] */
    private array $projMatrix = [];
    /** @var float[] [r, g, b, intensity] */
    private array $ambient = [1.0, 1.0, 1.0, 0.1];
    /** @var float[] [dx, dy, dz, intensity, r, g, b] */
    private array $dirLight = [0.0, -1.0, 0.0, 0.0, 1.0, 1.0, 1.0];
    /** @var float[] [r, g, b] */
    private array $albedo = [0.8, 0.8, 0.8];
    /** @var float[] [r, g, b, near, far] */
    private array $fog = [0.5, 0.5, 0.5, 50.0, 200.0];
    /** @var float[] [x, y, z] */
    private array $cameraPos = [0.0, 0.0, 0.0];
    /** @var array<int, array{pos: float[], color: float[], intensity: float, radius: float}> */
    private array $pointLights = [];

    private float $clearR = 0.0;
    private float $clearG = 0.0;
    private float $clearB = 0.0;

    /** @var array<string, array{vb: Buffer, ib: Buffer, count: int}> */
    private array $meshCache = [];

    public function __construct(int $width, int $height, \GLFWwindow $windowHandle)
    {
        $this->width  = $width;
        $this->height = $height;
        $this->initMetal($windowHandle);
    }

    public function beginFrame(): void
    {
        $this->pointLights = [];
    }

    public function endFrame(): void
    {
        // Metal presentation is handled inside render() via commandBuffer->presentDrawable().
        // No separate endFrame work needed (unlike Vulkan's queue->present()).
    }

    public function clear(Color $color): void
    {
        $this->clearR = $color->r;
        $this->clearG = $color->g;
        $this->clearB = $color->b;
    }

    public function setViewport(int $x, int $y, int $width, int $height): void
    {
        $this->width  = $width;
        $this->height = $height;
        $this->layer->setDrawableSize($width, $height);
    }

    public function getWidth(): int  { return $this->width; }
    public function getHeight(): int { return $this->height; }

    public function render(RenderCommandList $commandList): void
    {
        $identity         = Mat4::identity()->toArray();
        $this->viewMatrix = $identity;
        $this->projMatrix = $identity;
        $this->ambient    = [1.0, 1.0, 1.0, 0.1];
        $this->dirLight   = [0.0, -1.0, 0.0, 0.0, 1.0, 1.0, 1.0];
        $this->albedo     = [0.8, 0.8, 0.8];
        $this->fog        = [0.5, 0.5, 0.5, 50.0, 200.0];
        $this->cameraPos  = [0.0, 0.0, 0.0];

        foreach ($commandList->getCommands() as $command) {
            if ($command instanceof SetCamera) {
                $this->viewMatrix = $command->viewMatrix->toArray();
                $this->projMatrix = $command->projectionMatrix->toArray();
                $camPos           = $command->viewMatrix->inverse()->getTranslation();
                $this->cameraPos  = [$camPos->x, $camPos->y, $camPos->z];
            } elseif ($command instanceof SetAmbientLight) {
                $this->ambient = [$command->color->r, $command->color->g, $command->color->b, $command->intensity];
            } elseif ($command instanceof SetDirectionalLight) {
                $this->dirLight = [
                    $command->direction->x, $command->direction->y, $command->direction->z,
                    $command->intensity,
                    $command->color->r, $command->color->g, $command->color->b,
                ];
            } elseif ($command instanceof AddPointLight && count($this->pointLights) < 8) {
                $this->pointLights[] = [
                    'pos'       => [$command->position->x, $command->position->y, $command->position->z],
                    'color'     => [$command->color->r, $command->color->g, $command->color->b],
                    'intensity' => $command->intensity,
                    'radius'    => $command->radius,
                ];
            } elseif ($command instanceof SetFog) {
                $this->fog = [$command->color->r, $command->color->g, $command->color->b, $command->near, $command->far];
            } elseif ($command instanceof SetSkybox) {
                // TODO Phase 7: Skybox pipeline
            }
        }

        $this->uploadFrameUbo();
        $this->uploadLightingUbo();

        // ── Acquire drawable + build render pass ───────────────────────────
        $drawable     = $this->layer->nextDrawable();
        $colorTexture = $drawable->getTexture();

        // Depth texture — recreated each frame (simple; optimise with caching if needed)
        $depthDesc = new TextureDescriptor();
        $depthDesc->setPixelFormat(\Metal\PixelFormatDepth32Float);
        $depthDesc->setWidth($this->width);
        $depthDesc->setHeight($this->height);
        $depthDesc->setUsage(\Metal\TextureUsageRenderTarget);
        $depthDesc->setStorageMode(\Metal\StorageModePrivate); // depth/stencil must be Private on Apple GPUs
        $depthTexture = $this->device->createTexture($depthDesc);

        $renderPass = new RenderPassDescriptor();
        $renderPass->setColorAttachmentTexture(0, $colorTexture);
        $renderPass->setColorAttachmentLoadAction(0, \Metal\LoadActionClear);
        $renderPass->setColorAttachmentStoreAction(0, \Metal\StoreActionStore);
        $renderPass->setColorAttachmentClearColor(0, $this->clearR, $this->clearG, $this->clearB, 1.0);
        $renderPass->setDepthAttachmentTexture($depthTexture);
        $renderPass->setDepthAttachmentLoadAction(\Metal\LoadActionClear);
        $renderPass->setDepthAttachmentStoreAction(\Metal\StoreActionStore);
        $renderPass->setDepthAttachmentClearDepth(1.0);

        // ── Encode draw calls ──────────────────────────────────────────────
        $commandBuffer = $this->commandQueue->createCommandBuffer();
        $encoder       = $commandBuffer->createRenderCommandEncoder($renderPass);

        $encoder->setRenderPipelineState($this->pipeline);
        $encoder->setDepthStencilState($this->depthStencilState);
        $encoder->setCullMode(\Metal\CullModeBack);
        $encoder->setFrontFacingWinding(\Metal\WindingCounterClockwise);
        $encoder->setViewport(0.0, 0.0, (float) $this->width, (float) $this->height, 0.0, 1.0);
        $encoder->setScissorRect(0, 0, $this->width, $this->height);

        // Bind UBOs — indices match [[buffer(N)]] in mesh3d.metal
        $encoder->setVertexBuffer($this->frameUbo,   0, 1); // slot 1: FrameUBO
        $encoder->setFragmentBuffer($this->lightingUbo, 0, 2); // slot 2: LightingUBO

        foreach ($commandList->getCommands() as $command) {
            if ($command instanceof DrawMesh) {
                $this->resolveMaterial($command->materialId);
                $this->uploadLightingUbo();
                $this->drawMeshCommand($encoder, $command->meshId, $command->modelMatrix);
            } elseif ($command instanceof DrawMeshInstanced) {
                $this->resolveMaterial($command->materialId);
                $this->uploadLightingUbo();
                foreach ($command->matrices as $matrix) {
                    $this->drawMeshCommand($encoder, $command->meshId, $matrix);
                }
            }
        }

        $encoder->endEncoding();
        $commandBuffer->presentDrawable($drawable);
        $commandBuffer->commit();
    }

    // ── Private helpers ────────────────────────────────────────────────────

    private function resolveMaterial(string $materialId): void
    {
        $material     = MaterialRegistry::get($materialId);
        $this->albedo = $material !== null
            ? [$material->albedo->r, $material->albedo->g, $material->albedo->b]
            : [0.8, 0.8, 0.8];
    }

    private function drawMeshCommand(\Metal\RenderCommandEncoder $encoder, string $meshId, Mat4 $modelMatrix): void
    {
        $meshData = MeshRegistry::get($meshId);
        if ($meshData === null) {
            return;
        }

        if (!isset($this->meshCache[$meshId])) {
            $this->uploadMesh($meshId, $meshData);
        }

        // Push model matrix inline via setVertexBytes (slot 0).
        // Metal copies up to 4 KB directly into the command stream — no buffer allocation.
        // Equivalent to Vulkan pushConstants() but simpler: no pipeline layout declaration needed.
        $modelBytes = pack('f16', ...$modelMatrix->toArray());
        $encoder->setVertexBytes($modelBytes, 0); // slot 0: model matrix (length implicit from string)

        $encoder->setVertexBuffer($this->meshCache[$meshId]['vb'], 0, 3); // slot 3: vertex data
        $encoder->drawIndexedPrimitives(
            \Metal\PrimitiveTypeTriangle,
            $this->meshCache[$meshId]['count'],
            \Metal\IndexTypeUInt32,
            $this->meshCache[$meshId]['ib'],
            0,
        );
    }

    private function uploadMesh(string $meshId, MeshData $meshData): void
    {
        $vertexCount = $meshData->vertexCount();
        $vertexData  = '';
        for ($i = 0; $i < $vertexCount; $i++) {
            $vertexData .= pack(
                'f8',
                $meshData->vertices[$i * 3],     $meshData->vertices[$i * 3 + 1], $meshData->vertices[$i * 3 + 2],
                $meshData->normals[$i * 3],      $meshData->normals[$i * 3 + 1],  $meshData->normals[$i * 3 + 2],
                $meshData->uvs[$i * 2],          $meshData->uvs[$i * 2 + 1],
            );
        }

        $indexData = '';
        foreach ($meshData->indices as $idx) {
            $indexData .= pack('V', $idx);
        }

        // StorageModeShared: CPU writes once at upload, GPU reads every frame.
        $vb = $this->device->createBuffer(strlen($vertexData), \Metal\StorageModeShared);
        $vb->writeRawContents($vertexData, 0);
        $ib = $this->device->createBuffer(strlen($indexData), \Metal\StorageModeShared);
        $ib->writeRawContents($indexData, 0);

        $this->meshCache[$meshId] = ['vb' => $vb, 'ib' => $ib, 'count' => count($meshData->indices)];
    }

    private function uploadFrameUbo(): void
    {
        // Metal NDC: Y points UP (same as OpenGL) — no Y-flip needed.
        // Z range is 0..1 (same as Vulkan) — Z correction still required.
        $metalClip = new Mat4([
            1.0, 0.0, 0.0, 0.0,
            0.0, 1.0, 0.0, 0.0,  // Y row stays positive — Metal Y-up
            0.0, 0.0, 0.5, 0.0,
            0.0, 0.0, 0.5, 1.0,
        ]);
        $correctedProj = $metalClip->multiply(new Mat4($this->projMatrix));
        $data = pack('f16', ...$this->viewMatrix)
              . pack('f16', ...$correctedProj->toArray());
        $this->frameUbo->writeRawContents($data, 0);
    }

    private function uploadLightingUbo(): void
    {
        // Identical layout to VulkanRenderer3D — same LightingUBO struct in the shader.
        $data  = pack('f4', $this->ambient[0],   $this->ambient[1],   $this->ambient[2],   $this->ambient[3]);
        $data .= pack('f4', $this->dirLight[0],  $this->dirLight[1],  $this->dirLight[2],  $this->dirLight[3]);
        $data .= pack('f4', $this->dirLight[4],  $this->dirLight[5],  $this->dirLight[6],  0.0);
        $data .= pack('f4', $this->albedo[0],    $this->albedo[1],    $this->albedo[2],    0.0);
        $data .= pack('f4', 0.0, 0.0, 0.0, 0.0); // u_emission.xyz + u_metallic (not yet exposed)
        $data .= pack('f4', $this->fog[0],       $this->fog[1],       $this->fog[2],       $this->fog[3]);
        $data .= pack('f4', $this->cameraPos[0], $this->cameraPos[1], $this->cameraPos[2], $this->fog[4]);
        $plCount = count($this->pointLights);
        $data .= pack('l1f3', $plCount, 0.0, 0.0, 0.0);
        for ($i = 0; $i < 8; $i++) {
            if ($i < $plCount) {
                $pl    = $this->pointLights[$i];
                $data .= pack('f4', $pl['pos'][0],   $pl['pos'][1],   $pl['pos'][2],   $pl['intensity']);
                $data .= pack('f4', $pl['color'][0], $pl['color'][1], $pl['color'][2], $pl['radius']);
            } else {
                $data .= pack('f8', 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0);
            }
        }
        $this->lightingUbo->writeRawContents($data, 0);
    }

    private function initMetal(\GLFWwindow $windowHandle): void
    {
        $this->device       = \Metal\createSystemDefaultDevice();
        $this->commandQueue = $this->device->createCommandQueue();

        // Attach CAMetalLayer to the GLFW window's NSView.
        // glfwGetCocoaWindow() returns the NSWindow pointer as an integer (macOS only).
        // Must happen before any nextDrawable() calls.
        $nsWindowPtr  = glfwGetCocoaWindow($windowHandle);
        $this->layer  = new Layer($nsWindowPtr, $this->device, \Metal\PixelFormatBGRA8Unorm);
        $this->layer->setDrawableSize($this->width, $this->height);

        $this->createPipeline();
        $this->createDepthStencilState();
        $this->createUBOs();
    }

    private function createPipeline(): void
    {
        $library = $this->device->createLibraryWithFile(self::METALLIB_PATH);
        $vertFn  = $library->getFunction('vertex_mesh3d');
        $fragFn  = $library->getFunction('fragment_mesh3d');

        // Vertex layout: position(float3) + normal(float3) + uv(float2) = 32 bytes
        // Slot 0 = model matrix (setVertexBytes), slot 1 = FrameUBO, so vertex data goes to slot 3.
        $vertexDesc = new VertexDescriptor();
        $vertexDesc->setAttribute(0, \Metal\VertexFormatFloat3, 0,  3); // [[attribute(0)]] position  — buffer 3
        $vertexDesc->setAttribute(1, \Metal\VertexFormatFloat3, 12, 3); // [[attribute(1)]] normal    — buffer 3
        $vertexDesc->setAttribute(2, \Metal\VertexFormatFloat2, 24, 3); // [[attribute(2)]] uv        — buffer 3
        $vertexDesc->setLayout(3, 32);                                   // stride 32, buffer slot 3

        $pipelineDesc = new RenderPipelineDescriptor();
        $pipelineDesc->setVertexFunction($vertFn);
        $pipelineDesc->setFragmentFunction($fragFn);
        $pipelineDesc->getColorAttachment(0)->setPixelFormat(\Metal\PixelFormatBGRA8Unorm);
        $pipelineDesc->setDepthAttachmentPixelFormat(\Metal\PixelFormatDepth32Float);
        $pipelineDesc->setVertexDescriptor($vertexDesc);

        $this->pipeline = $this->device->createRenderPipelineState($pipelineDesc);
    }

    private function createDepthStencilState(): void
    {
        $desc = new DepthStencilDescriptor();
        $desc->setDepthCompareFunction(\Metal\CompareFunctionLess);
        $desc->setDepthWriteEnabled(true);
        $this->depthStencilState = $this->device->createDepthStencilState($desc);
    }

    private function createUBOs(): void
    {
        // StorageModeShared: accessible by both CPU and GPU — ideal for UBOs written each frame
        $this->frameUbo    = $this->device->createBuffer(self::FRAME_UBO_SIZE,    \Metal\StorageModeShared);
        $this->lightingUbo = $this->device->createBuffer(self::LIGHTING_UBO_SIZE, \Metal\StorageModeShared);
    }
}
