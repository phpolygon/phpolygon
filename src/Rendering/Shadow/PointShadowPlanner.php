<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Shadow;

use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Command\AddPointLight;

/**
 * Chooses, per frame, which shadow-casting point lights get a depth cube out of a fixed
 * budget, and remembers what each slot holds so an unchanged cube is not rendered again.
 *
 * Pure bookkeeping, no GPU: the renderer asks {@see plan()} for the slots, renders a cube
 * only where {@see isCurrent()} says no, and reports it with {@see markRendered()}.
 *
 * Ranking: higher PointLight::$shadowPriority first, then the nearer influence sphere to the
 * camera (0 inside it), then the nearer centre. A light that already holds a slot keeps it
 * while it stays within the budget, so its cube (and cache entry) survives a change in the
 * ranking of the others. A light is identified by its position (the draw command carries
 * no id): one that moves is a new light every frame, which is also when its cube is stale.
 */
final class PointShadowPlanner
{
    /** Smallest face size a per-light hint may select. */
    public const MIN_FACE_SIZE = 64;

    /** @var array<int, string|null> slot => light key */
    private array $slotKey = [];
    /** @var array<int, string|null> slot => signature of the cube as last rendered */
    private array $slotSignature = [];

    public function __construct(private int $budget)
    {
        $this->budget = max(0, $budget);
    }

    public function budget(): int
    {
        return $this->budget;
    }

    /** A new budget empties every slot: the target they live in is rebuilt for it. */
    public function setBudget(int $budget): void
    {
        $budget = max(0, $budget);
        if ($budget !== $this->budget) {
            $this->budget = $budget;
            $this->invalidate();
        }
    }

    /** Forget what the slots hold (the target was reallocated, the device was lost). */
    public function invalidate(): void
    {
        $this->slotKey = [];
        $this->slotSignature = [];
    }

    /** Whether a light can take part at all: it casts, reaches somewhere and shines. */
    public static function eligible(AddPointLight $light): bool
    {
        return $light->castsShadow && $light->radius > 0.2 && $light->intensity > 0.001;
    }

    public static function lightKey(AddPointLight $light): string
    {
        return sprintf('%.3f,%.3f,%.3f', $light->position->x, $light->position->y, $light->position->z);
    }

    /**
     * Face size of a light's cube: the atlas size, lowered by the light's hint to a power of
     * two (rounded down), never below {@see MIN_FACE_SIZE} nor above the atlas.
     */
    public static function faceSize(int $hint, int $atlasSize): int
    {
        if ($hint <= 0 || $hint >= $atlasSize) {
            return $atlasSize;
        }
        $size = self::MIN_FACE_SIZE;
        while ($size * 2 <= $hint) {
            $size *= 2;
        }

        return min($atlasSize, $size);
    }

    /**
     * The lights that may take part, best first: eligible ones (one per position), by
     * shadowPriority, then the nearer sphere to $eye, then the nearer centre. The single
     * cube takes the first of this list; the budgeted planner takes as many as it has slots.
     *
     * @param list<AddPointLight> $lights
     * @return list<AddPointLight>
     */
    public static function rank(array $lights, Vec3 $eye): array
    {
        $ranked = [];
        $seen = [];
        foreach ($lights as $light) {
            if (!self::eligible($light)) {
                continue;
            }
            $key = self::lightKey($light);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $centre = $light->position->sub($eye)->length();
            $ranked[] = [$light->shadowPriority, max(0.0, $centre - $light->radius), $centre, $key, $light];
        }
        usort($ranked, static fn(array $a, array $b): int => ($b[0] <=> $a[0]) ?: ($a[1] <=> $b[1]) ?: ($a[2] <=> $b[2]) ?: strcmp($a[3], $b[3]));

        return array_map(static fn(array $e): AddPointLight => $e[4], $ranked);
    }

    /**
     * @param list<AddPointLight> $lights every point light of the frame
     * @return list<PointShadowSlot> in slot order
     */
    public function plan(array $lights, Vec3 $eye, int $atlasSize): array
    {
        if ($this->budget === 0) {
            $this->invalidate();

            return [];
        }

        /** @var array<string, AddPointLight> $chosen best first */
        $chosen = [];
        foreach (array_slice(self::rank($lights, $eye), 0, $this->budget) as $light) {
            $chosen[self::lightKey($light)] = $light;
        }
        // Slots whose light left the budget are free again.
        foreach ($this->slotKey as $slot => $key) {
            if ($key !== null && !isset($chosen[$key])) {
                $this->slotKey[$slot] = null;
                $this->slotSignature[$slot] = null;
            }
        }
        $held = [];
        foreach ($this->slotKey as $slot => $key) {
            if ($key !== null) {
                $held[$key] = $slot;
            }
        }
        // The rest take the lowest free slots, best rank first.
        $free = 0;
        foreach ($chosen as $key => $_) {
            if (isset($held[$key])) {
                continue;
            }
            while (($this->slotKey[$free] ?? null) !== null) {
                $free++;
            }
            $this->slotKey[$free] = $key;
            $this->slotSignature[$free] = null;
            $held[$key] = $free;
        }

        $slots = [];
        foreach ($held as $key => $slot) {
            $light = $chosen[$key];
            $slots[$slot] = new PointShadowSlot($slot, $light, self::faceSize($light->shadowResolution, $atlasSize), $key);
        }
        ksort($slots);

        return array_values($slots);
    }

    /** Whether the slot's cube already shows this state (same light, casters, size). */
    public function isCurrent(int $slot, string $signature): bool
    {
        return ($this->slotSignature[$slot] ?? null) === $signature;
    }

    public function markRendered(int $slot, string $signature): void
    {
        $this->slotSignature[$slot] = $signature;
    }
}
