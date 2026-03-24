<?php

declare(strict_types=1);

namespace PHPolygon\SaveGame;

class SaveManager
{
    private string $savePath;
    private int $maxSlots;

    /** @var array<int, SaveSlot> */
    private array $slots = [];

    public function __construct(string $savePath, int $maxSlots = 10)
    {
        $this->savePath = rtrim($savePath, '/\\');
        $this->maxSlots = $maxSlots;
    }

    public function getSavePath(): string
    {
        return $this->savePath;
    }

    public function getMaxSlots(): int
    {
        return $this->maxSlots;
    }

    /**
     * Save game data to a slot.
     *
     * @param array<string, mixed> $data     Game state to persist
     * @param array<string, mixed> $metadata Display info (level name, screenshot path, etc.)
     */
    public function save(int $slotIndex, string $name, array $data, array $metadata = [], float $playTime = 0.0): SaveSlot
    {
        if ($slotIndex < 0 || $slotIndex >= $this->maxSlots) {
            throw new \InvalidArgumentException("Slot index must be between 0 and " . ($this->maxSlots - 1));
        }

        $now = new \DateTimeImmutable();
        $existing = $this->slots[$slotIndex] ?? null;

        $slot = new SaveSlot(
            index: $slotIndex,
            name: $name,
            createdAt: $existing !== null ? $existing->createdAt : $now,
            updatedAt: $now,
            playTime: $playTime,
            metadata: $metadata,
            data: $data,
        );

        $this->slots[$slotIndex] = $slot;
        $this->writeToDisk($slot);

        return $slot;
    }

    /**
     * Load a save slot from disk.
     */
    public function load(int $slotIndex): ?SaveSlot
    {
        if (isset($this->slots[$slotIndex])) {
            return $this->slots[$slotIndex];
        }

        $path = $this->slotPath($slotIndex);
        if (!file_exists($path)) {
            return null;
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return null;
        }
        $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            return null;
        }
        /** @var array<string, mixed> $raw */
        $raw = $decoded;
        $slot = SaveSlot::fromArray($raw);
        $this->slots[$slotIndex] = $slot;

        return $slot;
    }

    /**
     * Delete a save slot.
     */
    public function delete(int $slotIndex): void
    {
        unset($this->slots[$slotIndex]);

        $path = $this->slotPath($slotIndex);
        if (file_exists($path)) {
            unlink($path);
        }
    }

    /**
     * Check if a slot has a saved game.
     */
    public function exists(int $slotIndex): bool
    {
        return isset($this->slots[$slotIndex]) || file_exists($this->slotPath($slotIndex));
    }

    /**
     * List all occupied save slots with their metadata (without loading full data).
     *
     * @return list<SaveSlotInfo>
     */
    public function listSlots(): array
    {
        $result = [];

        for ($i = 0; $i < $this->maxSlots; $i++) {
            $slot = $this->load($i);
            if ($slot !== null) {
                $result[] = new SaveSlotInfo(
                    index: $slot->index,
                    name: $slot->name,
                    createdAt: $slot->createdAt,
                    updatedAt: $slot->updatedAt,
                    playTime: $slot->playTime,
                    metadata: $slot->metadata,
                );
            }
        }

        return $result;
    }

    /**
     * Find the first empty slot index, or null if all slots are occupied.
     */
    public function findEmptySlot(): ?int
    {
        for ($i = 0; $i < $this->maxSlots; $i++) {
            if (!$this->exists($i)) {
                return $i;
            }
        }
        return null;
    }

    private function slotPath(int $index): string
    {
        return $this->savePath . '/slot_' . $index . '.save.json';
    }

    private function writeToDisk(SaveSlot $slot): void
    {
        if (!is_dir($this->savePath)) {
            mkdir($this->savePath, 0755, true);
        }

        $json = json_encode($slot->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        file_put_contents($this->slotPath($slot->index), $json, LOCK_EX);
    }
}
