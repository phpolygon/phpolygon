<?php

declare(strict_types=1);

namespace PHPolygon\Tests\System;

use PHPUnit\Framework\TestCase;
use PHPolygon\ECS\World;
use PHPolygon\Rendering\Command\SetFieldtracing;
use PHPolygon\Rendering\NullRenderer3D;
use PHPolygon\Rendering\Quality\FieldtracingMode;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\System\Renderer3DSystem;

class Renderer3DSystemFieldtracingTest extends TestCase
{
    /** A spy renderer that captures SetFieldtracing commands at flush time. */
    private function spy(): NullRenderer3D
    {
        return new class extends NullRenderer3D {
            /** @var list<SetFieldtracing> */
            public array $ft = [];
            public function render(RenderCommandList $commands): void
            {
                $this->ft = $commands->ofType(SetFieldtracing::class);
            }
        };
    }

    public function testNoCommandEmittedByDefault(): void
    {
        $spy = $this->spy();
        $system = new Renderer3DSystem($spy, new RenderCommandList());
        $system->render(new World());

        // Until a tier is set (the Engine pushes GraphicsSettings::$fieldtracing),
        // the system emits nothing — the backend uses its own settings-derived tier.
        $this->assertCount(0, $spy->ft);
    }

    public function testEmitsSetFieldtracingWhenModeSet(): void
    {
        $spy = $this->spy();
        $system = new Renderer3DSystem($spy, new RenderCommandList());
        $system->setFieldtracingMode(FieldtracingMode::SdfOcclusion);
        $system->render(new World());

        $this->assertCount(1, $spy->ft);
        $this->assertSame(FieldtracingMode::SdfOcclusion, $spy->ft[0]->mode);
    }

    public function testSettingNullDisablesEmissionAgain(): void
    {
        $spy = $this->spy();
        $system = new Renderer3DSystem($spy, new RenderCommandList());
        $system->setFieldtracingMode(FieldtracingMode::SdfBounce);
        $system->render(new World());
        $this->assertCount(1, $spy->ft);

        $system->setFieldtracingMode(null);
        $system->render(new World());
        $this->assertCount(0, $spy->ft);
    }
}
