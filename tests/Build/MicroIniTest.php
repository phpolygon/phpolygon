<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Build;

use PHPolygon\Build\MicroIni;
use PHPUnit\Framework\TestCase;

class MicroIniTest extends TestCase
{
    public function testEmptySettingsWriteNothing(): void
    {
        $this->assertSame('', MicroIni::block([]));
    }

    public function testBlockIsMagicLengthAndIniLines(): void
    {
        $block = MicroIni::block([
            'opcache.enable_cli' => '1',
            'opcache.jit' => 'tracing',
            'opcache.jit_buffer_size' => '128M',
        ]);

        $ini = "opcache.enable_cli=1\nopcache.jit=tracing\nopcache.jit_buffer_size=128M\n"
            . "opcache.validate_timestamps=0\nopcache.file_update_protection=0\n";
        $this->assertSame("\xfd\xf6\x69\xe6" . pack('N', strlen($ini)) . $ini, $block);
    }

    public function testLengthMatchesPayload(): void
    {
        $block = MicroIni::block(['date.timezone' => 'Europe/Berlin', 'error_log' => 'C:\\logs\\game "x".log']);

        $this->assertSame(MicroIni::MAGIC, substr($block, 0, 4));
        $length = unpack('N', substr($block, 4, 4));
        $this->assertIsArray($length);
        $this->assertSame(strlen($block) - 8, $length[1]);
    }

    public function testValuesSurviveTheIniParser(): void
    {
        $settings = [
            'opcache.jit' => 'tracing',
            'date.timezone' => 'Europe/Berlin',
            'error_log' => 'C:\\logs\\my game.log',
            'user_agent' => 'Game "Edition"; 1.0',
            'memory_limit' => '-1',
        ];

        $parsed = parse_ini_string(substr(MicroIni::block($settings), 8), false, INI_SCANNER_NORMAL);

        $this->assertSame($settings, $parsed);
    }

    public function testRejectsKeysThatWouldBreakTheSection(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MicroIni::block(["opcache.jit\nmemory_limit" => '1']);
    }

    public function testOpcacheOnTurnsOffTheTimestampCheck(): void
    {
        $parsed = parse_ini_string(substr(MicroIni::block([
            'opcache.enable_cli' => '1',
            'opcache.jit' => 'tracing',
        ]), 8), false, INI_SCANNER_RAW);

        $this->assertIsArray($parsed);
        $this->assertSame('0', $parsed['opcache.validate_timestamps']);
        $this->assertSame('0', $parsed['opcache.file_update_protection']);
        $this->assertSame('tracing', $parsed['opcache.jit']);
    }

    public function testOpcacheEnableAlsoCounts(): void
    {
        $settings = MicroIni::settings(['opcache.enable' => 'On']);

        $this->assertSame('0', $settings['opcache.validate_timestamps']);
        $this->assertSame('0', $settings['opcache.file_update_protection']);
    }

    public function testProjectCannotTurnTheTimestampCheckBackOn(): void
    {
        $settings = MicroIni::settings([
            'opcache.enable_cli' => 'true',
            'opcache.validate_timestamps' => '1',
            'opcache.file_update_protection' => '2',
        ]);

        $this->assertSame('0', $settings['opcache.validate_timestamps']);
        $this->assertSame('0', $settings['opcache.file_update_protection']);
    }

    public function testOpcacheOffAddsNothing(): void
    {
        $settings = ['opcache.enable_cli' => '0', 'memory_limit' => '-1'];

        $this->assertSame($settings, MicroIni::settings($settings));
        $this->assertSame(['date.timezone' => 'UTC'], MicroIni::settings(['date.timezone' => 'UTC']));
    }
}
