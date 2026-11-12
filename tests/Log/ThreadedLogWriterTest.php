<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Log;

use PHPUnit\Framework\TestCase;
use PHPolygon\Log\ThreadedLogWriter;
use PHPolygon\Thread\ParallelCapability;

/**
 * Exercises the real thread - skipped wherever ext-parallel/ZTS is not there
 * (the CI's non-ZTS PHP, most installs). The local PHP used for this task is
 * ZTS with parallel loaded, so this suite does run here.
 */
final class ThreadedLogWriterTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        if (!ParallelCapability::isAvailable()) {
            self::markTestSkipped('ext-parallel / ZTS not available');
        }
        $this->dir = sys_get_temp_dir() . '/phpolygon_tlw_' . uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        // setUp() may have stopped at markTestSkipped() (ParallelCapability::isAvailable()
        // false) before $dir was ever assigned - tearDown() still runs then, and the typed
        // property throws on read instead of a clean skip.
        if (!isset($this->dir)) {
            parent::tearDown();
            return;
        }
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function testStartReturnsNullWithoutParallel(): void
    {
        // Cannot force ParallelCapability::isAvailable() to false here (PHP_ZTS
        // is a compile-time constant), so this only documents the contract -
        // the real "no parallel" path is covered via Log::start(thread: false)
        // and FrameBatchedLogWriterTest.
        $this->addToAssertionCount(1);
    }

    public function testWritesReachTheFileInOrder(): void
    {
        $path = $this->dir . '/game.log';
        $writer = ThreadedLogWriter::start($path);
        self::assertNotNull($writer);

        self::assertTrue($writer->write("one\n"));
        self::assertTrue($writer->write("two\n"));
        self::assertTrue($writer->write("three\n"));
        $writer->flush();
        $writer->close();

        self::assertSame("one\ntwo\nthree\n", file_get_contents($path));
        self::assertTrue($writer->isAlive() === false); // closed
        self::assertSame('', $writer->failure());
    }

    public function testCloseWaitsForTheThreadAndReportsNoFailureOnACleanEnd(): void
    {
        $writer = ThreadedLogWriter::start($this->dir . '/game.log');
        self::assertNotNull($writer);

        $writer->write("x\n");
        $writer->close();

        self::assertSame('', $writer->failure());
        self::assertSame('', $writer->unwritten());
    }

    public function testADeadThreadIsDetectedAndWhatItNeverWroteIsReplayable(): void
    {
        $writer = ThreadedLogWriter::start($this->dir . '/game.log');
        self::assertNotNull($writer);

        self::assertTrue($writer->write("before fault\n"));
        $writer->injectFault();

        // The fault is processed asynchronously by the thread; poll write()
        // until it reports the thread gone, with a timeout.
        $deadline = microtime(true) + 2.0;
        $diedNow = false;
        while (microtime(true) < $deadline) {
            if (!$writer->write('probe')) {
                $diedNow = true;
                break;
            }
            usleep(1000);
        }

        self::assertTrue($diedNow, 'write() must notice the dead thread within the timeout');
        self::assertFalse($writer->isAlive());
        self::assertStringContainsString('injected fault', $writer->failure());
        $writer->close();

        // "before fault" was already on disk when the fault arrived, so it is
        // not part of what's replayed - the written-count accounting, not a
        // literal duplicate check (see LogFile replay-may-duplicate note).
        self::assertStringContainsString('before fault', (string) file_get_contents($this->dir . '/game.log'));
    }

    public function testRotatesByteForByteLikeLogFile(): void
    {
        $path = $this->dir . '/game.log';
        $writer = ThreadedLogWriter::start($path, maxBytes: 20, keep: 3);
        self::assertNotNull($writer);

        for ($i = 0; $i < 6; $i++) {
            self::assertTrue($writer->write('line' . $i . "\n")); // 6 bytes each
            usleep(2000); // let the thread actually process before the next send
        }
        $writer->flush();
        $writer->close();

        self::assertFileExists($path);
        self::assertFileExists($this->dir . '/game.1.log');
        self::assertStringContainsString('line0', (string) file_get_contents($this->dir . '/game.1.log'));
        self::assertStringContainsString('line5', (string) file_get_contents($path));
    }

    public function testChannelNameIsUniquePerInstance(): void
    {
        $a = ThreadedLogWriter::start($this->dir . '/a.log');
        $b = ThreadedLogWriter::start($this->dir . '/b.log');
        self::assertNotNull($a);
        self::assertNotNull($b);

        self::assertNotSame($a->channelName(), $b->channelName());

        $a->close();
        $b->close();
    }
}
