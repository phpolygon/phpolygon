<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Quality\TextureQuality;
use PHPolygon\Rendering\VioTextureManager;

/**
 * A .ktx2 next to an image is the texture that gets uploaded: it carries the
 * finished mip chain, and the texture-quality tier drops whole levels from it
 * (Half = 1 level), which the PNG path can only approximate with a sampler
 * bias. Without a sibling, or on a php-vio that cannot read KTX2, the image
 * loads exactly as before.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioTextureManagerKtx2Test extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/phpolygon-ktx2-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    public function testKtx2SiblingIsPreferredAndTierDropsLevels(): void
    {
        if (!function_exists('vio_texture_ktx2')) {
            $this->markTestSkipped('php-vio without vio_texture_ktx2 (< 2.18)');
        }
        $ctx = @vio_create('auto', ['width' => 16, 'height' => 16, 'headless' => true, 'vsync' => false]);
        if ($ctx === false) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }

        // 2x2 PNG (raw, no ext-gd needed) and a 4x4 KTX2 with a three-level chain.
        file_put_contents($this->dir . '/wall.png', $this->png2x2());
        file_put_contents($this->dir . '/wall.ktx2', $this->ktx2Rgba8(4, 4, [
            str_repeat("\xFF\x00\x00\xFF", 16),
            str_repeat("\x00\xFF\x00\xFF", 4),
            "\x00\x00\xFF\xFF",
        ]));
        file_put_contents($this->dir . '/plain.png', $this->png2x2());

        self::assertSame($this->dir . '/wall.ktx2', VioTextureManager::ktx2Sibling($this->dir . '/wall.png'));
        self::assertNull(VioTextureManager::ktx2Sibling($this->dir . '/plain.png'));

        $manager = new VioTextureManager($ctx, $this->dir);
        self::assertTrue($manager->ktx2Available());

        $full = $manager->load('wall.png');
        self::assertSame([4, 4], [$full->width, $full->height], 'the KTX2 (4x4) is uploaded, not the 2x2 PNG');
        self::assertStringEndsWith('wall.ktx2', $full->path);

        $plain = $manager->load('plain.png');
        self::assertSame([2, 2], [$plain->width, $plain->height], 'no sibling: the PNG loads as before');

        // Half quality drops the largest level: the same container is now 2x2.
        $half = new VioTextureManager($ctx, $this->dir);
        $half->applySettings(new GraphicsSettings(textureQuality: TextureQuality::Half));
        $tex = $half->load('wall.png');
        self::assertSame([2, 2], [$tex->width, $tex->height], 'TextureQuality::Half skips one mip level of the KTX2');

        vio_destroy($ctx);
    }

    /** Uncompressed 2x2 RGBA PNG (stored, no filtering) built by hand. */
    private function png2x2(): string
    {
        $chunk = static function (string $type, string $data): string {
            return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        };
        $raw = "\x00" . "\xFF\x00\x00\xFF\x00\xFF\x00\xFF" . "\x00" . "\x00\x00\xFF\xFF\xFF\xFF\xFF\xFF";
        return "\x89PNG\r\n\x1A\n"
            . $chunk('IHDR', pack('NNCCCCC', 2, 2, 8, 6, 0, 0, 0))
            . $chunk('IDAT', (string) zlib_encode($raw, ZLIB_ENCODING_DEFLATE))
            . $chunk('IEND', '');
    }

    /**
     * Minimal KTX2 container (VK_FORMAT_R8G8B8A8_UNORM, 2D, no supercompression):
     * header, index, level index, a 4-byte DFD stub, levels stored smallest-first.
     *
     * @param list<string> $levels level-major RGBA8 payloads, largest first
     */
    private function ktx2Rgba8(int $w, int $h, array $levels): string
    {
        $n = count($levels);
        $hdr = "\xABKTX 20\xBB\r\n\x1A\n" . pack('V9', 37, 1, $w, $h, 0, 0, 1, $n, 0);
        $dfdOff = 80 + $n * 24;
        $dfd = pack('V', 4);
        $dataStart = ($dfdOff + strlen($dfd) + 7) & ~7;
        $blob = '';
        $offsets = [];
        for ($l = $n - 1; $l >= 0; $l--) {
            while ((($dataStart + strlen($blob)) % 8) !== 0) {
                $blob .= "\0";
            }
            $offsets[$l] = $dataStart + strlen($blob);
            $blob .= $levels[$l];
        }
        $index = pack('V4', $dfdOff, strlen($dfd), 0, 0) . pack('P2', 0, 0);
        $lvl = '';
        for ($l = 0; $l < $n; $l++) {
            $lvl .= pack('P3', $offsets[$l], strlen($levels[$l]), strlen($levels[$l]));
        }
        return str_pad($hdr . $index . $lvl . $dfd, $dataStart, "\0") . $blob;
    }
}
