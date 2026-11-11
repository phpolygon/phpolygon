<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Log;

use PHPUnit\Framework\TestCase;
use PHPolygon\Log\Log;
use PHPolygon\Log\LogLevel;

final class LogTest extends TestCase
{
    protected function tearDown(): void
    {
        Log::reset();
        Log::setPath(null);
        parent::tearDown();
    }

    public function testOutsideTheGameLoopEveryLineGoesOutAtOnce(): void
    {
        $writer = new RecordingLogWriter();
        Log::startWithWriter($writer, hooks: false);

        Log::engine('one');
        Log::engine('two');

        // Not batching (frame() never called): each line is its own write().
        $this->assertCount(2, $writer->writes);
    }

    public function testFrameBatchesLinesIntoOneWritePerFrame(): void
    {
        $writer = new RecordingLogWriter();
        Log::startWithWriter($writer, hooks: false);

        Log::frame(); // enters batching mode
        Log::engine('a');
        Log::engine('b');
        Log::engine('c');
        Log::flush();

        $this->assertCount(1, $writer->writes);
        $this->assertStringContainsString('[engine] a', $writer->writes[0]);
        $this->assertStringContainsString('[engine] b', $writer->writes[0]);
        $this->assertStringContainsString('[engine] c', $writer->writes[0]);
    }

    public function testFrameNumberIncreasesAndIsWrittenIntoEveryLine(): void
    {
        $writer = new RecordingLogWriter();
        Log::startWithWriter($writer, hooks: false);

        Log::frame();
        Log::frame();
        Log::frame();
        Log::engine('x');
        Log::flush();

        $this->assertStringContainsString(' f=3 ', $writer->writes[0]);
    }

    public function testPendingLinesOlderThanTheCapGoOutEarly(): void
    {
        $writer = new RecordingLogWriter();
        Log::startWithWriter($writer, hooks: false);

        Log::frame();
        // Write directly through write() with manual timestamps is not exposed,
        // so this exercises the real clock instead: a line written now, then
        // one written after the cap has passed, must be two separate writes.
        Log::engine('first');
        usleep((int) (Log::MAX_PENDING_SECONDS * 1_000_000) + 20_000);
        Log::engine('second');

        $this->assertGreaterThanOrEqual(1, count($writer->writes), 'the stale line was handed over without an explicit flush()');
        Log::flush();
        $this->assertStringContainsString('second', $writer->all());
        $this->assertStringContainsString('first', $writer->all());
    }

    public function testRecentReturnsTheRingOldestFirstWithoutLineBreaks(): void
    {
        $writer = new RecordingLogWriter();
        Log::startWithWriter($writer, hooks: false);

        Log::engine('a');
        Log::engine('b');
        Log::engine('c');

        $recent = Log::recent();
        $this->assertCount(3, $recent);
        $this->assertStringEndsWith('a', $recent[0]);
        $this->assertStringEndsWith('b', $recent[1]);
        $this->assertStringEndsWith('c', $recent[2]);
        foreach ($recent as $line) {
            $this->assertStringNotContainsString("\n", $line);
        }
    }

    public function testRecentWrapsAroundTheRingLimit(): void
    {
        $writer = new RecordingLogWriter();
        Log::startWithWriter($writer, hooks: false);

        for ($i = 0; $i < Log::RING + 5; $i++) {
            Log::engine((string) $i);
        }

        $recent = Log::recent();
        $this->assertCount(Log::RING, $recent);
        $this->assertStringEndsWith('5', $recent[0]); // the oldest 5 (0-4) fell off
        $this->assertStringEndsWith((string) (Log::RING + 4), $recent[array_key_last($recent)]);
    }

    public function testDeadWriterFallsBackAndCarriesTheLostLineAlong(): void
    {
        $writer = new RecordingLogWriter();
        Log::startWithWriter($writer, hooks: false);

        $writer->failNextWrite = true;
        Log::engine('lost then replayed');

        // The facade swapped to a FrameBatchedLogWriter pointed at Log::path();
        // read the real file it just wrote to.
        $this->assertFileExists(Log::path());
        $this->assertStringContainsString('lost then replayed', (string) file_get_contents(Log::path()));

        @unlink(Log::path());
    }

    public function testTraceLevelLinesAreDroppedWhenTraceIsOff(): void
    {
        $writer = new RecordingLogWriter();
        Log::startWithWriter($writer, hooks: false, trace: false);

        Log::system('frame times', [], LogLevel::Trace);

        $this->assertSame([], $writer->writes);
    }

    public function testTraceLevelLinesAreWrittenWhenTraceIsOn(): void
    {
        $writer = new RecordingLogWriter();
        Log::startWithWriter($writer, hooks: false, trace: true);

        Log::system('frame times', [], LogLevel::Trace);

        $this->assertCount(1, $writer->writes);
    }

    public function testShutdownFlushesPendingLinesAndClosesTheWriter(): void
    {
        $writer = new RecordingLogWriter();
        Log::startWithWriter($writer, hooks: false);

        Log::frame();
        Log::engine('still pending');
        Log::shutdown();

        // The writer was already closed by the time the pending text is known,
        // so shutdown() writes it straight to Log::path() instead - the
        // writer only has to be closed, not hold the last line.
        $this->assertTrue($writer->closed);
        $this->assertStringContainsString('still pending', (string) file_get_contents(Log::path()));
        $this->assertFalse(Log::isStarted());

        @unlink(Log::path());
    }

    public function testSecondCallToStartWithWriterIsANoOp(): void
    {
        $first = new RecordingLogWriter();
        $second = new RecordingLogWriter();
        Log::startWithWriter($first, hooks: false);
        Log::startWithWriter($second, hooks: false);

        Log::engine('x');

        $this->assertCount(1, $first->writes);
        $this->assertSame([], $second->writes);
    }

    public function testHooksFlagReflectsWhatStartWasToldAndResetClearsIt(): void
    {
        $writer = new RecordingLogWriter();
        Log::startWithWriter($writer, hooks: true);
        $this->assertTrue(Log::$hooks);

        Log::reset();
        $this->assertFalse(Log::$hooks);
    }

    public function testErrorHandlerCountsAndThrottlesRepeatsOfTheSamePlace(): void
    {
        // Through handlePhpError() directly (it is @internal but public for
        // exactly this): PHPUnit lowers error_reporting() for the duration of
        // a test (it tracks warnings its own way, regardless of that level),
        // so trigger_error() here would tell nothing about the throttling -
        // and handlePhpError()'s own "respect error_reporting()" gate needs
        // E_USER_WARNING back in the mask to be exercised at all.
        $previousReporting = error_reporting(E_ALL);
        $writer = new RecordingLogWriter();
        Log::startWithWriter($writer, hooks: false);
        Log::installErrorHandler();

        try {
            for ($i = 0; $i < 12; $i++) {
                Log::handlePhpError(E_USER_WARNING, 'repeat me', 'x.php', 1);
            }
        } finally {
            error_reporting($previousReporting);
        }

        // 3 immediately, the 10th occurrence, nothing else: 4 lines.
        $count = 0;
        foreach ($writer->writes as $w) {
            $count += substr_count($w, 'repeat me');
        }
        $this->assertSame(4, $count);
        $this->assertStringContainsString('count=10', $writer->all());
    }
}
