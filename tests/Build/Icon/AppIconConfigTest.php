<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Build\Icon;

use PHPolygon\Build\BuildConfig;
use PHPolygon\Build\Icon\AppIconConfig;
use PHPUnit\Framework\TestCase;

class AppIconConfigTest extends TestCase
{
    public function testStringIsTheSource(): void
    {
        $config = AppIconConfig::fromBuildJson('resources/icon.png', []);
        $this->assertSame('resources/icon.png', $config->source);
        $this->assertNull($config->small);
        $this->assertTrue($config->covers('windows'));
        $this->assertTrue($config->covers('linux'));
    }

    public function testObjectWithSmallAndPlatformOverrides(): void
    {
        $config = AppIconConfig::fromBuildJson(
            ['source' => 'art/icon.png', 'small' => 'art\\icon-16.png'],
            ['windows' => ['icon' => 'art/app.ico'], 'macos' => ['icon' => 'art/app.icns', 'minimumVersion' => '12.0'], 'linux' => ['icon' => 'art/app.png']],
        );
        $this->assertSame('art/icon.png', $config->source);
        $this->assertSame('art/icon-16.png', $config->small, 'backslashes normalised');
        $this->assertSame('art/app.ico', $config->windows);
        $this->assertSame('art/app.icns', $config->macos);
        $this->assertSame('art/app.png', $config->linux);
    }

    public function testLegacyMacIconAloneStillWorks(): void
    {
        $config = AppIconConfig::fromBuildJson(null, ['macos' => ['icon' => 'resources/icon.icns']]);
        $this->assertNull($config->source);
        $this->assertTrue($config->covers('macos'));
        $this->assertFalse($config->covers('windows'));
        $this->assertFalse($config->isEmpty());
        $this->assertTrue(AppIconConfig::fromBuildJson(null, [])->isEmpty());
    }

    /**
     * @return array<string, array{mixed, array<string, array<string, mixed>>, string}>
     */
    public static function invalidProvider(): array
    {
        return [
            'not png' => ['icon.jpg', [], '.png'],
            'parent dir' => ['../icon.png', [], "'..'"],
            'absolute' => ['C:/art/icon.png', [], "'..'"],
            'object without source' => [['small' => 'a.png'], [], 'source'],
            'unknown key' => [['source' => 'a.png', 'big' => 'b.png'], [], 'unknown'],
            'number' => [42, [], 'PNG path'],
            'windows not ico' => [null, ['windows' => ['icon' => 'a.png']], '.ico'],
            'linux not png' => [null, ['linux' => ['icon' => 'a.svg']], '.png'],
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $platforms
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidProvider')]
    public function testRejectsMalformedEntries(mixed $icon, array $platforms, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        AppIconConfig::fromBuildJson($icon, $platforms);
    }

    public function testBuildConfigReadsIt(): void
    {
        $dir = sys_get_temp_dir() . '/phpolygon-icon-config-' . getmypid();
        @mkdir($dir, 0755, true);
        try {
            file_put_contents($dir . '/build.json', json_encode(['name' => 'X', 'icon' => ['source' => 'icon.png', 'small' => 'small.png']]));
            $config = BuildConfig::load($dir);
            $this->assertSame('icon.png', $config->icon->source);
            $this->assertSame(['source' => 'icon.png', 'small' => 'small.png', 'windows' => null, 'macos' => null, 'linux' => null], $config->toArray()['icon']);
        } finally {
            @unlink($dir . '/build.json');
            @rmdir($dir);
        }
    }
}
