<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\TestCase;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\SetWind;
use PHPolygon\Rendering\Material;

final class FoliageMaterialTest extends TestCase
{
    public function testDefaultsLeaveExistingMaterialsUnchanged(): void
    {
        $m = new Material();
        self::assertFalse($m->twoSided);
        self::assertFalse($m->foliageWind);
        self::assertSame(0.0, $m->windFlutter);
    }

    public function testFoliageIsTwoSidedTranslucentAndSways(): void
    {
        $m = Material::foliage(Color::srgb('#5E9E3A'), flutter: 0.07);
        self::assertTrue($m->twoSided);
        self::assertTrue($m->foliageWind);
        self::assertSame(0.07, $m->windFlutter);
        self::assertGreaterThan(0.0, $m->subsurfaceStrength, 'light shines through from behind');
        self::assertGreaterThan($m->albedo->g, $m->subsurfaceColor->g, 'transmission is brighter than the leaf');
        self::assertFalse($m->useEnvironmentMap);
        self::assertSame(0.0, $m->metallic);

        $still = Material::foliage(Color::srgb('#5E9E3A'), wind: false);
        self::assertTrue($still->twoSided);
        self::assertFalse($still->foliageWind);
        self::assertSame(0.0, $still->windFlutter);
    }

    public function testTuftWindIsOptInAndSurvivesCopies(): void
    {
        self::assertFalse((new Material())->windTufts);
        self::assertFalse(Material::foliage(Color::srgb('#5E9E3A'))->windTufts, 'other meshes keep the positional flutter');
        $tufts = Material::foliage(Color::srgb('#5E9E3A'), tufts: true);
        self::assertTrue($tufts->windTufts);
        self::assertTrue($tufts->withEmission(new Color(1.0, 0.0, 0.0))->windTufts);
        self::assertFalse(Material::foliage(Color::srgb('#5E9E3A'), wind: false, tufts: true)->windTufts, 'no wind, no tuft wind');
    }

    public function testBarkSwaysWithoutFlutter(): void
    {
        $m = Material::bark(Color::srgb('#6B4A32'));
        self::assertTrue($m->foliageWind);
        self::assertSame(0.0, $m->windFlutter);
        self::assertFalse($m->twoSided);
    }

    public function testCopiesKeepEverythingElse(): void
    {
        $base = new Material(albedo: new Color(0.2, 0.5, 0.1), roughness: 0.7, cavity: 0.3, subsurfaceStrength: 0.0);
        $glow = $base->withEmission(new Color(1.0, 0.0, 1.0));
        self::assertSame(1.0, $glow->emission->r);
        self::assertSame($base->albedo, $glow->albedo);
        self::assertSame(0.7, $glow->roughness);
        self::assertSame(0.3, $glow->cavity);

        $leaf = $base->withFoliage(0.2);
        self::assertTrue($leaf->twoSided);
        self::assertTrue($leaf->foliageWind);
        self::assertSame(0.2, $leaf->windFlutter);
        self::assertGreaterThan(0.0, $leaf->subsurfaceStrength);
        self::assertSame($base->albedo, $leaf->albedo);
        self::assertFalse($base->twoSided, 'the original is untouched');
    }

    public function testSetWindFoliageDefaults(): void
    {
        $w = new SetWind();
        self::assertSame(1.0, $w->foliageMotion);
        self::assertLessThan($w->foliageFadeEnd, $w->foliageFadeStart);
    }
}
