<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPolygon\Rendering\VioRenderer2D;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Integration tests for the non-blocking font load path
 * ({@see VioRenderer2D::preloadFontAsync()} +
 * {@see VioRenderer2D::pollAsyncFontLoads()}).
 *
 * The large CJK fallback fonts (NotoSansSC/KR) used to be loaded synchronously
 * the first time a CJK glyph hit the fallback chain, packing a 4096x4096 atlas
 * over ~32k glyphs on the render thread and freezing the splash for
 * 20-25 s. preloadFontAsync() defers the pack to a vio worker thread and
 * pollAsyncFontLoads() (called from beginFrame) promotes the finished atlas
 * into the font cache without ever blocking.
 *
 * Gated on the vio extension + the async font API; skipped where unavailable
 * (the API is no-op-safe in production, see testAsyncRegistrationIsGraceful).
 */
final class VioRenderer2DAsyncFontTest extends TestCase
{
    private const FONT_CANDIDATES = [
        '/Library/Fonts/Arial Unicode.ttf',
        '/System/Library/Fonts/Helvetica.ttc',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/dejavu/DejaVuSans.ttf',
        'C:\\Windows\\Fonts\\arial.ttf',
    ];

    protected function setUp(): void
    {
        if (!extension_loaded('vio')) {
            $this->markTestSkipped('vio extension not loaded.');
        }
        if (!function_exists('vio_font_load_async') || !function_exists('vio_font_load_poll')) {
            $this->markTestSkipped('vio build lacks the async font API.');
        }
    }

    public function testPreloadFontAsyncRegistersPathAndMarksAsync(): void
    {
        $renderer = new VioRenderer2D($this->createVioContext());
        $renderer->preloadFontAsync('cjk', $this->fontPath());

        // Path registered so the font is resolvable by name.
        $fontPaths = $this->readProperty($renderer, 'fontPaths');
        self::assertArrayHasKey('cjk', $fontPaths);

        // Marked async so resolution kicks off a worker load instead of blocking.
        $asyncNames = $this->readProperty($renderer, 'asyncFontNames');
        self::assertArrayHasKey('cjk', $asyncNames);
    }

    public function testFontIsNotReadyOnFirstFrameButResolvesLater(): void
    {
        $renderer = new VioRenderer2D($this->createVioContext());
        $renderer->setFont('cjk');
        $renderer->preloadFontAsync('cjk', $this->fontPath());

        $resolve = new ReflectionMethod(VioRenderer2D::class, 'resolveFont');

        // First resolution: the worker has just been kicked off, so the font is
        // not ready and the renderer must report null (skip it in the chain)
        // rather than blocking on the synchronous pack.
        $first = $resolve->invoke($renderer, 24.0);
        self::assertNull($first, 'font must not block-load on first use');

        // An async load is now in flight, keyed by name:size@scale (scale 100 =
        // the default 1.0× render scale).
        $pending = $this->readProperty($renderer, 'pendingFontLoads');
        self::assertArrayHasKey('cjk:24@100', $pending);

        // Drive frames: each beginFrame polls completions. The worker finishes
        // the CPU pack quickly for a normal-sized system font, but give it a
        // generous wall-clock budget so the assertion is not flaky on a loaded
        // CI box (the budget is far longer than the pack ever takes in practice).
        $font = null;
        $deadline = microtime(true) + 15.0;
        while (microtime(true) < $deadline) {
            $renderer->pollAsyncFontLoads();
            $font = $resolve->invoke($renderer, 24.0);
            if ($font !== null) {
                break;
            }
            usleep(2000);
        }

        self::assertInstanceOf(\VioFont::class, $font, 'async font never became ready');

        // Once cached, the pending entry is cleared and re-resolution is a hit.
        $pendingAfter = $this->readProperty($renderer, 'pendingFontLoads');
        self::assertArrayNotHasKey('cjk:24@100', $pendingAfter);
        self::assertSame($font, $resolve->invoke($renderer, 24.0));
    }

    public function testBeginFramePollIsSafeWithNothingPending(): void
    {
        $renderer = new VioRenderer2D($this->createVioContext());
        // No async fonts registered — must be a cheap no-op, no errors.
        $renderer->pollAsyncFontLoads();
        self::assertSame([], $this->readProperty($renderer, 'pendingFontLoads'));
    }

    /** @return array<mixed> */
    private function readProperty(VioRenderer2D $r, string $name): array
    {
        $p = new ReflectionProperty(VioRenderer2D::class, $name);
        /** @var array<mixed> $v */
        $v = $p->getValue($r);
        return $v;
    }

    private function fontPath(): string
    {
        foreach (self::FONT_CANDIDATES as $c) {
            if (is_file($c)) {
                return $c;
            }
        }
        $this->markTestSkipped('no system font found for async font test.');
    }

    private function createVioContext(): \VioContext
    {
        if (!function_exists('vio_create')) {
            $this->markTestSkipped('vio_create() not available.');
        }
        try {
            $ctx = vio_create('auto', [
                'width'  => 16,
                'height' => 16,
                'title'  => 'phpolygon-test',
                'hidden' => true,
            ]);
        } catch (\Throwable $e) {
            $this->markTestSkipped('vio_create() failed: ' . $e->getMessage());
        }
        if (!$ctx instanceof \VioContext) {
            $this->markTestSkipped('vio_create() did not return a VioContext.');
        }
        return $ctx;
    }
}
