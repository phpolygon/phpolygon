<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Build;

use PHPUnit\Framework\TestCase;
use PHPolygon\Build\BuildConfig;

class BuildConfigThreadingTest extends TestCase
{
    public function testThreadingDisabledByDefault(): void
    {
        $config = BuildConfig::load(__DIR__ . '/../../');
        $this->assertFalse($config->enableThreading);
    }

    public function testGetResolvedExtensionsWithoutThreading(): void
    {
        $config = BuildConfig::load(__DIR__ . '/../../');
        $config->enableThreading = false;

        $extensions = $config->getResolvedExtensions();
        $this->assertNotContains('parallel', $extensions);
    }

    public function testGetResolvedExtensionsWithThreading(): void
    {
        $config = BuildConfig::load(__DIR__ . '/../../');
        $config->enableThreading = true;

        $extensions = $config->getResolvedExtensions();
        $this->assertContains('parallel', $extensions);
    }

    public function testParallelNotDuplicatedIfAlreadyListed(): void
    {
        $config = BuildConfig::load(__DIR__ . '/../../');
        $config->enableThreading = true;
        $config->phpExtensions = ['glfw', 'parallel', 'mbstring'];

        $extensions = $config->getResolvedExtensions();
        $count = array_count_values($extensions)['parallel'] ?? 0;
        $this->assertSame(1, $count);
    }

    public function testGetPhpVariant(): void
    {
        $config = BuildConfig::load(__DIR__ . '/../../');

        $config->enableThreading = false;
        $this->assertSame('base', $config->getPhpVariant());

        $config->enableThreading = true;
        $this->assertSame('zts', $config->getPhpVariant());
    }

    public function testToArrayIncludesThreadingFields(): void
    {
        $config = BuildConfig::load(__DIR__ . '/../../');
        $config->enableThreading = true;

        $array = $config->toArray();
        $this->assertTrue($array['php.threading']);
        $this->assertSame('zts', $array['php.variant']);
        $this->assertContains('parallel', $array['php.extensions']);
    }
}
