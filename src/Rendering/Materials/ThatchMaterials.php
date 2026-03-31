<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Materials;

use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;

/**
 * Thatch/straw material presets — roof panels, palm leaf roofing, dried grass.
 *
 * IDs start with "hut_thatch" → renderer assigns proc_mode 8 (thatch shader).
 */
class ThatchMaterials
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
            'roof' => [
                'id' => 'hut_thatch',
                'material' => new Material(
                    albedo: Color::hex('#B89850'),
                    roughness: 0.95,
                ),
            ],
            'roof_dark' => [
                'id' => 'hut_thatch_dark',
                'material' => new Material(
                    albedo: Color::hex('#8A7038'),
                    roughness: 0.95,
                ),
            ],
            'roof_weathered' => [
                'id' => 'hut_thatch_weathered',
                'material' => new Material(
                    albedo: Color::hex('#A08848'),
                    roughness: 0.97,
                ),
            ],
            'palm_leaf' => [
                'id' => 'hut_thatch_palm',
                'material' => new Material(
                    albedo: Color::hex('#6B8038'),
                    roughness: 0.90,
                ),
            ],
            'dried_grass' => [
                'id' => 'hut_thatch_grass',
                'material' => new Material(
                    albedo: Color::hex('#C4A858'),
                    roughness: 0.96,
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

    public static function roof(): Material { return self::all()['roof']['material']; }
    public static function roofDark(): Material { return self::all()['roof_dark']['material']; }
    public static function roofWeathered(): Material { return self::all()['roof_weathered']['material']; }
    public static function palmLeaf(): Material { return self::all()['palm_leaf']['material']; }
    public static function driedGrass(): Material { return self::all()['dried_grass']['material']; }
}
