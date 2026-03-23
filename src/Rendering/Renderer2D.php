<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

use GL\VectorGraphics\VGColor;
use GL\VectorGraphics\VGContext;
use GL\VectorGraphics\VGAlign;
use PHPolygon\Math\Mat3;
use PHPolygon\Math\Rect;
use PHPolygon\Math\Vec2;
use PHPolygon\Runtime\Window;

class Renderer2D implements Renderer2DInterface
{
    private VGContext $vg;

    public function __construct(
        private readonly Window $window,
    ) {
        $this->vg = new VGContext(VGContext::ANTIALIAS | VGContext::STENCIL_STROKES);
    }

    public function beginFrame(): void
    {
        $width = $this->window->getWidth();
        $height = $this->window->getHeight();
        $pixelRatio = $this->window->getPixelRatio();

        glViewport(0, 0, $this->window->getFramebufferWidth(), $this->window->getFramebufferHeight());
        glClearColor(0.0, 0.0, 0.0, 1.0);
        glClear(GL_COLOR_BUFFER_BIT | GL_STENCIL_BUFFER_BIT);

        $this->vg->beginFrame((float)$width, (float)$height, $pixelRatio);
    }

    public function endFrame(): void
    {
        $this->vg->endFrame();
    }

    public function clear(Color $color): void
    {
        glClearColor($color->r, $color->g, $color->b, $color->a);
        glClear(GL_COLOR_BUFFER_BIT | GL_STENCIL_BUFFER_BIT);
    }

    public function setViewport(int $x, int $y, int $width, int $height): void
    {
        glViewport($x, $y, $width, $height);
    }

    public function getWidth(): int
    {
        return $this->window->getWidth();
    }

    public function getHeight(): int
    {
        return $this->window->getHeight();
    }

    public function drawRect(float $x, float $y, float $w, float $h, Color $color): void
    {
        $this->vg->beginPath();
        $this->vg->rect($x, $y, $w, $h);
        $this->vg->fillColor($this->toVGColor($color));
        $this->vg->fill();
    }

    public function drawRectOutline(float $x, float $y, float $w, float $h, Color $color, float $lineWidth = 1.0): void
    {
        $this->vg->beginPath();
        $this->vg->rect($x, $y, $w, $h);
        $this->vg->strokeColor($this->toVGColor($color));
        $this->vg->strokeWidth($lineWidth);
        $this->vg->stroke();
    }

    public function drawRoundedRect(float $x, float $y, float $w, float $h, float $radius, Color $color): void
    {
        $this->vg->beginPath();
        $this->vg->roundedRect($x, $y, $w, $h, $radius);
        $this->vg->fillColor($this->toVGColor($color));
        $this->vg->fill();
    }

    public function drawCircle(float $cx, float $cy, float $r, Color $color): void
    {
        $this->vg->beginPath();
        $this->vg->circle($cx, $cy, $r);
        $this->vg->fillColor($this->toVGColor($color));
        $this->vg->fill();
    }

    public function drawCircleOutline(float $cx, float $cy, float $r, Color $color, float $lineWidth = 1.0): void
    {
        $this->vg->beginPath();
        $this->vg->circle($cx, $cy, $r);
        $this->vg->strokeColor($this->toVGColor($color));
        $this->vg->strokeWidth($lineWidth);
        $this->vg->stroke();
    }

    public function drawLine(Vec2 $from, Vec2 $to, Color $color, float $width = 1.0): void
    {
        $this->vg->beginPath();
        $this->vg->moveTo($from->x, $from->y);
        $this->vg->lineTo($to->x, $to->y);
        $this->vg->strokeColor($this->toVGColor($color));
        $this->vg->strokeWidth($width);
        $this->vg->stroke();
    }

    public function drawText(string $text, float $x, float $y, float $size, Color $color): void
    {
        $this->vg->fontSize($size);
        $this->vg->fillColor($this->toVGColor($color));
        $this->vg->textAlign(VGAlign::LEFT | VGAlign::TOP);
        $this->vg->text($x, $y, $text);
    }

    public function drawTextBox(string $text, float $x, float $y, float $breakWidth, float $size, Color $color): void
    {
        $this->vg->fontSize($size);
        $this->vg->fillColor($this->toVGColor($color));
        $this->vg->textAlign(VGAlign::LEFT | VGAlign::TOP);
        $this->vg->textBox($x, $y, $breakWidth, $text);
    }

    public function drawSprite(Texture $texture, ?Rect $srcRegion, float $x, float $y, float $w, float $h, float $opacity = 1.0): void
    {
        $this->vg->save();
        $this->vg->globalAlpha($opacity);

        // If a source region is specified, calculate UV mapping
        if ($srcRegion !== null) {
            $scaleX = $w / $srcRegion->width;
            $scaleY = $h / $srcRegion->height;
            $imgX = $x - $srcRegion->x * $scaleX;
            $imgY = $y - $srcRegion->y * $scaleY;
            $imgW = $texture->width * $scaleX;
            $imgH = $texture->height * $scaleY;
        } else {
            $imgX = $x;
            $imgY = $y;
            $imgW = $w;
            $imgH = $h;
        }

        $image = $this->getOrCreateImage($texture);
        $paint = $image->makePaint($imgX, $imgY, $imgW, $imgH, 0.0, $opacity);

        $this->vg->beginPath();
        $this->vg->rect($x, $y, $w, $h);
        $this->vg->fillPaint($paint);
        $this->vg->fill();

        $this->vg->restore();
    }

    public function pushTransform(Mat3 $matrix): void
    {
        $m = $matrix->toArray();
        $this->vg->save();
        // NanoVG transform: a, b, c, d, e, f maps to our column-major mat3
        $this->vg->transform($m[0], $m[1], $m[3], $m[4], $m[6], $m[7]);
    }

    public function popTransform(): void
    {
        $this->vg->restore();
    }

    public function pushScissor(float $x, float $y, float $w, float $h): void
    {
        $this->vg->scissor($x, $y, $w, $h);
    }

    public function popScissor(): void
    {
        $this->vg->resetScissor();
    }

    public function loadFont(string $name, string $path): void
    {
        $this->vg->createFont($name, $path);
    }

    public function setFont(string $name): void
    {
        $this->vg->fontFace($name);
    }

    public function getVGContext(): VGContext
    {
        return $this->vg;
    }

    /** @var array<int, \GL\VectorGraphics\VGImage> */
    private array $imageCache = [];

    private function getOrCreateImage(Texture $texture): \GL\VectorGraphics\VGImage
    {
        if (isset($this->imageCache[$texture->glId])) {
            return $this->imageCache[$texture->glId];
        }

        $image = $this->vg->imageFromHandle($texture->glId, $texture->width, $texture->height, 0, 0);
        $this->imageCache[$texture->glId] = $image;
        return $image;
    }

    private function toVGColor(Color $color): VGColor
    {
        return new VGColor($color->r, $color->g, $color->b, $color->a);
    }
}
