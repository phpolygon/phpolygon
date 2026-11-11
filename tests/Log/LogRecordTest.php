<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Log;

use PHPUnit\Framework\TestCase;
use PHPolygon\Log\LogCategory;
use PHPolygon\Log\LogLevel;
use PHPolygon\Log\LogRecord;

final class LogRecordTest extends TestCase
{
    public function testFormatMatchesThePlannedLineShape(): void
    {
        // 2026-11-09 14:03:12.481, local time
        $time = mktime(14, 3, 12, 11, 9, 2026) + 0.481;
        $record = new LogRecord($time, 18233, LogCategory::Ui, LogLevel::Info, 'click', ['opt_benchmark_start', 'Benchmark starten']);

        $this->assertSame('2026-11-09 14:03:12.481 f=18233 [ui] click opt_benchmark_start "Benchmark starten"', $record->format());
    }

    public function testLevelIsNamedExceptInfoAndErrorInErrorCategory(): void
    {
        $plain = new LogRecord(0.0, 1, LogCategory::System, LogLevel::Info, 'x', []);
        $this->assertStringNotContainsString('INFO', $plain->format());

        $warn = new LogRecord(0.0, 1, LogCategory::System, LogLevel::Warn, 'x', []);
        $this->assertStringContainsString('WARN', $warn->format());

        $traced = new LogRecord(0.0, 1, LogCategory::System, LogLevel::Trace, 'x', []);
        $this->assertStringContainsString('TRACE', $traced->format());

        // [error] + Error level: the tag already says it, no redundant "ERROR".
        $error = new LogRecord(0.0, 1, LogCategory::Error, LogLevel::Error, 'boom', []);
        $this->assertSame('[error]', self::tag($error->format()));
        $this->assertStringNotContainsString('ERROR', $error->format());

        // Error level in a DIFFERENT category still gets the word.
        $errorElsewhere = new LogRecord(0.0, 1, LogCategory::System, LogLevel::Error, 'boom', []);
        $this->assertStringContainsString('ERROR', $errorElsewhere->format());
    }

    public function testMultilineTextIsIndentedNotBrokenIntoSeparateEntries(): void
    {
        $record = new LogRecord(0.0, 1, LogCategory::Error, LogLevel::Error, "boom\nat line 2", []);

        $lines = explode("\n", $record->format());
        $this->assertCount(2, $lines);
        $this->assertStringStartsWith('    at line 2', $lines[1]);
    }

    public function testValueBareTokenVsQuoted(): void
    {
        $this->assertSame('opt_benchmark_start', LogRecord::value('opt_benchmark_start'));
        $this->assertSame('"Benchmark starten"', LogRecord::value('Benchmark starten'));
        $this->assertSame('"with \\"quotes\\""', LogRecord::value('with "quotes"'));
    }

    public function testValueScalarsAndSpecials(): void
    {
        $this->assertSame('42', LogRecord::value(42));
        $this->assertSame('true', LogRecord::value(true));
        $this->assertSame('false', LogRecord::value(false));
        $this->assertSame('null', LogRecord::value(null));
        $this->assertSame('1.5', LogRecord::value(1.5));
        $this->assertSame('nan', LogRecord::value(NAN));
        $this->assertSame('inf', LogRecord::value(INF));
        $this->assertSame('-inf', LogRecord::value(-INF));
    }

    public function testValueEnum(): void
    {
        $this->assertSame('info', LogRecord::value(LogLevel::Info));
        $this->assertSame('System', LogRecord::value(TestUnitEnum::System));
    }

    public function testContextMixesPositionalAndNamed(): void
    {
        // "Turn on VSync" has a space, so it is quoted; the bare id and the
        // single-word label are not - 'bool' context values show as true/false.
        $context = ['opt_vsync', 'Turn on VSync', 'value' => true];
        $record = new LogRecord(0.0, 1, LogCategory::Ui, LogLevel::Info, 'checkbox', $context);

        $this->assertSame(' [ui] checkbox opt_vsync "Turn on VSync" value=true', LogRecord::body(LogCategory::Ui, LogLevel::Info, 'checkbox', $context));
        $this->assertStringEndsWith('checkbox opt_vsync "Turn on VSync" value=true', $record->format());
    }

    private static function tag(string $line): string
    {
        preg_match('/\[\w+\]/', $line, $m);

        return $m[0] ?? '';
    }
}

enum TestUnitEnum
{
    case System;
}
