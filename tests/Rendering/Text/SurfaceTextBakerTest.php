<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering\Text;

use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\TestCase;
use PHPolygon\Component\SurfaceText;
use PHPolygon\ECS\Serializer\AttributeSerializer;
use PHPolygon\Locale\LocaleManager;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\ProcModeRegistry;
use PHPolygon\Rendering\Text\SurfaceTextBaker;
use PHPolygon\Rendering\Text\SurfaceTextDirectoryStore;
use PHPolygon\Rendering\Text\SurfaceTextStoreInterface;
use PHPolygon\Rendering\Texture;
use PHPolygon\Testing\NullTextureManager;

final class SurfaceTextBakerTest extends TestCase
{
    private LocaleManager $locale;

    protected function setUp(): void
    {
        MaterialRegistry::clear();
        $this->locale = new LocaleManager('de', 'en');
        $this->locale->add('en', ['sign.open' => 'Open', 'sign.harbor' => 'Harbor']);
        $this->locale->add('de', ['sign.open' => 'Offen']);
        $this->locale->add('ja', ['sign.open' => '営業中']);
        $this->locale->add('zh-TW', ['sign.open' => '営業中']);
    }

    protected function tearDown(): void
    {
        MaterialRegistry::clear();
    }

    private function baker(?SurfaceTextStoreInterface $store = null, ?NullTextureManager $textures = null): SurfaceTextBaker
    {
        return new SurfaceTextBaker($this->locale, $textures ?? new NullTextureManager(), $store);
    }

    public function testKeyWinsAndFallsBackToTheFallbackLanguageThenTheKey(): void
    {
        $baker = $this->baker();
        self::assertSame('Offen', $baker->resolveText(new SurfaceText(text: 'x', textKey: 'sign.open'), 'de'));
        self::assertSame('Harbor', $baker->resolveText(new SurfaceText(textKey: 'sign.harbor'), 'de'));
        self::assertSame('sign.none', $baker->resolveText(new SurfaceText(textKey: 'sign.none'), 'de'));
        self::assertSame('Literal', $baker->resolveText(new SurfaceText(text: 'Literal'), 'ja'));
    }

    public function testTextureIdIsStableAndFollowsTheContent(): void
    {
        $baker = $this->baker();
        $base = static fn(): SurfaceText => new SurfaceText(text: 'Sign', width: 1.0, height: 0.2);
        $id = $baker->textureId($base(), 'de');

        self::assertSame($id, $baker->textureId($base(), 'de'), 'same content, same id');
        self::assertSame($id, $baker->textureId($base(), 'en'), 'a language that says the same with the same fonts shares it');

        self::assertNotSame($id, $baker->textureId(new SurfaceText(text: 'Other', width: 1.0, height: 0.2), 'de'));
        self::assertNotSame($id, $baker->textureId(new SurfaceText(text: 'Sign', width: 1.2, height: 0.2), 'de'));
        self::assertNotSame($id, $baker->textureId(new SurfaceText(text: 'Sign', width: 1.0, height: 0.2, color: new Color(1.0, 0.0, 0.0)), 'de'));
        self::assertNotSame($id, $baker->textureId(new SurfaceText(text: 'Sign', width: 1.0, height: 0.2, background: new Color(1.0, 0.0, 0.0)), 'de'));
        self::assertNotSame($id, $baker->textureId(new SurfaceText(text: 'Sign', width: 1.0, height: 0.2, fontId: 'regular'), 'de'));
    }

    public function testRegionalGlyphsSplitTheTextureOfTheSameString(): void
    {
        $baker = $this->baker();
        $surface = new SurfaceText(textKey: 'sign.open');

        self::assertNotSame($baker->textureId($surface, 'ja'), $baker->textureId($surface, 'zh-TW'));
    }

    public function testTextureEdgesAreMultiplesOfFour(): void
    {
        [$w, $h] = $this->baker()->pixelSize(new SurfaceText(width: 2.21, height: 0.401, pixelsPerMetre: 256.0));

        self::assertSame(568, $w);
        self::assertSame(104, $h);
    }

    public function testAClampedEdgeKeepsTheAspectSoTheTextIsNotStretched(): void
    {
        [$w, $h] = $this->baker()->pixelSize(new SurfaceText(width: 12.0, height: 2.0));

        self::assertSame(2048, $w);
        self::assertEqualsWithDelta(6.0, $w / $h, 0.05);

        [$tallW, $tallH] = $this->baker()->pixelSize(new SurfaceText(width: 1.0, height: 16.0));
        self::assertSame(2048, $tallH);
        self::assertEqualsWithDelta(16.0, $tallH / $tallW, 0.3);
    }

    public function testSceneJsonWithMissingKeysTakesTheDefaults(): void
    {
        $partial = (new AttributeSerializer())->fromArray(['text' => 'Sign'], SurfaceText::class);
        self::assertInstanceOf(SurfaceText::class, $partial);
        $baker = $this->baker();

        self::assertSame($baker->textureId(new SurfaceText(text: 'Sign'), 'de'), $baker->textureId($partial, 'de'));
        self::assertSame(SurfaceTextBaker::plainMaterial(new SurfaceText()), SurfaceTextBaker::plainMaterial($partial));
    }

    public function testPrebakedFileWinsOverTheRasterizer(): void
    {
        $textures = new class extends NullTextureManager {
            /** @var array<string, string|null> */
            public array $paths = [];

            public function load(string $id, ?string $path = null): Texture
            {
                $this->paths[$id] = $path;
                return parent::load($id, $path);
            }
        };
        $store = new class implements SurfaceTextStoreInterface {
            public function path(string $textureId): ?string
            {
                return "/baked/{$textureId}.ktx2";
            }

            public function store(string $textureId, string $rgba, int $width, int $height): void {}
        };
        $baker = $this->baker($store, $textures);
        $surface = new SurfaceText(text: 'Sign');

        $material = MaterialRegistry::get($baker->materialFor($surface, 'de'));

        self::assertNotNull($material);
        $id = $baker->textureId($surface, 'de');
        self::assertSame($id, $material->albedoTexture);
        self::assertSame(1.0, $material->albedo->r, 'the texture carries the colours');
        self::assertSame("/baked/{$id}.ktx2", $textures->paths[$id]);
    }

    public function testNoTextureMeansAPlainPlateInTheBackgroundColour(): void
    {
        $textures = new class extends NullTextureManager {
            public function loadRgba(string $id, int $width, int $height, string $rgba): ?Texture
            {
                return null;
            }
        };
        $background = new Color(0.1, 0.2, 0.3);
        $id = $this->baker(null, $textures)->materialFor(new SurfaceText(text: 'Sign', background: $background), 'de');
        $material = MaterialRegistry::get($id);

        self::assertNotNull($material);
        self::assertNull($material->albedoTexture);
        self::assertSame($background, $material->albedo);
        self::assertSame($id, SurfaceTextBaker::plainMaterial(new SurfaceText(background: $background)));
    }

    public function testBaseMaterialKeepsItsSurfaceAndUnlitTakesTheUnlitShader(): void
    {
        MaterialRegistry::register('painted_steel', new Material(roughness: 0.3, metallic: 0.9, albedoTexture: 'old'));
        $plain = MaterialRegistry::get(SurfaceTextBaker::plainMaterial(new SurfaceText(baseMaterial: 'painted_steel')));
        self::assertNotNull($plain);
        self::assertSame(0.3, $plain->roughness);
        self::assertSame(0.9, $plain->metallic);
        self::assertNull($plain->albedoTexture);

        $lit = SurfaceTextBaker::plainMaterial(new SurfaceText());
        $unlit = SurfaceTextBaker::plainMaterial(new SurfaceText(unlit: true));
        self::assertNotSame($lit, $unlit);
        self::assertSame('unlit', MaterialRegistry::get($unlit)?->shader);
    }

    /**
     * The vio renderer does not read Material::$shader and the engine ships no
     * unlit proc mode: an unlit surface is one the game can map by id prefix.
     */
    public function testUnlitMaterialsCarryAPrefixForAProcMode(): void
    {
        ProcModeRegistry::map(SurfaceTextBaker::UNLIT_MATERIAL_PREFIX, 77);
        $store = new class implements SurfaceTextStoreInterface {
            public function path(string $textureId): ?string
            {
                return "/baked/{$textureId}.ktx2";
            }

            public function store(string $textureId, string $rgba, int $width, int $height): void {}
        };
        try {
            $baker = $this->baker($store);
            $unlit = new SurfaceText(text: 'Sign', unlit: true);

            self::assertSame(77, ProcModeRegistry::resolve($baker->materialFor($unlit, 'de')));
            self::assertSame(77, ProcModeRegistry::resolve(SurfaceTextBaker::plainMaterial($unlit)));
            self::assertSame(0, ProcModeRegistry::resolve($baker->materialFor(new SurfaceText(text: 'Sign'), 'de')));
            self::assertSame(
                $baker->textureId($unlit, 'de'),
                MaterialRegistry::get($baker->materialFor($unlit, 'de'))?->albedoTexture,
                'an unlit surface shares the texture of a lit one',
            );
        } finally {
            ProcModeRegistry::clear();
        }
    }

    #[RequiresFunction('vio_text_bitmap')]
    public function testFitPlateFromWideToTwoLines(): void
    {
        // A label too long for one line of a narrow plate: 4.3 cm on one line,
        // 6 cm on two.
        $text = 'Siap diselesaikan untuk tugas berikutnya';
        $one = new SurfaceText(text: $text, width: 0.96, height: 0.26, maxLines: 1);
        $two = new SurfaceText(text: $text, width: 0.96, height: 0.26, maxLines: 2);
        $baker = $this->baker();

        self::assertLessThan(0.05, $baker->fontSizeMetres($one, 'id'));
        self::assertTrue($baker->layout($one, 'id')?->belowMinimum);
        self::assertGreaterThan(0.06, $baker->fontSizeMetres($two, 'id'));
        self::assertCount(2, $baker->layout($two, 'id')?->lines ?? []);
        self::assertFalse($baker->layout($two, 'id')?->belowMinimum);
    }

    #[RequiresFunction('vio_text_bitmap')]
    public function testPrebakeWritesEveryDistinctTextureOnceAndFilesRoundTrip(): void
    {
        $dir = sys_get_temp_dir() . '/phpolygon_surface_text_' . uniqid();
        $store = new SurfaceTextDirectoryStore($dir);
        $baker = $this->baker();
        $surfaces = [new SurfaceText(textKey: 'sign.open', width: 0.5, height: 0.125), new SurfaceText(textKey: 'sign.open', width: 0.5, height: 0.125)];

        self::assertSame(2, $baker->prebake($surfaces, ['de', 'ja'], $store));
        self::assertSame(0, $baker->prebake($surfaces, ['de', 'ja'], $store), 'already in the store');

        $path = $store->path($baker->textureId($surfaces[0], 'de'));
        self::assertNotNull($path);
        self::assertLessThan(128 * 32 * 4, (int) filesize($path), 'zlib shrinks a flat plate');

        foreach ((array) glob($dir . '/*') as $file) {
            unlink((string) $file);
        }
        rmdir($dir);
    }

    public function testMipChainHalvesDownToOnePixel(): void
    {
        $chain = SurfaceTextDirectoryStore::mipChain(str_repeat(pack('C4', 200, 100, 0, 255), 8 * 2), 8, 2);

        self::assertSame([64, 16, 8, 4], array_map('strlen', $chain));
        self::assertSame(pack('C4', 200, 100, 0, 255), $chain[3]);
    }
}
