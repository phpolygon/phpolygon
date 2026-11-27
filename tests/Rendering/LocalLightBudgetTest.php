<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\TestCase;
use PHPolygon\Rendering\Lighting\LocalLightBudget;

/**
 * The per-kind budget of local lights: the nearest few keep burning, and a light that is
 * already in keeps its place against a challenger that is only a little nearer, so the
 * set does not flicker at the border.
 */
final class LocalLightBudgetTest extends TestCase
{
    /**
     * Ranks of $n lights on a line, $spacing metres apart (sphere distance, centre distance).
     *
     * @return array<int, array{0: float, 1: float}>
     */
    private static function row(int $n, float $spacing = 10.0): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[$i] = [$i * $spacing, $i * $spacing];
        }
        return $out;
    }

    public function testTheNearestLightsWin(): void
    {
        $set = LocalLightBudget::select(self::row(20), 8, []);

        self::assertCount(8, $set);
        for ($i = 0; $i < 8; $i++) {
            self::assertArrayHasKey($i, $set);
        }
    }

    public function testFewerLightsThanTheCapAllStay(): void
    {
        self::assertCount(3, LocalLightBudget::select(self::row(3), 8, []));
    }

    public function testACapOfZeroKeepsNone(): void
    {
        self::assertSame([], LocalLightBudget::select(self::row(3), 0, []));
    }

    public function testAnIncumbentKeepsItsSlotAgainstASlightlyNearerChallenger(): void
    {
        // 'b' is in; 'a' is now 2 m nearer than 'b', within the hold margin.
        $ranks = ['a' => [10.0, 10.0], 'b' => [12.0, 12.0]];
        self::assertSame(['b' => true], LocalLightBudget::select($ranks, 1, ['b' => true]));
    }

    public function testAChallengerClearlyNearerReplacesTheIncumbent(): void
    {
        $ranks = ['a' => [10.0, 10.0], 'b' => [40.0, 40.0]];
        self::assertSame(['a' => true], LocalLightBudget::select($ranks, 1, ['b' => true]));
    }

    public function testASphereTheCameraStandsInsideOutranksANearerCentre(): void
    {
        // Rank 0 = the camera is inside the light's range.
        $ranks = ['near-small' => [4.0, 5.0], 'far-big' => [0.0, 30.0]];
        self::assertSame(['far-big' => true], LocalLightBudget::select($ranks, 1, []));
    }

    public function testTiesAreBrokenByTheCentreDistanceThenTheKey(): void
    {
        self::assertSame(['near' => true], LocalLightBudget::select(['far' => [0.0, 9.0], 'near' => [0.0, 3.0]], 1, []));
        self::assertSame(['a' => true], LocalLightBudget::select(['b' => [0.0, 3.0], 'a' => [0.0, 3.0]], 1, []));
    }

    public function testAHoveringLightDoesNotChangeTheSetFromFrameToFrame(): void
    {
        // Two lights swap places by a metre every frame; the set must stay the first one chosen.
        $previous = LocalLightBudget::select(['a' => [10.0, 10.0], 'b' => [11.0, 11.0]], 1, []);
        self::assertSame(['a' => true], $previous);
        for ($i = 0; $i < 20; $i++) {
            $ranks = $i % 2 === 0 ? ['a' => [11.0, 11.0], 'b' => [10.0, 10.0]] : ['a' => [10.0, 10.0], 'b' => [11.0, 11.0]];
            $previous = LocalLightBudget::select($ranks, 1, $previous);
            self::assertSame(['a' => true], $previous, 'frame ' . $i);
        }
    }
}
