<?php

declare(strict_types=1);

namespace PHPolygon\UI\Accessibility;

use PHPolygon\Runtime\VioMainWindow;

/**
 * {@see AccessibilitySink} over php-vio's vio_a11y_update() / vio_on_a11y_action().
 *
 * Gated per feature, not per php-vio version: without the function, or where
 * vio_platform_info()['accessibility'] is false (no AccessKit in the build),
 * the sink reports itself unavailable and the bridge does nothing.
 */
final class VioAccessibilitySink implements AccessibilitySink
{
    /**
     * Holds the context for the sink's lifetime: a VioMainWindow creates its
     * context once, so build a new sink (and bridge) with a new window.
     */
    public function __construct(private readonly \VioContext|\VioWindow $context) {}

    public static function forWindow(VioMainWindow $window): self
    {
        return new self($window->getContext());
    }

    public function isAvailable(): bool
    {
        if (!function_exists('vio_a11y_update') || !function_exists('vio_platform_info')) {
            return false;
        }
        $info = vio_platform_info();

        return ($info['accessibility'] ?? false) === true;
    }

    public function update(array $update): bool
    {
        if (!function_exists('vio_a11y_update')) {
            return false;
        }

        return vio_a11y_update($this->context, $update);
    }

    public function setActionHandler(callable $handler): void
    {
        if (function_exists('vio_on_a11y_action')) {
            vio_on_a11y_action($this->context, $handler);
        }
    }
}
