<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Materials;

use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;

/**
 * Metal material presets — lanterns, nails, hinges, corrugated roofing.
 *
 * IDs do NOT start with "hut_" → standard PBR shading (proc_mode 0).
 */
class MetalMaterials
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
            'lantern' => [
                'id' => 'metal_lantern',
                'material' => new Material(
                    albedo: Color::hex('#2A2520'),
                    roughness: 0.55,
                    metallic: 0.8,
                ),
            ],
            'lantern_glass' => [
                'id' => 'metal_lantern_glass',
                'material' => new Material(
                    albedo: Color::hex('#FFE8B0'),
                    roughness: 0.15,
                    metallic: 0.0,
                    emission: Color::hex('#FFD080'),
                    alpha: 0.6,
                ),
            ],
            'rusty_iron' => [
                'id' => 'metal_rusty',
                'material' => new Material(
                    albedo: Color::hex('#6B4030'),
                    roughness: 0.85,
                    metallic: 0.4,
                ),
            ],
            'nail' => [
                'id' => 'metal_nail',
                'material' => new Material(
                    albedo: Color::hex('#555555'),
                    roughness: 0.50,
                    metallic: 0.9,
                ),
            ],
            'hinge' => [
                'id' => 'metal_hinge',
                'material' => new Material(
                    albedo: Color::hex('#4A4038'),
                    roughness: 0.60,
                    metallic: 0.7,
                ),
            ],
            'corrugated' => [
                'id' => 'metal_corrugated',
                'material' => new Material(
                    albedo: Color::hex('#8A8880'),
                    roughness: 0.65,
                    metallic: 0.6,
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

    public static function lantern(): Material { return self::all()['lantern']['material']; }
    public static function lanternGlass(): Material { return self::all()['lantern_glass']['material']; }
    public static function rustyIron(): Material { return self::all()['rusty_iron']['material']; }
    public static function nail(): Material { return self::all()['nail']['material']; }
    public static function hinge(): Material { return self::all()['hinge']['material']; }
    public static function corrugated(): Material { return self::all()['corrugated']['material']; }
}
