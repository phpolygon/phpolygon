<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPolygon\Math\Mat4;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\DrawMeshInstanced;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\OpaqueDrawOrder;
use PHPolygon\Rendering\ProcModeRegistry;
use PHPolygon\Rendering\ProcModeShaderRegistry;
use PHPUnit\Framework\TestCase;

/**
 * The opaque submission order must stay exactly that of the former stable
 * usort over (materialId <=> , meshId <=> , moved <=>) - opaque draws with
 * equal depth resolve by draw order, so a different order could change pixels.
 */
final class OpaqueDrawOrderTest extends TestCase
{
    protected function setUp(): void
    {
        foreach (['ob_a', 'ob_b', 'ob_ab', 'ob_a_b', '10', '9', '1e1'] as $id) {
            MaterialRegistry::register($id, new Material(albedo: new Color(0.5, 0.5, 0.5)));
        }
        MaterialRegistry::register('ob_glass', new Material(albedo: new Color(0.5, 0.5, 0.5), alpha: 0.5));
    }

    public function testMatchesTheComparatorOrderOnMixedDraws(): void
    {
        mt_srand(7);
        $materials = ['ob_a', 'ob_b', 'ob_ab', 'ob_a_b', 'ob_glass', 'ob_missing'];
        $meshes = ['box', 'box_2', 'b', 'box ', 'cyl'];
        $commands = [new SetCamera(Mat4::identity(), Mat4::identity())];
        for ($i = 0; $i < 300; $i++) {
            $material = $materials[mt_rand(0, count($materials) - 1)];
            $mesh = $meshes[mt_rand(0, count($meshes) - 1)];
            $commands[] = mt_rand(0, 2) === 0
                ? DrawMeshInstanced::flat($mesh, $material, array_fill(0, 16, 0.0), 1)
                : new DrawMesh($mesh, $material, Mat4::identity(), prevModelMatrix: mt_rand(0, 1) === 1 ? Mat4::identity() : null);
        }

        foreach ([false, true] as $motion) {
            self::assertSame(self::reference($commands, $motion), self::ids(OpaqueDrawOrder::sort($commands, $motion)), $motion ? 'motion' : 'still');
        }
    }

    public function testNumericIdsKeepTheComparatorOrder(): void
    {
        $commands = [];
        foreach (['10', '9', '1e1', 'ob_a', '10', '9'] as $i => $material) {
            $commands[] = new DrawMesh('m' . ($i % 2), $material, Mat4::identity());
        }
        self::assertSame(self::reference($commands, false), self::ids(OpaqueDrawOrder::sort($commands, false)));
    }

    /**
     * A sea plane meeting the sand at the same depth: whichever draws first keeps
     * the shore pixels. Its own program's run moved it behind the sand; with
     * keepOrder it stays at its material position, as without variants.
     */
    public function testAKeepOrderVariantStaysAtItsMaterialPosition(): void
    {
        MaterialRegistry::register('ob_coast_sea', new Material(albedo: new Color(0.1, 0.3, 0.6)));
        MaterialRegistry::register('ob_sand', new Material(albedo: new Color(0.8, 0.7, 0.5)));
        ProcModeRegistry::map('ob_coast_sea', 14);
        $commands = [
            new DrawMesh('plane', 'ob_sand', Mat4::identity()),
            new DrawMesh('plane', 'ob_coast_sea', Mat4::identity()),
            new DrawMesh('box', 'ob_a', Mat4::identity()),
        ];
        $order = static fn (): array => array_map(static fn (array $e): string => $e[0], OpaqueDrawOrder::sort($commands, false));
        try {
            self::assertSame(['ob_a', 'ob_coast_sea', 'ob_sand'], $order(), 'no variant: material order');

            ProcModeShaderRegistry::defineVariant('water', [14]);
            self::assertSame(['ob_a', 'ob_sand', 'ob_coast_sea'], $order(), 'own run: the sea after the sand');

            ProcModeShaderRegistry::clear();
            ProcModeShaderRegistry::defineVariant('water', [14], keepOrder: true);
            self::assertSame(['ob_a', 'ob_coast_sea', 'ob_sand'], $order(), 'keepOrder: the order without variants');
            self::assertSame('water', ProcModeShaderRegistry::variantOf(14), 'it still draws with its own program');
            self::assertSame(ProcModeShaderRegistry::BASE_VARIANT, ProcModeShaderRegistry::sortVariantOf(14));
        } finally {
            ProcModeShaderRegistry::clear();
            ProcModeRegistry::clear();
        }
    }

    /**
     * The order the renderer used before: a stable usort with <=> per field.
     *
     * @param list<object> $commands
     * @return list<int>
     */
    private static function reference(array $commands, bool $motion): array
    {
        $opaque = [];
        foreach ($commands as $cmd) {
            if ($cmd instanceof DrawMesh || $cmd instanceof DrawMeshInstanced) {
                $material = MaterialRegistry::get($cmd->materialId);
                if ($material === null || $material->alpha < 1.0) {
                    continue;
                }
                $moved = $motion && $cmd instanceof DrawMesh && $cmd->prevModelMatrix !== null ? 1 : 0;
                $opaque[] = [$cmd->materialId, $cmd->meshId, $cmd, $material, $moved];
            }
        }
        usort($opaque, static fn (array $a, array $b): int => ($a[0] <=> $b[0]) ?: ($a[1] <=> $b[1]) ?: ($a[4] <=> $b[4]));

        return self::ids($opaque);
    }

    /**
     * @param list<array{0: string, 1: string, 2: object, 3: Material, 4: int}> $entries
     * @return list<int>
     */
    private static function ids(array $entries): array
    {
        return array_map(static fn (array $e): int => spl_object_id($e[2]), $entries);
    }
}
