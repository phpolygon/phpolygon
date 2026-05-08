<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

use Vk\Device;
use Vk\DeviceMemory;
use Vk\Framebuffer;
use Vk\Image;
use Vk\ImageView;
use Vk\RenderPass;

/**
 * Off-screen render target backing the Phase 1.5 render-scale + AA pipeline
 * on the standalone Vulkan backend.
 *
 * Owns the colour image, depth image, and (when MSAA is on) the resolve image
 * plus a matching VkRenderPass + VkFramebuffer pair. Mirrors the semantics of
 * `OpenGLOffscreenTarget` and `MetalOffscreenTarget`.
 *
 * MSAA support is probed lazily: the first time a sample-count > 1 resize is
 * requested, the helper attempts to create a multisample colour image, depth
 * image, and a render pass that resolves into the single-sample resolve
 * image. If anything in that chain throws (because ext-vulkan does not
 * accept the multisample state struct on this build) the failure is caught
 * once, the helper falls back to a single-sample target, and any future
 * sample-count > 1 request is silently coerced to 1.
 *
 * Lifecycle: callers invoke `resize($w, $h, $samples, $colorFormat,
 * $depthFormat, $memoryFinder)` from `applySettings()` / start-of-frame,
 * `bindForDraw($cmd, $clearColor)` to begin the render pass, and
 * `presentImage()` to obtain the image that should be blitted onto the
 * swapchain.
 */
final class VulkanOffscreenTarget
{
    // VK_FORMAT and usage flag mirrors used at construction time.
    private const VK_IMAGE_USAGE_TRANSFER_SRC  = 1;
    private const VK_IMAGE_USAGE_COLOR         = 16;   // COLOR_ATTACHMENT
    private const VK_IMAGE_USAGE_DEPTH         = 32;   // DEPTH_STENCIL_ATTACHMENT
    private const VK_SAMPLE_COUNT_1            = 1;
    private const VK_LOAD_OP_CLEAR             = 1;
    private const VK_LOAD_OP_DONT_CARE         = 2;
    private const VK_STORE_OP_STORE            = 0;
    private const VK_STORE_OP_DONT_CARE        = 1;
    private const VK_LAYOUT_UNDEFINED          = 0;
    private const VK_LAYOUT_COLOR_ATTACHMENT   = 2;
    private const VK_LAYOUT_DEPTH_ATTACHMENT   = 3;
    private const VK_PIPELINE_BIND_GRAPHICS    = 0;
    private const VK_ASPECT_COLOR              = 1;
    private const VK_ASPECT_DEPTH              = 2;

    private int $width = 0;
    private int $height = 0;
    private int $samples = 1;

    private int $colorFormat = 0;
    private int $depthFormat = 0;

    /** Single-sample colour image - used as colour attachment when samples == 1
     *  and as the resolve target when samples > 1. Always sampled / blitted. */
    private ?Image $resolveImage = null;
    private ?DeviceMemory $resolveImageMem = null;
    private ?ImageView $resolveImageView = null;

    /** Multisample colour image - only allocated when samples > 1. */
    private ?Image $msaaColorImage = null;
    private ?DeviceMemory $msaaColorMem = null;
    private ?ImageView $msaaColorView = null;

    /** Depth image, sample count matches the colour attachment. */
    private ?Image $depthImage = null;
    private ?DeviceMemory $depthMem = null;
    private ?ImageView $depthView = null;

    private ?RenderPass $renderPass = null;
    private ?Framebuffer $framebuffer = null;

    private bool $allocated = false;

    /**
     * Tri-state MSAA support cache:
     *   null  - not yet probed
     *   true  - ext-vulkan accepted a samples > 1 attachment chain
     *   false - rejected once, future probes are skipped and samples coerced to 1
     */
    private ?bool $msaaSupported = null;

    public function __construct(
        private readonly Device $device,
    ) {
    }

    /**
     * Allocate or rebuild the offscreen target.
     *
     * No-op when the requested config matches the current state. The
     * `$memoryFinder` callable receives the result of
     * `$image->getMemoryRequirements()` plus a `$hostVisible` hint and must
     * return a memory type index suitable for `DeviceMemory`.
     *
     * @param callable(array<mixed>, bool): int $memoryFinder
     */
    public function resize(
        int $width,
        int $height,
        int $samples,
        int $colorFormat,
        int $depthFormat,
        callable $memoryFinder,
    ): void {
        $width   = max(1, $width);
        $height  = max(1, $height);
        $samples = max(1, $samples);

        // Suppress MSAA on builds that previously refused it.
        if ($samples > 1 && $this->msaaSupported === false) {
            $samples = 1;
        }

        if ($this->allocated
            && $this->width === $width
            && $this->height === $height
            && $this->samples === $samples
            && $this->colorFormat === $colorFormat
            && $this->depthFormat === $depthFormat
        ) {
            return;
        }

        $this->release();

        // Probe MSAA the first time a sample-count > 1 build is requested.
        if ($samples > 1 && $this->msaaSupported === null) {
            try {
                $this->allocate($width, $height, $samples, $colorFormat, $depthFormat, $memoryFinder);
                $this->msaaSupported = true;
                return;
            } catch (\Throwable $e) {
                $this->msaaSupported = false;
                $this->release();
                fwrite(STDERR, sprintf(
                    "[VulkanOffscreenTarget] MSAA samples=%d rejected by ext-vulkan (%s) - falling back to single-sample target.\n",
                    $samples,
                    $e->getMessage(),
                ));
                $samples = 1;
            }
        } elseif ($samples > 1) {
            // Already known good; allocate the multisample chain directly.
            $this->allocate($width, $height, $samples, $colorFormat, $depthFormat, $memoryFinder);
            return;
        }

        $this->allocate($width, $height, $samples, $colorFormat, $depthFormat, $memoryFinder);
    }

    /**
     * @param callable(array<mixed>, bool): int $memoryFinder
     */
    private function allocate(
        int $width,
        int $height,
        int $samples,
        int $colorFormat,
        int $depthFormat,
        callable $memoryFinder,
    ): void {
        $this->width       = $width;
        $this->height      = $height;
        $this->samples     = $samples;
        $this->colorFormat = $colorFormat;
        $this->depthFormat = $depthFormat;

        // ── Resolve / single-sample colour image ─────────────────────────
        $this->resolveImage = new Image(
            $this->device,
            $width,
            $height,
            $colorFormat,
            self::VK_IMAGE_USAGE_COLOR | self::VK_IMAGE_USAGE_TRANSFER_SRC,
            0,
            self::VK_SAMPLE_COUNT_1,
        );
        $req  = $this->resolveImage->getMemoryRequirements();
        $size = $this->intFromReq($req, 'resolve image');
        $this->resolveImageMem = new DeviceMemory($this->device, $size, $memoryFinder($req, false));
        $this->resolveImage->bindMemory($this->resolveImageMem, 0);
        $this->resolveImageView = new ImageView(
            $this->device,
            $this->resolveImage,
            $colorFormat,
            self::VK_ASPECT_COLOR,
            1,
        );

        // ── Multisample colour image (when samples > 1) ──────────────────
        if ($samples > 1) {
            $this->msaaColorImage = new Image(
                $this->device,
                $width,
                $height,
                $colorFormat,
                self::VK_IMAGE_USAGE_COLOR,
                0,
                $samples,
            );
            $req  = $this->msaaColorImage->getMemoryRequirements();
            $size = $this->intFromReq($req, 'msaa colour image');
            $this->msaaColorMem = new DeviceMemory($this->device, $size, $memoryFinder($req, false));
            $this->msaaColorImage->bindMemory($this->msaaColorMem, 0);
            $this->msaaColorView = new ImageView(
                $this->device,
                $this->msaaColorImage,
                $colorFormat,
                self::VK_ASPECT_COLOR,
                1,
            );
        }

        // ── Depth image (sample count matches the colour attachment) ────
        $this->depthImage = new Image(
            $this->device,
            $width,
            $height,
            $depthFormat,
            self::VK_IMAGE_USAGE_DEPTH,
            0,
            $samples,
        );
        $req  = $this->depthImage->getMemoryRequirements();
        $size = $this->intFromReq($req, 'depth image');
        $this->depthMem = new DeviceMemory($this->device, $size, $memoryFinder($req, false));
        $this->depthImage->bindMemory($this->depthMem, 0);
        $this->depthView = new ImageView(
            $this->device,
            $this->depthImage,
            $depthFormat,
            self::VK_ASPECT_DEPTH,
            1,
        );

        // ── Render pass + framebuffer ───────────────────────────────────
        if ($samples > 1) {
            $this->renderPass = $this->createMsaaRenderPass($colorFormat, $depthFormat, $samples);
            $this->framebuffer = new Framebuffer(
                $this->device,
                $this->renderPass,
                [$this->msaaColorView, $this->depthView, $this->resolveImageView],
                $width,
                $height,
                1,
            );
        } else {
            $this->renderPass = $this->createSingleSampleRenderPass($colorFormat, $depthFormat);
            $this->framebuffer = new Framebuffer(
                $this->device,
                $this->renderPass,
                [$this->resolveImageView, $this->depthView],
                $width,
                $height,
                1,
            );
        }

        $this->allocated = true;
    }

    private function createSingleSampleRenderPass(int $colorFormat, int $depthFormat): RenderPass
    {
        return new RenderPass(
            $this->device,
            [
                [
                    'format'         => $colorFormat,
                    'samples'        => self::VK_SAMPLE_COUNT_1,
                    'loadOp'         => self::VK_LOAD_OP_CLEAR,
                    'storeOp'        => self::VK_STORE_OP_STORE,
                    'stencilLoadOp'  => self::VK_LOAD_OP_DONT_CARE,
                    'stencilStoreOp' => self::VK_STORE_OP_DONT_CARE,
                    'initialLayout'  => self::VK_LAYOUT_UNDEFINED,
                    'finalLayout'    => self::VK_LAYOUT_COLOR_ATTACHMENT,
                ],
                [
                    'format'         => $depthFormat,
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
    }

    /**
     * MSAA render pass with a third resolve attachment that the driver
     * resolves into at end-of-pass. Throws when ext-vulkan does not accept
     * the resolve-attachment subpass key, allowing the caller to fall back.
     */
    private function createMsaaRenderPass(int $colorFormat, int $depthFormat, int $samples): RenderPass
    {
        return new RenderPass(
            $this->device,
            [
                // Attachment 0: multisample colour, transient (no store).
                [
                    'format'         => $colorFormat,
                    'samples'        => $samples,
                    'loadOp'         => self::VK_LOAD_OP_CLEAR,
                    'storeOp'        => self::VK_STORE_OP_DONT_CARE,
                    'stencilLoadOp'  => self::VK_LOAD_OP_DONT_CARE,
                    'stencilStoreOp' => self::VK_STORE_OP_DONT_CARE,
                    'initialLayout'  => self::VK_LAYOUT_UNDEFINED,
                    'finalLayout'    => self::VK_LAYOUT_COLOR_ATTACHMENT,
                ],
                // Attachment 1: multisample depth.
                [
                    'format'         => $depthFormat,
                    'samples'        => $samples,
                    'loadOp'         => self::VK_LOAD_OP_CLEAR,
                    'storeOp'        => self::VK_STORE_OP_DONT_CARE,
                    'stencilLoadOp'  => self::VK_LOAD_OP_DONT_CARE,
                    'stencilStoreOp' => self::VK_STORE_OP_DONT_CARE,
                    'initialLayout'  => self::VK_LAYOUT_UNDEFINED,
                    'finalLayout'    => self::VK_LAYOUT_DEPTH_ATTACHMENT,
                ],
                // Attachment 2: single-sample resolve target, kept for later sampling / blit.
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
                    'pipelineBindPoint'  => self::VK_PIPELINE_BIND_GRAPHICS,
                    'colorAttachments'   => [['attachment' => 0, 'layout' => self::VK_LAYOUT_COLOR_ATTACHMENT]],
                    'depthAttachment'    => ['attachment' => 1, 'layout' => self::VK_LAYOUT_DEPTH_ATTACHMENT],
                    'resolveAttachments' => [['attachment' => 2, 'layout' => self::VK_LAYOUT_COLOR_ATTACHMENT]],
                ],
            ],
            [],
        );
    }

    /** @param array<mixed> $req */
    private function intFromReq(array $req, string $label): int
    {
        $size = $req['size'] ?? null;
        if (!is_int($size)) {
            throw new \RuntimeException("Invalid {$label} memory requirements");
        }
        return $size;
    }

    public function isAllocated(): bool
    {
        return $this->allocated;
    }

    public function width(): int { return $this->width; }
    public function height(): int { return $this->height; }
    public function samples(): int { return $this->samples; }

    public function renderPass(): ?RenderPass { return $this->renderPass; }
    public function framebuffer(): ?Framebuffer { return $this->framebuffer; }

    /** Image that the present blit / FXAA pass should sample / copy from. */
    public function presentImage(): ?Image { return $this->resolveImage; }
    public function presentImageView(): ?ImageView { return $this->resolveImageView; }

    /** Whether MSAA has been verified working. Null until the first probe. */
    public function msaaSupported(): ?bool { return $this->msaaSupported; }

    /**
     * Release every Vulkan object owned by this target. Safe to call before
     * `resize()` and during destruction; a second call is a no-op.
     *
     * Width/height/samples/format are retained so `resize()` can short-circuit.
     */
    public function release(): void
    {
        $this->framebuffer      = null;
        $this->renderPass       = null;

        $this->resolveImageView = null;
        $this->resolveImage     = null;
        $this->resolveImageMem  = null;

        $this->msaaColorView    = null;
        $this->msaaColorImage   = null;
        $this->msaaColorMem     = null;

        $this->depthView        = null;
        $this->depthImage       = null;
        $this->depthMem         = null;

        $this->allocated        = false;
    }
}
