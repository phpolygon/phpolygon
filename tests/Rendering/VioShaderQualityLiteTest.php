<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\AddPointLight;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetDirectionalLight;
use PHPolygon\Rendering\Command\SetFog;
use PHPolygon\Rendering\Command\SetShader;
use PHPolygon\Rendering\CubemapRegistry;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\ProcModeRegistry;
use PHPolygon\Rendering\ProcModeShaderRegistry;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ScreenSpaceAO;
use PHPolygon\Rendering\Quality\ShaderQuality;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\ShaderPath;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * ShaderQuality::Lite: the reduced lit program of the mesh shader. It must compile on
 * every vio backend, look lit (the sun shades it, unlike Unlit), keep fog and emission,
 * keep proc-mode materials drawing, and leave the Full image exactly as it was.
 *
 * Separate processes: a D3D11 context opened after another test's loses its device.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
#[Group('gpu')]
#[RunTestsInSeparateProcesses]
final class VioShaderQualityLiteTest extends TestCase
{
    private const int W = 128;
    private const int H = 96;
    private const array CLEAR = [0.1, 0.2, 0.4];

    private ?\VioContext $ctx = null;
    private ?VioRenderer3D $renderer = null;

    protected function setUp(): void
    {
        MeshRegistry::clear();
        MaterialRegistry::clear();
        CubemapRegistry::clear();
        ProcModeRegistry::clear();
        ProcModeShaderRegistry::clear();
        MeshRegistry::register('box', BoxMesh::generate(2.0, 2.0, 2.0));
        MeshRegistry::register('floor', BoxMesh::generate(24.0, 0.2, 24.0));
        MaterialRegistry::register('stone', new Material(albedo: new Color(0.7, 0.7, 0.7), roughness: 0.8));
        MaterialRegistry::register('brick', new Material(albedo: new Color(0.7, 0.4, 0.3), roughness: 0.8, normalPattern: 'bricks'));
        MaterialRegistry::register('lamp', new Material(albedo: new Color(0.0, 0.0, 0.0), emission: new Color(2.0, 0.0, 0.0)));
        MaterialRegistry::register('tint_red', new Material(albedo: new Color(0.8, 0.8, 0.8)));
        MaterialRegistry::register('flat_mode', new Material(albedo: new Color(0.2, 0.6, 0.9)));
        ProcModeRegistry::map('tint_red', 3);
        ProcModeRegistry::map('flat_mode', 5);
        ProcModeShaderRegistry::registerGlsl(
            3,
            "vec3 tintRed(vec3 c) { return c * vec3(1.0, 0.2, 0.2); }\n",
            "    if (u_proc_mode == 3) {\n        albedo = tintRed(texAlbedo);\n",
        );
        ProcModeShaderRegistry::registerGlsl(
            5,
            '',
            "    } else if (u_proc_mode == 5) {\n        frag_color = outputColor(texAlbedo * 0.5, alpha);\n        return;\n    } else",
        );
    }

    protected function tearDown(): void
    {
        putenv('PHPOLYGON_VIO_MRT');
        ProcModeRegistry::clear();
        ProcModeShaderRegistry::clear();
        MaterialRegistry::clear();
        MeshRegistry::clear();
        $this->renderer = null;
        if ($this->ctx !== null) {
            vio_destroy($this->ctx);
            $this->ctx = null;
        }
    }

    /** @return array<string, array{0: string}> */
    public static function backends(): array
    {
        return [
            'd3d12' => ['d3d12'],
            'd3d11' => ['d3d11'],
            'vulkan' => ['vulkan'],
            'opengl' => ['opengl'],
            'metal' => ['metal'],
        ];
    }

    /** The program compiles on the backend and the frame draws with it; the image is lit. */
    #[DataProvider('backends')]
    public function testLiteCompilesAndDrawsALitSceneOnEveryBackend(string $backend): void
    {
        $this->context($backend);
        $lite = $this->render(ShaderQuality::Lite, $this->boxScene('stone'));
        self::assertSame(ProcModeShaderRegistry::LITE_VARIANT, $this->renderer?->frameBaseProgramVariant(), $backend . ': the frame draws with the Lite program');
        [$min, $max, $count] = self::luminanceRange($lite);
        self::assertGreaterThan(300, $count, 'the box is on screen');
        self::assertGreaterThan(0.05, $max - $min, $backend . ': the sun shades the box faces differently (top vs sides)');
    }

    public function testUnlitIsFlatWhereLiteIsShaded(): void
    {
        $this->context('auto');
        $list = $this->boxScene('stone');
        [$litMin, $litMax] = self::luminanceRange($this->render(ShaderQuality::Lite, $list));
        [$flatMin, $flatMax] = self::luminanceRange($this->render(ShaderQuality::Unlit, $list, true));
        self::assertLessThan(0.03, $flatMax - $flatMin, 'Unlit: one colour on every face');
        self::assertGreaterThan($flatMax - $flatMin + 0.05, $litMax - $litMin, 'Lite: shaded');
    }

    public function testTheSunSideIsBrighterThanTheShadowSide(): void
    {
        $this->context('auto');
        // The camera looks down on the box: its top face is the brightest thing in the image when the
        // light comes from above, and a dark one when the light comes from below (the face turns away).
        $above = $this->render(ShaderQuality::Lite, $this->boxScene('stone', sun: new Vec3(-0.3, -1.0, -0.2), ambient: 0.1));
        $below = $this->render(ShaderQuality::Lite, $this->boxScene('stone', sun: new Vec3(-0.3, 1.0, -0.2), ambient: 0.1));
        [, $maxAbove] = self::luminanceRange($above);
        [, $maxBelow] = self::luminanceRange($below);
        self::assertGreaterThan($maxBelow + 0.15, $maxAbove, 'the face towards the sun is brighter than the faces turned away');
    }

    public function testFogApplies(): void
    {
        $this->context('auto');
        $near = $this->render(ShaderQuality::Lite, $this->boxScene('stone', fog: [1.0, 4000.0]));
        $far = $this->render(ShaderQuality::Lite, $this->boxScene('stone', fog: [1.0, 9.0]));
        $nearPx = self::px($near, 64, 40);
        $farPx = self::px($far, 64, 40);
        // The fog colour is red: with a short fog range the box loses green and blue to it, with a long range it keeps its colour.
        self::assertGreaterThan($farPx[1] + 40, $nearPx[1], 'the fogged box has lost its green');
        self::assertGreaterThan($farPx[2] + 40, $nearPx[2], 'and its blue');
    }

    public function testEmissionShows(): void
    {
        $this->context('auto');
        $image = $this->render(ShaderQuality::Lite, $this->boxScene('lamp'));
        $p = self::px($image, 64, 50);
        self::assertGreaterThan(150, $p[0], 'the emission is the colour of the lamp');
        self::assertLessThan(60, $p[1]);
        self::assertLessThan(60, $p[2]);
    }

    public function testALocalLightLightsTheBox(): void
    {
        $this->context('auto');
        $dark = $this->boxScene('stone', sun: null, ambient: 0.02);
        $lit = $this->boxScene('stone', sun: null, ambient: 0.02, pointLights: 1);
        $a = self::luminance(self::px($this->render(ShaderQuality::Lite, $dark), 64, 50));
        $b = self::luminance(self::px($this->render(ShaderQuality::Lite, $lit), 64, 50));
        self::assertGreaterThan($a + 0.08, $b, 'a point light adds light in Lite');
    }

    /** The single-tap sun shadow: a box on a floor darkens the floor under it (shadow maps on). */
    #[DataProvider('backends')]
    public function testTheSunShadowDarkensTheFloorInLite(string $backend): void
    {
        if ($backend === 'opengl') {
            $this->markTestSkipped('shadow maps on the OpenGL backend are not asserted here');
        }
        $this->context($backend);
        // The light travels towards +X: the box's shadow falls on the floor on the camera's side.
        $sun = new Vec3(0.7, -1.0, 0.0);
        $shadowed = $this->render(ShaderQuality::Lite, $this->boxScene('stone', sun: $sun, floor: true, ambient: 0.2), shadows: ShadowQuality::High);
        $open = $this->render(ShaderQuality::Lite, $this->boxScene('stone', sun: $sun, floor: true, ambient: 0.2), shadows: ShadowQuality::Off);
        $darker = 0;
        for ($i = 0, $n = self::W * self::H; $i < $n; $i++) {
            $a = self::luminance(array_values((array) unpack('C4', substr($open, $i * 4, 4))));
            $b = self::luminance(array_values((array) unpack('C4', substr($shadowed, $i * 4, 4))));
            if ($a - $b > 0.08) {
                $darker++;
            }
        }
        self::assertGreaterThan(40, $darker, $backend . ': the shadow of the box darkens the floor (pixels darker with the shadow map)');
    }

    /** More than eight point lights: the light-grid branch of the Lite program. */
    public function testManyPointLightsLightTheScene(): void
    {
        $this->context('auto');
        $few = $this->render(ShaderQuality::Lite, $this->boxScene('stone', sun: null, ambient: 0.02));
        $many = $this->render(ShaderQuality::Lite, $this->boxScene('stone', sun: null, ambient: 0.02, pointLights: 14));
        self::assertGreaterThan(self::luminance(self::px($few, 64, 50)) + 0.05, self::luminance(self::px($many, 64, 50)), 'the lights of a crowded scene reach the box');
    }

    /** TAA: the motion twin of the Lite program. */
    public function testLiteDrawsWithTemporalAntiAliasing(): void
    {
        $this->context('auto');
        $list = $this->boxScene('stone');
        $image = '';
        for ($i = 0; $i < 3; $i++) {
            $image = $this->render(ShaderQuality::Lite, $list, aa: AntiAliasing::Taa);
        }
        [$min, $max, $count] = self::luminanceRange($image);
        self::assertGreaterThan(300, $count);
        self::assertGreaterThan(0.05, $max - $min);
        self::assertSame(ProcModeShaderRegistry::LITE_VARIANT, $this->renderer?->frameBaseProgramVariant());
    }

    /** Proc-mode materials (an own colour branch, one that ends the fragment early) keep drawing in Lite. */
    public function testProcModeMaterialsStillDrawInLite(): void
    {
        $this->context('auto');
        $list = $this->boxScene('tint_red');
        $p = self::px($this->render(ShaderQuality::Lite, $list), 64, 50);
        self::assertGreaterThan($p[1] + 30, $p[0], 'the proc-mode tint reached the pixel: red');

        $early = self::px($this->render(ShaderQuality::Lite, $this->boxScene('flat_mode')), 64, 50);
        self::assertGreaterThan(20, $early[0] + $early[1] + $early[2], 'the early-return arm drew (not black)');
        self::assertNotSame([255, 0, 255], array_slice($early, 0, 3), 'not the error colour');
    }

    /** A material with a normal pattern (left out of Lite) still draws as a lit surface. */
    public function testPatternMaterialsDrawAsLitSurfaces(): void
    {
        $this->context('auto');
        [$min, $max] = self::luminanceRange($this->render(ShaderQuality::Lite, $this->boxScene('brick')));
        self::assertGreaterThan(0.08, $max - $min);
        self::assertContains('PHPOLYGON_OMIT_NORMAL_PATTERNS', ShaderPath::liteOmissions());
    }

    /** The Lite program on the MRT path: the same tier, composite included. */
    public function testLiteOnTheMrtPath(): void
    {
        putenv('PHPOLYGON_VIO_MRT=1');
        $this->context('auto');
        $image = $this->render(ShaderQuality::Lite, $this->boxScene('stone'), ao: ScreenSpaceAO::Medium);
        [$min, $max, $count] = self::luminanceRange($image);
        self::assertGreaterThan(300, $count);
        self::assertGreaterThan(0.05, $max - $min);
    }

    /** The tier is read from the settings on every frame: it holds, and it goes away again. */
    public function testTheTierHoldsAcrossFramesAndLeavesNoTraceOnFull(): void
    {
        $this->context('auto');
        $list = $this->boxScene('stone');
        $first = $this->render(ShaderQuality::Full, $list);
        self::assertSame(self::fullBase($this->renderer), $this->renderer?->frameBaseProgramVariant());

        $renderer = $this->renderer;
        self::assertNotNull($renderer);
        $renderer->applySettings($this->settings(ShaderQuality::Lite));
        for ($i = 0; $i < 3; $i++) {
            $renderer->renderToImage($list, self::W, self::H, $this->clear());
            self::assertSame(ProcModeShaderRegistry::LITE_VARIANT, $renderer->frameBaseProgramVariant(), 'frame ' . $i);
        }
        $renderer->applySettings($this->settings(ShaderQuality::Full));
        $again = $renderer->renderToImage($list, self::W, self::H, $this->clear());
        self::assertSame(self::fullBase($renderer), $renderer->frameBaseProgramVariant());
        self::assertSame(md5($first), md5($again), 'Full after Lite: every pixel as before');
    }

    /** A game's own shader override wins over the tier. */
    public function testAShaderOverrideBeatsTheTier(): void
    {
        $this->context('auto');
        $list = $this->boxScene('stone', override: 'unlit');
        $this->render(ShaderQuality::Lite, $list);
        self::assertSame(ProcModeShaderRegistry::BASE_VARIANT, $this->renderer?->frameBaseProgramVariant());
    }

    /** The program a Full frame draws base materials with: the grid-less twin where the backend has the light grid. */
    private static function fullBase(?VioRenderer3D $renderer): string
    {
        return $renderer !== null && $renderer->maxPointLights() > 8 ? ProcModeShaderRegistry::NOGRID_VARIANT : ProcModeShaderRegistry::BASE_VARIANT;
    }

    private function context(string $backend): void
    {
        $cache = sys_get_temp_dir() . '/phpolygon-test-shader-cache';
        $ctx = $backend === 'auto'
            ? VioTestContext::create(self::W, self::H, ['shader_cache' => $cache])
            : @vio_create($backend, ['width' => self::W, 'height' => self::H, 'headless' => true, 'vsync' => false, 'shader_cache' => $cache]);
        if ($ctx === null || $ctx === false) {
            $this->markTestSkipped("vio_create($backend, headless) unavailable");
        }
        if ($backend !== 'auto' && vio_backend_name($ctx) !== $backend) {
            vio_destroy($ctx);
            $this->markTestSkipped("$backend unavailable");
        }
        $this->ctx = $ctx;
    }

    private function clear(): Color
    {
        return new Color(self::CLEAR[0], self::CLEAR[1], self::CLEAR[2], 1.0);
    }

    private function settings(
        ShaderQuality $tier,
        ScreenSpaceAO $ao = ScreenSpaceAO::Off,
        ShadowQuality $shadows = ShadowQuality::Off,
        AntiAliasing $aa = AntiAliasing::Off,
    ): GraphicsSettings {
        return new GraphicsSettings(
            shadowQuality: $shadows,
            ambientOcclusion: $ao,
            antiAliasing: $aa,
            bloom: false,
            shaderQuality: $tier,
        );
    }

    private function render(
        ShaderQuality $tier,
        RenderCommandList $list,
        bool $overrideFromTier = false,
        ScreenSpaceAO $ao = ScreenSpaceAO::Off,
        ShadowQuality $shadows = ShadowQuality::Off,
        AntiAliasing $aa = AntiAliasing::Off,
    ): string {
        self::assertNotNull($this->ctx);
        $this->renderer = new VioRenderer3D($this->ctx, self::W, self::H);
        $this->renderer->applySettings($this->settings($tier, $ao, $shadows, $aa));
        if ($overrideFromTier && $tier->shaderId() !== null) {
            // What the manager does for an override tier: a standing SetShader.
            $list = self::withShader($list, $tier->shaderId());
        }
        $rgba = $this->renderer->renderToImage($list, self::W, self::H, $this->clear());
        self::assertSame(self::W * self::H * 4, strlen($rgba));
        return $rgba;
    }

    private static function withShader(RenderCommandList $list, string $shaderId): RenderCommandList
    {
        $out = new RenderCommandList();
        $out->add(new SetShader($shaderId));
        foreach ($list->getCommands() as $command) {
            $out->add($command);
        }
        return $out;
    }

    /**
     * One box on a floor-less background, seen from above the front. Default sun travels along
     * (-0.4, -1, -0.3): the top face is the most lit one.
     *
     * @param array{0: float, 1: float}|null $fog near, far (red fog)
     */
    private function boxScene(
        string $material,
        ?Vec3 $sun = new Vec3(-0.4, -1.0, -0.3),
        ?Vec3 $cameraEye = null,
        ?array $fog = null,
        float $ambient = 0.4,
        int $pointLights = 0,
        ?string $override = null,
        bool $floor = false,
    ): RenderCommandList {
        $list = new RenderCommandList();
        if ($override !== null) {
            $list->add(new SetShader($override));
        }
        $list->add(new SetCamera(
            Mat4::lookAt($cameraEye ?? new Vec3(2.5, 4.0, 6.0), new Vec3(0, 0, 0), new Vec3(0, 1, 0)),
            Mat4::perspective(deg2rad(50.0), self::W / self::H, 0.1, 100.0),
        ));
        $list->add(new SetAmbientLight(new Color(0.4, 0.4, 0.45), $ambient));
        if ($sun !== null) {
            $list->add(new SetDirectionalLight($sun, new Color(1.0, 0.95, 0.9), 1.5));
        }
        for ($i = 0; $i < $pointLights; $i++) {
            $list->add(new AddPointLight(new Vec3(-3.0 + $i * 0.5, 3.0, 4.0), new Color(1.0, 1.0, 1.0), 2.0, 14.0));
        }
        if ($fog !== null) {
            $list->add(new SetFog(new Color(0.9, 0.1, 0.1), $fog[0], $fog[1]));
        }
        if ($floor) {
            $list->add(new DrawMesh('floor', 'stone', Mat4::translation(0.0, -1.1, 0.0)));
        }
        $list->add(new DrawMesh('box', $material, Mat4::identity()));
        return $list;
    }

    /** @return list<int> */
    private static function px(string $rgba, int $x, int $y): array
    {
        return array_values((array) unpack('C4', substr($rgba, (($y * self::W) + $x) * 4, 4)));
    }

    /** @param list<int> $p */
    private static function luminance(array $p): float
    {
        return (0.2126 * $p[0] + 0.7152 * $p[1] + 0.0722 * $p[2]) / 255.0;
    }

    /**
     * Luminance range over the pixels that are not the clear colour.
     *
     * @return array{0: float, 1: float, 2: int} min, max, pixel count
     */
    private static function luminanceRange(string $rgba): array
    {
        $min = 1.0;
        $max = 0.0;
        $count = 0;
        $clear = [(int) round(self::CLEAR[0] * 255), (int) round(self::CLEAR[1] * 255), (int) round(self::CLEAR[2] * 255)];
        for ($i = 0, $n = self::W * self::H; $i < $n; $i++) {
            $p = array_values((array) unpack('C4', substr($rgba, $i * 4, 4)));
            if (abs($p[0] - $clear[0]) + abs($p[1] - $clear[1]) + abs($p[2] - $clear[2]) < 6) {
                continue;
            }
            $l = self::luminance($p);
            $min = min($min, $l);
            $max = max($max, $l);
            $count++;
        }
        return [$min, $max, $count];
    }
}
