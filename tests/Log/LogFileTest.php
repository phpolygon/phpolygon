<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Log;

use PHPUnit\Framework\TestCase;
use PHPolygon\Log\LogFile;

final class LogFileTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/phpolygon_logfile_' . uniqid();
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

    public function testAppendCreatesTheDirectoryAndTheFile(): void
    {
        $path = $this->dir . '/sub/game.log';
        $file = new LogFile($path);

        $this->assertTrue($file->append("hello\n"));
        $this->assertSame("hello\n", file_get_contents($path));
    }

    public function testAppendKeepsTheHandleOpenAcrossCalls(): void
    {
        $path = $this->dir . '/game.log';
        $file = new LogFile($path);

        $file->append("one\n");
        $file->append("two\n");
        $file->close();

        $this->assertSame("one\ntwo\n", file_get_contents($path));
    }

    public function testRotatesAtTheByteLimitAndKeepsAtMostNFiles(): void
    {
        $path = $this->dir . '/game.log';
        $file = new LogFile($path, maxBytes: 20, keep: 3);

        // Each line is 10 bytes; two fit, the third starts a new file.
        for ($i = 0; $i < 7; $i++) {
            $file->append('line' . $i . "\n"); // "lineN\n" = 6 bytes
        }
        $file->close();

        $this->assertFileExists($path);
        $this->assertTrue(is_file($this->dir . '/game.1.log') || is_file($this->dir . '/game.2.log'), 'at least one rotated file exists');
        $this->assertFileDoesNotExist($this->dir . '/game.3.log', 'keep=3 means at most 2 rotated files plus the live one');
    }

    public function testAppendOnceWritesWithoutKeepingTheFileOpen(): void
    {
        $path = $this->dir . '/game.log';

        $this->assertTrue(LogFile::appendOnce($path, "a\n"));
        $this->assertTrue(LogFile::appendOnce($path, "b\n"));
        $this->assertSame("a\nb\n", file_get_contents($path));
    }

    public function testAppendOnceRotatesTooAndCreatesMissingDirectories(): void
    {
        $path = $this->dir . '/nested/game.log';

        LogFile::appendOnce($path, str_repeat('x', 15) . "\n", maxBytes: 20, keep: 2);
        LogFile::appendOnce($path, str_repeat('y', 15) . "\n", maxBytes: 20, keep: 2);

        $this->assertFileExists($path);
        $this->assertFileExists($this->dir . '/nested/game.1.log');
    }

    public function testRotatedPathInsertsTheIndexBeforeTheExtension(): void
    {
        $this->assertSame('/a/b/game.1.log', LogFile::rotatedPath('/a/b/game.log', 1));
        $this->assertSame('/a/b/game.2.log', LogFile::rotatedPath('/a/b/game.log', 2));
        // No extension: the index is simply appended.
        $this->assertSame('/a/b/game.1', LogFile::rotatedPath('/a/b/game', 1));
    }
}
