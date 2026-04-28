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
use PHPolygon\Rendering\Command\SetSky;
use PHPolygon\Rendering\Command\SetSkybox;
use PHPolygon\Rendering\Command\SetSkyColors;

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

    private const FRAME_UBO_SIZE = 128;  // mat4 view + mat4 projection

    /** Per-frame view/projection buffer (uploaded once per render(), shared across draws). */
    private Buffer $frameUbo;

    /** MSL shader source — compiled at runtime via Device::createLibraryWithSource. */
    private const SHADER_PATH = __DIR__ . '/../../resources/shaders/source/mesh3d.metal';
    private const SKY_SHADER_PATH = __DIR__ . '/../../resources/shaders/source/sky.metal';

    /** Atmospheric sky pipeline (depth test off, no vertex buffer — fullscreen triangle). */
    private ?RenderPipelineState $skyPipeline = null;
    private ?DepthStencilState $skyDepthState = null;
    private ?SetSky $pendingSky = null;

    /** Material/proc_mode prefix → proc_mode int cache (mirrors VioRenderer3D::resolveProcMode). */
    /** @var array<string, int> */
    private static array $procModeCache = [];

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
    /** @var float[] [r, g, b] */
    private array $emission = [0.0, 0.0, 0.0];
    private float $roughness = 0.5;
    private float $metallic  = 0.0;
    private float $alpha     = 1.0;
    private int   $procMode  = 0;
    private float $moonPhase = 0.0;
    /** @var float[] [r, g, b, near, far] */
    private array $fog = [0.5, 0.5, 0.5, 50.0, 200.0];
    /** @var float[] [x, y, z] */
    private array $cameraPos = [0.0, 0.0, 0.0];
    /** @var array<int, array{pos: float[], color: float[], intensity: float, radius: float}> */
    private array $pointLights = [];

    private float $clearR = 0.0;
    private float $clearG = 0.0;
    private float $clearB = 0.0;

    /** @var float[] [r, g, b] — sampled from horizon for water reflections */
    private array $skyColor     = [0.55, 0.70, 0.85];
    /** @var float[] [r, g, b] */
    private array $horizonColor = [0.85, 0.88, 0.92];
    /** @var float[] [r, g, b] — global terrain/vegetation tint, default no-op */
    private array $seasonTint   = [1.0, 1.0, 1.0];

    private readonly float $bootTime;
    private float $globalTime = 0.0;

    /** @var array<string, array{vb: Buffer, ib: Buffer, count: int}> */
    private array $meshCache = [];

    public function __construct(int $width, int $height, int $nativeWindowHandle)
    {
        $this->width    = $width;
        $this->height   = $height;
        $this->bootTime = microtime(true);
        $this->initMetal($nativeWindowHandle);
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
        $this->globalTime = microtime(true) - $this->bootTime;

        $identity         = Mat4::identity()->toArray();
        $this->viewMatrix = $identity;
        $this->projMatrix = $identity;
        $this->ambient    = [1.0, 1.0, 1.0, 0.1];
        $this->dirLight   = [0.0, -1.0, 0.0, 0.0, 1.0, 1.0, 1.0];
        $this->albedo     = [0.8, 0.8, 0.8];
        $this->emission   = [0.0, 0.0, 0.0];
        $this->roughness  = 0.5;
        $this->metallic   = 0.0;
        $this->alpha      = 1.0;
        $this->procMode   = 0;
        $this->moonPhase  = 0.0;
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
            } elseif ($command instanceof SetSkyColors) {
                $this->clearR = $command->skyColor->r;
                $this->clearG = $command->skyColor->g;
                $this->clearB = $command->skyColor->b;
                $this->skyColor = [$command->skyColor->r, $command->skyColor->g, $command->skyColor->b];
                if (property_exists($command, 'horizonColor') && $command->horizonColor !== null) {
                    $this->horizonColor = [
                        $command->horizonColor->r,
                        $command->horizonColor->g,
                        $command->horizonColor->b,
                    ];
                }
            } elseif ($command instanceof SetSky) {
                $this->pendingSky = $command;
            } elseif ($command instanceof SetSkybox) {
                // TODO Phase 7: Skybox pipeline
            }
        }

        $this->uploadFrameUbo();

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

        $encoder->setViewport(0.0, 0.0, (float) $this->width, (float) $this->height, 0.0, 1.0);
        $encoder->setScissorRect(0, 0, $this->width, $this->height);

        // ── Atmospheric sky pass (before opaque, depth test off, fullscreen) ──
        if ($this->pendingSky !== null && $this->skyPipeline !== null) {
            $this->encodeSkyPass($encoder, $this->pendingSky);
        }
        $this->pendingSky = null;

        $encoder->setRenderPipelineState($this->pipeline);
        $encoder->setDepthStencilState($this->depthStencilState);
        // Match OpenGLRenderer3D / VioRenderer3D: culling disabled. Many
        // procedural meshes (TerrainMesh, PalmFrondMesh, RoofBuilder gables)
        // mix winding orders or have geometric normals pointing opposite to
        // their vertex normals; back-face culling makes them disappear.
        $encoder->setCullMode(\Metal\CullModeNone);
        $encoder->setFrontFacingWinding(\Metal\WindingCounterClockwise);

        // FrameUBO is constant for the whole frame — bind once.
        $encoder->setVertexBuffer($this->frameUbo, 0, 1); // slot 1: FrameUBO

        // LightingUBO changes per material, so it must be uploaded per draw.
        // setFragmentBytes copies the data into the command stream (≤4 KB),
        // giving each draw its own snapshot — using a single shared MTLBuffer
        // and rewriting it would race with in-flight draws and cause every
        // mesh to render with the LAST draw's material colour (the bug we
        // had before this rewrite).
        foreach ($commandList->getCommands() as $command) {
            if ($command instanceof DrawMesh) {
                $this->resolveMaterial($command->materialId);
                $encoder->setFragmentBytes($this->buildLightingUboBytes(), 2);
                $this->drawMeshCommand($encoder, $command->meshId, $command->modelMatrix);
            } elseif ($command instanceof DrawMeshInstanced) {
                $this->resolveMaterial($command->materialId);
                $encoder->setFragmentBytes($this->buildLightingUboBytes(), 2);
                foreach ($command->matrices as $matrix) {
                    $this->drawMeshCommand($encoder, $command->meshId, $matrix);
                }
            }
        }

        $encoder->endEncoding();
        $commandBuffer->presentDrawable($drawable);
        $commandBuffer->commit();

        // Wait for the GPU to finish reading the shared buffers before the CPU
        // writes new data in the next frame (prevents StorageModeShared race condition).
        $commandBuffer->waitUntilCompleted();
    }

    // ── Private helpers ────────────────────────────────────────────────────

    private function resolveMaterial(string $materialId): void
    {
        $material = MaterialRegistry::get($materialId);
        if ($material !== null) {
            $this->albedo    = [$material->albedo->r, $material->albedo->g, $material->albedo->b];
            $this->emission  = [$material->emission->r, $material->emission->g, $material->emission->b];
            $this->roughness = $material->roughness;
            $this->metallic  = $material->metallic;
            $this->alpha     = $material->alpha;
        } else {
            $this->albedo    = [0.8, 0.8, 0.8];
            $this->emission  = [0.0, 0.0, 0.0];
            $this->roughness = 0.5;
            $this->metallic  = 0.0;
            $this->alpha     = 1.0;
        }

        $this->procMode = self::$procModeCache[$materialId] ?? $this->resolveProcMode($materialId);

        // Moon material encodes its current phase in the roughness slot
        // (mirrors VioRenderer3D — the dedicated moon shader reads it as
        // u_moon_phase). Reset to 0 for everything else so we never bleed
        // a leftover phase value into other procedural modes.
        $this->moonPhase = $this->procMode === 9 && $material !== null
            ? $material->roughness
            : 0.0;
    }

    private function resolveProcMode(string $materialId): int
    {
        $prefixRaw = strtok($materialId, '0123456789');
        $prefix    = $prefixRaw === false ? $materialId : $prefixRaw;

        $mode = match (true) {
            str_starts_with($prefix, 'sand_terrain')   => 1,
            str_starts_with($prefix, 'water_')         => 2,
            str_starts_with($prefix, 'rock')           => 3,
            str_starts_with($prefix, 'palm_trunk')     => 4,
            str_starts_with($prefix, 'palm_branch'),
            str_starts_with($prefix, 'palm_leaves'),
            str_starts_with($prefix, 'palm_leaf'),
            str_starts_with($prefix, 'palm_canopy'),
            str_starts_with($prefix, 'palm_frond')     => 5,
            str_starts_with($prefix, 'cloud_')         => 6,
            str_starts_with($prefix, 'hut_wood'),
            str_starts_with($prefix, 'hut_door'),
            str_starts_with($prefix, 'hut_table'),
            str_starts_with($prefix, 'hut_chair'),
            str_starts_with($prefix, 'hut_floor'),
            str_starts_with($prefix, 'hut_window')     => 7,
            str_starts_with($prefix, 'hut_thatch')     => 8,
            str_starts_with($prefix, 'moon_disc')      => 9,
            default                                    => 0,
        };

        self::$procModeCache[$materialId] = $mode;
        return $mode;
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

    /**
     * Pack the LightingUBO into a binary string matching the MSL `LightingUBO`
     * struct layout in mesh3d.metal. Caller hands this directly to
     * `RenderCommandEncoder::setFragmentBytes` — Metal copies the contents
     * into the command stream so each draw owns its own snapshot, even
     * though we mutate $this->* between draws.
     *
     * Field order, byte offsets, and packing must match the MSL struct
     * exactly. `packed_float3` is 12 bytes; the trailing scalar fills the
     * remaining 4 bytes of each 16-byte slot.
     */
    private function buildLightingUboBytes(): string
    {
        $data  = pack('f4', $this->ambient[0],   $this->ambient[1],   $this->ambient[2],   $this->ambient[3]);
        $data .= pack('f4', $this->dirLight[0],  $this->dirLight[1],  $this->dirLight[2],  $this->dirLight[3]);
        $data .= pack('f4', $this->dirLight[4],  $this->dirLight[5],  $this->dirLight[6],  0.0);
        $data .= pack('f4', $this->albedo[0],    $this->albedo[1],    $this->albedo[2],    $this->roughness);
        $data .= pack('f4', $this->emission[0],  $this->emission[1],  $this->emission[2],  $this->metallic);
        $data .= pack('f4', $this->fog[0],       $this->fog[1],       $this->fog[2],       $this->fog[3]);
        $data .= pack('f4', $this->cameraPos[0], $this->cameraPos[1], $this->cameraPos[2], $this->fog[4]);
        $plCount = count($this->pointLights);
        $data .= pack('l1f3', $plCount, 0.0, 0.0, 0.0);

        // Procedural-mode environment block (added 2026-04-28).
        $data .= pack('f3f1', $this->skyColor[0],     $this->skyColor[1],     $this->skyColor[2],     $this->globalTime);
        $data .= pack('f3f1', $this->horizonColor[0], $this->horizonColor[1], $this->horizonColor[2], $this->moonPhase);
        $data .= pack('f3l1', $this->seasonTint[0],   $this->seasonTint[1],   $this->seasonTint[2],   $this->procMode);
        $data .= pack('f4',   $this->alpha, 0.0, 0.0, 0.0);

        for ($i = 0; $i < 8; $i++) {
            if ($i < $plCount) {
                $pl    = $this->pointLights[$i];
                $data .= pack('f4', $pl['pos'][0],   $pl['pos'][1],   $pl['pos'][2],   $pl['intensity']);
                $data .= pack('f4', $pl['color'][0], $pl['color'][1], $pl['color'][2], $pl['radius']);
            } else {
                $data .= pack('f8', 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0);
            }
        }
        return $data;
    }

    private function initMetal(int $nativeWindowHandle): void
    {
        if ($nativeWindowHandle === 0) {
            throw new \RuntimeException(
                'MetalRenderer3D: native window handle is 0 — vio_native_window_handle() returned no NSWindow. '
                . 'Ensure the engine runs on macOS with vio + GLFW windowing.'
            );
        }

        $this->device       = \Metal\createSystemDefaultDevice();
        $this->commandQueue = $this->device->createCommandQueue();

        // Attach CAMetalLayer to the GLFW window's NSView. The handle is the
        // raw NSWindow pointer (uintptr_t cast to int), which Metal\Layer's
        // constructor bridges back to an Objective-C NSWindow before
        // installing the CAMetalLayer on its content view.
        $this->layer = new Layer($nativeWindowHandle, $this->device, \Metal\PixelFormatBGRA8Unorm);
        $this->layer->setDrawableSize($this->width, $this->height);

        $this->createPipeline();
        $this->createDepthStencilState();
        $this->createUBOs();
        $this->createSkyPipeline();
    }

    private function createPipeline(): void
    {
        // Compile MSL at runtime via createLibraryWithSource. This avoids the
        // dependency on full Xcode (the `metal` driver shipped with CLT only
        // is missing) and lets us iterate the shader without a build step.
        $mslSource = @file_get_contents(self::SHADER_PATH);
        if ($mslSource === false) {
            throw new \RuntimeException(
                'MetalRenderer3D: failed to read MSL source at ' . self::SHADER_PATH
            );
        }

        $library = $this->device->createLibraryWithSource($mslSource);
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

        // Sky pass uses an "always pass, never write" depth state — atmospheric
        // sky is drawn first as a fullscreen pass, then opaque geometry overwrites
        // wherever it draws.
        $skyDesc = new DepthStencilDescriptor();
        $skyDesc->setDepthCompareFunction(\Metal\CompareFunctionAlways);
        $skyDesc->setDepthWriteEnabled(false);
        $this->skyDepthState = $this->device->createDepthStencilState($skyDesc);
    }

    private function createUBOs(): void
    {
        // FrameUBO is rebound once per render() and read by every draw, so it
        // stays a managed Buffer. LightingUBO is per-draw and goes through
        // setFragmentBytes (see buildLightingUboBytes), so no Buffer is
        // allocated for it.
        $this->frameUbo = $this->device->createBuffer(self::FRAME_UBO_SIZE, \Metal\StorageModeShared);
    }

    private function createSkyPipeline(): void
    {
        $mslSource = @file_get_contents(self::SKY_SHADER_PATH);
        if ($mslSource === false) {
            // Sky is optional — log and skip. Renderer falls back to clear color.
            return;
        }

        try {
            $library = $this->device->createLibraryWithSource($mslSource);
            $vertFn  = $library->getFunction('vertex_sky');
            $fragFn  = $library->getFunction('fragment_sky');

            $desc = new RenderPipelineDescriptor();
            $desc->setVertexFunction($vertFn);
            $desc->setFragmentFunction($fragFn);
            $desc->getColorAttachment(0)->setPixelFormat(\Metal\PixelFormatBGRA8Unorm);
            $desc->setDepthAttachmentPixelFormat(\Metal\PixelFormatDepth32Float);
            // No vertex descriptor — fullscreen triangle uses [[vertex_id]].

            $this->skyPipeline = $this->device->createRenderPipelineState($desc);
        } catch (\Throwable $e) {
            $this->skyPipeline = null;
        }
    }

    /**
     * Encode the atmospheric sky pass. Runs INSIDE the same render encoder as
     * the opaque pass — depth state is "always pass, never write" so the
     * sky is rendered first and opaque geometry overdraws it.
     */
    private function encodeSkyPass(\Metal\RenderCommandEncoder $encoder, SetSky $sky): void
    {
        if ($this->skyPipeline === null || $this->skyDepthState === null) {
            return;
        }

        // Build inverse(projection * rotation_view) so the fragment shader can
        // unproject NDC back to a world-space view direction. Translation is
        // stripped — sky depends only on look direction, not position.
        $vm = $this->viewMatrix;
        $rotView = new Mat4([
            $vm[0],  $vm[1],  $vm[2],  0.0,
            $vm[4],  $vm[5],  $vm[6],  0.0,
            $vm[8],  $vm[9],  $vm[10], 0.0,
            0.0,     0.0,     0.0,     1.0,
        ]);
        // Match the Z-corrected projection used for opaque draws (uploadFrameUbo).
        $metalClip = new Mat4([
            1.0, 0.0, 0.0, 0.0,
            0.0, 1.0, 0.0, 0.0,
            0.0, 0.0, 0.5, 0.0,
            0.0, 0.0, 0.5, 1.0,
        ]);
        $proj  = $metalClip->multiply(new Mat4($this->projMatrix));
        $vp    = $proj->multiply($rotView);
        $invVp = $vp->inverse();

        $encoder->setRenderPipelineState($this->skyPipeline);
        $encoder->setDepthStencilState($this->skyDepthState);
        $encoder->setCullMode(\Metal\CullModeNone);

        // Pack SkyUBO matching the MSL struct layout (see sky.metal).
        // float4x4 (64) + 14 × (vec3 + scalar) blocks (16 bytes each) + 8 trailing bytes.
        // Easiest: build via pack() in the same field order as the struct.
        $sd = $sky->sunDirection;
        $sc = $sky->sunColor;
        $zc = $sky->zenithColor;
        $hc = $sky->horizonColor;
        $gc = $sky->groundColor;
        $md = $sky->moonDirection ?? new \PHPolygon\Math\Vec3(0.0, -1.0, 0.0);
        $mc = $sky->moonColor;
        $cwd = $sky->cloudWindDirection;
        $cwl = sqrt($cwd->x * $cwd->x + $cwd->z * $cwd->z);
        $wx  = $cwl > 1e-6 ? $cwd->x / $cwl : 1.0;
        $wz  = $cwl > 1e-6 ? $cwd->z / $cwl : 0.0;

        $bytes = pack('f16', ...$invVp->toArray())
               . pack('f3f',  $this->cameraPos[0], $this->cameraPos[1], $this->cameraPos[2], $sky->time)
               . pack('f3f',  $sd->x, $sd->y, $sd->z, $sky->sunIntensity)
               . pack('f3f',  $sc->r, $sc->g, $sc->b, $sky->sunSize)
               . pack('f3f',  $zc->r, $zc->g, $zc->b, $sky->sunGlowSize)
               . pack('f3f',  $hc->r, $hc->g, $hc->b, $sky->sunGlowIntensity)
               . pack('f3f',  $gc->r, $gc->g, $gc->b, $sky->starBrightness)
               . pack('f3f',  $md->x, $md->y, $md->z, $sky->moonIntensity)
               . pack('f3f',  $mc->r, $mc->g, $mc->b, $sky->cloudCover)
               . pack('ffff', $sky->cloudAltitude, $sky->cloudDensity, $sky->cloudWindSpeed, $sky->fogDensity)
               . pack('ffff', $wx, $wz, 0.0, 0.0);

        $encoder->setFragmentBytes($bytes, 0);
        $encoder->drawPrimitives(\Metal\PrimitiveTypeTriangle, 0, 3);
    }
}
