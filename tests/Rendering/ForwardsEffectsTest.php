<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\TestCase;
use PHPolygon\Rendering\Effect2D\EffectRenderer2DInterface;
use PHPolygon\Rendering\Effect2D\ForwardsEffects;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Renderer2DInterface;
use PHPolygon\Tests\UI\Widget\WidgetTestHelper;

class ForwardsEffectsTest extends TestCase
{
    private function decorator(Renderer2DInterface $inner): EffectRenderer2DInterface
    {
        return new class($inner) extends WidgetTestHelper implements EffectRenderer2DInterface {
            use ForwardsEffects;

            public function __construct(private readonly Renderer2DInterface $inner) {}

            protected function effectTarget(): Renderer2DInterface
            {
                return $this->inner;
            }
        };
    }

    public function testSettingsReachTheWrappedEffectRenderer(): void
    {
        $inner = new class extends WidgetTestHelper implements EffectRenderer2DInterface {
            public ?GraphicsSettings $applied = null;
            public bool $drawn = false;

            public function drawEffect(string $name, float $x, float $y, float $w, float $h, array $parameters = [], array $textures = [], ?float $padding = null): bool
            {
                $this->drawn = true;

                return true;
            }

            public function effectAvailable(string $name): bool
            {
                return $name === 'glow';
            }

            public function applyEffectSettings(GraphicsSettings $settings): void
            {
                $this->applied = $settings;
            }
        };
        $outer = $this->decorator($inner);
        $settings = new GraphicsSettings();

        $outer->applyEffectSettings($settings);

        $this->assertSame($settings, $inner->applied);
        $this->assertTrue($outer->effectAvailable('glow'));
        $this->assertFalse($outer->effectAvailable('other'));
        $this->assertTrue($outer->drawEffect('glow', 0, 0, 1, 1));
        $this->assertTrue($inner->drawn);
    }

    public function testWrappingAPlainRendererIsHarmless(): void
    {
        $outer = $this->decorator(new WidgetTestHelper());

        $outer->applyEffectSettings(new GraphicsSettings());

        $this->assertFalse($outer->effectAvailable('glow'));
        $this->assertFalse($outer->drawEffect('glow', 0, 0, 1, 1));
    }
}
