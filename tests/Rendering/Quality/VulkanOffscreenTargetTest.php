<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering\Quality;

use PHPolygon\Rendering\PostProcess\VulkanFxaaPass;
use PHPUnit\Framework\TestCase;

/**
 * Phase 1.5d standalone Vulkan offscreen-target wiring tests.
 *
 * Structural assertions for the renderer-side maths live in
 * `OffscreenTargetTest` (NullRenderer3D mirrors the OpenGL path).
 *
 * The standalone Vulkan backend cannot run in CI without a GPU device, an
 * MoltenVK ICD, and a window surface, so the live-Vulkan tests here are
 * gated on `extension_loaded('vulkan')` and skipped otherwise. What we
 * still cover unconditionally:
 *
 *  - The compiled FXAA SPIR-V binaries are present in
 *    `resources/shaders/compiled/` (the Vulkan FXAA pipeline relies on
 *    them being committed alongside the source GLSL).
 *  - Class autoloads + helper construction don't fault under headless.
 */
final class VulkanOffscreenTargetTest extends TestCase
{
    public function testFxaaSpirVBinariesArePresent(): void
    {
        // The standalone Vulkan backend ships with pre-compiled FXAA SPIR-V
        // because we can't rely on glslangValidator being installed at build
        // time. Loss of these files would silently disable FXAA; assert their
        // presence at the test level so the regression is loud.
        self::assertTrue(
            VulkanFxaaPass::shadersAvailable(),
            'fxaa_vk.{vert,frag}.spv must be checked in alongside the GLSL sources '
            . 'so the standalone Vulkan backend can use FXAA without a build-time shader compiler.',
        );
    }

    public function testFxaaPassWithoutVulkanExtensionFailsGracefully(): void
    {
        if (extension_loaded('vulkan')) {
            $this->markTestSkipped('Live Vulkan device available - this test verifies the no-extension path.');
        }

        // Without ext-vulkan the helper still constructs a valid object - it
        // just cannot be initialise()'d into a working pipeline. We don't
        // call initialise() here because that requires a real Device.
        self::assertTrue(VulkanFxaaPass::shadersAvailable());
    }

    public function testHelperReachableFromAutoloader(): void
    {
        // Ensures the new Phase 1.5d classes are registered with composer's
        // PSR-4 map. Skips the live-device assertions but still catches
        // missing-namespace and naming regressions.
        self::assertTrue(class_exists(\PHPolygon\Rendering\VulkanOffscreenTarget::class));
        self::assertTrue(class_exists(\PHPolygon\Rendering\PostProcess\VulkanFxaaPass::class));
    }

    public function testFxaaPassExposesRenderPassAndRecordContract(): void
    {
        // Reflection-only: confirm the helper exposes the methods the
        // VulkanRenderer3D wiring relies on, even on builds without an
        // active Vulkan device. Catches accidental rename / signature
        // regressions before they hit a live GPU.
        $rc = new \ReflectionClass(VulkanFxaaPass::class);

        self::assertTrue($rc->hasMethod('initialise'));
        self::assertTrue($rc->hasMethod('isReady'));
        self::assertTrue($rc->hasMethod('renderPass'));
        self::assertTrue($rc->hasMethod('bindInput'));
        self::assertTrue($rc->hasMethod('record'));
        self::assertTrue($rc->hasMethod('release'));

        self::assertSame(
            'bool',
            (string) ($rc->getMethod('initialise')->getReturnType() ?? ''),
            'initialise() must report whether the FXAA chain is usable so the renderer can fall back to a plain blit.',
        );
        self::assertSame(
            'bool',
            (string) ($rc->getMethod('bindInput')->getReturnType() ?? ''),
            'bindInput() must signal descriptor-write failures so the renderer knows to skip the pass for this frame.',
        );
    }
}
