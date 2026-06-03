<?php

declare(strict_types=1);

namespace PHPolygon\UI;

use PHPolygon\Engine;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Renderer2DInterface;
use PHPolygon\Rendering\Quality\ThermalSourceFrametime;
use PHPolygon\Rendering\Quality\ThermalSourceOs;
use PHPolygon\Rendering\TextAlign;

/**
 * Expanded developer panel rendered in place of the compact PerfOverlay
 * when the engine is started with --dev-monitor (or EngineConfig::$devMonitor)
 * and the V key has activated the verbose view.
 *
 * Shows HardwareProfile, active thermal pressure, individual sources, p95
 * frametime + budget, and the current targetFps with its ceiling. Read-only
 * - all state comes from Engine fields, so toggling the view never disturbs
 * the simulation.
 */
final class DevMonitorPanel
{
    private const PANEL_WIDTH = 360.0;
    private const PADDING = 10.0;
    private const LINE_HEIGHT = 15.0;
    private const FONT_SIZE = 11.0;
    private const HEADER_FONT_SIZE = 13.0;

    public function __construct(private readonly Engine $engine)
    {
    }

    public function render(Renderer2DInterface $r): void
    {
        $bg = new Color(0.0, 0.0, 0.0, 0.78);
        $border = new Color(0.95, 0.6, 0.2, 0.9);
        $textPrimary = new Color(0.95, 0.95, 0.95, 1.0);
        $textDim = new Color(0.7, 0.7, 0.7, 1.0);
        $textWarn = new Color(1.0, 0.7, 0.2, 1.0);
        $textOk = new Color(0.6, 0.95, 0.6, 1.0);

        $lines = $this->buildLines();
        $height = self::PADDING * 2 + self::LINE_HEIGHT * (count($lines) + 1);

        $x = 8.0;
        $y = 8.0;
        $r->drawRect($x, $y, self::PANEL_WIDTH, $height, $bg);
        $r->drawRectOutline($x, $y, self::PANEL_WIDTH, $height, $border, 1.0);

        $r->setTextAlign(TextAlign::LEFT | TextAlign::TOP);
        $cx = $x + self::PADDING;
        $cy = $y + self::PADDING;
        $r->drawText('DevMonitor (V)', $cx, $cy, self::HEADER_FONT_SIZE, $textPrimary);
        $cy += self::LINE_HEIGHT + 2.0;

        foreach ($lines as $line) {
            $color = match ($line['tone']) {
                'warn'  => $textWarn,
                'ok'    => $textOk,
                'dim'   => $textDim,
                default => $textPrimary,
            };
            $r->drawText($line['text'], $cx, $cy, self::FONT_SIZE, $color);
            $cy += self::LINE_HEIGHT;
        }
    }

    /**
     * @return list<array{text:string, tone:string}>
     */
    private function buildLines(): array
    {
        $engine = $this->engine;
        $hw = $engine->hardware;
        $settings = $engine->graphics->settings();
        $monitor = $engine->thermalMonitor;

        $out = [];
        $out[] = ['text' => 'Hardware:', 'tone' => 'dim'];
        $out[] = ['text' => '  ' . $hw->thermalProfile->label(), 'tone' => 'primary'];
        $out[] = ['text' => '  ' . self::trimBrand($hw->cpuBrand), 'tone' => 'dim'];

        $out[] = ['text' => '', 'tone' => 'dim'];
        $out[] = ['text' => 'Thermal:', 'tone' => 'dim'];

        if ($monitor === null) {
            $out[] = ['text' => '  (disabled)', 'tone' => 'dim'];
        } else {
            $pressure = $monitor->currentPressure();
            $pressureTone = match ($pressure->value) {
                'nominal' => 'ok',
                'fair' => 'primary',
                'serious', 'critical' => 'warn',
                default => 'dim',
            };
            $out[] = ['text' => sprintf(
                '  Pressure: %s (source=%s)',
                $pressure->value,
                $monitor->lastTriggerSource() === '' ? '-' : $monitor->lastTriggerSource(),
            ), 'tone' => $pressureTone];
            $out[] = ['text' => sprintf('  Ceiling:  %.0f fps', $monitor->ceiling()), 'tone' => 'dim'];

            foreach ($monitor->sources() as $source) {
                if ($source instanceof ThermalSourceFrametime) {
                    $budgetMs = 1000.0 / max(1.0, $settings->targetFps);
                    $out[] = ['text' => sprintf(
                        '  Frametime p95: %.2f ms (budget %.2f, samples %d)',
                        $source->lastP95Ms(),
                        $budgetMs,
                        $source->sampleCount(),
                    ), 'tone' => $source->lastP95Ms() > $budgetMs * 1.2 ? 'warn' : 'primary'];
                } elseif ($source instanceof ThermalSourceOs) {
                    $out[] = ['text' => '  OS thermal:    ' . $source->lastState()->value, 'tone' => 'primary'];
                }
            }
        }

        $out[] = ['text' => '', 'tone' => 'dim'];
        $out[] = ['text' => 'Target:', 'tone' => 'dim'];
        $out[] = ['text' => sprintf('  targetFps:   %.0f', $settings->targetFps), 'tone' => 'primary'];
        $effectiveCap = $engine->gameLoop->getFpsCap();
        $capLabel = $effectiveCap > 0 ? sprintf('%d fps', $effectiveCap) : 'uncapped';
        $userCapLabel = $settings->fpsCap > 0 ? sprintf('%d fps', $settings->fpsCap) : 'auto';
        $out[] = ['text' => sprintf('  fpsCap:      %s (user: %s)', $capLabel, $userCapLabel), 'tone' => 'dim'];
        $out[] = ['text' => sprintf('  qualityMode: %s', $settings->mode->label()), 'tone' => 'dim'];

        // Real frame rate (wall clock between loop iterations, includes
        // throttle + vsync). Differs from Engine::$frameTimesMs which only
        // measures render work, not the full frame interval.
        $actualFps = $engine->gameLoop->getAverageFps();
        $out[] = ['text' => sprintf('  actual:      %.1f fps', $actualFps), 'tone' => 'primary'];

        if ($engine->frameTimesMs !== []) {
            $count = count($engine->frameTimesMs);
            $latest = $engine->frameTimesMs[$count - 1];
            $out[] = ['text' => sprintf('  render work: %.2f ms / frame', $latest), 'tone' => 'dim'];
        }

        if ($engine->devLogger !== null) {
            $out[] = ['text' => '', 'tone' => 'dim'];
            $out[] = ['text' => 'Log: ' . $engine->devLogger->path(), 'tone' => 'dim'];
        }

        return $out;
    }

    private static function trimBrand(string $brand): string
    {
        if ($brand === '') {
            return '(unknown CPU)';
        }
        if (strlen($brand) > 48) {
            return substr($brand, 0, 47) . '…';
        }
        return $brand;
    }
}
