<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Build;

use PHPolygon\Build\ThirdPartyNotices;
use PHPUnit\Framework\TestCase;

/**
 * THIRD-PARTY-NOTICES.txt next to the executable: the licence texts and
 * attributions of the redistributed upscaler runtimes.
 */
final class ThirdPartyNoticesTest extends TestCase
{
    public function testNoComponentsNoFile(): void
    {
        $this->assertSame('', ThirdPartyNotices::render([]));
    }

    public function testFidelityFxCarriesTheFullMitText(): void
    {
        $text = ThirdPartyNotices::render(['fsr']);
        $this->assertStringContainsString('AMD FidelityFX SDK 1.1.4', $text);
        $this->assertStringContainsString('amd_fidelityfx_dx12.dll', $text);
        $this->assertStringContainsString('Copyright (C) 2024 Advanced Micro Devices, Inc.', $text);
        $this->assertStringContainsString('The above copyright notice and this permission notice shall be included in', $text);
        $this->assertStringContainsString('THE SOFTWARE IS PROVIDED "AS IS"', $text);
        $this->assertStringNotContainsString('NVIDIA', $text);
    }

    public function testDlssCarriesNvidiasNoticesAndRestrictions(): void
    {
        $text = ThirdPartyNotices::render(['dlss']);
        $this->assertStringContainsString('NVIDIA DLSS', $text);
        $this->assertStringContainsString('nvngx_dlss.dll', $text);
        $this->assertStringContainsString('This software contains source code provided by NVIDIA Corporation.', $text);
        $this->assertStringContainsString('NVIDIA and the NVIDIA logo are trademarks', $text);
        // §2c: passed on under terms at least as protective as NVIDIA's
        $this->assertStringContainsString('reverse engineer', $text);
        $this->assertStringContainsString('only as part of this application', $text);
        $this->assertStringNotContainsString('Advanced Micro Devices', $text);
    }

    public function testOrderFollowsTheComponentsAndUnknownOnesAreIgnored(): void
    {
        $text = ThirdPartyNotices::render(['dlss', 'xess', 'fsr']);
        $this->assertLessThan(strpos($text, 'AMD FidelityFX'), strpos($text, 'NVIDIA DLSS'));
        $this->assertStringStartsWith('Third-party software notices', $text);
    }

    public function testWindowsLineEndingsSoNotepadShowsIt(): void
    {
        $text = ThirdPartyNotices::render(['fsr'], "\r\n");
        $this->assertStringContainsString("\r\n", $text);
        $this->assertDoesNotMatchRegularExpression("/(?<!\r)\n/", $text);
    }
}
