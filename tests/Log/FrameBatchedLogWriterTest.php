<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Log;

use PHPUnit\Framework\TestCase;
use PHPolygon\Log\FrameBatchedLogWriter;

final class FrameBatchedLogWriterTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/phpolygon_fbw_' . uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function testWritesLandOnDiskImmediately(): void
    {
        $path = $this->dir . '/game.log';
        $writer = new FrameBatchedLogWriter($path);

        $this->assertTrue($writer->write("line one\n"));
        $this->assertTrue($writer->write("line two\n"));
        $writer->close();

        $this->assertSame("line one\nline two\n", file_get_contents($path));
    }

    public function testFlushIsANoOpSinceNothingIsEverBuffered(): void
    {
        $writer = new FrameBatchedLogWriter($this->dir . '/game.log');
        $writer->write("a\n");
        $writer->flush(); // must not throw, must not lose/duplicate anything
        $writer->close();

        $this->assertSame("a\n", file_get_contents($this->dir . '/game.log'));
    }

    public function testRotatesLikeLogFileSinceItJustDelegates(): void
    {
        $path = $this->dir . '/game.log';
        $writer = new FrameBatchedLogWriter($path, maxBytes: 10, keep: 2);

        $writer->write("0123456789\n"); // 11 bytes, already over the limit alone
        $writer->write("0123456789\n");
        $writer->close();

        $this->assertFileExists($path);
        $this->assertFileExists($this->dir . '/game.1.log');
    }
}
