<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Runtime;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use PHPolygon\Rendering\GpuAdapters;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Runtime\Input;
use PHPolygon\Runtime\VioMainWindow;

/**
 * The GPU choice of a graphics menu (GraphicsSettings::$gpu): stored by name, offered
 * by GpuAdapters, handed to php-vio's 'adapter' option when the window opens, and a
 * GPU that is gone falls back to the automatic choice instead of failing.
 */
final class GpuChoiceTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function config(VioMainWindow $window): array
    {
        return (new \ReflectionMethod(VioMainWindow::class, 'createConfig'))->invoke($window);
    }

    public function testTheChoiceIsSavedByNameAndAutomaticIsLeftOut(): void
    {
        $auto = new GraphicsSettings();
        $this->assertSame('', $auto->gpu);
        $this->assertArrayNotHasKey('gpu', $auto->toJson());

        $chosen = $auto->with(gpu: 'Intel(R) UHD Graphics 630');
        $this->assertSame('Intel(R) UHD Graphics 630', $chosen->toJson()['gpu']);
        $this->assertSame('Intel(R) UHD Graphics 630', GraphicsSettings::fromJson($chosen->toJson())->gpu);
        $this->assertSame('Intel(R) UHD Graphics 630', $chosen->with(bloom: false)->gpu, 'other changes keep the choice');
        $this->assertSame('', GraphicsSettings::fromJson(['gpu' => 42])->gpu, 'a broken value means automatic');
    }

    public function testTheWindowHandsTheChoiceToVio(): void
    {
        $this->assertArrayNotHasKey('adapter', self::config(new VioMainWindow(320, 200, 'test')));
        $this->assertSame('NVIDIA', self::config(new VioMainWindow(320, 200, 'test', gpu: 'NVIDIA'))['adapter'] ?? null);
    }

    #[RequiresPhpExtension('vio')]
    public function testTheMenuListsHardwareGpusOnly(): void
    {
        $list = GpuAdapters::available();
        if ($list === []) {
            $this->markTestSkipped('vio lists no adapters here');
        }
        $names = array_column($list, 'name');
        $this->assertSame(array_values(array_unique($names)), $names, 'one entry per GPU');
        $this->assertNotContains('software', array_column($list, 'type'));
        $this->assertTrue(GpuAdapters::isAvailable(''));
        $this->assertTrue(GpuAdapters::isAvailable($names[0]));
        $this->assertFalse(GpuAdapters::isAvailable('no such GPU 123'));
    }

    #[RequiresPhpExtension('vio')]
    public function testTheChosenGpuRendersAndAMissingOneFallsBack(): void
    {
        if (PHP_OS_FAMILY !== 'Windows' || !in_array('d3d12', vio_backends(), true)) {
            $this->markTestSkipped('D3D12 only exists on Windows');
        }
        $gpus = GpuAdapters::available('d3d12');
        if ($gpus === []) {
            $this->markTestSkipped('no D3D12 hardware adapter');
        }
        $last = $gpus[count($gpus) - 1]['name'];
        $window = new VioMainWindow(64, 64, 'test', backend: 'd3d12', offscreen: true, gpu: $last);
        $window->initialize(new Input());
        try {
            $this->assertSame($last, $window->gpuName());
        } finally {
            $window->destroy();
        }

        $window = new VioMainWindow(64, 64, 'test', backend: 'd3d12', offscreen: true, gpu: 'no such GPU 123');
        @$window->initialize(new Input());
        try {
            $this->assertNotSame('', $window->gpuName(), 'the automatic choice still opens');
        } finally {
            $window->destroy();
        }
    }
}
