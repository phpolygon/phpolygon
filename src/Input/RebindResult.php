<?php

declare(strict_types=1);

namespace PHPolygon\Input;

/** Outcome of {@see InputMap::rebind()}. */
final class RebindResult
{
    public function __construct(
        public readonly RebindStatus $status,
        /** The other action involved in a Conflict or Swapped outcome. */
        public readonly ?string $otherAction = null,
    ) {}

    public function isSuccess(): bool
    {
        return $this->status === RebindStatus::Bound
            || $this->status === RebindStatus::Unchanged
            || $this->status === RebindStatus::Swapped;
    }
}
