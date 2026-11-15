<?php

declare(strict_types=1);

namespace PHPolygon\UI\Accessibility;

/**
 * Where the accessibility tree goes. The seam that keeps {@see AccessibilityBridge}
 * testable without a window: production uses {@see VioAccessibilitySink}, tests
 * use a recording double.
 *
 * An update has the shape of vio_a11y_update(): optional 'root' (id), 'focus'
 * (id) and 'nodes' (list of node arrays); see php-vio's vio.stub.php.
 */
interface AccessibilitySink
{
    /** Whether this platform exposes an accessibility tree at all. */
    public function isAvailable(): bool;

    /**
     * Send an update. False when the platform has no accessibility support.
     *
     * @param array<string, mixed> $update
     */
    public function update(array $update): bool;

    /**
     * Register the callback that receives requests from assistive technology:
     * callable(string $id, string $action, mixed $value). Called from the
     * window's event polling, on the game thread.
     *
     * @param callable(string, string, mixed): void $handler
     */
    public function setActionHandler(callable $handler): void;
}
