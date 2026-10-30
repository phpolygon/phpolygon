<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use PHPolygon\Component\SurfaceText;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\World;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Geometry\QuadMesh;
use PHPolygon\Locale\LocaleManager;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;
use PHPolygon\Prefab\TextPlate;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\DrawMeshInstanced;
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetDirectionalLight;
use PHPolygon\Rendering\CubemapRegistry;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ScreenSpaceAO;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\Text\SurfaceTextBaker;
use PHPolygon\Rendering\Text\SurfaceTextDirectoryStore;
use PHPolygon\Rendering\VioRenderer3D;
use PHPolygon\Rendering\VioTextureManager;
use PHPolygon\Scene\SceneBuilder;
use PHPolygon\System\SurfaceTextSystem;

/**
 * Surface text on the GPU, on every backend: the raw upload is not upside down
 * or mirrored, from the front and from behind; a back surface hides the mirrored
 * front; equal plates draw instanced;
 * the prebaked KTX2 shows the same image as the raster path; a language change
 * shows the new text and leaves the old texture to be freed.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioSurfaceTextRenderTest extends TestCase
{
    private const int W = 128;
    private const int H = 128;

    private ?\VioContext $ctx = null;

    private ?VioTextureManager $textures = null;

    private ?VioRenderer3D $renderer = null;

    private bool $firstImage = true;

    private function open(string $backend): void
    {
        $ctx = @vio_create($backend, ['width' => self::W, 'height' => self::H, 'headless' => true, 'vsync' => false]);
        if ($ctx === false) {
            $this->markTestSkipped("{$backend} unavailable");
        }
        if (!vio_supports_feature($ctx, VIO_FEATURE_3D_PIPELINE)) {
            vio_destroy($ctx);
            $this->markTestSkipped('backend has no 3D pipeline');
        }
        $this->ctx = $ctx;
        $this->textures = new VioTextureManager($ctx);
        MeshRegistry::clear();
        MaterialRegistry::clear();
        CubemapRegistry::clear();
        QuadMesh::unit();
        MeshRegistry::register('backdrop', BoxMesh::generate(10.0, 10.0, 0.1));
        MaterialRegistry::register('backdrop', new Material(albedo: new Color(0.0, 0.0, 0.0)));
    }

    protected function tearDown(): void
    {
        // Textures and pipelines must go before their context.
        $this->renderer = null;
        $this->textures = null;
        if ($this->ctx !== null) {
            vio_destroy($this->ctx);
            $this->ctx = null;
        }
        MeshRegistry::clear();
        MaterialRegistry::clear();
    }

    /** @return iterable<string, array{0: string}> */
    public static function backends(): iterable
    {
        foreach (['d3d12', 'd3d11', 'vulkan', 'opengl'] as $backend) {
            yield $backend => [$backend];
        }
    }

    /**
     * RGBA8 image, top row first: left half red, right half green, top quarter
     * blue over both. Any flip or mirror moves a colour to the wrong corner.
     */
    private static function marker(int $w, int $h): string
    {
        $rgba = '';
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $rgba .= $y < $h / 4 ? "\x00\x00\xFF\xFF" : ($x < $w / 2 ? "\xFF\x00\x00\xFF" : "\x00\xFF\x00\xFF");
            }
        }

        return $rgba;
    }

    /** @param list<object> $draws */
    private function image(array $draws, Vec3 $eye): string
    {
        self::assertNotNull($this->ctx);
        self::assertNotNull($this->textures);
        if ($this->renderer === null) {
            $this->renderer = new VioRenderer3D($this->ctx, self::W, self::H);
            $this->renderer->setTextureManager($this->textures);
            $this->renderer->applySettings(new GraphicsSettings(shadowQuality: ShadowQuality::Off, antiAliasing: AntiAliasing::Off, ambientOcclusion: ScreenSpaceAO::Off, bloom: false));
            $this->firstImage = true;
        }
        $renderer = $this->renderer;
        $list = new RenderCommandList();
        $list->add(new SetCamera(Mat4::lookAt($eye, new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 1.0, 0.0)), Mat4::perspective(deg2rad(50.0), 1.0, 0.05, 500.0)));
        $list->add(new SetAmbientLight(new Color(1.0, 1.0, 1.0), 1.0));
        $list->add(new SetDirectionalLight((new Vec3(0.0, -0.2, -1.0))->normalize(), new Color(1.0, 1.0, 1.0), 0.0));
        foreach ($draws as $draw) {
            $list->add($draw);
        }

        if ($this->firstImage) {
            // The OpenGL backend answers the very first off-screen image black.
            $this->firstImage = false;
            $renderer->renderToImage($list, self::W, self::H, new Color(0.0, 0.0, 0.0));
        }

        return $renderer->renderToImage($list, self::W, self::H, new Color(0.0, 0.0, 0.0));
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function pixel(string $rgba, int $x, int $y): array
    {
        $o = ($y * self::W + $x) * 4;

        return [ord($rgba[$o]), ord($rgba[$o + 1]), ord($rgba[$o + 2])];
    }

    private static function assertDominant(int $channel, array $pixel, string $where): void
    {
        $others = array_values(array_filter($pixel, static fn(int $c): bool => true, ARRAY_FILTER_USE_KEY));
        unset($others[$channel]);
        self::assertGreaterThan(max($others) + 30, $pixel[$channel], $where . ': ' . implode(',', $pixel));
    }

    private static function assertMarkerUpright(string $image, string $label): void
    {
        self::assertDominant(0, self::pixel($image, 44, 76), "{$label} lower left");
        self::assertDominant(1, self::pixel($image, 84, 76), "{$label} lower right");
        self::assertDominant(2, self::pixel($image, 44, 42), "{$label} top left");
        self::assertDominant(2, self::pixel($image, 84, 42), "{$label} top right");
    }

    private function markerMaterial(): void
    {
        self::assertNotNull($this->textures);
        self::assertNotNull($this->textures->loadRgba('marker', 64, 64, self::marker(64, 64)));
        MaterialRegistry::register('marker', new Material(albedo: Color::white(), albedoTexture: 'marker', useEnvironmentMap: false));
    }

    #[DataProvider('backends')]
    public function testRawUploadIsNeitherFlippedNorMirrored(string $backend): void
    {
        $this->open($backend);
        $this->markerMaterial();

        $front = Mat4::trs(new Vec3(0.0, 0.0, 0.0), Quaternion::identity(), new Vec3(1.0, 1.0, 1.0));
        self::assertMarkerUpright($this->image([new DrawMesh(QuadMesh::UNIT_ID, 'marker', $front)], new Vec3(0.0, 0.0, 2.0)), 'front');
    }

    #[DataProvider('backends')]
    public function testBackSurfaceReadsCorrectlyFromBehind(string $backend): void
    {
        $this->open($backend);
        $this->markerMaterial();

        $normal = new Vec3(0.0, 0.0, -1.0);
        $back = Mat4::trs(new Vec3(0.0, 0.0, 0.0), TextPlate::facing($normal), new Vec3(1.0, 1.0, 1.0));
        self::assertMarkerUpright($this->image([new DrawMesh(QuadMesh::UNIT_ID, 'marker', $back)], new Vec3(0.0, 0.0, -2.0)), 'back');
    }

    /**
     * The opaque pass culls nothing: from behind a lone quad shows its image
     * mirrored, dark because the normal faces away. A surface behind it, as
     * TextPlate builds, hides that.
     */
    #[DataProvider('backends')]
    public function testBackSurfaceHidesTheMirroredFront(string $backend): void
    {
        $this->open($backend);
        $this->markerMaterial();
        MaterialRegistry::register('blank', new Material(albedo: new Color(0.1, 0.1, 0.1), useEnvironmentMap: false));

        $unit = new Vec3(1.0, 1.0, 1.0);
        $front = new DrawMesh(QuadMesh::UNIT_ID, 'marker', Mat4::trs(new Vec3(0.0, 0.0, 0.02), Quaternion::identity(), $unit));
        $back = new DrawMesh(QuadMesh::UNIT_ID, 'blank', Mat4::trs(new Vec3(0.0, 0.0, -0.02), TextPlate::facing(new Vec3(0.0, 0.0, -1.0)), $unit));
        $eye = new Vec3(0.0, 0.0, -2.0);

        // Seen from behind, the red half of the image lies on the right: mirrored.
        $lone = self::pixel($this->image([$front], $eye), 84, 76);
        self::assertGreaterThan($lone[1] + 15, $lone[0], 'lone quad: ' . implode(',', $lone));

        $hidden = self::pixel($this->image([$front, $back], $eye), 84, 76);
        self::assertLessThan(10, abs($hidden[0] - $hidden[1]), 'the blank back covers it: ' . implode(',', $hidden));
    }

    #[DataProvider('backends')]
    public function testInstancedDrawShowsTheSameImage(string $backend): void
    {
        $this->open($backend);
        $this->markerMaterial();

        $matrices = [
            Mat4::trs(new Vec3(0.0, 0.0, 0.0), Quaternion::identity(), new Vec3(1.0, 1.0, 1.0)),
            Mat4::trs(new Vec3(5.0, 0.0, 0.0), Quaternion::identity(), new Vec3(1.0, 1.0, 1.0)),
        ];
        $image = $this->image([new DrawMeshInstanced(QuadMesh::UNIT_ID, 'marker', $matrices, castShadows: false)], new Vec3(0.0, 0.0, 2.0));

        self::assertMarkerUpright($image, 'instanced');
    }

    /**
     * The plate takes the colour of its carrier: a background given as linear
     * albedo reaches the screen like the same albedo on a plain material.
     */
    #[DataProvider('backends')]
    #[RequiresFunction('vio_text_bitmap')]
    public function testPlateMatchesTheSameAlbedoOnAPlainMaterial(string $backend): void
    {
        $this->open($backend);
        self::assertNotNull($this->textures);
        $wood = Color::srgb('#6B4A2B');
        $locale = new LocaleManager('en', 'en');
        $surface = new SurfaceText(text: '', width: 1.0, height: 1.0, background: $wood);
        MaterialRegistry::register('carrier', new Material(albedo: $wood, roughness: 0.75, useEnvironmentMap: false));
        $textMaterial = (new SurfaceTextBaker($locale, $this->textures))->materialFor($surface, 'en');

        $eye = new Vec3(0.0, 0.0, 2.0);
        $plate = self::pixel($this->image([new DrawMesh(QuadMesh::UNIT_ID, $textMaterial, Mat4::identity())], $eye), 64, 64);
        $carrier = self::pixel($this->image([new DrawMesh(QuadMesh::UNIT_ID, 'carrier', Mat4::identity())], $eye), 64, 64);

        foreach ([0, 1, 2] as $c) {
            self::assertEqualsWithDelta($carrier[$c], $plate[$c], 3.0, 'channel ' . $c);
        }
    }

    /** @return array{0: LocaleManager, 1: SurfaceText} */
    private static function signs(): array
    {
        $locale = new LocaleManager('en', 'en');
        $locale->add('en', ['sign' => 'III']);
        $locale->add('ar', ['sign' => 'ساحة']);
        $locale->add('ja', ['sign' => '訓練場']);

        return [$locale, new SurfaceText(textKey: 'sign', width: 1.0, height: 0.5, background: new Color(0.0, 0.0, 0.0), padding: 0.02, unlit: true)];
    }

    #[DataProvider('backends')]
    #[RequiresFunction('vio_text_bitmap')]
    public function testPrebakedFileShowsTheSameAsTheRasterPath(string $backend): void
    {
        $this->open($backend);
        self::assertNotNull($this->textures);
        if (!$this->textures->ktx2Available()) {
            $this->markTestSkipped('no KTX2 on this build');
        }
        [$locale, $surface] = self::signs();
        $dir = sys_get_temp_dir() . '/phpolygon_surface_text_' . uniqid();
        $store = new SurfaceTextDirectoryStore($dir);

        $raster = new SurfaceTextBaker($locale, $this->textures);
        $baked = new SurfaceTextBaker($locale, $this->textures, $store);
        $raster->prebake([$surface], ['ja'], $store);
        $id = $raster->textureId($surface, 'ja');

        $matrix = Mat4::trs(new Vec3(0.0, 0.0, 0.0), Quaternion::identity(), new Vec3(1.0, 0.5, 1.0));
        $eye = new Vec3(0.0, 0.0, 1.5);
        $first = $this->image([new DrawMesh(QuadMesh::UNIT_ID, $raster->materialFor($surface, 'ja'), $matrix)], $eye);
        $this->textures->unload($id);
        $second = $this->image([new DrawMesh(QuadMesh::UNIT_ID, $baked->materialFor($surface, 'ja'), $matrix)], $eye);

        array_map('unlink', (array) glob($dir . '/*'));
        rmdir($dir);

        self::assertTrue($this->textures->has($id));
        $different = 0;
        for ($i = 0, $n = strlen($first); $i < $n; $i += 4) {
            $different += abs(ord($first[$i]) - ord($second[$i])) > 24 ? 1 : 0;
        }
        self::assertLessThan(self::W * self::H / 100, $different, 'prebaked text differs from the raster path');
    }

    #[DataProvider('backends')]
    #[RequiresFunction('vio_text_bitmap')]
    public function testLanguageChangeShowsTheNewTextAndFreesTheOldTexture(string $backend): void
    {
        $this->open($backend);
        self::assertNotNull($this->textures);
        [$locale, $surface] = self::signs();
        $baker = new SurfaceTextBaker($locale, $this->textures);
        $system = new SurfaceTextSystem($locale, $baker);

        $builder = new SceneBuilder();
        TextPlate::place($builder, 'Sign', new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 0.0, 1.0), $surface, standoff: 0.0);
        $world = new World();
        $id = $builder->materialize($world)['Sign'];
        $world->getComponent($id, Transform3D::class)->worldMatrix = Mat4::trs(new Vec3(0.0, 0.0, 0.0), Quaternion::identity(), new Vec3(1.0, 0.5, 1.0));
        $surface = $world->getComponent($id, SurfaceText::class);

        $frame = function () use ($world, $id, $system): string {
            $system->update($world, 0.016);
            $material = $world->getComponent($id, \PHPolygon\Component\MeshRenderer::class)->materialId;
            $image = $this->image([new DrawMesh(QuadMesh::UNIT_ID, $material, $world->getComponent($id, Transform3D::class)->worldMatrix)], new Vec3(0.0, 0.0, 1.5));
            $system->render($world);
            return $image;
        };

        $latin = $frame();
        $latinTexture = $surface->textureId;
        self::assertGreaterThan(50, self::lit($latin));

        // Round trip through two languages, as often as the frames-in-flight window.
        $images = [];
        foreach (['ar', 'ja', 'ar', 'ja'] as $language) {
            $locale->setLocale($language);
            $images[$language] = $frame();
        }
        for ($i = 0; $i < 12; $i++) {
            $frame();
        }

        self::assertNotSame($latinTexture, $surface->textureId);
        self::assertFalse($this->textures->has($latinTexture));
        self::assertGreaterThan(50, self::lit($images['ar']));
        self::assertGreaterThan(50, self::lit($images['ja']));
        self::assertGreaterThan(50, self::differing($images['ar'], $images['ja']));
        self::assertGreaterThan(50, self::differing($latin, $images['ja']));
    }

    private static function lit(string $image): int
    {
        $count = 0;
        for ($i = 0, $n = strlen($image); $i < $n; $i += 4) {
            $count += ord($image[$i]) > 96 ? 1 : 0;
        }

        return $count;
    }

    private static function differing(string $a, string $b): int
    {
        $count = 0;
        for ($i = 0, $n = strlen($a); $i < $n; $i += 4) {
            $count += abs(ord($a[$i]) - ord($b[$i])) > 64 ? 1 : 0;
        }

        return $count;
    }
}
