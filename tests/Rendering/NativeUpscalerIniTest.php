<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use PHPolygon\EngineConfig;
use PHPolygon\Rendering\NativeUpscalerIni;

/**
 * The game identifies itself to NVIDIA's NGX (DLSS) with its own project id
 * and may keep the upscaler runtimes (FidelityFX / DLSS libraries) in a
 * directory of its own; the engine hands both to php-vio before the context
 * exists.
 */
final class NativeUpscalerIniTest extends TestCase
{
    private const GUID = '3f2a9c1e-7b4d-4e8a-9f60-1c2d3e4f5a6b';

    public function testNothingConfiguredSetsNothing(): void
    {
        $this->assertSame([], NativeUpscalerIni::settings('', ''));
    }

    public function testProjectIdAndRuntimeDirectory(): void
    {
        $this->assertSame([
            'vio.dlss_project_id' => self::GUID,
            'vio.ffx_path' => 'C:\\Game\\redist',
            'vio.dlss_path' => 'C:\\Game\\redist',
        ], NativeUpscalerIni::settings(self::GUID, 'C:\\Game\\redist'));
    }

    public function testTheProjectIdMustLookLikeAGuid(): void
    {
        // NGX rejects anything else with "invalid parameter" - say it early.
        $this->expectException(\InvalidArgumentException::class);
        NativeUpscalerIni::settings('my-game', '');
    }

    public function testBracedGuidIsAccepted(): void
    {
        $this->assertSame(
            ['vio.dlss_project_id' => self::GUID],
            NativeUpscalerIni::settings('{' . strtoupper(self::GUID) . '}', ''),
        );
    }

    public function testEngineConfigDefaultsLeavePhpViosOwn(): void
    {
        $config = new EngineConfig();
        $this->assertSame('', $config->dlssProjectId);
        $this->assertSame('', $config->upscalerRuntimePath);
        $config = new EngineConfig(dlssProjectId: self::GUID, upscalerRuntimePath: 'redist');
        $this->assertSame(self::GUID, $config->dlssProjectId);
        $this->assertSame('redist', $config->upscalerRuntimePath);
    }

    #[RequiresPhpExtension('vio')]
    public function testApplyReachesPhpVio(): void
    {
        $before = ini_get('vio.dlss_project_id');
        if ($before === false) {
            $this->markTestSkipped('this php-vio build has no DLSS settings');
        }
        try {
            $applied = NativeUpscalerIni::apply(new EngineConfig(dlssProjectId: self::GUID));
            $this->assertSame(['vio.dlss_project_id' => self::GUID], $applied);
            $this->assertSame(self::GUID, ini_get('vio.dlss_project_id'));
        } finally {
            ini_set('vio.dlss_project_id', $before);
        }
    }
}
