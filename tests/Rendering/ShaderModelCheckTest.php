<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use PHPolygon\Rendering\ShaderModelCheck;

/** The opt-in shader-model check: D3D12 with DXC passes, D3D12 with FXC throws, other backends pass. */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class ShaderModelCheckTest extends TestCase
{
    public function testTheCheckFollowsTheContextsShaderModel(): void
    {
        $backend = getenv('PHPOLYGON_TEST_VIO_BACKEND') ?: 'auto';
        if ($backend !== 'd3d12') {
            $this->markTestSkipped('needs a D3D12 context (PHPOLYGON_TEST_VIO_BACKEND=d3d12)');
        }
        // One context at a time: two live D3D12 devices in one process are not guaranteed.
        $sm6 = VioTestContext::create(16, 16, ['shader_model' => 6]);
        if ($sm6 === null) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        $detected = ShaderModelCheck::detect($sm6);
        if ($detected === 6) {
            ShaderModelCheck::require($sm6);
        }
        vio_destroy($sm6);
        if ($detected !== 6) {
            $this->markTestSkipped('DXC is not available on this host');
        }
        $fxc = VioTestContext::create(16, 16);
        if ($fxc === null) {
            $this->markTestSkipped('second context unavailable');
        }
        self::assertSame(5, ShaderModelCheck::detect($fxc));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('shader model 5');
        ShaderModelCheck::require($fxc);
    }
}
