<?php

declare(strict_types=1);

namespace PHPolygon\Tests\System;

use PHPolygon\System\GpuParticleBaker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The D3D12 descriptor-ring budget only applies to php-vio versions whose
 * compute heap is an unfenced 16-block ring; no GPU needed.
 */
class GpuParticleBakerDescriptorBudgetTest extends TestCase
{
    /** @return iterable<string, array{string, string, bool}> */
    public static function cases(): iterable
    {
        yield 'd3d12 before the fence' => ['d3d12', '2.32.0', true];
        yield 'd3d12 old' => ['d3d12', '2.24.3', true];
        yield 'd3d12 fenced' => ['d3d12', GpuParticleBaker::FENCED_DESCRIPTOR_VIO_VERSION, false];
        yield 'd3d12 newer' => ['d3d12', '2.40.0', false];
        yield 'vulkan old' => ['vulkan', '2.24.3', false];
        yield 'opengl old' => ['opengl', '2.30.0', false];
    }

    #[DataProvider('cases')]
    public function testBudgetOnlyForTheUnfencedD3D12Ring(string $backend, string $version, bool $limited): void
    {
        $budget = GpuParticleBaker::descriptorBudgetFor($backend, $version);
        if ($limited) {
            $this->assertGreaterThan(0, $budget);
            $this->assertLessThan(16, $budget, 'must leave headroom inside the 16-block ring');
        } else {
            $this->assertSame(0, $budget);
        }
    }
}
