<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\PostProcess;

use Vk\CommandBuffer;
use Vk\DescriptorPool;
use Vk\DescriptorSet;
use Vk\DescriptorSetLayout;
use Vk\Device;
use Vk\Framebuffer;
use Vk\ImageView;
use Vk\Pipeline;
use Vk\PipelineLayout;
use Vk\RenderPass;
use Vk\Sampler;
use Vk\ShaderModule;

/**
 * FXAA post-process pass for the standalone Vulkan backend.
 *
 * Owns the FXAA-specific GPU state: sampler, descriptor set layout / pool /
 * set, pipeline layout, render pass, and graphics pipeline. The vertex shader
 * synthesises a fullscreen triangle from `gl_VertexIndex`, so no vertex
 * buffer is required - the helper invokes `vkCmdDraw(cmd, 3, 1, 0, 0)`.
 *
 * Lifecycle
 *   1. Construct with a Device.
 *   2. Call `initialise($colorFormat)` once. The helper compiles the SPIR-V
 *      modules, builds the descriptor / pipeline state, and creates a render
 *      pass compatible with the desired output colour format. Failures are
 *      swallowed - callers must check `isReady()` before invoking `record()`.
 *   3. Each frame: call `bindInput($offscreenView)` to point the sampler at
 *      the latest offscreen colour view, then `record(...)` to run the pass
 *      against the destination framebuffer.
 *
 * Best-effort fallback: if any of the underlying ext-vulkan APIs reject the
 * call (older bindings without `Vk\Sampler` / `DescriptorSet::writeImage`),
 * the helper logs once and stays in a non-ready state. The renderer falls
 * back to a plain blit so the game still renders, just without FXAA.
 */
final class VulkanFxaaPass
{
    private const VERT_SPV = __DIR__ . '/../../../resources/shaders/compiled/fxaa_vk.vert.spv';
    private const FRAG_SPV = __DIR__ . '/../../../resources/shaders/compiled/fxaa_vk.frag.spv';

    private const VK_SHADER_STAGE_FRAGMENT             = 16;
    private const VK_DESCRIPTOR_COMBINED_IMAGE_SAMPLER = 1;
    private const VK_LAYOUT_SHADER_READ_ONLY           = 5;
    private const VK_LAYOUT_COLOR_ATTACHMENT           = 2;
    private const VK_LAYOUT_UNDEFINED                  = 0;
    private const VK_LOAD_OP_DONT_CARE                 = 2;
    private const VK_STORE_OP_STORE                    = 0;
    private const VK_STORE_OP_DONT_CARE                = 1;
    private const VK_SAMPLE_COUNT_1                    = 1;
    private const VK_PIPELINE_BIND_GRAPHICS            = 0;
    private const VK_FILTER_LINEAR                     = 1;
    private const VK_SAMPLER_ADDRESS_CLAMP_EDGE        = 2;

    private bool $initialised = false;
    private bool $ready       = false;

    private ?ShaderModule $vertModule = null;
    private ?ShaderModule $fragModule = null;
    private ?Pipeline $pipeline = null;
    private ?PipelineLayout $pipelineLayout = null;
    private ?DescriptorSetLayout $descriptorSetLayout = null;
    private ?DescriptorPool $descriptorPool = null;
    private ?DescriptorSet $descriptorSet = null;
    private ?Sampler $sampler = null;
    private ?RenderPass $renderPass = null;

    private int $colorFormat = 0;

    public function __construct(
        private readonly Device $device,
    ) {
    }

    /**
     * Whether the FXAA SPIR-V binaries are present on disk. Returns false on
     * builds that haven't been through the shader-compile step; the standalone
     * Vulkan renderer treats this as "FXAA unavailable, present without it".
     */
    public static function shadersAvailable(): bool
    {
        return is_file(self::VERT_SPV) && is_file(self::FRAG_SPV);
    }

    /**
     * Compile shaders and build all GPU state required to record an FXAA
     * pass. Idempotent; subsequent calls with the same colour format are
     * no-ops. Returns true if the helper is ready to record commands.
     */
    public function initialise(int $colorFormat): bool
    {
        if ($this->initialised && $this->colorFormat === $colorFormat) {
            return $this->ready;
        }

        if ($this->initialised) {
            $this->release();
        }

        $this->initialised = true;
        $this->colorFormat = $colorFormat;

        if (!self::shadersAvailable()) {
            fwrite(STDERR, "[VulkanFxaaPass] fxaa_vk.{vert,frag}.spv missing - FXAA disabled.\n");
            return false;
        }

        try {
            $this->vertModule = ShaderModule::createFromFile($this->device, self::VERT_SPV);
            $this->fragModule = ShaderModule::createFromFile($this->device, self::FRAG_SPV);

            $this->descriptorSetLayout = new DescriptorSetLayout($this->device, [
                [
                    'binding'        => 0,
                    'descriptorType' => self::VK_DESCRIPTOR_COMBINED_IMAGE_SAMPLER,
                    'stageFlags'     => self::VK_SHADER_STAGE_FRAGMENT,
                ],
            ]);

            // Push constant: vec2 inverse_resolution (8 bytes) at offset 0,
            // visible to the fragment shader (matches the SPIR-V layout).
            $this->pipelineLayout = new PipelineLayout(
                $this->device,
                [$this->descriptorSetLayout],
                [['stageFlags' => self::VK_SHADER_STAGE_FRAGMENT, 'offset' => 0, 'size' => 8]],
            );

            $this->renderPass = new RenderPass(
                $this->device,
                [
                    [
                        'format'         => $colorFormat,
                        'samples'        => self::VK_SAMPLE_COUNT_1,
                        'loadOp'         => self::VK_LOAD_OP_DONT_CARE,
                        'storeOp'        => self::VK_STORE_OP_STORE,
                        'stencilLoadOp'  => self::VK_LOAD_OP_DONT_CARE,
                        'stencilStoreOp' => self::VK_STORE_OP_DONT_CARE,
                        'initialLayout'  => self::VK_LAYOUT_UNDEFINED,
                        'finalLayout'    => self::VK_LAYOUT_COLOR_ATTACHMENT,
                    ],
                ],
                [
                    [
                        'pipelineBindPoint' => self::VK_PIPELINE_BIND_GRAPHICS,
                        'colorAttachments'  => [['attachment' => 0, 'layout' => self::VK_LAYOUT_COLOR_ATTACHMENT]],
                    ],
                ],
                [],
            );

            $this->pipeline = Pipeline::createGraphics($this->device, [
                'renderPass'       => $this->renderPass,
                'layout'           => $this->pipelineLayout,
                'vertexShader'     => $this->vertModule,
                'fragmentShader'   => $this->fragModule,
                // Fullscreen triangle is generated in the vertex shader from
                // gl_VertexIndex, so the pipeline declares no vertex inputs.
                'vertexBindings'   => [],
                'vertexAttributes' => [],
                'cullMode'         => 0,
                'frontFace'        => 0,
                'depthTest'        => false,
                'depthWrite'       => false,
            ]);

            $this->sampler = new Sampler($this->device, [
                'magFilter'    => self::VK_FILTER_LINEAR,
                'minFilter'    => self::VK_FILTER_LINEAR,
                'addressModeU' => self::VK_SAMPLER_ADDRESS_CLAMP_EDGE,
                'addressModeV' => self::VK_SAMPLER_ADDRESS_CLAMP_EDGE,
                'addressModeW' => self::VK_SAMPLER_ADDRESS_CLAMP_EDGE,
            ]);

            $this->descriptorPool = new DescriptorPool(
                $this->device,
                1,
                [['type' => self::VK_DESCRIPTOR_COMBINED_IMAGE_SAMPLER, 'count' => 1]],
            );
            $sets = $this->descriptorPool->allocateSets([$this->descriptorSetLayout]);
            $first = $sets[0] ?? null;
            if (!$first instanceof DescriptorSet) {
                throw new \RuntimeException('Failed to allocate FXAA descriptor set');
            }
            $this->descriptorSet = $first;

            $this->ready = true;
            return true;
        } catch (\Throwable $e) {
            fwrite(STDERR, sprintf(
                "[VulkanFxaaPass] init failed (%s) - FXAA disabled.\n",
                $e->getMessage(),
            ));
            $this->release();
            $this->initialised = true;
            $this->ready       = false;
            return false;
        }
    }

    public function isReady(): bool
    {
        return $this->ready;
    }

    public function renderPass(): ?RenderPass
    {
        return $this->renderPass;
    }

    /**
     * Point the FXAA descriptor at the latest offscreen colour view. Must be
     * called after the offscreen image is in `SHADER_READ_ONLY_OPTIMAL`
     * layout (the renderer issues the barrier before invoking this).
     */
    public function bindInput(ImageView $colorView): bool
    {
        if (!$this->ready || $this->descriptorSet === null || $this->sampler === null) {
            return false;
        }
        try {
            $this->descriptorSet->writeImage(
                0,
                $colorView,
                $this->sampler,
                self::VK_LAYOUT_SHADER_READ_ONLY,
                self::VK_DESCRIPTOR_COMBINED_IMAGE_SAMPLER,
            );
            return true;
        } catch (\Throwable $e) {
            fwrite(STDERR, "[VulkanFxaaPass] writeImage failed: " . $e->getMessage() . "\n");
            $this->ready = false;
            return false;
        }
    }

    /**
     * Record the FXAA pass into `$cmd`. The caller has already transitioned
     * the input image to `SHADER_READ_ONLY_OPTIMAL` and called
     * `bindInput()` for this frame. `$framebuffer` is created from
     * `renderPass()` against the destination colour image view.
     *
     * `$sourceWidth`/`$sourceHeight` are the dimensions of the input texture
     * (used for the `1 / resolution` push constant); `$dstWidth`/`$dstHeight`
     * are the output viewport size.
     */
    public function record(
        CommandBuffer $cmd,
        Framebuffer $framebuffer,
        int $dstWidth,
        int $dstHeight,
        int $sourceWidth,
        int $sourceHeight,
    ): void {
        if (!$this->ready || $this->renderPass === null
            || $this->pipeline === null || $this->pipelineLayout === null
            || $this->descriptorSet === null
        ) {
            return;
        }

        $invW = $sourceWidth  > 0 ? 1.0 / (float) $sourceWidth  : 0.0;
        $invH = $sourceHeight > 0 ? 1.0 / (float) $sourceHeight : 0.0;

        $cmd->beginRenderPass(
            $this->renderPass,
            $framebuffer,
            0, 0, $dstWidth, $dstHeight,
            // Single attachment, DONT_CARE on load -> no real clear needed,
            // but the API still wants one entry per attachment.
            [['color' => [0.0, 0.0, 0.0, 1.0]]],
        );
        $cmd->setViewport(0.0, 0.0, (float) $dstWidth, (float) $dstHeight, 0.0, 1.0);
        $cmd->setScissor(0, 0, $dstWidth, $dstHeight);
        $cmd->bindPipeline(self::VK_PIPELINE_BIND_GRAPHICS, $this->pipeline);
        $cmd->bindDescriptorSets(
            self::VK_PIPELINE_BIND_GRAPHICS,
            $this->pipelineLayout,
            0,
            [$this->descriptorSet],
        );
        $cmd->pushConstants(
            $this->pipelineLayout,
            self::VK_SHADER_STAGE_FRAGMENT,
            0,
            pack('ff', $invW, $invH),
        );
        $cmd->draw(3, 1, 0, 0);
        $cmd->endRenderPass();
    }

    /**
     * Release every Vk object owned by the helper. PHP's GC drops references
     * on its own once `$this` goes out of scope, but the renderer calls this
     * explicitly during shutdown so the order is deterministic.
     */
    public function release(): void
    {
        $this->pipeline            = null;
        $this->pipelineLayout      = null;
        $this->descriptorSet       = null;
        $this->descriptorPool      = null;
        $this->descriptorSetLayout = null;
        $this->sampler             = null;
        $this->renderPass          = null;
        $this->vertModule          = null;
        $this->fragModule          = null;
        $this->initialised         = false;
        $this->ready               = false;
        $this->colorFormat         = 0;
    }
}
