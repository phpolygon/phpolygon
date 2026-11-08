<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\TestCase;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\NormalPattern;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\ShaderPath;
use PHPolygon\Rendering\SplatLayer;
use PHPolygon\Rendering\SurfacePattern;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * Automatic shader-path omission: the mesh programs leave out every material-driven
 * path no registered material needs; each path can be left out alone and still
 * compiles and draws; a material registered later brings its path back.
 */
final class VioAutomaticOmissionTest extends TestCase
{
    private const W = 64;
    private const H = 48;

    private ?\VioContext $ctx = null;

    protected function setUp(): void
    {
        $ctx = VioTestContext::create(self::W, self::H, ['shader_cache' => sys_get_temp_dir() . '/phpolygon-test-shader-cache']);
        if ($ctx === null) {
            $this->markTestSkipped('vio_create(headless) unavailable');
        }
        $this->ctx = $ctx;
        MeshRegistry::clear();
        MaterialRegistry::clear();
        MeshRegistry::register('box', BoxMesh::generate(1.0, 1.0, 1.0));
    }

    protected function tearDown(): void
    {
        MaterialRegistry::clear();
        MeshRegistry::clear();
        if ($this->ctx !== null) {
            vio_destroy($this->ctx);
            $this->ctx = null;
        }
    }

    public function testPlainMaterialsLeaveOutEveryAutomaticPath(): void
    {
        MaterialRegistry::register('plain', new Material(albedo: new Color(0.6, 0.3, 0.2), useEnvironmentMap: false));
        $r = $this->renderer();
        $this->draw($r, 'plain');
        self::assertEqualsCanonicalizing(
            array_map(static fn (ShaderPath $p): string => $p->define(), ShaderPath::automatic()),
            $r->automaticShaderOmissions(),
        );
    }

    public function testEachPathCanBeLeftOutAlone(): void
    {
        foreach (ShaderPath::automatic() as $left) {
            MaterialRegistry::clear();
            foreach (ShaderPath::automatic() as $path) {
                if ($path !== $left) {
                    MaterialRegistry::register('m_' . $path->value, self::needing($path));
                }
            }
            $r = $this->renderer();
            foreach (MaterialRegistry::ids() as $id) {
                $this->draw($r, $id);
            }
            self::assertSame([$left->define()], $r->automaticShaderOmissions(), "only {$left->value} left out");
        }
    }

    public function testALaterMaterialBringsItsPathBack(): void
    {
        MaterialRegistry::register('plain', new Material(albedo: new Color(0.6, 0.3, 0.2), useEnvironmentMap: false));
        $r = $this->renderer();
        $this->draw($r, 'plain');
        self::assertContains(ShaderPath::Glass->define(), $r->automaticShaderOmissions());

        MaterialRegistry::register('pane', self::needing(ShaderPath::Glass));
        $this->draw($r, 'pane');
        self::assertNotContains(ShaderPath::Glass->define(), $r->automaticShaderOmissions());

        // Taking the material away again costs no rebuild: the path stays in.
        MaterialRegistry::register('pane', new Material(albedo: new Color(0.5, 0.5, 0.5), useEnvironmentMap: false));
        $this->draw($r, 'plain');
        self::assertNotContains(ShaderPath::Glass->define(), $r->automaticShaderOmissions());
    }

    private function renderer(): VioRenderer3D
    {
        self::assertNotNull($this->ctx);
        $r = new VioRenderer3D($this->ctx, self::W, self::H);
        $r->applySettings(new GraphicsSettings(shadowQuality: ShadowQuality::Off, antiAliasing: AntiAliasing::Off, bloom: false, hdr: false));
        return $r;
    }

    private function draw(VioRenderer3D $r, string $materialId): void
    {
        $l = new RenderCommandList();
        $l->add(new SetCamera(Mat4::lookAt(new Vec3(0.0, 1.0, 3.0), new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 1.0, 0.0)), Mat4::perspective(deg2rad(60.0), self::W / self::H, 0.1, 50.0)));
        $l->add(new SetAmbientLight(new Color(1.0, 1.0, 1.0), 0.8));
        $l->add(new DrawMesh('box', $materialId, Mat4::identity()));
        $px = $r->renderToImage($l, self::W, self::H, new Color(0.0, 0.0, 0.0));
        self::assertSame(self::W * self::H * 4, strlen($px), "{$materialId} draws");
    }

    private static function needing(ShaderPath $path): Material
    {
        $c = new Color(0.6, 0.4, 0.3);
        $m = match ($path) {
            ShaderPath::NormalPatterns => new Material(albedo: $c, normalPattern: NormalPattern::BRICKS),
            ShaderPath::SurfacePatterns => new Material(albedo: $c, surfacePattern: SurfacePattern::RUST),
            ShaderPath::Grass => new Material(albedo: $c, grass: true),
            ShaderPath::Subsurface => new Material(albedo: $c, subsurfaceColor: new Color(0.9, 0.3, 0.2), subsurfaceStrength: 0.5),
            ShaderPath::Clearcoat => new Material(albedo: $c, clearcoat: 0.6),
            ShaderPath::Splat => new Material(albedo: $c, splatLayers: [new SplatLayer(new Color(0.3, 0.5, 0.2))]),
            ShaderPath::Triplanar => new Material(albedo: $c, triplanar: 1.0),
            ShaderPath::Flipbook => new Material(albedo: $c, flipbook: [2, 2]),
            ShaderPath::Fur => new Material(albedo: $c, furLength: 0.05),
            ShaderPath::Hair => new Material(albedo: $c, hair: true),
            ShaderPath::Wetness => new Material(albedo: $c, wetness: 0.6),
            ShaderPath::Glass => new Material(albedo: $c, alpha: 0.4, glass: 0.8),
            ShaderPath::MaterialLook => new Material(albedo: $c, sheen: 0.5),
            ShaderPath::FigureLook => new Material(albedo: $c, rimLight: 0.5),
            ShaderPath::EmissiveLook => new Material(albedo: $c, emission: new Color(1.0, 0.2, 0.6), neonTube: true),
            ShaderPath::SoftParticle => new Material(albedo: $c, alpha: 0.5, softParticle: 0.5),
            ShaderPath::Refraction => new Material(albedo: $c, alpha: 0.5, refraction: 0.5),
            default => throw new \LogicException($path->value),
        };
        self::assertTrue($path->neededBy($m), "{$path->value} fixture");
        return $m;
    }
}
