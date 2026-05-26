<?php

declare(strict_types=1);

namespace PHPolygon\Scene\Transpiler;

/**
 * Turns an `import.json` (produced by the R3F TSX importer) into a
 * self-contained, canonical PHP Scene.
 *
 * The TSX -> import.json half runs in Node (it needs a JS/TSX parser); this is
 * the PHP half. It reuses {@see PhpCodeGenerator} for the entity tree and only
 * adds the asset registration: each procedural mesh becomes a
 * `MeshRegistry::register('id', BoxMesh::generate(...))` and each material a
 * `MaterialRegistry::register('id', new Material(...))`, emitted into the top
 * of build() so the imported scene is runnable on its own.
 *
 * import.json shape:
 * ```
 * {
 *   "name": "prototype",
 *   "systems": ["PHPolygon\\System\\Renderer3DSystem"],
 *   "meshes":    { "box_6x12x5": { "generator": "BoxMesh", "args": [6, 12, 5] } },
 *   "materials": { "mat_wall":   { "albedo": "#9a5b3a", "roughness": 0.8 } },
 *   "entities":  [ { "name": "...", "components": [ {"_class": "...", ...} ] } ]
 * }
 * ```
 */
final class SceneImportGenerator
{
    /**
     * Argument types per procedural generator, so ints (segment counts) and
     * floats (dimensions) render correctly under strict_types. The R3F
     * importer is responsible for ordering args to match these signatures.
     *
     * @var array<string, list<'float'|'int'>>
     */
    private const GENERATOR_ARG_TYPES = [
        'BoxMesh' => ['float', 'float', 'float'],
        'SphereMesh' => ['float', 'int', 'int'],
        'CylinderMesh' => ['float', 'float', 'int'],
        'PlaneMesh' => ['float', 'float', 'int'],
        'TorusMesh' => ['float', 'float', 'int', 'int'],
        'OctahedronMesh' => ['float'],
    ];

    public function __construct(
        private readonly PhpCodeGenerator $generator = new PhpCodeGenerator(),
    ) {}

    /**
     * @param array<string, mixed> $import
     */
    public function generate(array $import): string
    {
        $name = is_string($import['name'] ?? null) ? $import['name'] : 'imported';
        $systems = is_array($import['systems'] ?? null) ? array_values($import['systems']) : [];
        $meshes = is_array($import['meshes'] ?? null) ? $import['meshes'] : [];
        $materials = is_array($import['materials'] ?? null) ? $import['materials'] : [];
        $entities = is_array($import['entities'] ?? null) ? array_values($import['entities']) : [];

        [$preludeLines, $extraUses] = $this->renderAssets($meshes, $materials);

        $scene = [
            '_version' => JsonSceneFormat::VERSION,
            'name' => $name,
            'systems' => $systems,
            'entities' => $entities,
        ];
        if (isset($import['_scene']) && is_string($import['_scene'])) {
            $scene['_scene'] = $import['_scene'];
        }

        return $this->generator->generate($scene, implode("\n", $preludeLines), $extraUses);
    }

    /**
     * @param array<mixed> $meshes
     * @param array<mixed> $materials
     * @return array{0: list<string>, 1: list<string>}
     */
    private function renderAssets(array $meshes, array $materials): array
    {
        $lines = [];
        $uses = [];
        $indent = '        ';

        foreach ($meshes as $id => $spec) {
            if (!is_string($id) || !is_array($spec)) {
                continue;
            }
            // Baked custom geometry (small non-primitive mesh) -> explicit MeshData.
            if (is_array($spec['raw'] ?? null)) {
                $lines[] = sprintf(
                    "%sMeshRegistry::register('%s', %s);",
                    $indent,
                    $this->escape($id),
                    $this->renderMeshData($spec['raw']),
                );
                $uses[] = 'PHPolygon\\Geometry\\MeshRegistry';
                $uses[] = 'PHPolygon\\Geometry\\MeshData';
                continue;
            }
            $generatorName = is_string($spec['generator'] ?? null) ? $spec['generator'] : null;
            if ($generatorName === null || !isset(self::GENERATOR_ARG_TYPES[$generatorName])) {
                continue;
            }
            $args = is_array($spec['args'] ?? null) ? array_values($spec['args']) : [];
            $lines[] = sprintf(
                "%sMeshRegistry::register('%s', %s::generate(%s));",
                $indent,
                $this->escape($id),
                $generatorName,
                $this->renderGeneratorArgs($generatorName, $args),
            );
            $uses[] = 'PHPolygon\\Geometry\\MeshRegistry';
            $uses[] = 'PHPolygon\\Geometry\\' . $generatorName;
        }

        foreach ($materials as $id => $props) {
            if (!is_string($id) || !is_array($props)) {
                continue;
            }
            $lines[] = sprintf(
                "%sMaterialRegistry::register('%s', %s);",
                $indent,
                $this->escape($id),
                $this->renderMaterial($props),
            );
            $uses[] = 'PHPolygon\\Rendering\\MaterialRegistry';
            $uses[] = 'PHPolygon\\Rendering\\Material';
            $uses[] = 'PHPolygon\\Rendering\\Color';
        }

        return [$lines, array_values(array_unique($uses))];
    }

    /**
     * @param list<mixed> $args
     */
    private function renderGeneratorArgs(string $generatorName, array $args): string
    {
        $types = self::GENERATOR_ARG_TYPES[$generatorName];
        $rendered = [];
        foreach ($args as $i => $arg) {
            if (!is_int($arg) && !is_float($arg) && !(is_string($arg) && is_numeric($arg))) {
                continue;
            }
            $value = (float) $arg;
            $rendered[] = ($types[$i] ?? 'float') === 'int'
                ? (string) (int) round($value)
                : $this->renderFloat($value);
        }
        return implode(', ', $rendered);
    }

    /**
     * Render a baked custom mesh as an explicit MeshData literal.
     *
     * @param array<mixed> $raw
     */
    private function renderMeshData(array $raw): string
    {
        return sprintf(
            'new MeshData(vertices: [%s], normals: [%s], uvs: [%s], indices: [%s])',
            $this->floatList(is_array($raw['vertices'] ?? null) ? $raw['vertices'] : []),
            $this->floatList(is_array($raw['normals'] ?? null) ? $raw['normals'] : []),
            $this->floatList(is_array($raw['uvs'] ?? null) ? $raw['uvs'] : []),
            $this->intList(is_array($raw['indices'] ?? null) ? $raw['indices'] : []),
        );
    }

    /** @param array<mixed> $values */
    private function floatList(array $values): string
    {
        return implode(', ', array_map(fn($v): string => $this->renderFloat($this->toFloat($v)), array_values($values)));
    }

    /** @param array<mixed> $values */
    private function intList(array $values): string
    {
        return implode(', ', array_map(static fn($v): string => (string) (is_numeric($v) ? (int) $v : 0), array_values($values)));
    }

    /**
     * @param array<mixed> $props
     */
    private function renderMaterial(array $props): string
    {
        $args = [];
        if (isset($props['albedo'])) {
            $args[] = 'albedo: ' . $this->renderColor($props['albedo']);
        }
        if (isset($props['roughness']) && is_numeric($props['roughness'])) {
            $args[] = 'roughness: ' . $this->renderFloat((float) $props['roughness']);
        }
        if (isset($props['metallic']) && is_numeric($props['metallic'])) {
            $args[] = 'metallic: ' . $this->renderFloat((float) $props['metallic']);
        }
        if (isset($props['emission'])) {
            $args[] = 'emission: ' . $this->renderColor($props['emission']);
        }
        return 'new Material(' . implode(', ', $args) . ')';
    }

    private function renderColor(mixed $color): string
    {
        if (is_string($color)) {
            return "Color::hex('" . $this->escape($color) . "')";
        }
        if (is_array($color)) {
            $r = $this->renderFloat($this->toFloat($color['r'] ?? $color[0] ?? 1));
            $g = $this->renderFloat($this->toFloat($color['g'] ?? $color[1] ?? 1));
            $b = $this->renderFloat($this->toFloat($color['b'] ?? $color[2] ?? 1));
            return "new Color({$r}, {$g}, {$b})";
        }
        return 'new Color()';
    }

    private function toFloat(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function renderFloat(float $value): string
    {
        $str = (string) $value;
        if (!str_contains($str, '.') && !str_contains($str, 'E') && !str_contains($str, 'e')) {
            $str .= '.0';
        }
        return $str;
    }

    private function escape(string $value): string
    {
        return addcslashes($value, "\\'");
    }
}
