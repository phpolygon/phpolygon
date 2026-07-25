<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

use PHPolygon\Geometry\MeshData;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Mat4;
use PHPolygon\Rendering\Command\AddPointLight;
use PHPolygon\Rendering\Command\AddSpotLight;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\DrawMeshInstanced;
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetDirectionalLight;
use PHPolygon\Rendering\Command\SetFog;
use PHPolygon\Rendering\Command\SetSkybox;
use PHPolygon\Rendering\Command\SetSkyColors;
use PHPolygon\Rendering\PostProcess\VulkanFxaaPass;
use PHPolygon\Rendering\Quality\AntiAliasing;
use Vk\Buffer;
use Vk\CommandPool;
use Vk\DescriptorPool;
use Vk\DescriptorSet;
use Vk\DescriptorSetLayout;
use Vk\Device;
use Vk\DeviceMemory;
use Vk\Fence;
use Vk\Image;
use Vk\ImageView;
use Vk\Instance;
use Vk\PhysicalDevice;
use Vk\Pipeline;
use Vk\PipelineLayout;
use Vk\Queue;
use Vk\RenderPass;
use Vk\Semaphore;
use Vk\ShaderModule;
use Vk\Surface;
use Vk\Swapchain;

/**
 * Vulkan 3D renderer.
 *
 * Renders into a private offscreen image (not directly into the swapchain) and
 * copies the result to the acquired swapchain image before presentation.
 * This avoids MoltenVK instability when drawing indexed to swapchain images.
 *
 * Requires a GLFW window created with GLFW_CLIENT_API = GLFW_NO_API.
 */
class VulkanRenderer3D implements Renderer3DInterface
{
    private int $width;
    private int $height;

    private Instance $instance;
    private PhysicalDevice $gpu;
    private Surface $surface;
    private Device $device;
    private Queue $queue;
    private int $graphicsFamily;
    private Swapchain $swapchain;
    private int $surfaceFormat;
    private RenderPass $renderPass;
    private \Vk\Framebuffer $framebuffer;
    private Pipeline $pipeline;
    private PipelineLayout $pipelineLayout;
    private DescriptorSetLayout $descriptorSetLayout;
    private DescriptorPool $descriptorPool;
    private DescriptorSet $descriptorSet;
    private CommandPool $commandPool;
    private \Vk\CommandBuffer $commandBuffer;
    private Fence $inFlightFence;
    private Semaphore $imageAvailableSem;
    private Semaphore $renderFinishedSem;

    /** @var array<int, Image> — swapchain images, stored only to prevent PHP GC during rendering */
    private array $swapImages = [];

    // Offscreen color image: render here, then copyImage → swapchain
    private Image     $offscreenColor;
    private DeviceMemory $offscreenColorMem;
    private ImageView $offscreenColorView;

    // Depth resources — class properties to prevent premature GC
    private Image     $depthImage;
    private DeviceMemory $depthMem;
    private ImageView $depthView;

    // No Framebuffer or RenderPass — using VK_KHR_dynamic_rendering

    /** @var array<array<mixed>> */
    private array $memTypes = [];

    private Buffer    $frameUbo;
    private DeviceMemory $frameUboMem;
    private Buffer    $lightingUbo;
    private DeviceMemory $lightingUboMem;

    private int   $currentImageIndex = 0;
    private float $clearR = 0.0;
    private float $clearG = 0.0;
    private float $clearB = 0.0;

    /** @var float[] */
    private array $viewMatrix = [];
    /** @var float[] */
    private array $projMatrix = [];
    /** @var float[] */
    private array $ambient  = [1.0, 1.0, 1.0, 0.1];
    /** @var float[] */
    private array $dirLight = [0.0, -1.0, 0.0, 0.0, 1.0, 1.0, 1.0];
    /** @var float[] */
    private array $albedo   = [0.8, 0.8, 0.8];
    private float $roughness = 0.5;
    private float $metallic  = 0.0;
    /** @var float[] */
    private array $fog      = [0.5, 0.5, 0.5, 50.0, 200.0];
    /** @var float[] */
    private array $cameraPos = [0.0, 0.0, 0.0];
    /** @var array<int, array{pos: float[], color: float[], intensity: float, radius: float}> */
    private array $pointLights = [];
    /** @var array<int, array{pos: float[], dir: float[], color: float[], intensity: float, range: float, angle: float, penumbra: float}> */
    private array $spotLights = [];

    /** @var array<string, array{vb: Buffer, vbMem: DeviceMemory, ib: Buffer, ibMem: DeviceMemory, count: int}> */
    private array $meshCache = [];

    private const VERT_SPV         = __DIR__ . '/../../resources/shaders/compiled/mesh3d_vk.vert.spv';
    private const FRAG_SPV         = __DIR__ . '/../../resources/shaders/compiled/mesh3d_vk.frag.spv';
    private const FRAME_UBO_SIZE   = 128;
    // 384 = header + 8 point-light slots. Spot block adds a 16-byte count slot
    // plus 8 × 64-byte spot slots = 528 bytes → 912. Must match the
    // LightingUBO struct in mesh3d_vk.frag.glsl (recompile SPIR-V after edits).
    private const LIGHTING_UBO_SIZE = 912;

    private const VK_PIPELINE_BIND_GRAPHICS    = 0;
    private const VK_SHADER_STAGE_VERTEX       = 1;
    private const VK_SHADER_STAGE_FRAGMENT     = 16;
    private const VK_INDEX_TYPE_UINT32         = 1;
    private const VK_IMAGE_USAGE_COLOR         = 16;   // COLOR_ATTACHMENT
    private const VK_IMAGE_USAGE_DEPTH         = 32;   // DEPTH_STENCIL_ATTACHMENT
    private const VK_IMAGE_USAGE_TRANSFER_SRC  = 1;
    private const VK_IMAGE_USAGE_TRANSFER_DST  = 2;
    private const VK_IMAGE_USAGE_SAMPLED       = 4;
    private const VK_SHARING_EXCLUSIVE         = 0;
    private const VK_SAMPLE_COUNT_1            = 1;
    private const VK_LOAD_OP_CLEAR             = 1;
    private const VK_LOAD_OP_DONT_CARE         = 2;
    private const VK_STORE_OP_STORE            = 0;
    private const VK_STORE_OP_DONT_CARE        = 1;
    private const VK_LAYOUT_UNDEFINED          = 0;
    private const VK_LAYOUT_PRESENT_SRC        = 1000001002;
    private const VK_LAYOUT_COLOR_ATTACHMENT   = 2;
    private const VK_LAYOUT_DEPTH_ATTACHMENT   = 3;
    private const VK_LAYOUT_SHADER_READ_ONLY   = 5;
    private const VK_LAYOUT_TRANSFER_SRC       = 6;
    private const VK_LAYOUT_TRANSFER_DST       = 7;
    private const VK_ASPECT_COLOR              = 1;
    private const VK_ASPECT_DEPTH              = 2;
    private const VK_FORMAT_D32_SFLOAT         = 126;
    private const VK_FORMAT_R32G32B32_SFLOAT   = 106;
    private const VK_FORMAT_R32G32_SFLOAT      = 103;
    private const VK_BUFFER_USAGE_VERTEX       = 128;
    private const VK_BUFFER_USAGE_INDEX        = 64;
    private const VK_BUFFER_USAGE_UNIFORM      = 16;
    private const VK_DESCRIPTOR_UNIFORM_BUFFER = 6;
    private const VK_VERTEX_INPUT_RATE_VERTEX  = 0;
    private const VK_CULL_MODE_BACK            = 2;
    private const VK_FRONT_FACE_CCW            = 0;
    private const VK_CMD_POOL_RESET_CMD_BUFFER = 2;
    private const VK_PRESENT_MODE_FIFO         = 2;
    private const VK_CMD_ONE_TIME_SUBMIT        = 1;
    private const VK_FILTER_LINEAR              = 1;
    // Access masks
    private const VK_ACCESS_NONE               = 0;
    private const VK_ACCESS_SHADER_READ        = 0x20;     // 32
    private const VK_ACCESS_COLOR_WRITE        = 0x100;    // 256
    private const VK_ACCESS_DEPTH_WRITE        = 0x400;    // 1024
    private const VK_ACCESS_TRANSFER_READ      = 0x800;    // 2048
    private const VK_ACCESS_TRANSFER_WRITE     = 0x1000;   // 4096
    // Pipeline stages
    private const VK_STAGE_TOP                 = 0x1;      // TOP_OF_PIPE
    private const VK_STAGE_EARLY_FRAG_TESTS    = 0x100;    // EARLY_FRAGMENT_TESTS
    private const VK_STAGE_FRAGMENT            = 0x80;     // FRAGMENT_SHADER
    private const VK_STAGE_COLOR_OUTPUT        = 0x400;    // COLOR_ATTACHMENT_OUTPUT
    private const VK_STAGE_TRANSFER            = 0x1000;   // TRANSFER
    private const VK_STAGE_BOTTOM              = 0x2000;   // BOTTOM_OF_PIPE

    /**
     * Live graphics settings. Honours fog toggle + view-distance clamp during
     * SetFog handling, plus Phase 1.5 render-scale and (best-effort) MSAA via
     * `$scaledTarget`.
     */
    private GraphicsSettings $settings;

    /**
     * Phase 1.5 scaled / multisample off-screen target. Allocated lazily via
     * `resizeOffscreenIfNeeded()` whenever renderScale != 1.0 or AA != Off.
     * When null the renderer uses the constructor-allocated single-sample
     * `$offscreenColor` + `$depthImage` at native swapchain resolution.
     */
    private ?VulkanOffscreenTarget $scaledTarget = null;

    /** Render width / height for the active off-screen target this frame. */
    private int $offscreenWidth = 0;
    private int $offscreenHeight = 0;

    /** True when this frame draws into `$scaledTarget` instead of the default chain. */
    private bool $offscreenActive = false;

    /** Lazily-created FXAA post-process pass. */
    private ?VulkanFxaaPass $fxaaPass = null;

    /** True once the FXAA pass tried to allocate AND failed. Suppresses retry. */
    private bool $fxaaInitFailed = false;

    /**
     * Single-sample colour image that receives the FXAA fragment-shader
     * output at swapchain resolution. Allocated lazily by `ensureFxaaOutput()`
     * when AntiAliasing::FXAA is selected, released when FXAA is off or the
     * window resizes.
     */
    private ?Image $fxaaOutputImage = null;
    private ?DeviceMemory $fxaaOutputMem = null;
    private ?ImageView $fxaaOutputView = null;
    private ?\Vk\Framebuffer $fxaaFramebuffer = null;
    private int $fxaaOutputWidth = 0;
    private int $fxaaOutputHeight = 0;

    public function __construct(
        int $width,
        int $height,
        object $windowHandle,
        ?GraphicsSettings $settings = null,
    ) {
        $this->width  = $width;
        $this->height = $height;
        $this->settings = $settings ?? new GraphicsSettings();
        $this->initVulkan($windowHandle);
    }

    public function applySettings(GraphicsSettings $settings): void
    {
        $this->settings = $settings;

        // Phase 1.5: rebuild the scaled / multisample off-screen target when
        // the slider moves. Default settings (renderScale 1.0 + AA Off) keep
        // the constructor-allocated single-sample chain in use, so the fast
        // path matches pre-Phase-1.5 behaviour byte-for-byte.
        $this->resizeOffscreenIfNeeded();
    }

    /**
     * Decide whether the scaled off-screen pipeline is needed for the current
     * `$this->settings`, allocate or resize the target, and update the
     * helper-state used during rendering. Wired from `applySettings()` and
     * from the start of every frame so window resizes also propagate.
     */
    private function resizeOffscreenIfNeeded(): void
    {
        $needsScaled = $this->settings->renderScale !== 1.0
            || $this->settings->antiAliasing->sampleCount() > 1;

        if (!$needsScaled) {
            // Drop any previously-allocated scaled target; default path is
            // single-sample at native swapchain resolution.
            if ($this->scaledTarget !== null) {
                $this->scaledTarget->release();
                $this->scaledTarget = null;
            }
            $this->offscreenWidth  = 0;
            $this->offscreenHeight = 0;
            return;
        }

        $renderW = max(1, (int) round($this->width  * $this->settings->renderScale));
        $renderH = max(1, (int) round($this->height * $this->settings->renderScale));
        $samples = max(1, $this->settings->antiAliasing->sampleCount());

        if ($this->scaledTarget === null) {
            $this->scaledTarget = new VulkanOffscreenTarget($this->device);
        }

        $this->scaledTarget->resize(
            $renderW,
            $renderH,
            $samples,
            $this->surfaceFormat,
            self::VK_FORMAT_D32_SFLOAT,
            fn (array $req, bool $hostVisible): int => $this->findMemory($req, $hostVisible),
        );

        $this->offscreenWidth  = $renderW;
        $this->offscreenHeight = $renderH;
    }

    public function getSettings(): GraphicsSettings
    {
        return $this->settings;
    }

    public function __destruct()
    {
        // Wait for all in-flight GPU work to finish before PHP destroys Vulkan objects.
        // Without this, the GPU may still be accessing images/buffers while PHP frees them,
        // causing a MoltenVK segfault in MVKSwapchain::destroy().
        $this->queue->waitIdle();

        // Drop Phase 1.5 helpers before the Device goes out of scope so their
        // child objects (images, framebuffers, render passes) tear down with
        // a still-valid parent device.
        $this->releaseFxaaResources();
        if ($this->fxaaPass !== null) {
            $this->fxaaPass->release();
            $this->fxaaPass = null;
        }
        if ($this->scaledTarget !== null) {
            $this->scaledTarget->release();
            $this->scaledTarget = null;
        }
    }

    public function beginFrame(): void
    {
        $this->pointLights = [];
        $this->spotLights = [];

        $this->inFlightFence->wait(1_000_000_000);
        $this->inFlightFence->reset();

        $this->currentImageIndex = $this->swapchain->acquireNextImage(
            $this->imageAvailableSem,
            null,
            1_000_000_000,
        );

        $this->commandBuffer->reset(0);
        $this->commandBuffer->begin(self::VK_CMD_ONE_TIME_SUBMIT);
    }

    public function endFrame(): void
    {
        $this->commandBuffer->end();

        $this->queue->submit(
            [$this->commandBuffer],
            $this->inFlightFence,
            [$this->imageAvailableSem],
            [$this->renderFinishedSem],
        );

        $this->queue->present(
            [$this->swapchain],
            [$this->currentImageIndex],
            [$this->renderFinishedSem],
        );
    }

    public function clear(Color $color): void
    {
        $this->clearR = $color->r;
        $this->clearG = $color->g;
        $this->clearB = $color->b;
    }

    public function setViewport(int $x, int $y, int $width, int $height): void
    {
        $changed = ($this->width !== $width) || ($this->height !== $height);
        $this->width  = $width;
        $this->height = $height;
        if ($changed) {
            $this->resizeOffscreenIfNeeded();
            // FXAA output sits at swapchain resolution, so a resize must
            // throw away the previous image. ensureFxaaResources() rebuilds
            // it lazily on the next FXAA frame.
            $this->releaseFxaaResources();
        }
    }

    /**
     * Allocate (or reuse) the FXAA pipeline + the swapchain-resolution output
     * image that the FXAA fragment shader writes into. Returns true once the
     * full chain is ready to record commands. Failures (older ext-vulkan
     * builds without `Vk\Sampler` / `DescriptorSet::writeImage`) are sticky -
     * the renderer falls back to a plain blit and never retries this frame.
     */
    private function ensureFxaaResources(): bool
    {
        if ($this->fxaaInitFailed) {
            return false;
        }

        if ($this->fxaaPass === null) {
            $this->fxaaPass = new VulkanFxaaPass($this->device);
        }

        if (!$this->fxaaPass->isReady() && !$this->fxaaPass->initialise($this->surfaceFormat)) {
            $this->fxaaInitFailed = true;
            return false;
        }

        if ($this->fxaaOutputImage !== null
            && $this->fxaaOutputWidth === $this->width
            && $this->fxaaOutputHeight === $this->height
        ) {
            return true;
        }

        $this->releaseFxaaResources();
        $renderPass = $this->fxaaPass->renderPass();
        if ($renderPass === null) {
            $this->fxaaInitFailed = true;
            return false;
        }

        try {
            $this->fxaaOutputImage = new Image(
                $this->device,
                $this->width, $this->height,
                $this->surfaceFormat,
                self::VK_IMAGE_USAGE_COLOR | self::VK_IMAGE_USAGE_TRANSFER_SRC,
                0,
                self::VK_SAMPLE_COUNT_1,
            );
            $req  = $this->fxaaOutputImage->getMemoryRequirements();
            $size = $req['size'];
            if (!is_int($size)) {
                throw new \RuntimeException('Invalid FXAA output image memory requirements');
            }
            $this->fxaaOutputMem = new DeviceMemory($this->device, $size, $this->findMemory($req, false));
            $this->fxaaOutputImage->bindMemory($this->fxaaOutputMem, 0);
            $this->fxaaOutputView = new ImageView(
                $this->device, $this->fxaaOutputImage, $this->surfaceFormat, self::VK_ASPECT_COLOR, 1,
            );
            $this->fxaaFramebuffer = new \Vk\Framebuffer(
                $this->device, $renderPass,
                [$this->fxaaOutputView],
                $this->width, $this->height, 1,
            );
            $this->fxaaOutputWidth  = $this->width;
            $this->fxaaOutputHeight = $this->height;
            return true;
        } catch (\Throwable $e) {
            fwrite(STDERR, "[VkRenderer] FXAA output allocation failed: " . $e->getMessage() . "\n");
            $this->releaseFxaaResources();
            $this->fxaaInitFailed = true;
            return false;
        }
    }

    private function releaseFxaaResources(): void
    {
        $this->fxaaFramebuffer  = null;
        $this->fxaaOutputView   = null;
        $this->fxaaOutputImage  = null;
        $this->fxaaOutputMem    = null;
        $this->fxaaOutputWidth  = 0;
        $this->fxaaOutputHeight = 0;
    }

    public function getWidth(): int { return $this->width; }
    public function getHeight(): int { return $this->height; }

    public function render(RenderCommandList $commandList): void
    {
        $identity = Mat4::identity()->toArray();
        $this->viewMatrix = $identity;
        $this->projMatrix = $identity;
        $this->ambient    = [1.0, 1.0, 1.0, 0.1];
        $this->dirLight   = [0.0, -1.0, 0.0, 0.0, 1.0, 1.0, 1.0];
        $this->albedo     = [0.8, 0.8, 0.8];
        $this->roughness  = 0.5;
        $this->metallic   = 0.0;
        $this->fog        = [0.5, 0.5, 0.5, 50.0, 200.0];
        $this->cameraPos  = [0.0, 0.0, 0.0];

        foreach ($commandList->getCommands() as $command) {
            if ($command instanceof SetCamera) {
                $this->viewMatrix = $command->viewMatrix->toArray();
                $this->projMatrix = $command->projectionMatrix->toArray();
                $camPos           = $command->viewMatrix->inverse()->getTranslation();
                $this->cameraPos  = [$camPos->x, $camPos->y, $camPos->z];

            } elseif ($command instanceof SetAmbientLight) {
                $this->ambient = [
                    $command->color->r, $command->color->g, $command->color->b, $command->intensity,
                ];

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

            } elseif ($command instanceof AddSpotLight && count($this->spotLights) < 8) {
                $this->spotLights[] = [
                    'pos'       => [$command->position->x, $command->position->y, $command->position->z],
                    'dir'       => [$command->direction->x, $command->direction->y, $command->direction->z],
                    'color'     => [$command->color->r, $command->color->g, $command->color->b],
                    'intensity' => $command->intensity,
                    'range'     => $command->range,
                    'angle'     => $command->angle,
                    'penumbra'  => $command->penumbra,
                ];

            } elseif ($command instanceof SetFog) {
                if ($this->settings->fog) {
                    $clampedFar = min($command->far, $this->settings->viewDistance);
                    $clampedNear = min($command->near, max(0.0, $clampedFar - 1.0));
                    $this->fog = [
                        $command->color->r, $command->color->g, $command->color->b,
                        $clampedNear, $clampedFar,
                    ];
                } else {
                    $this->fog = [
                        $command->color->r, $command->color->g, $command->color->b,
                        99998.0, 99999.0,
                    ];
                }

            } elseif ($command instanceof SetSkyColors) {
                $this->clearR = $command->skyColor->r;
                $this->clearG = $command->skyColor->g;
                $this->clearB = $command->skyColor->b;
            } elseif ($command instanceof SetSkybox) {
                // TODO Phase 8+
            }
        }

        $this->uploadFrameUbo();
        $this->uploadLightingUbo();

        // ── Pick render pass + framebuffer for this frame ────────────────────
        // Default path: native-resolution single-sample chain (`$this->renderPass`
        // + `$this->framebuffer`, drawing into `$this->offscreenColor`).
        //
        // Phase 1.5 path: scaled / multisample chain owned by `$scaledTarget`
        // (`renderPass()` + `framebuffer()`). MSAA failures during `resize()`
        // fall back to single-sample inside the helper, so this branch only
        // produces multisampled draws when ext-vulkan accepted the chain.
        $this->offscreenActive = $this->scaledTarget !== null
            && $this->scaledTarget->isAllocated()
            && $this->scaledTarget->renderPass() !== null
            && $this->scaledTarget->framebuffer() !== null;

        if ($this->offscreenActive) {
            $renderPass  = $this->scaledTarget->renderPass();
            $framebuffer = $this->scaledTarget->framebuffer();
            $renderW     = $this->scaledTarget->width();
            $renderH     = $this->scaledTarget->height();

            // MSAA chain has three attachments (msaa colour, depth, resolve).
            $clearValues = $this->scaledTarget->samples() > 1
                ? [
                    [$this->clearR, $this->clearG, $this->clearB, 1.0],
                    [1.0, 0],
                    [$this->clearR, $this->clearG, $this->clearB, 1.0],
                ]
                : [[$this->clearR, $this->clearG, $this->clearB, 1.0], [1.0, 0]];
        } else {
            $renderPass  = $this->renderPass;
            $framebuffer = $this->framebuffer;
            $renderW     = $this->width;
            $renderH     = $this->height;
            $clearValues = [[$this->clearR, $this->clearG, $this->clearB, 1.0], [1.0, 0]];
        }

        // ── Render into offscreen image via render pass ──────────────────────
        $this->commandBuffer->beginRenderPass(
            $renderPass,
            $framebuffer,
            0, 0, $renderW, $renderH,
            $clearValues,
        );
        $this->commandBuffer->setViewport(0.0, 0.0, (float) $renderW, (float) $renderH, 0.0, 1.0);
        $this->commandBuffer->setScissor(0, 0, $renderW, $renderH);
        $this->commandBuffer->bindPipeline(self::VK_PIPELINE_BIND_GRAPHICS, $this->pipeline);
        $this->commandBuffer->bindDescriptorSets(
            self::VK_PIPELINE_BIND_GRAPHICS, $this->pipelineLayout, 0, [$this->descriptorSet],
        );

        foreach ($commandList->getCommands() as $command) {
            if ($command instanceof DrawMesh) {
                $this->resolveMaterial($command->materialId);
                $this->uploadLightingUbo();
                $this->drawMeshCommand($command->meshId, $command->modelMatrix);
            } elseif ($command instanceof DrawMeshInstanced) {
                $this->resolveMaterial($command->materialId);
                $this->uploadLightingUbo();
                if ($command->flatMatrices !== [] || $command->packedMatrices !== '') {
                    $count = $command->instanceCount >= 0 ? $command->instanceCount : count($command->matrices);
                    // Packed bytes (a compute readback) unpack once here; the
                    // per-instance Mat4 below is a transient cost that disappears
                    // once Vulkan grows a real instance buffer like OpenGL/Vio.
                    $flat = $command->flatMatricesResolved();
                    for ($i = 0; $i < $count; $i++) {
                        $base = $i * 16;
                        // Reconstruct one Mat4 per instance for the
                        // current Vulkan path. The flat layout still
                        // wins for Mat4-mode upstream callers (no
                        // per-particle Mat4 alloc on game side); the
                        // per-instance Mat4 here is a transient cost
                        // that disappears once Vulkan grows a real
                        // instance buffer like OpenGL/Vio do.
                        $this->drawMeshCommand($command->meshId, new \PHPolygon\Math\Mat4(array_slice($flat, $base, 16)));
                    }
                } else {
                    foreach ($command->matrices as $matrix) {
                        $this->drawMeshCommand($command->meshId, $matrix);
                    }
                }
            }
        }

        $this->commandBuffer->endRenderPass();

        // ── Blit offscreen → swapchain (with optional FXAA between) ─────────

        // Pick the colour image / view that holds the rendered scene. For the
        // scaled path that's the resolve image owned by `$scaledTarget`; for
        // the default path it's the constructor-allocated `$offscreenColor`.
        if ($this->offscreenActive) {
            $sourceImage = $this->scaledTarget->presentImage();
            $sourceView  = $this->scaledTarget->presentImageView();
            $sourceW     = $this->scaledTarget->width();
            $sourceH     = $this->scaledTarget->height();
        } else {
            $sourceImage = $this->offscreenColor;
            $sourceView  = $this->offscreenColorView;
            $sourceW     = $this->width;
            $sourceH     = $this->height;
        }

        if ($sourceImage === null) {
            // Helper failed to allocate; skip the blit so we don't crash on a
            // missing source image. Visible result is the previous swapchain
            // image; not great but safer than dereferencing null.
            return;
        }

        // FXAA path: scene → offscreen → FXAA fragment shader → fxaaOutput → blit.
        // Only engages when AntiAliasing::FXAA is selected AND the helper
        // managed to allocate sampler / descriptor APIs (older ext-vulkan
        // builds without `Vk\Sampler` flip `$fxaaInitFailed = true` and we
        // fall through to the plain blit path).
        $useFxaa = $this->settings->antiAliasing === AntiAliasing::Fxaa
            && $sourceView !== null
            && $this->ensureFxaaResources();

        if ($useFxaa) {
            // Offscreen: COLOR_ATTACHMENT_OPTIMAL → SHADER_READ_ONLY_OPTIMAL
            $this->commandBuffer->imageMemoryBarrier(
                $sourceImage,
                self::VK_LAYOUT_COLOR_ATTACHMENT,
                self::VK_LAYOUT_SHADER_READ_ONLY,
                self::VK_ACCESS_COLOR_WRITE,
                self::VK_ACCESS_SHADER_READ,
                self::VK_STAGE_COLOR_OUTPUT,
                self::VK_STAGE_FRAGMENT,
                self::VK_ASPECT_COLOR,
            );

            $this->fxaaPass->bindInput($sourceView);
            $this->fxaaPass->record(
                $this->commandBuffer,
                $this->fxaaFramebuffer,
                $this->width, $this->height,
                $sourceW, $sourceH,
            );

            // FXAA output is now the source for the swapchain blit.
            $sourceImage = $this->fxaaOutputImage;
            $sourceW = $this->width;
            $sourceH = $this->height;
        }

        // Source: COLOR_ATTACHMENT_OPTIMAL → TRANSFER_SRC_OPTIMAL
        $this->commandBuffer->imageMemoryBarrier(
            $sourceImage,
            self::VK_LAYOUT_COLOR_ATTACHMENT,
            self::VK_LAYOUT_TRANSFER_SRC,
            self::VK_ACCESS_COLOR_WRITE,
            self::VK_ACCESS_TRANSFER_READ,
            self::VK_STAGE_COLOR_OUTPUT,
            self::VK_STAGE_TRANSFER,
            self::VK_ASPECT_COLOR,
        );

        // Swapchain image: UNDEFINED → TRANSFER_DST_OPTIMAL
        $this->commandBuffer->imageMemoryBarrier(
            $this->swapImages[$this->currentImageIndex],
            self::VK_LAYOUT_UNDEFINED,
            self::VK_LAYOUT_TRANSFER_DST,
            self::VK_ACCESS_NONE,
            self::VK_ACCESS_TRANSFER_WRITE,
            self::VK_STAGE_TOP,
            self::VK_STAGE_TRANSFER,
            self::VK_ASPECT_COLOR,
        );

        // Use blitImage so the linear filter handles up- / down-scaling between
        // the (possibly scaled) source image and the swapchain. With FXAA on
        // the source already matches swapchain size so this is a 1:1 blit; with
        // FXAA off the linear filter performs the render-scale upscale.
        $this->commandBuffer->blitImage(
            $sourceImage, self::VK_LAYOUT_TRANSFER_SRC,
            $this->swapImages[$this->currentImageIndex], self::VK_LAYOUT_TRANSFER_DST,
            $sourceW, $sourceH,
            $this->width, $this->height,
            self::VK_FILTER_LINEAR,
        );

        // Swapchain image: TRANSFER_DST_OPTIMAL → PRESENT_SRC_KHR
        $this->commandBuffer->imageMemoryBarrier(
            $this->swapImages[$this->currentImageIndex],
            self::VK_LAYOUT_TRANSFER_DST,
            self::VK_LAYOUT_PRESENT_SRC,
            self::VK_ACCESS_TRANSFER_WRITE,
            self::VK_ACCESS_NONE,
            self::VK_STAGE_TRANSFER,
            self::VK_STAGE_BOTTOM,
            self::VK_ASPECT_COLOR,
        );
    }

    private function resolveMaterial(string $materialId): void
    {
        $material = MaterialRegistry::get($materialId);
        if ($material !== null) {
            $this->albedo    = [$material->albedo->r, $material->albedo->g, $material->albedo->b];
            $this->roughness = $material->roughness;
            $this->metallic  = $material->metallic;
        } else {
            $this->albedo    = [0.8, 0.8, 0.8];
            $this->roughness = 0.5;
            $this->metallic  = 0.0;
        }
    }

    private function drawMeshCommand(string $meshId, Mat4 $modelMatrix): void
    {
        $meshData = MeshRegistry::get($meshId);
        if ($meshData === null) {
            error_log("[VkRenderer] drawMeshCommand: mesh '$meshId' not found in registry");
            return;
        }

        if (!isset($this->meshCache[$meshId])) {
            $this->uploadMesh($meshId, $meshData);
        }

        $modelBytes = pack('f16', ...$modelMatrix->toArray());
        $this->commandBuffer->pushConstants($this->pipelineLayout, self::VK_SHADER_STAGE_VERTEX, 0, $modelBytes);
        $this->commandBuffer->bindVertexBuffers(0, [$this->meshCache[$meshId]['vb']], [0]);
        $this->commandBuffer->bindIndexBuffer($this->meshCache[$meshId]['ib'], 0, self::VK_INDEX_TYPE_UINT32);
        $this->commandBuffer->drawIndexed($this->meshCache[$meshId]['count'], 1, 0, 0, 0);
    }

    private function uploadMesh(string $meshId, MeshData $meshData): void
    {
        $vertexCount = $meshData->vertexCount();
        $vertexData  = '';
        for ($i = 0; $i < $vertexCount; $i++) {
            $vertexData .= pack(
                'f8',
                $meshData->vertices[$i * 3], $meshData->vertices[$i * 3 + 1], $meshData->vertices[$i * 3 + 2],
                $meshData->normals[$i * 3],  $meshData->normals[$i * 3 + 1],  $meshData->normals[$i * 3 + 2],
                $meshData->uvs[$i * 2],      $meshData->uvs[$i * 2 + 1],
            );
        }

        $vb    = new Buffer($this->device, strlen($vertexData), self::VK_BUFFER_USAGE_VERTEX, self::VK_SHARING_EXCLUSIVE);
        $vbReq = $vb->getMemoryRequirements();
        $vbSize = $vbReq['size'];
        if (!is_int($vbSize)) {
            throw new \RuntimeException('Invalid vertex buffer memory requirements');
        }
        $vbMem = new DeviceMemory($this->device, $vbSize, $this->findMemory($vbReq, true));
        $vb->bindMemory($vbMem, 0);
        $vbMem->map(0, $vbSize);
        $vbMem->write($vertexData, 0);

        $indexData = '';
        foreach ($meshData->indices as $idx) {
            $indexData .= pack('V', $idx);
        }
        $ib    = new Buffer($this->device, strlen($indexData), self::VK_BUFFER_USAGE_INDEX, self::VK_SHARING_EXCLUSIVE);
        $ibReq = $ib->getMemoryRequirements();
        $ibSize = $ibReq['size'];
        if (!is_int($ibSize)) {
            throw new \RuntimeException('Invalid index buffer memory requirements');
        }
        $ibMem = new DeviceMemory($this->device, $ibSize, $this->findMemory($ibReq, true));
        $ib->bindMemory($ibMem, 0);
        $ibMem->map(0, $ibSize);
        $ibMem->write($indexData, 0);

        $this->meshCache[$meshId] = [
            'vb'    => $vb,
            'vbMem' => $vbMem,
            'ib'    => $ib,
            'ibMem' => $ibMem,
            'count' => count($meshData->indices),
        ];
    }

    private function uploadFrameUbo(): void
    {
        $vulkanClip = new Mat4([
             1.0,  0.0,  0.0,  0.0,
             0.0, -1.0,  0.0,  0.0,
             0.0,  0.0,  0.5,  0.0,
             0.0,  0.0,  0.5,  1.0,
        ]);
        $correctedProj = $vulkanClip->multiply(new Mat4($this->projMatrix));
        $data = pack('f16', ...$this->viewMatrix)
              . pack('f16', ...$correctedProj->toArray());

        $this->frameUboMem->write($data, 0);
    }

    private function uploadLightingUbo(): void
    {
        $data  = pack('f4', $this->ambient[0], $this->ambient[1], $this->ambient[2], $this->ambient[3]);
        $data .= pack('f4', $this->dirLight[0], $this->dirLight[1], $this->dirLight[2], $this->dirLight[3]);
        $data .= pack('f4', $this->dirLight[4], $this->dirLight[5], $this->dirLight[6], 0.0);
        $data .= pack('f4', $this->albedo[0], $this->albedo[1], $this->albedo[2], $this->roughness);
        $data .= pack('f4', 0.0, 0.0, 0.0, $this->metallic);
        $data .= pack('f4', $this->fog[0], $this->fog[1], $this->fog[2], $this->fog[3]);
        $data .= pack('f4', $this->cameraPos[0], $this->cameraPos[1], $this->cameraPos[2], $this->fog[4]);
        $plCount = count($this->pointLights);
        $data .= pack('l1f3', $plCount, 0.0, 0.0, 0.0);
        for ($i = 0; $i < 8; $i++) {
            if ($i < $plCount) {
                $pl = $this->pointLights[$i];
                $data .= pack('f4', $pl['pos'][0], $pl['pos'][1], $pl['pos'][2], $pl['intensity']);
                $data .= pack('f4', $pl['color'][0], $pl['color'][1], $pl['color'][2], $pl['radius']);
            } else {
                $data .= pack('f8', 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0);
            }
        }
        // Spot-light block (mirrors mesh3d_vk.frag.glsl SpotLight[] + count):
        // count slot, then 8 × 64-byte slots of position+intensity,
        // direction+range, color+angle, penumbra+pad.
        $slCount = count($this->spotLights);
        $data .= pack('l1f3', $slCount, 0.0, 0.0, 0.0);
        for ($i = 0; $i < 8; $i++) {
            if ($i < $slCount) {
                $sl = $this->spotLights[$i];
                $data .= pack('f4', $sl['pos'][0], $sl['pos'][1], $sl['pos'][2], $sl['intensity']);
                $data .= pack('f4', $sl['dir'][0], $sl['dir'][1], $sl['dir'][2], $sl['range']);
                $data .= pack('f4', $sl['color'][0], $sl['color'][1], $sl['color'][2], $sl['angle']);
                $data .= pack('f4', $sl['penumbra'], 0.0, 0.0, 0.0);
            } else {
                $data .= pack('f4', 0.0, 0.0, 0.0, 0.0);
                $data .= pack('f4', 0.0, 0.0, 0.0, 0.0);
                $data .= pack('f4', 0.0, 0.0, 0.0, 0.0);
                $data .= pack('f4', 0.0, 0.0, 0.0, 0.0);
            }
        }
        $this->lightingUboMem->write($data, 0);
    }

    private function initVulkan(\GLFWwindow $windowHandle): void
    {
        $this->ensureMacOSVulkanEnv();

        $this->instance = new Instance('PHPolygon', 1, 'PHPolygon', 1, null, false, [
            'VK_KHR_surface',
            'VK_EXT_metal_surface',
            'VK_KHR_portability_enumeration',
        ]);

        $this->surface = new Surface($this->instance, $windowHandle);

        $rawDevices = $this->instance->getPhysicalDevices();
        $firstDevice = $rawDevices[0] ?? null;
        if (!$firstDevice instanceof PhysicalDevice) {
            throw new \RuntimeException('No Vulkan physical devices found');
        }
        $this->gpu = $firstDevice;

        $rawMemProps = $this->gpu->getMemoryProperties();
        $rawTypes    = $rawMemProps['types'] ?? [];
        if (is_array($rawTypes)) {
            foreach ($rawTypes as $t) {
                $this->memTypes[] = is_array($t) ? $t : [];
            }
        }

        $this->graphicsFamily = $this->selectQueueFamily();

        $this->device = new Device(
            $this->gpu,
            [['familyIndex' => $this->graphicsFamily, 'count' => 1]],
            ['VK_KHR_swapchain', 'VK_KHR_dynamic_rendering'],
            null,
        );
        $this->queue = $this->device->getQueue($this->graphicsFamily, 0);

        $this->createSwapchain();
        $this->createOffscreenAndDepthImages();
        $this->createRenderPass();
        $this->createPipeline();
        $this->createUBOs();
        $this->createDescriptors();
        $this->createCommandObjects();
        $this->createSyncObjects();
    }

    private function selectQueueFamily(): int
    {
        $queueFamilies = $this->gpu->getQueueFamilies();
        if (!is_array($queueFamilies)) {
            throw new \RuntimeException('getQueueFamilies() did not return an array');
        }
        foreach ($queueFamilies as $qf) {
            if (!is_array($qf) || empty($qf['graphics'])) {
                continue;
            }
            $idx = $qf['index'];
            if (!is_int($idx)) {
                continue;
            }
            if ($this->gpu->getSurfaceSupport($idx, $this->surface)) {
                return $idx;
            }
        }
        throw new \RuntimeException('No Vulkan graphics+present queue family found');
    }

    private function createSwapchain(): void
    {
        $caps         = $this->surface->getCapabilities($this->gpu);
        $rawFormats   = $this->surface->getFormats($this->gpu);
        $presentModes = $this->surface->getPresentModes($this->gpu);

        $firstFormat = is_array($rawFormats) ? ($rawFormats[0] ?? []) : [];
        $format      = is_array($firstFormat) ? ($firstFormat['format'] ?? 44) : 44;
        $colorSpace  = is_array($firstFormat) ? ($firstFormat['colorSpace'] ?? 0) : 0;

        $this->surfaceFormat = is_int($format) ? $format : (int) $format;
        $colorSpaceInt       = is_int($colorSpace) ? $colorSpace : (int) $colorSpace;

        $hasFifo     = is_array($presentModes) && in_array(self::VK_PRESENT_MODE_FIFO, $presentModes, true);
        $presentMode = $hasFifo ? self::VK_PRESENT_MODE_FIFO : self::VK_PRESENT_MODE_FIFO;

        $minCount   = is_array($caps) ? ($caps['minImageCount'] ?? 2) : 2;
        $maxCount   = is_array($caps) ? ($caps['maxImageCount'] ?? 3) : 3;
        $transform  = is_array($caps) ? ($caps['currentTransform'] ?? 1) : 1;
        $imageCount = max(
            is_int($minCount) ? $minCount : (int) $minCount,
            min(3, $maxCount ? (is_int($maxCount) ? $maxCount : (int) $maxCount) : 3),
        );

        // Use the surface's reported currentExtent for swapchain dimensions.
        // On macOS/MoltenVK, getFramebufferWidth/Height may return Retina pixel counts
        // while the Vulkan surface operates at a different (often smaller) resolution.
        $rawExtent   = is_array($caps) ? ($caps['currentExtent'] ?? []) : [];
        $extentW     = is_array($rawExtent) ? ($rawExtent['width']  ?? $this->width)  : $this->width;
        $extentH     = is_array($rawExtent) ? ($rawExtent['height'] ?? $this->height) : $this->height;
        $extentW     = is_int($extentW) ? $extentW : (int) $extentW;
        $extentH     = is_int($extentH) ? $extentH : (int) $extentH;
        // Clamp to valid range
        $minExtW     = is_array($caps) ? (int)($caps['minImageExtent']['width']  ?? 1) : 1;
        $minExtH     = is_array($caps) ? (int)($caps['minImageExtent']['height'] ?? 1) : 1;
        $maxExtW     = is_array($caps) ? (int)($caps['maxImageExtent']['width']  ?? $extentW) : $extentW;
        $maxExtH     = is_array($caps) ? (int)($caps['maxImageExtent']['height'] ?? $extentH) : $extentH;
        $this->width  = max($minExtW, min($extentW, $maxExtW));
        $this->height = max($minExtH, min($extentH, $maxExtH));

        $this->swapchain = new Swapchain($this->device, $this->surface, [
            'minImageCount'    => $imageCount,
            'imageFormat'      => $this->surfaceFormat,
            'imageColorSpace'  => $colorSpaceInt,
            'imageExtent'      => ['width' => $this->width, 'height' => $this->height],
            'imageArrayLayers' => 1,
            'imageUsage'       => self::VK_IMAGE_USAGE_COLOR | self::VK_IMAGE_USAGE_TRANSFER_DST,
            'imageSharingMode' => self::VK_SHARING_EXCLUSIVE,
            'preTransform'     => is_int($transform) ? $transform : (int) $transform,
            'compositeAlpha'   => 1,
            'presentMode'      => $presentMode,
            'clipped'          => true,
        ]);

        $rawImages = $this->swapchain->getImages();
        if (!is_array($rawImages)) {
            throw new \RuntimeException('getImages() did not return an array');
        }
        foreach ($rawImages as $img) {
            if (!$img instanceof Image) {
                throw new \RuntimeException('Swapchain image is not a Vk\\Image');
            }
            $this->swapImages[] = $img;
        }
    }

    private function createOffscreenAndDepthImages(): void
    {
        // Offscreen color: rendered into, then copied to swapchain
        $this->offscreenColor = new Image(
            $this->device,
            $this->width, $this->height,
            $this->surfaceFormat,
            self::VK_IMAGE_USAGE_COLOR | self::VK_IMAGE_USAGE_TRANSFER_SRC,
            0,
            self::VK_SAMPLE_COUNT_1,
        );
        $colorReq  = $this->offscreenColor->getMemoryRequirements();
        $colorSize = $colorReq['size'];
        if (!is_int($colorSize)) {
            throw new \RuntimeException('Invalid offscreen color image memory size');
        }
        $this->offscreenColorMem = new DeviceMemory($this->device, $colorSize, $this->findMemory($colorReq, false));
        $this->offscreenColor->bindMemory($this->offscreenColorMem, 0);
        $this->offscreenColorView = new ImageView(
            $this->device, $this->offscreenColor, $this->surfaceFormat, self::VK_ASPECT_COLOR, 1,
        );

        // Depth image
        $this->depthImage = new Image(
            $this->device, $this->width, $this->height,
            self::VK_FORMAT_D32_SFLOAT, self::VK_IMAGE_USAGE_DEPTH,
            0, self::VK_SAMPLE_COUNT_1,
        );
        $depthReq  = $this->depthImage->getMemoryRequirements();
        $depthSize = $depthReq['size'];
        if (!is_int($depthSize)) {
            throw new \RuntimeException('Invalid depth image memory requirements');
        }
        $this->depthMem = new DeviceMemory($this->device, $depthSize, $this->findMemory($depthReq, false));
        $this->depthImage->bindMemory($this->depthMem, 0);
        $this->depthView = new ImageView(
            $this->device, $this->depthImage, self::VK_FORMAT_D32_SFLOAT, self::VK_ASPECT_DEPTH, 1,
        );
    }

    private function createRenderPass(): void
    {
        $this->renderPass = new RenderPass(
            $this->device,
            [
                [
                    'format'         => $this->surfaceFormat,
                    'samples'        => self::VK_SAMPLE_COUNT_1,
                    'loadOp'         => self::VK_LOAD_OP_CLEAR,
                    'storeOp'        => self::VK_STORE_OP_STORE,
                    'stencilLoadOp'  => self::VK_LOAD_OP_DONT_CARE,
                    'stencilStoreOp' => self::VK_STORE_OP_DONT_CARE,
                    'initialLayout'  => self::VK_LAYOUT_UNDEFINED,
                    'finalLayout'    => self::VK_LAYOUT_COLOR_ATTACHMENT,
                ],
                [
                    'format'         => self::VK_FORMAT_D32_SFLOAT,
                    'samples'        => self::VK_SAMPLE_COUNT_1,
                    'loadOp'         => self::VK_LOAD_OP_CLEAR,
                    'storeOp'        => self::VK_STORE_OP_DONT_CARE,
                    'stencilLoadOp'  => self::VK_LOAD_OP_DONT_CARE,
                    'stencilStoreOp' => self::VK_STORE_OP_DONT_CARE,
                    'initialLayout'  => self::VK_LAYOUT_UNDEFINED,
                    'finalLayout'    => self::VK_LAYOUT_DEPTH_ATTACHMENT,
                ],
            ],
            [
                [
                    'pipelineBindPoint' => self::VK_PIPELINE_BIND_GRAPHICS,
                    'colorAttachments'  => [['attachment' => 0, 'layout' => self::VK_LAYOUT_COLOR_ATTACHMENT]],
                    'depthAttachment'   => ['attachment' => 1, 'layout' => self::VK_LAYOUT_DEPTH_ATTACHMENT],
                ],
            ],
            [],
        );

        $this->framebuffer = new \Vk\Framebuffer(
            $this->device, $this->renderPass,
            [$this->offscreenColorView, $this->depthView],
            $this->width, $this->height, 1,
        );
    }

    private function createPipeline(): void
    {
        $vertModule = ShaderModule::createFromFile($this->device, self::VERT_SPV);
        $fragModule = ShaderModule::createFromFile($this->device, self::FRAG_SPV);

        $this->descriptorSetLayout = new DescriptorSetLayout($this->device, [
            ['binding' => 0, 'descriptorType' => self::VK_DESCRIPTOR_UNIFORM_BUFFER, 'stageFlags' => self::VK_SHADER_STAGE_VERTEX],
            ['binding' => 1, 'descriptorType' => self::VK_DESCRIPTOR_UNIFORM_BUFFER, 'stageFlags' => self::VK_SHADER_STAGE_FRAGMENT],
        ]);

        $this->pipelineLayout = new PipelineLayout(
            $this->device,
            [$this->descriptorSetLayout],
            [['stageFlags' => self::VK_SHADER_STAGE_VERTEX, 'offset' => 0, 'size' => 64]],
        );

        // Pipeline created with renderPass (extension requires it), but actual rendering
        // uses beginRendering/endRendering (VK_KHR_dynamic_rendering) to avoid MoltenVK
        // render-pass layout bugs that silently discard draw calls.
        $this->pipeline = Pipeline::createGraphics($this->device, [
            'renderPass'       => $this->renderPass,
            'layout'           => $this->pipelineLayout,
            'vertexShader'     => $vertModule,
            'fragmentShader'   => $fragModule,
            'vertexBindings'   => [
                ['binding' => 0, 'stride' => 32, 'inputRate' => self::VK_VERTEX_INPUT_RATE_VERTEX],
            ],
            'vertexAttributes' => [
                ['location' => 0, 'binding' => 0, 'format' => self::VK_FORMAT_R32G32B32_SFLOAT, 'offset' => 0],
                ['location' => 1, 'binding' => 0, 'format' => self::VK_FORMAT_R32G32B32_SFLOAT, 'offset' => 12],
                ['location' => 2, 'binding' => 0, 'format' => self::VK_FORMAT_R32G32_SFLOAT,    'offset' => 24],
            ],
            'cullMode'         => self::VK_CULL_MODE_BACK,
            'frontFace'        => self::VK_FRONT_FACE_CCW,
            'depthTest'        => true,
            'depthWrite'       => true,
        ]);
    }

    private function createUBOs(): void
    {
        $this->frameUbo = new Buffer(
            $this->device, self::FRAME_UBO_SIZE, self::VK_BUFFER_USAGE_UNIFORM, self::VK_SHARING_EXCLUSIVE,
        );
        $req = $this->frameUbo->getMemoryRequirements();
        $reqSize = $req['size'];
        if (!is_int($reqSize)) {
            throw new \RuntimeException('Invalid frame UBO memory size');
        }
        $this->frameUboMem = new DeviceMemory($this->device, $reqSize, $this->findMemory($req, true));
        $this->frameUbo->bindMemory($this->frameUboMem, 0);
        $this->frameUboMem->map(0, $reqSize);

        $this->lightingUbo = new Buffer(
            $this->device, self::LIGHTING_UBO_SIZE, self::VK_BUFFER_USAGE_UNIFORM, self::VK_SHARING_EXCLUSIVE,
        );
        $req2 = $this->lightingUbo->getMemoryRequirements();
        $req2Size = $req2['size'];
        if (!is_int($req2Size)) {
            throw new \RuntimeException('Invalid lighting UBO memory size');
        }
        $this->lightingUboMem = new DeviceMemory($this->device, $req2Size, $this->findMemory($req2, true));
        $this->lightingUbo->bindMemory($this->lightingUboMem, 0);
        $this->lightingUboMem->map(0, $req2Size);
    }

    private function createDescriptors(): void
    {
        $this->descriptorPool = new DescriptorPool(
            $this->device, 1,
            [['type' => self::VK_DESCRIPTOR_UNIFORM_BUFFER, 'count' => 2]],
        );

        $rawSets = $this->descriptorPool->allocateSets([$this->descriptorSetLayout]);
        $firstSet = $rawSets[0] ?? null;
        if (!$firstSet instanceof DescriptorSet) {
            throw new \RuntimeException('Failed to allocate descriptor set');
        }
        $this->descriptorSet = $firstSet;
        $this->descriptorSet->writeBuffer(0, $this->frameUbo, 0, self::FRAME_UBO_SIZE, self::VK_DESCRIPTOR_UNIFORM_BUFFER);
        $this->descriptorSet->writeBuffer(1, $this->lightingUbo, 0, self::LIGHTING_UBO_SIZE, self::VK_DESCRIPTOR_UNIFORM_BUFFER);
    }

    private function createCommandObjects(): void
    {
        $this->commandPool = new CommandPool($this->device, $this->graphicsFamily, self::VK_CMD_POOL_RESET_CMD_BUFFER);
        $rawCmds = $this->commandPool->allocateBuffers(1, true);
        $firstCmd = $rawCmds[0] ?? null;
        if (!$firstCmd instanceof \Vk\CommandBuffer) {
            throw new \RuntimeException('Failed to allocate command buffer');
        }
        $this->commandBuffer = $firstCmd;
    }

    private function createSyncObjects(): void
    {
        $this->imageAvailableSem = new Semaphore($this->device, false, 0);
        $this->renderFinishedSem = new Semaphore($this->device, false, 0);
        $this->inFlightFence     = new Fence($this->device, true);
    }

    /** @param array<mixed> $memReqs */
    private function findMemory(array $memReqs, bool $hostVisible): int
    {
        $bitsRaw = $memReqs['memoryTypeBits'] ?? 0;
        $bits    = is_int($bitsRaw) ? $bitsRaw : (int) $bitsRaw;

        foreach ($this->memTypes as $i => $t) {
            if (!($bits & (1 << $i))) {
                continue;
            }
            if ($hostVisible) {
                if (!empty($t['hostVisible']) && !empty($t['hostCoherent'])) {
                    return $i;
                }
            } else {
                if (!empty($t['deviceLocal'])) {
                    return $i;
                }
            }
        }
        throw new \RuntimeException('No suitable Vulkan memory type found');
    }

    private function ensureMacOSVulkanEnv(): void
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            return;
        }
        foreach (['/opt/homebrew/lib', '/usr/local/lib'] as $libDir) {
            if (file_exists("{$libDir}/libvulkan.dylib")) {
                $icd = dirname($libDir) . '/etc/vulkan/icd.d/MoltenVK_icd.json';
                if (!getenv('DYLD_LIBRARY_PATH')) {
                    putenv("DYLD_LIBRARY_PATH={$libDir}");
                }
                if (!getenv('VK_ICD_FILENAMES') && file_exists($icd)) {
                    putenv("VK_ICD_FILENAMES={$icd}");
                }
                return;
            }
        }
    }
}
