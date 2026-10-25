<?php

declare(strict_types=1);

namespace PHPolygon\Tests\System;

use PHPUnit\Framework\TestCase;
use PHPolygon\Component\GrassBender;
use PHPolygon\Component\GrassField;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\World;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Command\DrawMeshInstanced;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetGrass;
use PHPolygon\Rendering\GraphicsSettings;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\Quality\GrassQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\System\GrassSystem;
use PHPolygon\Vegetation\GrassDetail;
use PHPolygon\Vegetation\GrassMask;

final class GrassSystemTest extends TestCase
{
    private RenderCommandList $list;
    private World $world;

    protected function setUp(): void
    {
        MeshRegistry::clear();
        MaterialRegistry::clear();
        $this->list = new RenderCommandList();
        $this->world = new World();
    }

    protected function tearDown(): void
    {
        MeshRegistry::clear();
        MaterialRegistry::clear();
    }

    private function camera(float $x = 0.0, float $z = 0.0, float $lookX = 0.0, float $lookZ = -1.0, float $y = 1.7): void
    {
        $this->list->clear();
        $this->list->add(new SetCamera(
            Mat4::lookAt(new Vec3($x, $y, $z), new Vec3($x + $lookX, $y - 0.3, $z + $lookZ), new Vec3(0.0, 1.0, 0.0)),
            Mat4::perspective(deg2rad(70.0), 16 / 9, 0.1, 2000.0),
        ));
    }

    private function field(?GrassMask $mask = null, int $seed = 3, ?callable $ground = null): GrassField
    {
        $field = new GrassField(-150.0, -150.0, 150.0, 150.0, $ground ?? static fn(float $x, float $z): float => 0.02 * $x, $mask, seed: $seed);
        $this->world->createEntity()->attach($field);
        return $field;
    }

    /** @return list<DrawMeshInstanced> */
    private function draws(): array
    {
        $out = [];
        foreach ($this->list->getCommands() as $cmd) {
            if ($cmd instanceof DrawMeshInstanced) {
                $out[] = $cmd;
            }
        }
        return $out;
    }

    /**
     * Every drawn tuft as [x, y, z, hash, scale].
     *
     * @return list<array{0: float, 1: float, 2: float, 3: float, 4: float}>
     */
    private function tufts(): array
    {
        $out = [];
        foreach ($this->draws() as $cmd) {
            $f = [];
            foreach ((array) unpack('g*', $cmd->packedMatrices) as $v) {
                $f[] = is_float($v) ? $v : 0.0;
            }
            for ($i = 0; $i < $cmd->instanceCount; $i++) {
                $m = array_slice($f, $i * 16, 16);
                $out[] = [$m[12], $m[13], $m[14], $m[3], $m[5]];
            }
        }
        return $out;
    }

    private function system(GrassQuality|GrassDetail|null $q = GrassQuality::Medium): GrassSystem
    {
        $s = new GrassSystem($this->list, $q);
        return $s;
    }

    public function testEmitsOneShadowlessDrawPerVariantAndLodPlusTheFrameState(): void
    {
        $this->field();
        $system = $this->system();
        $system->prewarm($this->world, 0.0, 0.0);
        $this->camera();
        $system->render($this->world);
        $detail = GrassQuality::Medium->detail();
        self::assertNotNull($detail);
        $draws = $this->draws();
        self::assertCount($detail->variants * 2, $draws);
        $grass = array_values(array_filter($this->list->getCommands(), static fn(object $c): bool => $c instanceof SetGrass));
        self::assertCount(1, $grass);
        self::assertInstanceOf(SetGrass::class, $grass[0]);
        self::assertSame($detail->fullRange, $grass[0]->fullRange);
        self::assertSame($detail->fadeRange, $grass[0]->fadeRange);
        foreach ($draws as $d) {
            self::assertFalse($d->castShadows, 'blades never cast shadows');
            self::assertNotSame('', $d->packedMatrices);
            self::assertSame($d->instanceCount * 64, strlen($d->packedMatrices));
            $material = MaterialRegistry::get($d->materialId);
            self::assertNotNull($material);
            self::assertTrue($material->grass);
            self::assertTrue($material->twoSided);
            self::assertNotNull(MeshRegistry::get($d->meshId));
        }
        self::assertSame(count($this->tufts()), $system->lastInstanceCount);
        self::assertSame($detail->variants * 2, $system->lastDrawCount);
    }

    public function testDeterministicPerSeed(): void
    {
        $this->field(seed: 9);
        $a = $this->system();
        $a->prewarm($this->world, 0.0, 0.0);
        $this->camera();
        $a->render($this->world);
        $first = array_map(static fn(DrawMeshInstanced $d): string => $d->packedMatrices, $this->draws());

        $b = $this->system();
        $b->prewarm($this->world, 0.0, 0.0);
        $this->camera();
        $b->render($this->world);
        self::assertSame($first, array_map(static fn(DrawMeshInstanced $d): string => $d->packedMatrices, $this->draws()));

        $this->world = new World();
        $this->field(seed: 10);
        $c = $this->system();
        $c->prewarm($this->world, 0.0, 0.0);
        $this->camera();
        $c->render($this->world);
        self::assertNotSame($first, array_map(static fn(DrawMeshInstanced $d): string => $d->packedMatrices, $this->draws()));
    }

    public function testMaskAndGroundAreRespected(): void
    {
        $mask = (new GrassMask())
            ->excludePath([[-40.0, -5.0], [40.0, -5.0]], 1.5, 0.5)
            ->excludeCircle(6.0, -12.0, 3.0, 0.0);
        $field = $this->field($mask);
        $system = $this->system();
        $system->prewarm($this->world, 0.0, 0.0);
        $this->camera();
        $system->render($this->world);
        $tufts = $this->tufts();
        self::assertGreaterThan(1000, count($tufts));
        foreach ($tufts as [$x, $y, $z]) {
            self::assertGreaterThanOrEqual(1.5, abs($z + 5.0), "no tuft on the path ({$x}, {$z})");
            self::assertGreaterThanOrEqual(3.0, hypot($x - 6.0, $z + 12.0), 'no tuft on the plaza');
            self::assertEqualsWithDelta(0.02 * $x - $field->sink, $y, 1e-4, 'root on the ground, sunk a little');
        }
    }

    public function testNoGroundNoGrass(): void
    {
        $this->field(ground: static fn(float $x, float $z): ?float => $x < 0.0 ? null : 0.0);
        $system = $this->system();
        $system->prewarm($this->world, 0.0, 0.0);
        $this->camera(lookX: 0.0, lookZ: -1.0);
        $system->render($this->world);
        foreach ($this->tufts() as [$x]) {
            self::assertGreaterThanOrEqual(-0.01, $x);
        }
    }

    public function testDensityFallsOffWithDistanceAndEndsAtTheFadeRange(): void
    {
        $this->field(ground: static fn(float $x, float $z): float => 0.0);
        $detail = GrassQuality::Medium->detail();
        self::assertNotNull($detail);
        $system = $this->system();
        $system->prewarm($this->world, 0.0, 0.0);
        $this->camera();
        $system->render($this->world);
        $near = 0;
        $far = 0;
        foreach ($this->tufts() as [$x, $y, $z, $hash]) {
            $d = hypot($x, $z);
            self::assertLessThan($detail->fadeRange + $system->cellSize * 1.5, $d, 'nothing far beyond the fade range');
            self::assertGreaterThanOrEqual(0.0, $hash);
            self::assertLessThan(1.0, $hash);
            if ($d > $detail->fullRange + $system->cellSize * 1.5) {
                // Outside full density only tufts the shader may still keep go out.
                $density = 1.0 - GrassSystem::smoothstep($detail->fullRange, $detail->fadeRange, $d - $system->cellSize * 1.5 - $system->rebuildDistance);
                self::assertLessThan(min(1.0, $density * 1.25) + 1e-6, $hash);
            }
            // Per square metre in the full band vs. near the end of the fade band.
            if ($d > 8.0 && $d < 18.0) {
                $near++;
            } elseif ($d > 48.0 && $d < 58.0) {
                $far++;
            }
        }
        $nearArea = M_PI * (18.0 ** 2 - 8.0 ** 2);
        $farArea = M_PI * (58.0 ** 2 - 48.0 ** 2);
        self::assertGreaterThan(3.0 * $far / $farArea, $near / $nearArea, 'far fewer tufts per m² near the fade range');
    }

    public function testAtRestTheBatchesAreResentAndRebuiltOnlyAfterMoving(): void
    {
        $this->field();
        $system = $this->system();
        $system->prewarm($this->world, 0.0, 0.0);
        $this->camera();
        $system->render($this->world);
        self::assertTrue($system->lastRebuilt);
        $first = $this->draws();

        $this->camera();
        $system->render($this->world);
        self::assertFalse($system->lastRebuilt, 'same camera: re-sent');
        $second = $this->draws();
        self::assertCount(count($first), $second);
        foreach ($first as $i => $d) {
            self::assertSame($d, $second[$i], 'the very same command objects');
        }

        $this->camera(0.5, 0.0);
        $system->render($this->world);
        self::assertFalse($system->lastRebuilt, 'half a metre: still the same batches');

        $this->camera(4.0, 0.0);
        $system->render($this->world);
        self::assertTrue($system->lastRebuilt, 'four metres: rebuilt');
    }

    public function testBudgetCapsTheTufts(): void
    {
        $this->field();
        $detail = (new GrassDetail())->with(maxInstances: 1500);
        $system = $this->system($detail);
        $system->prewarm($this->world, 0.0, 0.0);
        $this->camera();
        $system->render($this->world);
        self::assertLessThanOrEqual(1500, $system->lastInstanceCount);
        self::assertGreaterThan(1000, $system->lastInstanceCount);
    }

    public function testGenerationBudgetBuildsNearestCellsFirst(): void
    {
        $this->field();
        $system = $this->system();
        $this->camera();
        $system->render($this->world);
        self::assertGreaterThanOrEqual(1, $system->lastGeneratedCells, 'at least the nearest cell');
        $made = $system->cachedCellCount();
        for ($i = 0; $i < 400 && $system->lastGeneratedCells > 0; $i++) {
            $this->camera();
            $system->render($this->world);
        }
        self::assertGreaterThan($made, $system->cachedCellCount());
        self::assertSame(0, $system->lastGeneratedCells, 'everything in reach is built eventually');
    }

    public function testQualityOffDrawsNothingAndSettingsDriveTheLevel(): void
    {
        $this->field();
        $system = $this->system(GrassQuality::Off);
        $this->camera();
        $system->render($this->world);
        self::assertSame([], $this->draws());
        self::assertSame(0, $system->lastDrawCount);

        $system->setQuality(GrassQuality::Low);
        $system->prewarm($this->world, 0.0, 0.0);
        $this->camera();
        $system->render($this->world);
        self::assertNotSame([], $this->draws());

        $settings = new GraphicsSettings(grassQuality: GrassQuality::High);
        self::assertSame(GrassQuality::High, GraphicsSettings::fromJson($settings->toJson())->grassQuality);
        self::assertSame(GrassQuality::Medium, (new GraphicsSettings())->grassQuality, 'medium by default');
    }

    public function testBendersNearestToTheCameraReachTheShader(): void
    {
        $this->field();
        foreach ([[2.0, -3.0, 0.8], [40.0, -3.0, 0.8], [1.0, -1.0, 1.1], [3.0, -6.0, 0.5], [-2.0, -2.0, 0.6], [0.5, -0.5, 0.0]] as [$x, $z, $r]) {
            $this->world->createEntity()->attach(new Transform3D(new Vec3($x, 0.0, $z)))->attach(new GrassBender($r));
        }
        $system = $this->system();
        $this->camera();
        $system->render($this->world);
        $grass = array_values(array_filter($this->list->getCommands(), static fn(object $c): bool => $c instanceof SetGrass));
        self::assertInstanceOf(SetGrass::class, $grass[0]);
        $benders = $grass[0]->benders;
        self::assertCount(SetGrass::MAX_BENDERS, $benders);
        self::assertSame([1.0, 0.0, -1.0, 1.1], $benders[0], 'nearest first');
        foreach ($benders as $b) {
            self::assertGreaterThan(0.0, $b[3], 'radius 0 is no bender');
            self::assertNotSame(40.0, $b[0], 'far ones dropped first');
        }
        self::assertCount(4, $grass[0]->benderSlots());

        $system->setDetail((new GrassDetail())->with(interaction: false));
        $this->camera();
        $system->render($this->world);
        $grass = array_values(array_filter($this->list->getCommands(), static fn(object $c): bool => $c instanceof SetGrass));
        self::assertInstanceOf(SetGrass::class, $grass[0]);
        self::assertSame([], $grass[0]->benders);
    }

    public function testFieldChangesRebuildTheCells(): void
    {
        $field = $this->field();
        $system = $this->system();
        $system->prewarm($this->world, 0.0, 0.0);
        $this->camera();
        $system->render($this->world);
        $before = $system->lastInstanceCount;
        $field->mask->excludeRect(-200.0, -200.0, 200.0, 0.0, 0.0);
        $system->prewarm($this->world, 0.0, 0.0);
        $this->camera();
        $system->render($this->world);
        self::assertTrue($system->lastRebuilt);
        self::assertLessThan($before / 10, $system->lastInstanceCount, 'the excluded half in front of the camera is gone');
    }
}
