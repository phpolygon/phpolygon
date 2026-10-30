<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Text;

use PHPolygon\Rendering\Ktx2Container;

/**
 * A directory of `<texture id>.ktx2` files: RGBA8 with a finished mip chain,
 * zlib per level. Flat plates compress to a few percent, and the ids hold the
 * content, so a file can never show an older text. Needs no encoder and no GPU.
 */
final class SurfaceTextDirectoryStore implements SurfaceTextStoreInterface
{
    private const int VK_FORMAT_R8G8B8A8_UNORM = 37;

    public function __construct(
        private readonly string $directory,
        private readonly int $zlibLevel = 9,
    ) {}

    public function path(string $textureId): ?string
    {
        $path = $this->directory . '/' . $textureId . '.ktx2';

        return function_exists('vio_texture_ktx2') && is_file($path) ? $path : null;
    }

    public function store(string $textureId, string $rgba, int $width, int $height): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0777, true) && !is_dir($this->directory)) {
            throw new \RuntimeException("Cannot create {$this->directory}");
        }
        $bytes = Ktx2Container::build(
            self::VK_FORMAT_R8G8B8A8_UNORM,
            1,
            $width,
            $height,
            self::mipChain($rgba, $width, $height),
            $this->zlibLevel,
        );
        if (file_put_contents($this->directory . '/' . $textureId . '.ktx2', $bytes) === false) {
            throw new \RuntimeException("Cannot write {$textureId}.ktx2");
        }
    }

    /**
     * Every level down to 1 x 1, base first, each a box filter of the one above
     * (an odd edge drops its last row or column).
     *
     * @return list<string>
     */
    public static function mipChain(string $rgba, int $width, int $height): array
    {
        $levels = [$rgba];
        while ($width > 1 || $height > 1) {
            $nextWidth = max(1, intdiv($width, 2));
            $nextHeight = max(1, intdiv($height, 2));
            $stepX = $width > 1 ? 4 : 0;
            $stride = $height > 1 ? $width * 4 : 0;
            $out = '';
            for ($y = 0; $y < $nextHeight; $y++) {
                $base = ($height > 1 ? $y * 2 : $y) * $width * 4;
                for ($x = 0; $x < $nextWidth; $x++) {
                    $a = $base + ($width > 1 ? $x * 8 : 0);
                    for ($c = $a, $end = $a + 3; $c < $end; $c++) {
                        $out .= chr((ord($rgba[$c]) + ord($rgba[$c + $stepX]) + ord($rgba[$c + $stride]) + ord($rgba[$c + $stride + $stepX]) + 2) >> 2);
                    }
                    $out .= "\xFF";
                }
            }
            $levels[] = $rgba = $out;
            $width = $nextWidth;
            $height = $nextHeight;
        }

        return $levels;
    }
}
