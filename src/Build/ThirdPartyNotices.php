<?php

declare(strict_types=1);

namespace PHPolygon\Build;

/**
 * THIRD-PARTY-NOTICES.txt, written next to the executable of a build that
 * ships redistributed native libraries (see {@see NativeUpscalerResolver}):
 *
 *   fsr   AMD FidelityFX SDK runtimes - MIT, the notice must accompany every copy.
 *   dlss  NVIDIA DLSS runtime + php-vio's DLSS plugin - NVIDIA RTX SDKs licence:
 *         object code inside an application only (§1c), passed on under terms
 *         at least as protective as NVIDIA's (§2c), proprietary notices kept
 *         (§4a). The "source code provided by NVIDIA" sentence of §2b is due for
 *         distributed source; it is included anyway because the plugin is
 *         built from NVIDIA's SDK headers.
 *
 * What the file cannot do for a game - showing the NVIDIA / DLSS attribution in
 * its credits, notifying NVIDIA before release - is listed in the engine docs.
 */
final class ThirdPartyNotices
{
    public const string FILE = 'THIRD-PARTY-NOTICES.txt';

    private const string HEADER = <<<'TXT'
Third-party software notices

This application includes the third-party components listed below. Each is
the property of its respective owner and is provided under the terms shown.
TXT;

    private const string FIDELITYFX = <<<'TXT'
AMD FidelityFX SDK 1.1.4 (FidelityFX Super Resolution 3.1)
Files: amd_fidelityfx_dx12.dll, amd_fidelityfx_vk.dll
https://github.com/GPUOpen-LibrariesAndSDKs/FidelityFX-SDK

Copyright (C) 2024 Advanced Micro Devices, Inc.

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files(the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and /or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions :

The above copyright notice and this permission notice shall be included in
all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
THE SOFTWARE.

AMD, the AMD Arrow logo, FidelityFX and combinations thereof are trademarks
of Advanced Micro Devices, Inc.
TXT;

    private const string DLSS = <<<'TXT'
NVIDIA DLSS (Deep Learning Super Sampling)
Files: nvngx_dlss.dll / libnvidia-ngx-dlss.so (NVIDIA DLSS runtime),
       vio_dlss.dll / libvio_dlss.so (DLSS plugin of the php-vio extension,
       built with the NVIDIA DLSS SDK)

This software contains source code provided by NVIDIA Corporation.

Copyright (C) NVIDIA Corporation. All rights reserved.
NVIDIA and the NVIDIA logo are trademarks and/or registered trademarks of
NVIDIA Corporation in the U.S. and other countries. DLSS and RTX are
trademarks of NVIDIA Corporation. Other company and product names may be
trademarks of the respective companies with which they are associated.

The NVIDIA components are provided under the NVIDIA RTX SDKs License and are
distributed only as part of this application, in object code form. NVIDIA and
its suppliers retain all rights, title and interest in them. You may not
reverse engineer, decompile or disassemble them, remove copyright or other
proprietary notices from them, distribute them separately from this
application, or use them in any system or application where their use or
failure could reasonably be expected to result in personal injury, death or
catastrophic loss. They are provided "AS IS", without warranty of any kind;
to the maximum extent permitted by applicable law NVIDIA and its affiliates
disclaim all warranties and shall not be liable for any damages arising from
their use.
TXT;

    private function __construct() {}

    /**
     * The notices for $components (`fsr`, `dlss`; others are skipped) in that
     * order, '' when none applies.
     *
     * @param list<string> $components
     */
    public static function render(array $components, string $eol = "\n"): string
    {
        $sections = [];
        foreach (array_unique($components) as $component) {
            $text = match ($component) {
                'fsr' => self::FIDELITYFX,
                'dlss' => self::DLSS,
                default => null,
            };
            if ($text !== null) {
                $sections[] = $text;
            }
        }
        if ($sections === []) {
            return '';
        }

        $rule = str_repeat('=', 78);
        $text = self::HEADER . "\n\n" . $rule . "\n\n" . implode("\n\n" . $rule . "\n\n", $sections) . "\n";
        // A CRLF checkout of this file puts \r\n into the heredocs.
        return str_replace("\n", $eol, str_replace("\r\n", "\n", $text));
    }
}
