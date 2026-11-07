<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Impostor;

use PHPolygon\Geometry\MeshData;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetShader;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ScreenSpaceAO;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * Bakes a mesh into an impostor (shader plan 9.1): $views orthographic pictures
 * around the vertical axis, one albedo texture each, with coverage as alpha.
 *
 * Each view is rendered twice, in front of black and of white; the difference is the
 * coverage, the black picture divided by it the colour. The renderer's output is a
 * display image (lit, tonemapped, gamma-encoded), so a grey ramp rendered under the
 * same flat light first gives the curve from displayed value back to albedo - the
 * texture holds albedo, lit again by the scene at run time.
 *
 * With $normals each view also gets a normal map (shader plan 9.1, impostor normals):
 * the mesh drawn with the 'normals' shader, its world normals turned into the view's
 * frame (right, up, towards the camera) - the frame the mesh shader builds for the
 * billboard quad - so far instances turn with the sun instead of keeping the light
 * they were baked in.
 */
final class ImpostorBaker
{
    private const RAMP = 17;

    /** @var array<int, list<float>>|null displayed value (0..255) per ramp step and channel */
    private ?array $ramp = null;

    private VioRenderer3D $renderer;

    public function __construct(private readonly \VioContext $ctx, ?VioRenderer3D $renderer = null)
    {
        $this->renderer = $renderer ?? new VioRenderer3D($ctx, 64, 64);
        $this->renderer->applySettings(new GraphicsSettings(
            shadowQuality: ShadowQuality::Off,
            antiAliasing: AntiAliasing::Off,
            ambientOcclusion: ScreenSpaceAO::Off,
            bloom: false,
            hdr: false,
        ));
    }

    /**
     * Bake $meshId drawn with $materialId. The textures are registered on $target (the
     * renderer that draws the impostors) as "$prefix#0" .. "$prefix#(views-1)", the
     * quad mesh as "$prefix#quad".
     */
    public function bake(VioRenderer3D $target, string $meshId, string $materialId, string $prefix, int $views = 8, int $size = 128, bool $normals = false): ImpostorSet
    {
        $mesh = MeshRegistry::get($meshId);
        if ($mesh === null || $views < 1 || $size < 8) {
            throw new \InvalidArgumentException("ImpostorBaker: unknown mesh '{$meshId}' or bad view count / size");
        }
        [$cx, $cz, $radius, $minY, $maxY] = self::extent($mesh);
        $height = max($maxY - $minY, 1e-3);
        $width = max(2.0 * $radius, 1e-3);
        // renderToImage() draws at the context's size: the orthographic box spans the
        // whole frame at $ppm texels per metre (the mesh fits, at most $size texels on
        // its longer side) and the mesh's rectangle is cut out of the middle.
        [$fw, $fh] = $this->frameSize();
        $ppm = min($fw / $width, $fh / $height, $size / max($width, $height));
        $w = max(1, min($fw, (int) round($width * $ppm)));
        $h = max(1, min($fh, (int) round($height * $ppm)));
        $halfW = $fw / (2.0 * $ppm);
        $halfH = $fh / (2.0 * $ppm);
        $ramp = $this->ramp();

        $ids = [];
        $normalIds = [];
        $cy = ($minY + $maxY) * 0.5;
        $distance = $radius + $height + 1.0;
        for ($k = 0; $k < $views; $k++) {
            $a = 2.0 * M_PI * $k / $views;
            $eye = new Vec3($cx + sin($a) * $distance, $cy, $cz + cos($a) * $distance);
            $list = new RenderCommandList();
            $list->add(new SetCamera(
                Mat4::lookAt($eye, new Vec3($cx, $cy, $cz), new Vec3(0.0, 1.0, 0.0)),
                Mat4::orthographic(-$halfW, $halfW, -$halfH, $halfH, 0.05, 2.0 * $distance + $radius),
            ));
            $list->add(new SetAmbientLight(new Color(1.0, 1.0, 1.0), 1.0));
            $list->add(new DrawMesh($meshId, $materialId, Mat4::identity()));
            $black = self::crop($this->renderer->renderToImage($list, $fw, $fh, new Color(0.0, 0.0, 0.0)), $fw, $fh, $w, $h);
            $white = self::crop($this->renderer->renderToImage($list, $fw, $fh, new Color(1.0, 1.0, 1.0)), $fw, $fh, $w, $h);
            $rgba = self::unmix($black, $white, $w * $h, $ramp);
            $tex = vio_texture($this->ctx, ['data' => $rgba, 'width' => $w, 'height' => $h, 'wrap' => VIO_WRAP_CLAMP, 'mipmaps' => true]);
            if (!$tex instanceof \VioTexture) {
                throw new \RuntimeException("ImpostorBaker: texture for view {$k} not created");
            }
            $target->registerGpuTexture($prefix . '#' . $k, $tex);
            $ids[] = $prefix . '#' . $k;
            if ($normals) {
                $list->add(new SetShader('normals'));
                $world = self::crop($this->renderer->renderToImage($list, $fw, $fh, new Color(0.0, 0.0, 0.0)), $fw, $fh, $w, $h);
                $nrgba = self::viewNormals($world, $rgba, $w * $h, $a);
                $ntex = vio_texture($this->ctx, ['data' => $nrgba, 'width' => $w, 'height' => $h, 'wrap' => VIO_WRAP_CLAMP, 'mipmaps' => true]);
                if (!$ntex instanceof \VioTexture) {
                    throw new \RuntimeException("ImpostorBaker: normal map for view {$k} not created");
                }
                $target->registerGpuTexture($prefix . '#n' . $k, $ntex);
                $normalIds[] = $prefix . '#n' . $k;
            }
        }

        // The quad spans the picture: centred on the mesh's vertical axis, bottom at minY.
        $hw = $width / 2;
        MeshRegistry::register($prefix . '#quad', new MeshData(
            [-$hw, $minY, 0.0, $hw, $minY, 0.0, $hw, $maxY, 0.0, -$hw, $maxY, 0.0],
            [0.0, 0.0, 1.0, 0.0, 0.0, 1.0, 0.0, 0.0, 1.0, 0.0, 0.0, 1.0],
            [0.0, 1.0, 1.0, 1.0, 1.0, 0.0, 0.0, 0.0],
            [0, 1, 2, 0, 2, 3],
        ));
        return new ImpostorSet($prefix . '#quad', $ids, $cx, $cz, $normalIds);
    }

    /**
     * World normals (as the 'normals' shader writes them) in the frame of the view from
     * azimuth $a: x along the view's right, y up, z towards the camera; RGB = n * 0.5 + 0.5,
     * alpha the coverage of the albedo picture. Uncovered texels face the camera.
     */
    private static function viewNormals(string $world, string $albedo, int $pixels, float $a): string
    {
        $rx = cos($a);
        $rz = -sin($a);
        $tx = sin($a);
        $tz = cos($a);
        $out = '';
        for ($p = 0; $p < $pixels; $p++) {
            $o = $p * 4;
            $alpha = ord($albedo[$o + 3]);
            if ($alpha < 5) {
                $out .= pack('C4', 128, 128, 255, $alpha);
                continue;
            }
            $nx = ord($world[$o]) / 127.5 - 1.0;
            $ny = ord($world[$o + 1]) / 127.5 - 1.0;
            $nz = ord($world[$o + 2]) / 127.5 - 1.0;
            $x = $nx * $rx + $nz * $rz;
            $z = $nx * $tx + $nz * $tz;
            $l = sqrt($x * $x + $ny * $ny + $z * $z);
            $l = $l > 1e-6 ? $l : 1.0;
            $out .= pack('C4', (int) round(($x / $l * 0.5 + 0.5) * 255.0), (int) round(($ny / $l * 0.5 + 0.5) * 255.0), (int) round(($z / $l * 0.5 + 0.5) * 255.0), $alpha);
        }
        return $out;
    }

    /** @return array{0: int, 1: int} */
    private function frameSize(): array
    {
        $size = vio_framebuffer_size($this->ctx);
        return [max(1, (int) $size[0]), max(1, (int) $size[1])];
    }

    /** The $w x $h rectangle in the middle of an $fw x $fh RGBA picture. */
    private static function crop(string $rgba, int $fw, int $fh, int $w, int $h): string
    {
        $x0 = intdiv($fw - $w, 2);
        $y0 = intdiv($fh - $h, 2);
        $out = '';
        for ($y = 0; $y < $h; $y++) {
            $out .= substr($rgba, (($y0 + $y) * $fw + $x0) * 4, $w * 4);
        }
        return $out;
    }

    /**
     * Mesh centre on the vertical axis, horizontal radius around it, height range.
     *
     * @return array{0: float, 1: float, 2: float, 3: float, 4: float}
     */
    private static function extent(MeshData $mesh): array
    {
        $min = [INF, INF, INF];
        $max = [-INF, -INF, -INF];
        $n = intdiv(count($mesh->vertices), 3);
        for ($i = 0; $i < $n; $i++) {
            for ($a = 0; $a < 3; $a++) {
                $v = (float) $mesh->vertices[$i * 3 + $a];
                $min[$a] = min($min[$a], $v);
                $max[$a] = max($max[$a], $v);
            }
        }
        $cx = ($min[0] + $max[0]) * 0.5;
        $cz = ($min[2] + $max[2]) * 0.5;
        $r = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $r = max($r, hypot((float) $mesh->vertices[$i * 3] - $cx, (float) $mesh->vertices[$i * 3 + 2] - $cz));
        }
        return [$cx, $cz, $r, $min[1], $max[1]];
    }

    /**
     * The displayed value of albedo 0, 1/16, .. 1 under the bake light, per channel:
     * a flat quad of each grey, filling the picture.
     *
     * @return array<int, list<float>>
     */
    private function ramp(): array
    {
        if ($this->ramp !== null) {
            return $this->ramp;
        }
        MeshRegistry::register("\0impostor_ramp", new MeshData(
            [-1.0, -1.0, 0.0, 1.0, -1.0, 0.0, 1.0, 1.0, 0.0, -1.0, 1.0, 0.0],
            [0.0, 0.0, 1.0, 0.0, 0.0, 1.0, 0.0, 0.0, 1.0, 0.0, 0.0, 1.0],
            [0.0, 1.0, 1.0, 1.0, 1.0, 0.0, 0.0, 0.0],
            [0, 1, 2, 0, 2, 3],
        ));
        $ramp = [];
        for ($i = 0; $i < self::RAMP; $i++) {
            $g = $i / (self::RAMP - 1);
            MaterialRegistry::register("\0impostor_ramp", new Material(albedo: new Color($g, $g, $g), roughness: 1.0, metallic: 0.0, useEnvironmentMap: false));
            $list = new RenderCommandList();
            $list->add(new SetCamera(Mat4::lookAt(new Vec3(0.0, 0.0, 3.0), new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 1.0, 0.0)), Mat4::orthographic(-0.5, 0.5, -0.5, 0.5, 0.1, 10.0)));
            $list->add(new SetAmbientLight(new Color(1.0, 1.0, 1.0), 1.0));
            $list->add(new DrawMesh("\0impostor_ramp", "\0impostor_ramp", Mat4::identity()));
            [$fw, $fh] = $this->frameSize();
            $px = $this->renderer->renderToImage($list, $fw, $fh, new Color(0.0, 0.0, 0.0));
            $o = (intdiv($fh, 2) * $fw + intdiv($fw, 2)) * 4;
            $ramp[$i] = [(float) ord($px[$o]), (float) ord($px[$o + 1]), (float) ord($px[$o + 2])];
        }
        return $this->ramp = $ramp;
    }

    /**
     * Coverage and albedo from the black and white pictures; uncovered texels take
     * the mean colour of the covered ones (no dark fringe in the mips).
     *
     * @param array<int, list<float>> $ramp
     */
    private static function unmix(string $black, string $white, int $pixels, array $ramp): string
    {
        $alpha = [];
        $color = [];
        $sum = [0.0, 0.0, 0.0];
        $covered = 0;
        for ($p = 0; $p < $pixels; $p++) {
            $o = $p * 4;
            $diff = 0.0;
            for ($c = 0; $c < 3; $c++) {
                $diff += ord($white[$o + $c]) - ord($black[$o + $c]);
            }
            $a = max(0.0, min(1.0, 1.0 - $diff / (3.0 * 255.0)));
            $alpha[$p] = $a;
            if ($a > 0.02) {
                $rgb = [];
                for ($c = 0; $c < 3; $c++) {
                    $rgb[$c] = self::toAlbedo(min(255.0, ord($black[$o + $c]) / $a), $ramp, $c);
                    $sum[$c] += $rgb[$c];
                }
                $color[$p] = $rgb;
                $covered++;
            }
        }
        $mean = $covered > 0 ? [$sum[0] / $covered, $sum[1] / $covered, $sum[2] / $covered] : [0.5, 0.5, 0.5];
        $out = '';
        for ($p = 0; $p < $pixels; $p++) {
            $rgb = $color[$p] ?? $mean;
            $out .= pack('C4', (int) round($rgb[0] * 255.0), (int) round($rgb[1] * 255.0), (int) round($rgb[2] * 255.0), (int) round($alpha[$p] * 255.0));
        }
        return $out;
    }

    /**
     * Displayed value -> linear albedo (0..1) through the measured ramp, linear
     * between its steps.
     *
     * @param array<int, list<float>> $ramp
     */
    private static function toAlbedo(float $shown, array $ramp, int $channel): float
    {
        $last = count($ramp) - 1;
        if ($shown <= $ramp[0][$channel]) {
            return 0.0;
        }
        for ($i = 1; $i <= $last; $i++) {
            $hi = $ramp[$i][$channel];
            if ($shown <= $hi) {
                $lo = $ramp[$i - 1][$channel];
                $t = $hi > $lo ? ($shown - $lo) / ($hi - $lo) : 0.0;
                return ($i - 1 + $t) / $last;
            }
        }
        return 1.0;
    }
}
