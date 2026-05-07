<?php

declare(strict_types=1);

namespace PHPolygon\UI;

use PHPolygon\Engine;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Renderer2DInterface;
use PHPolygon\Rendering\TextAlign;
use PHPolygon\Runtime\InputInterface;
use PHPolygon\Runtime\PerfProfiler;

/**
 * Developer overlay showing FPS, frame time, p95, GC counters and the
 * top PerfProfiler sections. Toggled with F3 when EngineConfig::$devMode
 * is true. Reads stats from Engine::$frameTimesMs / $lastGcDelta and
 * the global PerfProfiler accumulator. Draws via Renderer2D directly,
 * not UIContext - the overlay is non-interactive and must not steal input.
 */
final class PerfOverlay
{
    private const KEY_F3 = 292;
    private const PANEL_WIDTH = 280.0;
    private const PADDING = 8.0;
    private const LINE_HEIGHT = 14.0;
    private const FONT_SIZE = 11.0;
    private const HEADER_FONT_SIZE = 13.0;

    private bool $visible = false;

    public function __construct(private readonly Engine $engine)
    {
    }

    public function isVisible(): bool
    {
        return $this->visible;
    }

    public function setVisible(bool $visible): void
    {
        $this->visible = $visible;
    }

    /**
     * Read input and toggle visibility on F3 press. Call once per render
     * frame, after game render so the overlay sits on top.
     */
    public function tickInput(InputInterface $input): void
    {
        if ($input->isKeyPressed(self::KEY_F3)) {
            $this->visible = !$this->visible;
        }
    }

    public function render(Renderer2DInterface $r): void
    {
        if (!$this->visible) {
            return;
        }

        $stats = $this->computeFrameStats();
        $sections = $this->topSections(8);

        $lineCount = 5 + ($sections === [] ? 0 : 1 + count($sections));
        $height = self::PADDING * 2 + self::LINE_HEIGHT * ($lineCount + 1);

        $bg = new Color(0.0, 0.0, 0.0, 0.72);
        $border = new Color(0.3, 0.85, 0.45, 0.9);
        $textPrimary = new Color(0.95, 0.95, 0.95, 1.0);
        $textDim = new Color(0.7, 0.7, 0.7, 1.0);
        $textWarn = new Color(1.0, 0.7, 0.2, 1.0);

        $x = 8.0;
        $y = 8.0;

        $r->drawRect($x, $y, self::PANEL_WIDTH, $height, $bg);
        $r->drawRectOutline($x, $y, self::PANEL_WIDTH, $height, $border, 1.0);

        $r->setTextAlign(TextAlign::LEFT | TextAlign::TOP);

        $cx = $x + self::PADDING;
        $cy = $y + self::PADDING;

        $r->drawText('PerfOverlay (F3)', $cx, $cy, self::HEADER_FONT_SIZE, $textPrimary);
        $cy += self::LINE_HEIGHT + 2.0;

        $r->drawText(\sprintf('FPS:    %6.1f', $stats['fps']), $cx, $cy, self::FONT_SIZE, $textPrimary);
        $cy += self::LINE_HEIGHT;

        $frameColor = $stats['frameMs'] > 16.7 ? $textWarn : $textPrimary;
        $r->drawText(\sprintf('Frame:  %6.2f ms', $stats['frameMs']), $cx, $cy, self::FONT_SIZE, $frameColor);
        $cy += self::LINE_HEIGHT;

        $r->drawText(\sprintf('p95:    %6.2f ms', $stats['p95Ms']), $cx, $cy, self::FONT_SIZE, $textDim);
        $cy += self::LINE_HEIGHT;

        $gc = $this->engine->lastGcDelta;
        $gcColor = $gc['runs'] > 0 ? $textWarn : $textDim;
        $r->drawText(
            \sprintf('GC:     %d runs / %d collected', $gc['runs'], $gc['collected']),
            $cx,
            $cy,
            self::FONT_SIZE,
            $gcColor,
        );
        $cy += self::LINE_HEIGHT + 4.0;

        if ($sections !== []) {
            $r->drawText('Top sections (avg ms):', $cx, $cy, self::FONT_SIZE, $textDim);
            $cy += self::LINE_HEIGHT;
            foreach ($sections as $name => $info) {
                $r->drawText(
                    \sprintf('  %-26s %6.3f', $this->truncate($name, 26), $info['avgMs']),
                    $cx,
                    $cy,
                    self::FONT_SIZE,
                    $textPrimary,
                );
                $cy += self::LINE_HEIGHT;
            }
        } elseif (!PerfProfiler::isActive()) {
            $r->drawText(
                'Profiler inactive - run with SPX_ENABLED=1',
                $cx,
                $cy,
                self::FONT_SIZE,
                $textDim,
            );
        }
    }

    /**
     * @return array{fps:float, frameMs:float, p95Ms:float}
     */
    private function computeFrameStats(): array
    {
        $times = $this->engine->frameTimesMs;
        if ($times === []) {
            return ['fps' => 0.0, 'frameMs' => 0.0, 'p95Ms' => 0.0];
        }

        $count = count($times);
        $latest = $times[$count - 1];
        $sum = \array_sum($times);
        $avg = $sum / $count;
        $fps = $avg > 0.0 ? 1000.0 / $avg : 0.0;

        $sorted = $times;
        \sort($sorted);
        $p95Index = (int) \floor(0.95 * ($count - 1));
        $p95 = $sorted[$p95Index];

        return ['fps' => $fps, 'frameMs' => $latest, 'p95Ms' => $p95];
    }

    /**
     * @return array<string, array{avgMs:float, calls:int}>
     */
    private function topSections(int $limit): array
    {
        $snapshot = PerfProfiler::snapshot();
        if ($snapshot === []) {
            return [];
        }

        \uasort(
            $snapshot,
            static fn(array $a, array $b): int => ($b['totalNs'] <=> $a['totalNs']),
        );

        $top = \array_slice($snapshot, 0, $limit, true);

        $out = [];
        foreach ($top as $name => $info) {
            $out[$name] = [
                'avgMs' => $info['avgNs'] / 1_000_000.0,
                'calls' => $info['calls'],
            ];
        }
        return $out;
    }

    private function truncate(string $s, int $max): string
    {
        if (\strlen($s) <= $max) {
            return $s;
        }
        return \substr($s, 0, $max - 1) . '…';
    }
}
