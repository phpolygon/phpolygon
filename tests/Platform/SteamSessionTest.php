<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Platform;

use PHPUnit\Framework\TestCase;
use PHPolygon\Platform\SteamSession;

class SteamSessionTest extends TestCase
{
    protected function setUp(): void
    {
        if (function_exists('steam_init')) {
            $this->markTestSkipped('php-steamworks is loaded here; these tests cover the build without it');
        }
    }

    public function testWithoutTheExtensionNothingStarts(): void
    {
        $this->assertFalse(SteamSession::available());
        $this->assertFalse(SteamSession::start());
        $this->assertFalse(SteamSession::start(), 'a second start answers the same, without retrying');
        $this->assertFalse(SteamSession::isRunning());
    }

    public function testPumpAndShutdownAreHarmlessWithoutSteam(): void
    {
        SteamSession::runCallbacks();
        SteamSession::shutdown();
        $this->assertFalse(SteamSession::isRunning());
    }
}
