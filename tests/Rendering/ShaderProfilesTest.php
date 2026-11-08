<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\TestCase;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\NormalPattern;
use PHPolygon\Rendering\ShaderPath;
use PHPolygon\Rendering\ShaderProfiles;

/** Shader profiles: which lean program an element draws with, and that none loses a path it needs. */
final class ShaderProfilesTest extends TestCase
{
    protected function setUp(): void
    {
        ShaderProfiles::clear();
        ShaderProfiles::register('figure', [ShaderPath::Subsurface, ShaderPath::FigureLook, ShaderPath::Hair], ['npc_']);
        ShaderProfiles::register('building', [ShaderPath::NormalPatterns, ShaderPath::SurfacePatterns]);
        ShaderProfiles::register('plain', []);
    }

    protected function tearDown(): void
    {
        ShaderProfiles::clear();
    }

    public function testExplicitThenPrefixThenSmallestCoveringProfile(): void
    {
        $skin = new Material(albedo: new Color(0.8, 0.6, 0.5), subsurfaceStrength: 0.5, rimLight: 0.4);
        self::assertSame('figure', ShaderProfiles::profileOf('npc_skin_1', $skin));
        self::assertSame('building', ShaderProfiles::profileOf('wall', new Material(normalPattern: NormalPattern::BRICKS)));
        self::assertSame('plain', ShaderProfiles::profileOf('crate', new Material()));
        self::assertSame('building', ShaderProfiles::profileOf('npc_crate', new Material(shaderProfile: 'building')));
    }

    public function testNoProfileTakesAPathAMaterialNeeds(): void
    {
        $glass = new Material(alpha: 0.4, glass: 0.7);
        // No profile keeps glass: the base program.
        self::assertNull(ShaderProfiles::profileOf('pane', $glass));
        // Assigned by prefix to a profile without glass: reported, and drawn with the base program.
        self::assertNull(ShaderProfiles::profileOf('npc_visor', $glass));
        self::assertSame(['npc_visor: figure lacks GLASS'], ShaderProfiles::violations(['npc_visor' => $glass, 'pane' => $glass]));
    }

    public function testAProfileProgramLeavesOutEveryOtherAutomaticPath(): void
    {
        $omit = ShaderProfiles::omissions('figure');
        self::assertNotContains(ShaderPath::FigureLook->define(), $omit);
        self::assertContains(ShaderPath::Glass->define(), $omit);
        self::assertContains(ShaderPath::NormalPatterns->define(), $omit);
        self::assertNotContains(ShaderPath::Weathering->define(), $omit, 'frame-driven paths stay');
        self::assertCount(count(ShaderPath::automatic()) - 3, $omit);
    }

    public function testWithoutProfilesNothingChanges(): void
    {
        ShaderProfiles::clear();
        self::assertNull(ShaderProfiles::profileOf('npc_skin_1', new Material(subsurfaceStrength: 0.5)));
        self::assertSame([], ShaderProfiles::violations(['a' => new Material(glass: 0.5)]));
    }
}
