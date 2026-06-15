<?php

declare(strict_types=1);

/**
 * Fieldtracing — CPU SDF authoring + bake demo (GPU-free).
 *
 * Builds the SAME scene the GPU demo marches (examples/fieldtracing_sdf.php /
 * resources/shaders/source/vio/fieldtrace.frag.glsl), but using the engine's
 * PHP-side analytic SDF primitives (PHPolygon\Fieldtracing\Sdf). It then:
 *
 *   1. bakes the analytic field into an SdfVolume (SdfVolumeBaker),
 *   2. prints a few sampled distances so you can see CPU == GPU field,
 *   3. writes a signed-distance SLICE image of the baked volume, and
 *   4. (optional) CPU sphere-traces the field to a shaded image, proving the
 *      PHP authoring side and the GLSL shader describe the same geometry.
 *
 * This is the "geometry as maths" half of Fieldtracing — version-controlled PHP,
 * no model files, no GPU. In production the bake runs on a worker thread
 * (SdfBakeSystem); here it runs inline for clarity.
 *
 * Usage:
 *   php examples/fieldtracing_bake.php                      # stats + slice PNG
 *   php examples/fieldtracing_bake.php --raymarch=cpu.png   # + CPU-traced image
 *   php examples/fieldtracing_bake.php --slice=slice.png --y=1.0 --res=64
 */

require __DIR__ . '/../vendor/autoload.php';

use PHPolygon\Fieldtracing\Bake\SdfVolumeBaker;
use PHPolygon\Fieldtracing\Sdf\BoxSdf;
use PHPolygon\Fieldtracing\Sdf\PlaneSdf;
use PHPolygon\Fieldtracing\Sdf\SdfComposite;
use PHPolygon\Fieldtracing\Sdf\SdfPrimitive;
use PHPolygon\Fieldtracing\Sdf\SphereSdf;
use PHPolygon\Math\Vec3;

$opt        = getopt('', ['slice::', 'raymarch::', 'y::', 'res::']);
$slicePath  = $opt['slice'] ?? 'tmp_ft_slice.png';
$rayPath    = $opt['raymarch'] ?? null;
$sliceY     = isset($opt['y']) ? (float)$opt['y'] : 0.9;
$res        = (int)($opt['res'] ?? 64);

// ---- The scene: identical to the GLSL mapScene() at t = 0 -------------------
// Two smooth-blended spheres + a box, smooth-unioned. (The GPU shader animates
// the sphere heights with time; here we bake the t=0 pose.)
function buildScene(): SdfPrimitive
{
    $s0  = new SphereSdf(1.0, new Vec3(-1.1, 1.0, 0.0));
    $s1  = new SphereSdf(0.8, new Vec3( 1.1, 1.0, 0.0));
    $box = new BoxSdf(new Vec3(1.8, 1.4, 1.8), new Vec3(0.0, 0.7, -1.8));

    $blob = SdfComposite::smoothUnion($s0, $s1, 0.6);
    $blob = SdfComposite::smoothUnion($blob, $box, 0.4);

    // Ground plane unioned in for the traced image; the *baked* volume below
    // bakes only the bounded blob (a plane is unbounded — see PlaneSdf::bounds).
    return $blob;
}

$blob   = buildScene();
$ground = new PlaneSdf(new Vec3(0.0, 1.0, 0.0), 0.0);
$scene  = SdfComposite::union($blob, $ground); // full scene for ray-marching

// ---- 1+2. Bake the bounded blob and verify CPU distances -------------------
$t0  = microtime(true);
$vol = SdfVolumeBaker::bakeAuto($blob, $res, 0.8);
$bakeMs = (microtime(true) - $t0) * 1000.0;

printf(
    "Baked SdfVolume: %dx%dx%d (%d samples), cell=%.4f, in %.1f ms\n",
    $vol->nx, $vol->ny, $vol->nz, $vol->sampleCount(), $vol->cellSize, $bakeMs
);

$probes = [
    [-1.1, 1.0, 0.0], // sphere-0 centre (inside)
    [ 0.0, 1.0, 0.0], // between the two spheres (in the smooth blend)
    [ 3.0, 1.0, 0.0], // clearly outside
];
echo "CPU field check (analytic vs trilinear-sampled baked volume):\n";
foreach ($probes as $c) {
    $p = new Vec3($c[0], $c[1], $c[2]);
    printf(
        "  p=(%+.1f,%+.1f,%+.1f)  analytic=%+.3f  baked=%+.3f\n",
        $c[0], $c[1], $c[2], $blob->distance($p), $vol->sample($p)
    );
}

// ---- 3. Slice image of the baked volume ------------------------------------
if (!extension_loaded('gd')) {
    fwrite(STDERR, "ext-gd not loaded; skipping image output.\n");
    exit(0);
}

writeSlicePng($vol, $sliceY, $slicePath);
echo "Wrote distance-field slice (y={$sliceY}) -> {$slicePath}\n";

// ---- 4. Optional CPU sphere-trace ------------------------------------------
if ($rayPath !== null) {
    $t1 = microtime(true);
    writeRaymarchPng($scene, $rayPath, 240, 150);
    printf("Wrote CPU-traced image -> %s in %.1f ms\n", $rayPath, (microtime(true) - $t1) * 1000.0);
}

// ============================================================================

/** Render a horizontal (xz) slice of the volume: red=inside, blue=outside. */
function writeSlicePng(\PHPolygon\Fieldtracing\Volume\SdfVolume $vol, float $y, string $path): void
{
    $w = $vol->nx;
    $h = $vol->nz;
    $img = imagecreatetruecolor($w, $h);
    $min = $vol->origin;
    $max = $vol->max();

    for ($iz = 0; $iz < $h; $iz++) {
        $z = $min->z + ($max->z - $min->z) * ($iz / max(1, $h - 1));
        for ($ix = 0; $ix < $w; $ix++) {
            $x = $min->x + ($max->x - $min->x) * ($ix / max(1, $w - 1));
            $d = $vol->sample(new Vec3($x, $y, $z));
            // Map signed distance to colour: inside -> red, surface -> white,
            // outside -> blue, with banded iso-lines for readability.
            $band = (abs($d) < 0.06) ? 1.0 : (0.5 + 0.5 * cos($d * 6.2831853));
            if ($d < 0.0) {
                $col = imagecolorallocate($img, (int)(200 * $band) + 55, 30, 30);
            } else {
                $shade = (int)(180 * $band) + 40;
                $col = imagecolorallocate($img, 30, 30, $shade);
            }
            imagesetpixel($img, $ix, $iz, $col);
        }
    }
    imagepng($img, $path);
}

/** Tiny CPU sphere-tracer over the SdfPrimitive tree (matches the GLSL pass). */
function writeRaymarchPng(SdfPrimitive $scene, string $path, int $w, int $h): void
{
    $img = imagecreatetruecolor($w, $h);
    $ro  = new Vec3(sin(0.0) * 6.0, 3.2, cos(0.0) * 6.0);
    $target = new Vec3(0.0, 0.9, 0.0);
    $sun = (new Vec3(0.5, 0.85, 0.0))->normalize();

    $fwd   = $target->sub($ro)->normalize();
    $right = $fwd->cross(new Vec3(0.0, 1.0, 0.0))->normalize();
    $up    = $right->cross($fwd);
    $aspect = $w / $h;

    for ($py = 0; $py < $h; $py++) {
        for ($px = 0; $px < $w; $px++) {
            $u = ((($px + 0.5) / $w) * 2.0 - 1.0) * $aspect;
            $v = (1.0 - ($py + 0.5) / $h) * 2.0 - 1.0; // flip y for image space
            $rd = $right->mul($u)->add($up->mul($v))->add($fwd->mul(1.6))->normalize();

            $t = 0.0; $hit = false;
            for ($i = 0; $i < 96; $i++) {
                $p = $ro->add($rd->mul($t));
                $d = $scene->distance($p);
                if ($d < 0.001 * max(1.0, $t)) { $hit = true; break; }
                $t += $d;
                if ($t > 50.0) break;
            }

            if (!$hit) {
                $sd = max(0.0, $rd->y) ;
                $img && imagesetpixel($img, $px, $py, imagecolorallocate(
                    $img, (int)(150 + 50 * $sd), (int)(180 + 40 * $sd), 210
                ));
                continue;
            }

            $p = $ro->add($rd->mul($t));
            $n = sdfNormal($scene, $p);
            $ndl = max(0.0, $n->dot($sun));
            $sh  = softShadowCpu($scene, $p, $sun);
            $lit = 0.2 + 0.8 * $ndl * $sh;
            $base = ($n->y > 0.92 && $p->y < 0.05) ? [110, 105, 100] : [200, 90, 70];
            $img && imagesetpixel($img, $px, $py, imagecolorallocate(
                $img,
                (int)min(255, $base[0] * $lit),
                (int)min(255, $base[1] * $lit),
                (int)min(255, $base[2] * $lit)
            ));
        }
    }
    imagepng($img, $path);
}

function sdfNormal(SdfPrimitive $s, Vec3 $p): Vec3
{
    $e = 0.001;
    $dx = $s->distance(new Vec3($p->x + $e, $p->y, $p->z)) - $s->distance(new Vec3($p->x - $e, $p->y, $p->z));
    $dy = $s->distance(new Vec3($p->x, $p->y + $e, $p->z)) - $s->distance(new Vec3($p->x, $p->y - $e, $p->z));
    $dz = $s->distance(new Vec3($p->x, $p->y, $p->z + $e)) - $s->distance(new Vec3($p->x, $p->y, $p->z - $e));
    return (new Vec3($dx, $dy, $dz))->normalize();
}

function softShadowCpu(SdfPrimitive $s, Vec3 $p, Vec3 $dir): float
{
    $res = 1.0; $t = 0.04;
    for ($i = 0; $i < 32; $i++) {
        $h = $s->distance($p->add($dir->mul($t)));
        if ($h < 0.001) return 0.0;
        $res = min($res, 8.0 * $h / $t);
        $t += max(0.02, min(0.4, $h));
        if ($t > 20.0) break;
    }
    return max(0.0, min(1.0, $res));
}
