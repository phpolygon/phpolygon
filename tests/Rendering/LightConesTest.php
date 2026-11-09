<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\TestCase;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Effects\EffectDefinition;
use PHPolygon\Rendering\Effects\EffectFallback;
use PHPolygon\Rendering\Effects\EffectRegistry;
use PHPolygon\Rendering\Effects\EffectTier;
use PHPolygon\Rendering\Effects\FlashClass;
use PHPolygon\Rendering\Effects\ReducedMotion;
use PHPolygon\Rendering\LightCones;

final class LightConesTest extends TestCase
{
    protected function tearDown(): void
    {
        EffectRegistry::clear();
    }

    public function testTheUnitConeIsClosedAndWoundOutward(): void
    {
        $mesh = LightCones::mesh(16);
        $v = $mesh->vertices;
        $idx = $mesh->indices;
        self::assertCount(16 * 2 * 3, $idx);
        // Every triangle faces away from a point inside the cone (on the axis, half way).
        for ($t = 0; $t < count($idx); $t += 3) {
            [$a, $b, $c] = [$idx[$t] * 3, $idx[$t + 1] * 3, $idx[$t + 2] * 3];
            $p0 = new Vec3($v[$a], $v[$a + 1], $v[$a + 2]);
            $p1 = new Vec3($v[$b], $v[$b + 1], $v[$b + 2]);
            $p2 = new Vec3($v[$c], $v[$c + 1], $v[$c + 2]);
            $normal = $p1->sub($p0)->cross($p2->sub($p0));
            $centroid = $p0->add($p1)->add($p2)->div(3.0);
            self::assertGreaterThan(0.0, $normal->dot($centroid->sub(new Vec3(0.0, 0.0, 0.5))), "triangle {$t} faces outward");
        }
        // Closed: every edge is shared by exactly two triangles.
        $edges = [];
        for ($t = 0; $t < count($idx); $t += 3) {
            foreach ([[0, 1], [1, 2], [2, 0]] as [$i, $j]) {
                $key = min($idx[$t + $i], $idx[$t + $j]) . '-' . max($idx[$t + $i], $idx[$t + $j]);
                $edges[$key] = ($edges[$key] ?? 0) + 1;
            }
        }
        self::assertSame([2], array_values(array_unique($edges)));
    }

    public function testTheModelMatrixPlacesTheConeAlongItsBeam(): void
    {
        $apex = new Vec3(1.0, 4.0, -2.0);
        $dir = new Vec3(0.3, -1.0, 0.2);
        $m = LightCones::modelMatrix($apex, $dir, 0.4, 5.0, 24);
        $unit = $dir->normalize();

        self::assertTrue($m->transformPoint(new Vec3(0.0, 0.0, 0.0))->equals($apex, 1e-9), 'apex');
        self::assertTrue($m->transformPoint(new Vec3(0.0, 0.0, 1.0))->equals($apex->add($unit->mul(5.0)), 1e-9), 'base centre on the axis');
        // A rim vertex lies on the base plane, at the circumscribed radius.
        $rim = $m->transformPoint(new Vec3(1.0, 0.0, 1.0))->sub($apex->add($unit->mul(5.0)));
        self::assertEqualsWithDelta(0.0, $rim->dot($unit), 1e-9);
        self::assertEqualsWithDelta(5.0 * tan(0.4) / cos(M_PI / 24), $rim->length(), 1e-9);
        // No mirroring: the outward winding survives.
        $x = $m->transformDirection(new Vec3(1.0, 0.0, 0.0));
        $y = $m->transformDirection(new Vec3(0.0, 1.0, 0.0));
        $z = $m->transformDirection(new Vec3(0.0, 0.0, 1.0));
        self::assertGreaterThan(0.0, $x->cross($y)->dot($z));
        // The beam straight down or up has a basis too.
        $down = LightCones::modelMatrix($apex, new Vec3(0.0, -1.0, 0.0), 0.4, 5.0);
        self::assertTrue($down->transformPoint(new Vec3(0.0, 0.0, 1.0))->equals(new Vec3(1.0, -1.0, -2.0), 1e-9));
    }

    public function testTheBoundingSphereHoldsTheWholeCone(): void
    {
        $apex = new Vec3(0.0, 3.0, 0.0);
        $dir = new Vec3(1.0, -1.0, 0.0);
        foreach ([0.2, 0.7, 1.2] as $angle) {
            [$centre, $radius] = LightCones::boundingSphere($apex, $dir, $angle, 6.0);
            $mesh = LightCones::mesh();
            $m = LightCones::modelMatrix($apex, $dir, $angle, 6.0);
            // The mesh circumscribes the exact cone by 1 / cos(pi / 24); the sphere holds the exact cone.
            $slack = $radius * (1.0 / cos(M_PI / LightCones::SEGMENTS) - 1.0) + 1e-9;
            for ($i = 0; $i < count($mesh->vertices); $i += 3) {
                $p = $m->transformPoint(new Vec3($mesh->vertices[$i], $mesh->vertices[$i + 1], $mesh->vertices[$i + 2]));
                self::assertLessThanOrEqual($radius + $slack, $p->sub($centre)->length(), "angle {$angle}");
            }
            // Tight: the farthest point of the exact cone (apex or rim) lies on the sphere.
            $exact = LightCones::modelMatrix($apex, $dir, $angle, 6.0, 1_000_000);
            $far = $apex->sub($centre)->length();
            for ($k = 0; $k < 16; $k++) {
                $a = 2.0 * M_PI * $k / 16;
                $far = max($far, $exact->transformPoint(new Vec3(cos($a), sin($a), 1.0))->sub($centre)->length());
            }
            self::assertEqualsWithDelta($radius, $far, 1e-6, "angle {$angle}: not larger than needed");
        }
    }

    public function testContainsTellsInsideFromOutside(): void
    {
        $apex = new Vec3(0.0, 4.0, 0.0);
        $down = new Vec3(0.0, -1.0, 0.0);
        self::assertTrue(LightCones::contains($apex, $down, 0.5, 5.0, new Vec3(0.2, 2.0, 0.0)));
        self::assertFalse(LightCones::contains($apex, $down, 0.5, 5.0, new Vec3(2.0, 2.0, 0.0)), 'beside the beam');
        self::assertFalse(LightCones::contains($apex, $down, 0.5, 5.0, new Vec3(0.0, 5.0, 0.0)), 'above the lamp');
        self::assertFalse(LightCones::contains($apex, $down, 0.5, 5.0, new Vec3(0.0, -2.0, 0.0)), 'beyond its length');
        self::assertTrue(LightCones::contains($apex, $down, 0.5, 5.0, new Vec3(1.2, 2.0, 0.0), 0.3), 'within the margin');
    }

    public function testTheRegisterEntryIsAddedOnceAndAGameEntryWins(): void
    {
        LightCones::register();
        $entry = EffectRegistry::get(LightCones::EFFECT);
        self::assertNotNull($entry);
        self::assertSame(EffectTier::Low, $entry->tier);
        self::assertSame(EffectFallback::Same, $entry->unlitFallback);
        self::assertSame(EffectFallback::Off, $entry->glFallback);
        self::assertSame(ReducedMotion::Static, $entry->reducedMotion);
        self::assertSame(FlashClass::None, $entry->flash);
        LightCones::register();
        self::assertCount(1, EffectRegistry::all());

        EffectRegistry::clear();
        $game = new EffectDefinition(LightCones::EFFECT, EffectTier::High, null, EffectFallback::Off, EffectFallback::Off, ReducedMotion::Static, FlashClass::Gentle);
        EffectRegistry::register($game);
        LightCones::register();
        self::assertSame($game, EffectRegistry::get(LightCones::EFFECT));
    }
}
