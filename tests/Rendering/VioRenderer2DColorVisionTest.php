<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use PHPolygon\Math\Vec2;
use PHPolygon\Rendering\Accessibility\AccessibilitySettings;
use PHPolygon\Rendering\Accessibility\ColorVision;
use PHPolygon\Rendering\Accessibility\ColorVisionMode;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Renderer2DInterface;
use PHPolygon\Rendering\ScaledRenderer2D;
use PHPolygon\Rendering\VioRenderer2D;
use PHPolygon\Rendering\VioTextureManager;
use PHPolygon\UI\UIScale;

/**
 * The colour vision option reaches 2D draw colours: off it changes no byte, on it
 * corrects shapes, lines and text (through ScaledRenderer2D too), never sprites, and a
 * game can narrow it to the colours that carry a state or switch it off.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioRenderer2DColorVisionTest extends TestCase
{
    private const SIZE = 64;

    private const RED = [0.85, 0.2, 0.2];
    private const GREEN = [0.2, 0.65, 0.2];

    private ?\VioContext $ctx = null;
    private ?VioRenderer2D $renderer = null;
    private ?VioTextureManager $textures = null;

    protected function setUp(): void
    {
        $ctx = VioTestContext::create(self::SIZE, self::SIZE);
        if ($ctx === null) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        $this->ctx = $ctx;
        $this->renderer = new VioRenderer2D($ctx);
        $this->textures = new VioTextureManager($ctx);
        $this->textures->setRenderer($this->renderer);
        // A sprite in the status red: the option leaves its texels alone.
        $px = str_repeat(pack('C4', 217, 51, 51, 255), 16 * 16);
        $this->textures->loadRgba('red_icon', 16, 16, $px);
    }

    protected function tearDown(): void
    {
        $this->renderer = null;
        $this->textures = null;
        if ($this->ctx !== null) {
            vio_destroy($this->ctx);
            $this->ctx = null;
        }
    }

    public function testOffChangesNoByte(): void
    {
        $reference = $this->image();
        $this->apply(new AccessibilitySettings());
        self::assertSame(md5($reference), md5($this->image()), 'default settings');
        $this->apply(new AccessibilitySettings(colorVision: ColorVisionMode::Deuteranopia, colorVisionStrength: 0.0));
        self::assertSame(md5($reference), md5($this->image()), 'strength 0');
        $this->apply(new AccessibilitySettings(colorVision: ColorVisionMode::Deuteranopia));
        self::assertNotSame(md5($reference), md5($this->image()), 'on');
        $this->apply(new AccessibilitySettings());
        self::assertSame(md5($reference), md5($this->image()), 'off again');
    }

    public function testOnCorrectsShapesLinesAndTextButNotSprites(): void
    {
        $this->apply(new AccessibilitySettings(colorVision: ColorVisionMode::Deuteranopia));
        $px = $this->image();
        self::assertNotNull($this->renderer);
        $filter = $this->renderer->colorVisionFilter();
        self::assertNotNull($filter);
        $expected = static fn (array $c): array => self::rgb($filter->argb(self::argb($c)));

        self::assertSame($expected(self::RED), self::at($px, 8, 8), 'rect');
        self::assertSame($expected(self::GREEN), self::at($px, 40, 8), 'rounded rect');
        self::assertSame($expected([0.95, 0.6, 0.1]), self::at($px, 4, 60), 'line');
        self::assertSame([217, 51, 51], self::at($px, 44, 44), 'sprite texels unchanged');

        // What a deuteranope sees of the two status colours.
        $seen = static fn (array $rgb): array => ColorVision::simulate(ColorVisionMode::Deuteranopia, ColorVision::decode([$rgb[0] / 255, $rgb[1] / 255, $rgb[2] / 255]));
        $off = $this->offImage();
        $before = ColorVision::deltaE($seen(self::at($off, 8, 8)), $seen(self::at($off, 40, 8)));
        $after = ColorVision::deltaE($seen(self::at($px, 8, 8)), $seen(self::at($px, 40, 8)));
        fprintf(STDERR, "[2d colour vision] status red/green as a deuteranope sees them: dE76 %.1f -> %.1f\n", $before, $after);
        self::assertGreaterThan($before * 3.0, $after);
    }

    public function testTheScaledRendererPassesTheCorrectedColoursOn(): void
    {
        $this->apply(new AccessibilitySettings(colorVision: ColorVisionMode::Protanopia));
        self::assertNotNull($this->renderer);
        $scale = new UIScale();
        $scale->set(2.0);
        $scaled = new ScaledRenderer2D($this->renderer, $scale);
        $px = $this->image($scaled);
        $filter = $this->renderer->colorVisionFilter();
        self::assertNotNull($filter);
        // The red rect covers 0..24 logical, 0..48 physical pixels.
        self::assertSame(self::rgb($filter->argb(self::argb(self::RED))), self::at($px, 30, 30));
    }

    public function testAGameNarrowsOrSwitchesOffTheScope(): void
    {
        self::assertNotNull($this->renderer);
        $off = $this->offImage();
        $this->apply(new AccessibilitySettings(colorVision: ColorVisionMode::Deuteranopia));
        $this->renderer->setColorVisionScope(false);
        self::assertSame(md5($off), md5($this->image()), 'scope false: the game corrects itself');

        // Only the status red.
        $this->renderer->setColorVisionScope(static fn (Color $c): bool => $c->r === self::RED[0] && $c->g === self::RED[1] && $c->b === self::RED[2]);
        $px = $this->image();
        self::assertNotSame(self::at($off, 8, 8), self::at($px, 8, 8), 'red corrected');
        self::assertSame(self::at($off, 40, 8), self::at($px, 40, 8), 'green left alone');

        $this->renderer->setColorVisionScope(true);
        self::assertNotSame(self::at($off, 40, 8), self::at($this->image(), 40, 8), 'scope true: every colour again');
    }

    private function apply(AccessibilitySettings $a): void
    {
        self::assertNotNull($this->renderer);
        $this->renderer->applyEffectSettings((new GraphicsSettings())->with(accessibility: $a));
    }

    private function offImage(): string
    {
        self::assertNotNull($this->renderer);
        $current = $this->renderer->colorVisionFilter();
        $this->apply(new AccessibilitySettings());
        $px = $this->image();
        if ($current !== null) {
            $this->apply(new AccessibilitySettings(colorVision: $current->mode, colorVisionStrength: $current->strength, colorVisionSimulate: $current->simulate));
        }
        return $px;
    }

    private function image(?Renderer2DInterface $target = null): string
    {
        self::assertNotNull($this->ctx);
        self::assertNotNull($this->renderer);
        self::assertNotNull($this->textures);
        $r = $target ?? $this->renderer;
        $icon = $this->textures->get('red_icon');
        self::assertNotNull($icon);
        for ($i = 0; $i < 3; $i++) {
            $this->renderer->beginFrame();
            $r->clear(new Color(0.1, 0.1, 0.12, 1.0));
            $r->drawRect(0, 0, 24, 24, new Color(...self::RED));
            $r->drawRoundedRect(32, 0, 32, 24, 4, new Color(...self::GREEN));
            $r->drawLine(new Vec2(0, 60), new Vec2(30, 60), new Color(0.95, 0.6, 0.1), 3.0);
            $r->drawSprite($icon, null, 36, 36, 16, 16);
            $this->renderer->endFrame();
        }
        $pixels = vio_read_pixels($this->ctx);
        self::assertIsString($pixels);
        return $pixels;
    }

    /** @param array{float, float, float} $c */
    private static function argb(array $c): int
    {
        return (0xFF << 24) | (((int) ($c[0] * 255)) << 16) | (((int) ($c[1] * 255)) << 8) | (int) ($c[2] * 255);
    }

    /** @return list<int> */
    private static function rgb(int $argb): array
    {
        return [($argb >> 16) & 0xFF, ($argb >> 8) & 0xFF, $argb & 0xFF];
    }

    /** @return list<int> */
    private static function at(string $rgba, int $x, int $y): array
    {
        return array_values(array_slice((array) unpack('C4', substr($rgba, ($y * self::SIZE + $x) * 4, 4)), 0, 3));
    }
}
