<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\TestCase;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Mat4;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\EmissiveLights;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;

final class EmissiveLightsTest extends TestCase
{
    public function testEmissiveDrawsBecomePointLightsInTheirColour(): void
    {
        MeshRegistry::clear();
        MaterialRegistry::clear();
        MeshRegistry::register('tube', BoxMesh::generate(0.1, 2.0, 0.1));
        MaterialRegistry::register('neon', new Material(albedo: new Color(0.0, 0.0, 0.0), emission: new Color(0.2, 2.0, 1.8)));
        MaterialRegistry::register('dim', new Material(albedo: new Color(0.5, 0.5, 0.5), emission: new Color(0.1, 0.1, 0.1)));
        MaterialRegistry::register('plain', new Material(albedo: new Color(0.5, 0.5, 0.5)));
        $draws = [
            new DrawMesh('tube', 'neon', Mat4::translation(3.0, 1.0, -2.0)),
            new DrawMesh('tube', 'dim', Mat4::translation(0.0, 0.0, 0.0)),
            new DrawMesh('tube', 'plain', Mat4::translation(1.0, 0.0, 0.0)),
            new DrawMesh('tube', 'neon', Mat4::translation(-4.0, 1.0, 0.0)->multiply(Mat4::scaling(2.0, 2.0, 2.0))),
        ];
        $lights = EmissiveLights::fromDraws($draws, 1.0);
        self::assertCount(2, $lights, 'only the two neon draws light anything');
        // The larger (scaled) tube is the stronger light and comes first.
        self::assertSame(-4.0, round($lights[0]->position->x, 3));
        self::assertGreaterThan($lights[1]->intensity, $lights[0]->intensity);
        self::assertEqualsWithDelta(1.0, max($lights[1]->color->r, $lights[1]->color->g, $lights[1]->color->b), 1e-6);
        self::assertGreaterThan($lights[1]->color->r * 4.0, $lights[1]->color->g, 'the light is the tube\'s cyan');
        self::assertCount(1, EmissiveLights::fromDraws($draws, 1.0, max: 1));
        MaterialRegistry::clear();
        MeshRegistry::clear();
    }
}
