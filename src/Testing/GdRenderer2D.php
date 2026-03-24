<?php

declare(strict_types=1);

namespace PHPolygon\Testing;

use PHPolygon\Math\Mat3;
use PHPolygon\Math\Rect;
use PHPolygon\Math\Vec2;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\NullRenderer2D;
use PHPolygon\Rendering\Renderer2DInterface;
use PHPolygon\Rendering\Texture;

/**
 * Software 2D renderer using PHP GD for headless visual regression testing.
 *
 * Produces deterministic output without GPU. Not intended for real-time
 * rendering — only for generating reference screenshots and VRT comparisons.
 */
class GdRenderer2D implements Renderer2DInterface
{
    private \GdImage $image;
    private int $width;
    private int $height;

    /** @var array<string, string> font name → file path */
    private array $fonts = [];
    private string $currentFont = '';

    /** @var list<Mat3> */
    private array $transformStack = [];
    private Mat3 $currentTransform;

    /** @var list<array{x: float, y: float, w: float, h: float}> */
    private array $scissorStack = [];

    public function __construct(int $width = 1280, int $height = 720)
    {
        $this->width = $width;
        $this->height = $height;
        $this->currentTransform = Mat3::identity();
        $this->image = $this->createImage($width, $height);
    }

    private function createImage(int $width, int $height): \GdImage
    {
        $img = imagecreatetruecolor(max(1, $width), max(1, $height));
        if ($img === false) {
            throw new \RuntimeException('Failed to create GD image');
        }
        imagealphablending($img, true);
        imagesavealpha($img, true);

        // Fill with transparent black
        $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
        if ($transparent !== false) {
            imagefill($img, 0, 0, $transparent);
        }

        return $img;
    }

    public function getImage(): \GdImage
    {
        return $this->image;
    }

    /**
     * Save current frame as PNG file.
     */
    public function savePng(string $path): void
    {
        imagepng($this->image, $path);
    }

    // --- RenderContextInterface ---

    public function beginFrame(): void
    {
        // Clear to black opaque
        $black = imagecolorallocate($this->image, 0, 0, 0);
        if ($black !== false) {
            imagefilledrectangle($this->image, 0, 0, $this->width - 1, $this->height - 1, $black);
        }
        $this->transformStack = [];
        $this->currentTransform = Mat3::identity();
        $this->scissorStack = [];
    }

    public function endFrame(): void {}

    public function clear(Color $color): void
    {
        $gdColor = $this->allocateColor($color);
        imagefilledrectangle($this->image, 0, 0, $this->width - 1, $this->height - 1, $gdColor);
    }

    public function setViewport(int $x, int $y, int $width, int $height): void
    {
        $this->width = $width;
        $this->height = $height;
    }

    public function getWidth(): int
    {
        return $this->width;
    }

    public function getHeight(): int
    {
        return $this->height;
    }

    // --- Renderer2DInterface ---

    public function drawRect(float $x, float $y, float $w, float $h, Color $color): void
    {
        [$tx, $ty] = $this->transformPoint($x, $y);
        $gdColor = $this->allocateColor($color);
        imagefilledrectangle(
            $this->image,
            (int) round($tx),
            (int) round($ty),
            (int) round($tx + $w) - 1,
            (int) round($ty + $h) - 1,
            $gdColor,
        );
    }

    public function drawRectOutline(float $x, float $y, float $w, float $h, Color $color, float $lineWidth = 1.0): void
    {
        [$tx, $ty] = $this->transformPoint($x, $y);
        $gdColor = $this->allocateColor($color);
        imagesetthickness($this->image, max(1, (int) round($lineWidth)));
        imagerectangle(
            $this->image,
            (int) round($tx),
            (int) round($ty),
            (int) round($tx + $w) - 1,
            (int) round($ty + $h) - 1,
            $gdColor,
        );
        imagesetthickness($this->image, 1);
    }

    public function drawRoundedRect(float $x, float $y, float $w, float $h, float $radius, Color $color): void
    {
        [$tx, $ty] = $this->transformPoint($x, $y);
        $gdColor = $this->allocateColor($color);
        $ix = (int) round($tx);
        $iy = (int) round($ty);
        $iw = (int) round($w);
        $ih = (int) round($h);
        $ir = (int) round(min($radius, $iw / 2, $ih / 2));

        // Fill center and side rectangles
        imagefilledrectangle($this->image, $ix + $ir, $iy, $ix + $iw - $ir - 1, $iy + $ih - 1, $gdColor);
        imagefilledrectangle($this->image, $ix, $iy + $ir, $ix + $ir - 1, $iy + $ih - $ir - 1, $gdColor);
        imagefilledrectangle($this->image, $ix + $iw - $ir, $iy + $ir, $ix + $iw - 1, $iy + $ih - $ir - 1, $gdColor);

        // Fill corner arcs
        $d = $ir * 2;
        imagefilledarc($this->image, $ix + $ir, $iy + $ir, $d, $d, 180, 270, $gdColor, IMG_ARC_PIE);
        imagefilledarc($this->image, $ix + $iw - $ir - 1, $iy + $ir, $d, $d, 270, 360, $gdColor, IMG_ARC_PIE);
        imagefilledarc($this->image, $ix + $ir, $iy + $ih - $ir - 1, $d, $d, 90, 180, $gdColor, IMG_ARC_PIE);
        imagefilledarc($this->image, $ix + $iw - $ir - 1, $iy + $ih - $ir - 1, $d, $d, 0, 90, $gdColor, IMG_ARC_PIE);
    }

    public function drawCircle(float $cx, float $cy, float $r, Color $color): void
    {
        [$tx, $ty] = $this->transformPoint($cx, $cy);
        $gdColor = $this->allocateColor($color);
        imagefilledellipse($this->image, (int) round($tx), (int) round($ty), (int) round($r * 2), (int) round($r * 2), $gdColor);
    }

    public function drawCircleOutline(float $cx, float $cy, float $r, Color $color, float $lineWidth = 1.0): void
    {
        [$tx, $ty] = $this->transformPoint($cx, $cy);
        $gdColor = $this->allocateColor($color);
        imagesetthickness($this->image, max(1, (int) round($lineWidth)));
        imageellipse($this->image, (int) round($tx), (int) round($ty), (int) round($r * 2), (int) round($r * 2), $gdColor);
        imagesetthickness($this->image, 1);
    }

    public function drawLine(Vec2 $from, Vec2 $to, Color $color, float $width = 1.0): void
    {
        [$fx, $fy] = $this->transformPoint($from->x, $from->y);
        [$tx, $ty] = $this->transformPoint($to->x, $to->y);
        $gdColor = $this->allocateColor($color);
        imagesetthickness($this->image, max(1, (int) round($width)));
        imageline($this->image, (int) round($fx), (int) round($fy), (int) round($tx), (int) round($ty), $gdColor);
        imagesetthickness($this->image, 1);
    }

    public function drawText(string $text, float $x, float $y, float $size, Color $color): void
    {
        [$tx, $ty] = $this->transformPoint($x, $y);
        $gdColor = $this->allocateColor($color);

        if ($this->currentFont !== '' && isset($this->fonts[$this->currentFont])) {
            imagettftext($this->image, $size, 0, (int) round($tx), (int) round($ty + $size), $gdColor, $this->fonts[$this->currentFont], $text);
        } else {
            // Fallback: GD built-in font
            imagestring($this->image, 4, (int) round($tx), (int) round($ty), $text, $gdColor);
        }
    }

    public function drawTextCentered(string $text, float $cx, float $cy, float $size, Color $color): void
    {
        if ($this->currentFont !== '' && isset($this->fonts[$this->currentFont])) {
            $bbox = imagettfbbox($size, 0, $this->fonts[$this->currentFont], $text);
            if ($bbox !== false) {
                /** @var array<int, int> $bbox */
                $textWidth = $bbox[2] - $bbox[0];
                $textHeight = $bbox[1] - $bbox[7];
                $this->drawText($text, $cx - $textWidth / 2, $cy - $textHeight / 2, $size, $color);
                return;
            }
        }
        // Fallback
        $this->drawText($text, $cx, $cy, $size, $color);
    }

    public function drawTextBox(string $text, float $x, float $y, float $breakWidth, float $size, Color $color): void
    {
        if ($this->currentFont === '' || !isset($this->fonts[$this->currentFont])) {
            $this->drawText($text, $x, $y, $size, $color);
            return;
        }

        $fontPath = $this->fonts[$this->currentFont];
        $words = explode(' ', $text);
        $line = '';
        $lineY = $y;
        $lineHeight = $size * 1.4;

        foreach ($words as $word) {
            $testLine = $line === '' ? $word : $line . ' ' . $word;
            $bbox = imagettfbbox($size, 0, $fontPath, $testLine);
            /** @var array<int, int>|false $bbox */
            $lineWidth = $bbox !== false ? ($bbox[2] - $bbox[0]) : 0;

            if ($lineWidth > $breakWidth && $line !== '') {
                $this->drawText($line, $x, $lineY, $size, $color);
                $line = $word;
                $lineY += $lineHeight;
            } else {
                $line = $testLine;
            }
        }
        if ($line !== '') {
            $this->drawText($line, $x, $lineY, $size, $color);
        }
    }

    public function drawSprite(Texture $texture, ?Rect $srcRegion, float $x, float $y, float $w, float $h, float $opacity = 1.0): void
    {
        // In headless mode, draw a placeholder rectangle
        [$tx, $ty] = $this->transformPoint($x, $y);
        $alpha = max(0, min(127, (int) round((1.0 - $opacity) * 127)));
        $gdColor = imagecolorallocatealpha($this->image, 128, 128, 128, $alpha);
        if ($gdColor !== false) {
            imagefilledrectangle($this->image, (int) round($tx), (int) round($ty), (int) round($tx + $w) - 1, (int) round($ty + $h) - 1, $gdColor);
        }
        // Draw outline to show sprite bounds
        $outline = imagecolorallocatealpha($this->image, 200, 200, 200, $alpha);
        if ($outline !== false) {
            imagerectangle($this->image, (int) round($tx), (int) round($ty), (int) round($tx + $w) - 1, (int) round($ty + $h) - 1, $outline);
        }
    }

    public function pushTransform(Mat3 $matrix): void
    {
        $this->transformStack[] = $this->currentTransform;
        $this->currentTransform = $this->currentTransform->multiply($matrix);
    }

    public function popTransform(): void
    {
        if (count($this->transformStack) > 0) {
            $this->currentTransform = array_pop($this->transformStack);
        }
    }

    public function pushScissor(float $x, float $y, float $w, float $h): void
    {
        $this->scissorStack[] = ['x' => $x, 'y' => $y, 'w' => $w, 'h' => $h];
    }

    public function popScissor(): void
    {
        if (count($this->scissorStack) > 0) {
            array_pop($this->scissorStack);
        }
    }

    public function loadFont(string $name, string $path): void
    {
        $this->fonts[$name] = $path;
    }

    public function setFont(string $name): void
    {
        $this->currentFont = $name;
    }

    // --- Private helpers ---

    private function allocateColor(Color $color): int
    {
        $r = max(0, min(255, (int) round($color->r * 255)));
        $g = max(0, min(255, (int) round($color->g * 255)));
        $b = max(0, min(255, (int) round($color->b * 255)));
        $a = max(0, min(127, (int) round((1.0 - $color->a) * 127)));

        $result = imagecolorallocatealpha($this->image, $r, $g, $b, $a);
        return $result !== false ? $result : 0;
    }

    /**
     * Apply current transform matrix to a point.
     * @return array{float, float}
     */
    private function transformPoint(float $x, float $y): array
    {
        $p = $this->currentTransform->transformPoint(new Vec2($x, $y));
        return [$p->x, $p->y];
    }

    public function __destruct()
    {
        unset($this->image);
    }
}
