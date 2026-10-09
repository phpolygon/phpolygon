<?php

declare(strict_types=1);

namespace PHPolygon\Tests\UI;

use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Quality\TemporalCamera;
use PHPolygon\UI\DevMonitorPanel;
use PHPUnit\Framework\TestCase;

/**
 * The DevMonitor's temporal section: whether a temporal technique runs, where
 * the jitter sequence is, whether the history was dropped this frame, and
 * which PHPOLYGON_VIO_DEBUG_VIEW is active.
 */
final class DevMonitorTemporalLinesTest extends TestCase
{
    public function testOffWhenNoTemporalFrame(): void
    {
        $lines = DevMonitorPanel::temporalLines(null, null);
        $this->assertSame('Temporal:', $lines[0]['text']);
        $this->assertStringContainsString('off', $lines[1]['text']);
        $this->assertStringContainsString('debug view: off', $lines[2]['text']);
    }

    public function testShowsPhaseHistoryAndDebugView(): void
    {
        $camera = new TemporalCamera();
        $view = Mat4::lookAt(new Vec3(0, 1, 5), new Vec3(0, 0, 0), new Vec3(0, 1, 0));
        $projection = Mat4::perspective(1.0, 1.5, 0.1, 100.0);
        $first = $camera->begin($view, $projection, false, 640, 360, 1280, 720, true);
        $lines = DevMonitorPanel::temporalLines($first, 'motion');
        $this->assertStringContainsString('640x360 -> 1280x720', $lines[1]['text']);
        $this->assertStringContainsString('phase 1/32', $lines[1]['text']);
        $this->assertStringContainsString('history reset (first)', $lines[1]['text']);
        $this->assertSame('warn', $lines[1]['tone']);
        $this->assertStringContainsString('debug view: motion', $lines[2]['text']);

        $second = $camera->begin($view, $projection, false, 640, 360, 1280, 720, true);
        $lines = DevMonitorPanel::temporalLines($second, null);
        $this->assertStringContainsString('history ok', $lines[1]['text']);
        $this->assertSame('ok', $lines[1]['tone']);
    }
}
