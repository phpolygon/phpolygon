<?php

declare(strict_types=1);

namespace PHPolygon\SaveGame;

class SaveSlot
{
    public function __construct(
        public readonly int $index,
        public readonly string $name,
        public readonly \DateTimeImmutable $createdAt,
        public readonly \DateTimeImmutable $updatedAt,
        public readonly float $playTime,
        /** @var array<string, mixed> */
        public readonly array $metadata,
        /** @var array<string, mixed> */
        public readonly array $data,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'index' => $this->index,
            'name' => $this->name,
            'createdAt' => $this->createdAt->format('c'),
            'updatedAt' => $this->updatedAt->format('c'),
            'playTime' => $this->playTime,
            'metadata' => $this->metadata,
            'data' => $this->data,
        ];
    }

    /**
     * @param array<string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        /** @var array<string, mixed> $metadata */
        $metadata = is_array($raw['metadata'] ?? null) ? $raw['metadata'] : [];
        /** @var array<string, mixed> $data */
        $data = is_array($raw['data'] ?? null) ? $raw['data'] : [];

        return new self(
            index: is_int($raw['index'] ?? null) ? $raw['index'] : 0,
            name: is_string($raw['name'] ?? null) ? $raw['name'] : '',
            createdAt: new \DateTimeImmutable(is_string($raw['createdAt'] ?? null) ? $raw['createdAt'] : 'now'),
            updatedAt: new \DateTimeImmutable(is_string($raw['updatedAt'] ?? null) ? $raw['updatedAt'] : 'now'),
            playTime: is_float($raw['playTime'] ?? null) ? $raw['playTime'] : (is_int($raw['playTime'] ?? null) ? (float) $raw['playTime'] : 0.0),
            metadata: $metadata,
            data: $data,
        );
    }
}
