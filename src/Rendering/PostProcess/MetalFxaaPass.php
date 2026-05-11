<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\PostProcess;

use Metal\Device;
use Metal\Library;
use Metal\RenderCommandEncoder;
use Metal\RenderPipelineDescriptor;
use Metal\RenderPipelineState;
use Metal\SamplerDescriptor;
use Metal\SamplerState;
use Metal\ShaderFunction;
use Metal\Texture;

/**
 * Metal FXAA / passthrough-blit pass for the Phase 1.5 offscreen pipeline.
 *
 * Owns the post-process pipeline states (FXAA + blit), the linear sampler
 * used to read the offscreen colour texture, and a "depth always pass /
 * never write" depth-stencil state so the present pass shares the same
 * render encoder as the rest of the frame.
 *
 * Lifecycle: lazily compiled on first use; reused for every frame.
 * Pipelines depend on the destination colour pixel format - if the
 * drawable format changes (rare), call `release()` to force a rebuild.
 */
final class MetalFxaaPass
{
    private const SHADER_PATH = __DIR__ . '/../../../resources/shaders/source/fxaa.metal';

    private bool $initialised = false;
    private ?RenderPipelineState $fxaaPipeline = null;
    private ?RenderPipelineState $blitPipeline = null;
    private ?SamplerState $linearSampler = null;

    public function __construct(
        private readonly Device $device,
        private readonly int $destinationColorPixelFormat,
    ) {
    }

    /**
     * Run FXAA on the input texture. Caller has already begun the present
     * render encoder (with the drawable as the colour attachment, no depth)
     * and is responsible for setting the viewport.
     */
    public function applyFxaa(RenderCommandEncoder $encoder, Texture $input): void
    {
        if (!$this->initialised) {
            $this->initialise();
        }
        if ($this->fxaaPipeline === null || $this->linearSampler === null) {
            return;
        }

        $encoder->setRenderPipelineState($this->fxaaPipeline);
        $encoder->setCullMode(\Metal\CullModeNone);

        // Pack FxaaParams { float2 inv_resolution; float2 _pad; }.
        $w = $input->getWidth();
        $h = $input->getHeight();
        $invW = $w > 0 ? 1.0 / (float)$w : 0.0;
        $invH = $h > 0 ? 1.0 / (float)$h : 0.0;
        $bytes = pack('ffff', $invW, $invH, 0.0, 0.0);

        $encoder->setFragmentBytes($bytes, 0);
        $encoder->setFragmentTexture($input, 0);
        $encoder->setFragmentSamplerState($this->linearSampler, 0);

        $encoder->drawPrimitives(\Metal\PrimitiveTypeTriangle, 0, 3);
    }

    /**
     * Run a passthrough blit (no AA) - used when AA is off but render scale
     * is not 1.0. The bilinear sampler handles the up/downscale implicitly.
     */
    public function applyBlit(RenderCommandEncoder $encoder, Texture $input): void
    {
        if (!$this->initialised) {
            $this->initialise();
        }
        if ($this->blitPipeline === null || $this->linearSampler === null) {
            return;
        }

        $encoder->setRenderPipelineState($this->blitPipeline);
        $encoder->setCullMode(\Metal\CullModeNone);

        $encoder->setFragmentTexture($input, 0);
        $encoder->setFragmentSamplerState($this->linearSampler, 0);

        $encoder->drawPrimitives(\Metal\PrimitiveTypeTriangle, 0, 3);
    }

    public function release(): void
    {
        $this->fxaaPipeline  = null;
        $this->blitPipeline  = null;
        $this->linearSampler = null;
        $this->initialised   = false;
    }

    public function isReady(): bool
    {
        return $this->fxaaPipeline !== null && $this->blitPipeline !== null;
    }

    private function initialise(): void
    {
        $msl = @file_get_contents(self::SHADER_PATH);
        if ($msl === false) {
            $this->initialised = true;
            fwrite(STDERR, "[MetalFxaaPass] failed to read MSL source at " . self::SHADER_PATH . "\n");
            return;
        }

        try {
            $library = $this->device->createLibraryWithSource($msl);
            $vertFn  = $library->getFunction('vertex_fxaa');
            $fxaaFn  = $library->getFunction('fragment_fxaa');
            $blitFn  = $library->getFunction('fragment_blit');

            $this->fxaaPipeline = $this->buildPipeline($library, $vertFn, $fxaaFn);
            $this->blitPipeline = $this->buildPipeline($library, $vertFn, $blitFn);
        } catch (\Throwable $e) {
            $this->fxaaPipeline = null;
            $this->blitPipeline = null;
            $this->initialised  = true;
            fwrite(STDERR, "[MetalFxaaPass] pipeline compile failed: " . $e->getMessage() . "\n");
            return;
        }

        // Linear sampler for upscaling / FXAA neighbour fetches.
        $samplerDesc = new SamplerDescriptor();
        $samplerDesc->setMinFilter(\Metal\SamplerMinMagFilterLinear);
        $samplerDesc->setMagFilter(\Metal\SamplerMinMagFilterLinear);
        $samplerDesc->setSAddressMode(\Metal\SamplerAddressModeClampToEdge);
        $samplerDesc->setTAddressMode(\Metal\SamplerAddressModeClampToEdge);
        $this->linearSampler = $this->device->createSamplerState($samplerDesc);

        $this->initialised = true;
    }

    private function buildPipeline(Library $library, ShaderFunction $vertexFn, ShaderFunction $fragmentFn): RenderPipelineState
    {
        // The library reference is only kept for the duration of pipeline
        // creation; Metal retains the underlying functions internally.
        unset($library);

        $desc = new RenderPipelineDescriptor();
        $desc->setVertexFunction($vertexFn);
        $desc->setFragmentFunction($fragmentFn);
        $desc->getColorAttachment(0)->setPixelFormat($this->destinationColorPixelFormat);
        // No depth attachment in the present pass - the FXAA / blit pass
        // writes only colour. Metal infers no depth output from the absence
        // of setDepthAttachmentPixelFormat.
        // Single-sample destination (the drawable). Samples > 1 here would
        // require the colour attachment to be multisample, which Metal layers
        // never are.
        $desc->setRasterSampleCount(1);
        // No vertex descriptor - vertex_fxaa generates positions via [[vertex_id]].

        return $this->device->createRenderPipelineState($desc);
    }
}
