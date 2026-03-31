<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Materials;

use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;

/**
 * Fabric material presets — rope, canvas, hammock fabric, netting.
 *
 * IDs do NOT start with "hut_" → standard PBR shading (proc_mode 0).
 */
class FabricMaterials
{
    /** @var array<string, array{id: string, material: Material}>|null */
    private static ?array $cache = null;

    /** @return array<string, array{id: string, material: Material}> */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        self::$cache = [
            'rope' => [
                'id' => 'fabric_rope',
                'material' => new Material(
                    albedo: Color::hex('#8A7550'),
                    roughness: 0.92,
                ),
            ],
            'rope_dark' => [
                'id' => 'fabric_rope_dark',
                'material' => new Material(
                    albedo: Color::hex('#5C4A30'),
                    roughness: 0.90,
                ),
            ],
            'hammock' => [
                'id' => 'fabric_hammock',
                'material' => new Material(
                    albedo: Color::hex('#D4C4A0'),
                    roughness: 0.85,
                ),
            ],
            'hammock_striped' => [
                'id' => 'fabric_hammock_stripe',
                'material' => new Material(
                    albedo: Color::hex('#C0A878'),
                    roughness: 0.83,
                ),
            ],
            'canvas' => [
                'id' => 'fabric_canvas',
                'material' => new Material(
                    albedo: Color::hex('#C8B890'),
                    roughness: 0.88,
                ),
            ],
            'netting' => [
                'id' => 'fabric_netting',
                'material' => new Material(
                    albedo: Color::hex('#9A8A6A'),
                    roughness: 0.80,
                    alpha: 0.7,
                ),
            ],
        ];

        return self::$cache;
    }

    public static function registerAll(): void
    {
        foreach (self::all() as $preset) {
            MaterialRegistry::register($preset['id'], $preset['material']);
        }
    }

    public static function register(string $key): void
    {
        $all = self::all();
        if (isset($all[$key])) {
            MaterialRegistry::register($all[$key]['id'], $all[$key]['material']);
        }
    }

    public static function id(string $key): string
    {
        return self::all()[$key]['id'] ?? '';
    }

    public static function rope(): Material { return self::all()['rope']['material']; }
    public static function ropeDark(): Material { return self::all()['rope_dark']['material']; }
    public static function hammock(): Material { return self::all()['hammock']['material']; }
    public static function hammockStriped(): Material { return self::all()['hammock_striped']['material']; }
    public static function canvas(): Material { return self::all()['canvas']['material']; }
    public static function netting(): Material { return self::all()['netting']['material']; }
}
