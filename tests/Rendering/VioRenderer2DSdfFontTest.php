<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use PHPolygon\Math\Mat3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\ScaledRenderer2D;
use PHPolygon\Rendering\SdfFontRenderer2DInterface;
use PHPolygon\Rendering\VioRenderer2D;
use PHPolygon\UI\UIScale;

/**
 * SDF fonts (shader plan 8.1, SdfFontRenderer2DInterface): a font registered with
 * loadSdfFont() gets distance-field atlases from 24 px up, coverage below. Magnified
 * 4x by a transform, its glyphs keep a narrow edge where the coverage font smears;
 * ink and measured width stay those of the coverage font.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioRenderer2DSdfFontTest extends TestCase
{
    private const W = 256;
    private const H = 160;
    private const FONT_CANDIDATES = [
        '/Library/Fonts/Arial Unicode.ttf',
        '/System/Library/Fonts/Supplemental/Arial.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        'C:\\Windows\\Fonts\\arial.ttf',
    ];

    private ?\VioContext $ctx = null;

    protected function setUp(): void
    {
        if (!VioRenderer2D::vioSupportsSdfFonts()) {
            self::markTestSkipped('php-vio without vio_font options');
        }
        $ctx = VioTestContext::create(self::W, self::H);
        if ($ctx === null) {
            self::markTestSkipped('vio_create(headless) unavailable');
        }
        $this->ctx = $ctx;
    }

    protected function tearDown(): void
    {
        if ($this->ctx !== null) {
            vio_destroy($this->ctx);
            $this->ctx = null;
        }
    }

    private static function fontPath(): string
    {
        foreach (self::FONT_CANDIDATES as $c) {
            if (is_file($c)) {
                return $c;
            }
        }
        self::markTestSkipped('no system font found');
    }

    /** @return array<string, \VioFont> the renderer's cached atlases */
    private static function atlases(VioRenderer2D $r): array
    {
        /** @var array<string, \VioFont> */
        return (new \ReflectionProperty(VioRenderer2D::class, 'fontCache'))->getValue($r);
    }

    /** Distance-field spread of an atlas (0 = coverage). */
    private static function spread(\VioFont $font): int
    {
        $info = vio_font_info($font);
        self::assertIsArray($info);
        return $info['sdf'] ?? 0;
    }

    public function testLargeSizesGetDistanceFieldsSmallOnesCoverage(): void
    {
        self::assertNotNull($this->ctx);
        $r = new VioRenderer2D($this->ctx);
        self::assertInstanceOf(SdfFontRenderer2DInterface::class, $r);
        $r->loadSdfFont('sdf', self::fontPath());
        $r->loadFont('plain', self::fontPath());
        $r->setFont('sdf');
        $r->measureText('Ag', 32.0);
        $r->measureText('Ag', 12.0);
        $r->setFont('plain');
        $r->measureText('Ag', 32.0);
        $info = array_map(self::spread(...),self::atlases($r));
        self::assertGreaterThan(0, $info['sdf:32@100'] ?? 0, 'an SDF atlas at 32 px');
        self::assertSame(0, $info['sdf:12@100'] ?? -1, 'coverage below 24 px');
        self::assertSame(0, $info['plain:32@100'] ?? -1, 'loadFont stays coverage');

        // Registering the name again as a plain font drops the SDF flag.
        $r->loadFont('sdf', self::fontPath());
        $r->setFont('sdf');
        $r->measureText('Ag', 40.0);
        self::assertSame(0, self::spread(self::atlases($r)['sdf:40@100']));
    }

    public function testTheScaledRendererPassesSdfFontsOn(): void
    {
        self::assertNotNull($this->ctx);
        $inner = new VioRenderer2D($this->ctx);
        $scaled = new ScaledRenderer2D($inner, new UIScale(1.0));
        $scaled->loadSdfFont('sdf', self::fontPath());
        $inner->setFont('sdf');
        $inner->measureText('Ag', 32.0);
        self::assertGreaterThan(0, self::spread(self::atlases($inner)['sdf:32@100']));
    }

    public function testMagnifiedSdfTextKeepsANarrowEdge(): void
    {
        self::assertNotNull($this->ctx);
        $r = new VioRenderer2D($this->ctx);
        $r->loadFont('plain', self::fontPath());
        $r->loadSdfFont('sdf', self::fontPath());
        $wPlain = 0.0;
        $wSdf = 0.0;
        for ($frame = 0; $frame < 3; $frame++) {
            $r->beginFrame();
            $r->drawRect(0, 0, self::W, self::H, new Color(0.0, 0.0, 0.0, 1.0));
            foreach (['plain' => 4.0, 'sdf' => self::W / 2 + 4.0] as $font => $x) {
                $r->setFont($font);
                $r->pushTransform(new Mat3([4.0, 0.0, 0.0, 0.0, 4.0, 0.0, $x, 20.0, 1.0]));
                $r->drawText('Ag', 0.0, 24.0, 24.0, new Color(1.0, 1.0, 1.0, 1.0));
                $r->popTransform();
            }
            $r->endFrame();
        }
        $r->setFont('plain');
        $wPlain = $r->measureText('Ag', 24.0)->width;
        $r->setFont('sdf');
        $wSdf = $r->measureText('Ag', 24.0)->width;
        $px = (string) vio_read_pixels($this->ctx);
        self::assertSame(self::W * self::H * 4, strlen($px));

        [$pFull, $pEdge, $pInk] = self::ink($px, 0);
        [$sFull, $sEdge, $sInk] = self::ink($px, self::W / 2);
        $label = sprintf('plain %d/%d/%.0f, sdf %d/%d/%.0f', $pFull, $pEdge, $pInk, $sFull, $sEdge, $sInk);
        self::assertGreaterThan(300, $sFull, "{$label}: the SDF glyphs are drawn");
        self::assertEqualsWithDelta($pInk, $sInk, $pInk * 0.15, "{$label}: same ink");
        self::assertLessThan(0.5 * $pEdge / max(1, $pFull), $sEdge / max(1, $sFull), "{$label}: a far narrower edge band");
        self::assertEqualsWithDelta($wPlain, $wSdf, 1.0, 'the same measured width');
    }

    /** @return array{0: int, 1: int, 2: float} fully lit pixels, edge pixels, summed brightness of one half */
    private static function ink(string $px, int $x0): array
    {
        $full = 0;
        $edge = 0;
        $sum = 0.0;
        for ($y = 0; $y < self::H; $y++) {
            for ($x = $x0; $x < $x0 + self::W / 2; $x++) {
                $l = ord($px[($y * self::W + $x) * 4 + 1]);
                $sum += $l / 255.0;
                if ($l >= 215) {
                    $full++;
                } elseif ($l > 40) {
                    $edge++;
                }
            }
        }
        return [$full, $edge, $sum];
    }
}
