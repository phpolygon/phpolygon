<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Runtime;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use PHPolygon\Engine;
use PHPolygon\EngineConfig;
use PHPolygon\Runtime\Input;
use PHPolygon\Runtime\VioWindow;

/**
 * Rendering for a test does not belong on screen: an offscreen run draws into
 * an invisible surface, so it never takes the focus from whatever the person
 * is doing while the suite runs. The invisible surface still renders on the
 * hardware GPU unless the software adapter is asked for: php-vio puts a
 * headless D3D context on WARP otherwise (seconds per frame, no upscalers).
 */
final class OffscreenWindowTest extends TestCase
{
    private const SOFTWARE_ADAPTER = 'Microsoft Basic Render Driver';

    /** @return array<string, mixed> */
    private static function config(VioWindow $window): array
    {
        return (new \ReflectionMethod(VioWindow::class, 'createConfig'))->invoke($window);
    }

    public function testAWindowIsOnScreenByDefault(): void
    {
        $window = new VioWindow(320, 200, 'test');

        $this->assertArrayNotHasKey('headless', self::config($window));
        $this->assertArrayNotHasKey('headless_hardware', self::config($window));
    }

    public function testAnOffscreenWindowAsksVioForAnInvisibleOne(): void
    {
        $window = new VioWindow(320, 200, 'test', offscreen: true);

        $this->assertTrue(self::config($window)['headless'] ?? false);
    }

    public function testAnOffscreenWindowRendersOnTheHardwareGpuByDefault(): void
    {
        $window = new VioWindow(320, 200, 'test', offscreen: true);

        $this->assertTrue(self::config($window)['headless_hardware'] ?? false);
    }

    public function testAnOffscreenWindowTakesTheSoftwareAdapterWhenAskedTo(): void
    {
        $window = new VioWindow(320, 200, 'test', offscreen: true, offscreenHardware: false);

        $this->assertTrue(self::config($window)['headless'] ?? false);
        $this->assertArrayNotHasKey('headless_hardware', self::config($window));
    }

    public function testAGameWindowIsVisibleUnlessAskedOtherwise(): void
    {
        $this->assertFalse((new EngineConfig())->offscreen);
        $this->assertTrue((new EngineConfig(offscreen: true))->offscreen);
    }

    public function testOffscreenRenderingPrefersTheHardwareGpu(): void
    {
        $this->assertTrue((new EngineConfig())->offscreenHardware);
        $this->assertFalse((new EngineConfig(offscreen: true, offscreenHardware: false))->offscreenHardware);
    }

    /** @return array<string, array{bool}> */
    public static function adapterChoices(): array
    {
        return ['hardware GPU' => [true], 'software adapter' => [false]];
    }

    #[RequiresPhpExtension('vio')]
    #[DataProvider('adapterChoices')]
    public function testTheEngineHandsTheAdapterChoiceToItsWindow(bool $hardware): void
    {
        $dir = sys_get_temp_dir() . '/phpolygon-offscreen-' . bin2hex(random_bytes(4));
        $engine = new Engine(new EngineConfig(
            offscreen: true,
            savePath: $dir,
            graphicsSettingsPath: $dir . '/graphics.json',
            shaderCachePath: '',
            firstLaunchCalibration: false,
            autoThermalManagement: false,
            offscreenHardware: $hardware,
        ));

        foreach (is_dir($dir) ? (glob($dir . '/*') ?: []) : [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);

        $this->assertInstanceOf(VioWindow::class, $engine->window);
        $config = self::config($engine->window);
        $this->assertTrue($config['headless'] ?? false);
        $this->assertSame($hardware, $config['headless_hardware'] ?? false);
    }

    /**
     * The surface really lands on the GPU: php-vio's D3D12 backend opens the
     * software adapter for a headless context unless asked otherwise.
     */
    #[RequiresPhpExtension('vio')]
    public function testAnOffscreenD3d12WindowOpensOnTheHardwareAdapter(): void
    {
        $this->requireD3d12();
        $probe = @vio_create('d3d12', ['width' => 16, 'height' => 16, 'headless' => true, 'headless_hardware' => true, 'vsync' => false]);
        if ($probe === false) {
            $this->markTestSkipped('no D3D12 hardware adapter on this machine');
        }
        $hardware = (string) (vio_gpu_info()['name'] ?? '');
        vio_destroy($probe);
        if ($hardware === '' || $hardware === self::SOFTWARE_ADAPTER) {
            $this->markTestSkipped('no D3D12 hardware adapter on this machine');
        }

        $window = new VioWindow(64, 64, 'test', backend: 'd3d12', offscreen: true);
        $window->initialize(new Input());
        try {
            $this->assertSame($hardware, vio_gpu_info()['name'] ?? '');
        } finally {
            vio_destroy($window->getContext());
        }
    }

    /** Tests that compare against images rendered on WARP keep getting WARP. */
    #[RequiresPhpExtension('vio')]
    public function testTheSoftwareAdapterStaysAvailable(): void
    {
        $this->requireD3d12();

        $window = new VioWindow(64, 64, 'test', backend: 'd3d12', offscreen: true, offscreenHardware: false);
        $window->initialize(new Input());
        try {
            $this->assertSame(self::SOFTWARE_ADAPTER, vio_gpu_info()['name'] ?? '');
        } finally {
            vio_destroy($window->getContext());
        }
    }

    private function requireD3d12(): void
    {
        if (PHP_OS_FAMILY !== 'Windows' || !function_exists('vio_gpu_info') || !in_array('d3d12', vio_backends(), true)) {
            $this->markTestSkipped('D3D12 only exists on Windows');
        }
    }
}
