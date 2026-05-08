<?php

declare(strict_types=1);

namespace PHPolygon\Event;

use PHPolygon\Rendering\GraphicsSettings;

/**
 * Fired by the AdaptiveQualityController before it applies an automatic
 * quality adjustment. Listeners may call veto() to defer the change - the
 * controller will retry the adjustment after a short cool-down period.
 *
 * Typical use case: gameplay code blocks downgrades during combat or a
 * cinematic where a sudden visual change would be jarring.
 */
final class QualityChangeRequest
{
    private bool $vetoed = false;

    public function __construct(
        public readonly GraphicsSettings $current,
        public readonly GraphicsSettings $proposed,
        public readonly string $reason,
    ) {
    }

    public function veto(): void
    {
        $this->vetoed = true;
    }

    public function isVetoed(): bool
    {
        return $this->vetoed;
    }
}
