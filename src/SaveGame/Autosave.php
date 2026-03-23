<?php

declare(strict_types=1);

namespace PHPolygon\SaveGame;

/**
 * Autosave manager — periodically saves game state via SaveManager.
 *
 * Game code provides a data callback that returns the current state.
 * Autosave ticks in the update loop and writes when the interval elapses.
 */
class Autosave
{
    private SaveManager $saves;
    private int $slotIndex;
    private float $intervalSeconds;

    /** @var callable(): array<string, mixed> */
    private $dataProvider;

    /** @var (callable(): array<string, mixed>)|null */
    private $metadataProvider;

    /** @var (callable(): float)|null */
    private $playTimeProvider;

    private float $elapsed = 0.0;
    private bool $enabled = true;
    private int $saveCount = 0;
    private ?SaveSlot $lastSave = null;

    /**
     * @param callable(): array<string, mixed> $dataProvider Returns current game state
     */
    public function __construct(
        SaveManager $saves,
        callable $dataProvider,
        int $slotIndex = 0,
        float $intervalSeconds = 300.0,
    ) {
        $this->saves = $saves;
        $this->dataProvider = $dataProvider;
        $this->slotIndex = $slotIndex;
        $this->intervalSeconds = $intervalSeconds;
    }

    /**
     * Set a callback that provides metadata (level name, etc.) for the save slot.
     *
     * @param callable(): array<string, mixed> $provider
     */
    public function setMetadataProvider(callable $provider): self
    {
        $this->metadataProvider = $provider;
        return $this;
    }

    /**
     * Set a callback that returns current play time in seconds.
     *
     * @param callable(): float $provider
     */
    public function setPlayTimeProvider(callable $provider): self
    {
        $this->playTimeProvider = $provider;
        return $this;
    }

    public function getSlotIndex(): int
    {
        return $this->slotIndex;
    }

    public function setSlotIndex(int $index): void
    {
        $this->slotIndex = $index;
    }

    public function getIntervalSeconds(): float
    {
        return $this->intervalSeconds;
    }

    public function setIntervalSeconds(float $seconds): void
    {
        $this->intervalSeconds = max(1.0, $seconds);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function enable(): void
    {
        $this->enabled = true;
    }

    public function disable(): void
    {
        $this->enabled = false;
    }

    /**
     * How many autosaves have been performed this session.
     */
    public function getSaveCount(): int
    {
        return $this->saveCount;
    }

    /**
     * The last autosave slot, or null if none yet.
     */
    public function getLastSave(): ?SaveSlot
    {
        return $this->lastSave;
    }

    /**
     * Time elapsed since last autosave (or since start).
     */
    public function getElapsed(): float
    {
        return $this->elapsed;
    }

    /**
     * Time remaining until the next autosave triggers.
     */
    public function getTimeUntilNext(): float
    {
        return max(0.0, $this->intervalSeconds - $this->elapsed);
    }

    /**
     * Reset the timer without saving.
     */
    public function resetTimer(): void
    {
        $this->elapsed = 0.0;
    }

    /**
     * Tick the autosave timer. Call once per update frame.
     *
     * @return bool True if an autosave was performed this tick
     */
    public function tick(float $dt): bool
    {
        if (!$this->enabled) {
            return false;
        }

        $this->elapsed += $dt;

        if ($this->elapsed >= $this->intervalSeconds) {
            $this->saveNow();
            $this->elapsed = 0.0;
            return true;
        }

        return false;
    }

    /**
     * Force an immediate autosave, regardless of timer.
     */
    public function saveNow(): SaveSlot
    {
        $data = ($this->dataProvider)();
        $metadata = $this->metadataProvider !== null ? ($this->metadataProvider)() : ['autosave' => true];
        $playTime = $this->playTimeProvider !== null ? ($this->playTimeProvider)() : 0.0;

        $slot = $this->saves->save(
            $this->slotIndex,
            'Autosave',
            $data,
            $metadata,
            $playTime,
        );

        $this->lastSave = $slot;
        $this->saveCount++;

        return $slot;
    }
}
