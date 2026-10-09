<?php

declare(strict_types=1);

namespace PHPolygon\Tests\UI;

use PHPUnit\Framework\TestCase;
use PHPolygon\Engine;
use PHPolygon\EngineConfig;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\NullRenderer2D;
use PHPolygon\Rendering\NullRenderer3D;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\GraphicsCapabilities;
use PHPolygon\Rendering\Quality\UpscaleQuality;
use PHPolygon\Rendering\Quality\Upscaler;
use PHPolygon\Runtime\Input;
use PHPolygon\UI\GraphicsOptionsPanel;
use PHPolygon\UI\UIContext;

/**
 * The upscaler part of the graphics panel: a quality preset for the temporal
 * upscalers (the render-scale slider only decides under Custom), sharpness
 * wherever a sharpening pass runs, and a hint when the stored upscaler does
 * not run on this machine - why, and what runs instead.
 */
final class GraphicsOptionsPanelTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/phpolygon-gop-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    public function testTemporalUpscalerOffersQualityPresetsAndSharpness(): void
    {
        $texts = $this->draw(
            (new GraphicsSettings())->with(upscaler: Upscaler::Dlss, upscaleQuality: UpscaleQuality::Balanced),
            GraphicsCapabilities::unknown(),
        );
        $this->assertContains('Upscale Quality', $texts);
        $this->assertContains('Balanced', $texts, 'the dropdown shows the preset');
        $this->assertTrue(self::anyContains($texts, 'Sharpness:'));
        $this->assertFalse(self::anyContains($texts, 'Render Scale:'), 'a preset decides the render scale');
        $this->assertTrue(self::anyContains($texts, 'Render Scale (set by'), 'the slider says who decides');
    }

    public function testCustomPresetLeavesTheRenderScaleSlider(): void
    {
        $texts = $this->draw(
            (new GraphicsSettings())->with(upscaler: Upscaler::Fsr3, upscaleQuality: UpscaleQuality::Custom),
            GraphicsCapabilities::unknown(),
        );
        $this->assertContains('Upscale Quality', $texts);
        $this->assertTrue(self::anyContains($texts, 'Render Scale:'));
    }

    public function testSpatialAndNoUpscalerHaveNoQualityPresets(): void
    {
        $off = $this->draw(new GraphicsSettings(), GraphicsCapabilities::unknown());
        $this->assertNotContains('Upscale Quality', $off);
        $this->assertFalse(self::anyContains($off, 'Sharpness:'));

        $fsr1 = $this->draw((new GraphicsSettings())->with(upscaler: Upscaler::Fsr1), GraphicsCapabilities::unknown());
        $this->assertNotContains('Upscale Quality', $fsr1);
        $this->assertTrue(self::anyContains($fsr1, 'Sharpness:'));
    }

    public function testTaaSharpensToo(): void
    {
        $texts = $this->draw((new GraphicsSettings())->with(antiAliasing: AntiAliasing::Taa), GraphicsCapabilities::unknown());
        $this->assertTrue(self::anyContains($texts, 'Sharpness:'));
    }

    public function testUnavailableUpscalerExplainsItselfAndNamesTheReplacement(): void
    {
        $caps = new GraphicsCapabilities(
            upscalers: [Upscaler::Off, Upscaler::Fsr1, Upscaler::Taau, Upscaler::Fsr3],
            upscalerNotes: [Upscaler::Dlss->value => 'not an NVIDIA RTX GPU'],
        );
        $settings = (new GraphicsSettings())->with(upscaler: Upscaler::Dlss, upscaleQuality: UpscaleQuality::Quality);
        $hint = GraphicsOptionsPanel::upscalerHint($settings, $caps);
        $this->assertNotNull($hint);
        $this->assertStringContainsString('NVIDIA DLSS', $hint);
        $this->assertStringContainsString('not an NVIDIA RTX GPU', $hint);
        $this->assertStringContainsString('AMD FSR 3', $hint, 'what runs instead');

        $texts = $this->draw($settings, $caps);
        $this->assertContains($hint, $texts);
        $this->assertContains('Upscale Quality', $texts, 'FSR 3 is temporal: the preset still applies');
    }

    public function testNoHintWhenTheSelectionRuns(): void
    {
        $settings = (new GraphicsSettings())->with(upscaler: Upscaler::Fsr3);
        $this->assertNull(GraphicsOptionsPanel::upscalerHint($settings, GraphicsCapabilities::unknown()));
        $this->assertNull(GraphicsOptionsPanel::upscalerHint(new GraphicsSettings(), new GraphicsCapabilities()));
    }

    /**
     * @return list<string> every text the panel drew
     */
    private function draw(GraphicsSettings $settings, GraphicsCapabilities $caps): array
    {
        $engine = new Engine(new EngineConfig(
            headless: true,
            firstLaunchCalibration: false,
            graphicsSettingsPath: $this->dir . '/graphics.json',
        ));
        $engine->renderer3D = new class ($caps) extends NullRenderer3D {
            public function __construct(private readonly GraphicsCapabilities $caps)
            {
                parent::__construct(1280, 720);
            }

            public function graphicsCapabilities(): GraphicsCapabilities
            {
                return $this->caps;
            }
        };
        $engine->graphics->update(static fn(GraphicsSettings $g): GraphicsSettings => $settings);
        $renderer = new class extends NullRenderer2D {
            /** @var list<string> */
            public array $texts = [];

            public function __construct()
            {
                parent::__construct(1280, 4000);
            }

            public function drawText(string $text, float $x, float $y, float $size, Color $color): void
            {
                $this->texts[] = $text;
            }

            public function drawTextBox(string $text, float $x, float $y, float $breakWidth, float $size, Color $color): void
            {
                $this->texts[] = $text;
            }
        };
        $panel = new GraphicsOptionsPanel($engine, new UIContext($renderer, new Input()));
        $panel->draw(0.0, 0.0, 600.0);
        return $renderer->texts;
    }

    /** @param list<string> $texts */
    private static function anyContains(array $texts, string $needle): bool
    {
        foreach ($texts as $t) {
            if (str_contains($t, $needle)) {
                return true;
            }
        }
        return false;
    }
}
