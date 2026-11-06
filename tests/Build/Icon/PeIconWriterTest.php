<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Build\Icon;

use PHPolygon\Build\Icon\IcoEntry;
use PHPolygon\Build\Icon\IcoFile;
use PHPolygon\Build\Icon\PeIconWriter;
use PHPolygon\Build\Icon\PeImage;
use PHPolygon\Build\MicroIni;
use PHPolygon\Build\StaticPhpResolver;
use PHPolygon\Icon\IconResampler;
use PHPolygon\Tests\Icon\TestIcons;
use PHPUnit\Framework\TestCase;

class PeIconWriterTest extends TestCase
{
    private const VERSION_DATA = 'version-resource-bytes';
    private const NAMED_DATA = 'named-rcdata';

    public function testIconReplacesTheOldOneAndKeepsEveryOtherResource(): void
    {
        $pe = self::syntheticPe(withReloc: true);
        $entries = self::entries();
        $out = PeIconWriter::withIcon($pe, $entries);

        $icon = PeIconWriter::readIcon($out);
        $this->assertCount(count($entries), $icon);
        foreach ($entries as $i => $entry) {
            $this->assertSame($entry->width, $icon[$i]->width);
            $this->assertSame($entry->bitCount, $icon[$i]->bitCount);
            $this->assertSame($entry->data, $icon[$i]->data);
        }

        $tree = PeIconWriter::readTree($out);
        $this->assertSame(['i:' . PeIconWriter::RT_ICON, 'i:10', 'i:' . PeIconWriter::RT_GROUP_ICON, 'i:' . PeIconWriter::RT_VERSION], array_keys($tree));
        $this->assertSame(self::VERSION_DATA, $tree['i:16']['i:1']['i:1031']['data'], 'version resource kept byte for byte');
        $this->assertSame(self::NAMED_DATA, $tree['i:10'][self::nameKey('CONFIG')]['i:1031']['data'], 'named resource kept');
        $this->assertSame(['i:1'], array_keys($tree['i:14']), 'old groups are gone');
        $this->assertSame(['i:1031'], array_keys($tree['i:14']['i:1']), 'language copied from the existing group');
        $this->assertSame(range(1, count($entries)), array_map(static fn (string $k): int => (int) substr($k, 2), array_keys($tree['i:3'])));
    }

    public function testResourceSectionFollowedByAnotherGoesIntoANewLastSection(): void
    {
        $out = PeIconWriter::withIcon(self::syntheticPe(withReloc: true), self::entries());
        $image = PeImage::parse($out);

        $this->assertSame(['.text', '.rsrcold', '.reloc', '.rsrc'], array_column($image->sections, 'name'));
        $last = $image->sections[3];
        $this->assertSame(0x4000, $last['va']);
        $this->assertSame([0x4000, $last['vsize']], $image->directory(2));
        $this->assertSame(strlen($out), $image->sectionsEnd(), 'nothing after the last section: micro finds the payload at the file end');
        $this->assertSame(0, $last['rawsize'] % 0x200);
        $this->assertSizesAndChecksum($out);
    }

    public function testLastResourceSectionIsRewrittenInPlace(): void
    {
        $out = PeIconWriter::withIcon(self::syntheticPe(withReloc: false), self::entries());
        $image = PeImage::parse($out);

        $this->assertSame(['.text', '.rsrc'], array_column($image->sections, 'name'));
        $this->assertSame(0x2000, $image->sections[1]['va']);
        $this->assertSame(0x600, $image->sections[1]['raw']);
        $this->assertSame(strlen($out), $image->sectionsEnd());
        $this->assertSizesAndChecksum($out);
    }

    public function testExecutableWithoutResourcesGetsASection(): void
    {
        $out = PeIconWriter::withIcon(self::syntheticPe(withReloc: false, withResources: false), self::entries());
        $image = PeImage::parse($out);
        $this->assertSame(['.text', '.rsrc'], array_column($image->sections, 'name'));
        $this->assertCount(count(self::entries()), PeIconWriter::readIcon($out));
        $this->assertSizesAndChecksum($out);
    }

    public function testMicroSizeRecordNamesTheGrownRuntime(): void
    {
        $out = PeIconWriter::withIcon(self::syntheticPe(withReloc: true, sfxSize: true), self::entries());
        $record = PeIconWriter::readTree($out)['i:10']['i:' . PeIconWriter::MICRO_SFXSIZE_ID]['i:1031']['data'];
        $this->assertSame(16, strlen($record));
        $this->assertSame(strlen($out), unpack('J', $record)[1]);
        $this->assertSame(0, unpack('J', $record, 8)[1], 'limit untouched');
    }

    public function testSignatureIsRemovedAndPayloadIsRefused(): void
    {
        $pe = self::syntheticPe(withReloc: false);
        $image = PeImage::parse($pe);
        $signed = $image->withDirectory($pe . str_repeat("\xAA", 64), 4, strlen($pe), 64);
        $out = PeIconWriter::withIcon($signed, self::entries());
        $this->assertSame([0, 0], PeImage::parse($out)->directory(4));
        $this->assertStringNotContainsString(str_repeat("\xAA", 64), $out);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('after its last section');
        PeIconWriter::withIcon($pe . 'appended payload', self::entries());
    }

    public function testChecksumMatchesTheWindowsAlgorithm(): void
    {
        // Two words 0xffff + 0x0002 fold to 0x0002, plus the length 4.
        $this->assertSame(6, PeImage::checksum("\xff\xff\x02\x00"));
        // Odd length pads with a zero byte.
        $this->assertSame(0x0001 + 3 + 0x0002, PeImage::checksum("\x01\x00\x02"));
    }

    /**
     * The real runtime: patch the micro.sfx from the StaticPhpResolver cache (or
     * PHPOLYGON_TEST_MICRO_SFX), append an ini block and a PHAR like
     * GameBuilder does, and run it - it must still find both.
     */
    public function testPatchedMicroRuntimeStillFindsIniAndPhar(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('runs a Windows executable');
        }
        $sfx = getenv('PHPOLYGON_TEST_MICRO_SFX') ?: (new StaticPhpResolver())->getCacheDir() . '/windows-x86_64-php8.5/micro.sfx';
        if (!is_file($sfx)) {
            $this->markTestSkipped("no micro.sfx at {$sfx}");
        }
        $dir = sys_get_temp_dir() . '/phpolygon-pe-icon-' . getmypid();
        @mkdir($dir, 0755, true);
        try {
            $phar = $dir . '/t.phar';
            // A script file: Windows' escapeshellarg() drops double quotes.
            file_put_contents($dir . '/make-phar.php', '<?php $p = new Phar(' . var_export($phar, true) . ', 0, "t.phar");'
                . '$p->addFromString("main.php", "<?php echo \"PHAR OK \", ini_get(\"memory_limit\");");'
                . '$p->setStub("<?php Phar::mapPhar(\"t.phar\"); require \"phar://t.phar/main.php\"; __HALT_COMPILER(); ?>");');
            exec(escapeshellarg(PHP_BINARY) . ' -d phar.readonly=0 ' . escapeshellarg($dir . '/make-phar.php') . ' 2>&1', $phpOutput, $code);
            $this->assertSame(0, $code, 'building the test PHAR: ' . implode("\n", $phpOutput));

            $patched = PeIconWriter::withIcon((string) file_get_contents($sfx), self::entries());
            $exe = $dir . '/game.exe';
            file_put_contents($exe, $patched . MicroIni::block(['memory_limit' => '321M']) . file_get_contents($phar));

            exec(escapeshellarg($exe) . ' 2>&1', $output, $code);
            $this->assertSame(0, $code, implode("\n", $output));
            $this->assertSame('PHAR OK 321M', trim(implode("\n", $output)));
            $this->assertCount(count(self::entries()), PeIconWriter::readIcon($patched));
        } finally {
            array_map('unlink', glob($dir . '/*') ?: []);
            @rmdir($dir);
        }
    }

    private function assertSizesAndChecksum(string $out): void
    {
        $image = PeImage::parse($out);
        $sizeOfImage = unpack('V', $out, $image->optionalHeader + 56)[1];
        $this->assertSame(0, $sizeOfImage % 0x1000);
        $this->assertGreaterThanOrEqual($image->imageEnd(), $sizeOfImage);
        $this->assertLessThan($image->imageEnd() + 0x1000, $sizeOfImage);

        $stored = unpack('V', $out, $image->optionalHeader + 64)[1];
        $zeroed = substr_replace($out, "\0\0\0\0", $image->optionalHeader + 64, 4);
        $this->assertSame(PeImage::checksum($zeroed), $stored);
    }

    /**
     * @return list<IcoEntry>
     */
    private static function entries(): array
    {
        return IcoFile::entries(IconResampler::sizes(TestIcons::pattern(256), [16, 32, 256]));
    }

    private static function nameKey(string $name): string
    {
        return 'n:' . (string) preg_replace('/(.)/s', "\$1\0", $name);
    }

    /**
     * A minimal PE32+ image: headers (0x400), .text, optionally .rsrc with an old
     * icon, a version resource, a named RCDATA (and micro's size record), and
     * optionally a .reloc section after it.
     */
    private static function syntheticPe(bool $withReloc, bool $withResources = true, bool $sfxSize = false): string
    {
        $sections = [['.text', 0x1000, str_repeat("\xC3", 0x180), 0x60000020]];
        if ($withResources) {
            $tree = [
                'i:3' => ['i:1' => ['i:1031' => ['data' => 'old icon image', 'codepage' => 0]]],
                'i:10' => [self::nameKey('CONFIG') => ['i:1031' => ['data' => self::NAMED_DATA, 'codepage' => 0]]],
                'i:14' => [
                    self::nameKey('MAIN') => ['i:1031' => ['data' => 'old group', 'codepage' => 0]],
                    'i:32512' => ['i:1031' => ['data' => 'old group 2', 'codepage' => 0]],
                ],
                'i:16' => ['i:1' => ['i:1031' => ['data' => self::VERSION_DATA, 'codepage' => 1200]]],
            ];
            if ($sfxSize) {
                $tree['i:10']['i:' . PeIconWriter::MICRO_SFXSIZE_ID] = ['i:1031' => ['data' => pack('JJ', 0, 0), 'codepage' => 0]];
            }
            $sections[] = ['.rsrc', 0x2000, PeIconWriter::writeTree($tree, 0x2000), 0x40000040];
        }
        if ($withReloc) {
            $sections[] = ['.reloc', 0x3000, str_repeat("\0", 0x20), 0x42000040];
        }

        $dos = 'MZ' . str_repeat("\0", 0x3a) . pack('V', 0x40);
        $coff = "PE\0\0" . pack('vvVVVvv', 0x8664, count($sections), 0, 0, 0, 240, 0x22);
        $optional = pack('vCC', 0x20b, 14, 0) . str_repeat("\0", 236);
        $put = static function (string &$buf, int $at, string $bytes): void {
            $buf = substr_replace($buf, $bytes, $at, strlen($bytes));
        };
        $put($optional, 16, pack('V', 0x1000));              // AddressOfEntryPoint
        $put($optional, 32, pack('VV', 0x1000, 0x200));      // SectionAlignment, FileAlignment
        $put($optional, 56, pack('VV', 0x1000 * (count($sections) + 1), 0x400)); // SizeOfImage, SizeOfHeaders
        $put($optional, 68, pack('v', 3));                   // Subsystem: console
        $put($optional, 108, pack('V', 16));                 // NumberOfRvaAndSizes

        $table = '';
        $body = '';
        $raw = 0x400;
        foreach ($sections as [$name, $va, $data, $characteristics]) {
            $rawSize = intdiv(strlen($data) + 0x1ff, 0x200) * 0x200;
            $table .= str_pad($name, 8, "\0") . pack('VVVVVVvvV', strlen($data), $va, $rawSize, $raw, 0, 0, 0, 0, $characteristics);
            $body .= str_pad($data, $rawSize, "\0");
            if ($name === '.rsrc') {
                $put($optional, 112 + 8 * 2, pack('VV', $va, strlen($data)));
            }
            $raw += $rawSize;
        }
        $headers = str_pad($dos . $coff . $optional . $table, 0x400, "\0");
        return $headers . $body;
    }
}
