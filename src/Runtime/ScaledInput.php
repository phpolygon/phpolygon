<?php

declare(strict_types=1);

namespace PHPolygon\Runtime;

use PHPolygon\Math\Vec2;
use PHPolygon\UI\UIScale;

/**
 * Input whose pointer is in logical pixels: the mouse position of the wrapped
 * input divided by the shared {@see UIScale}. Everything else (keys, buttons,
 * scroll, text, suppression) is the wrapped input unchanged - including its
 * consuming reads, so a ScaledInput and its inner input share one set of edges.
 *
 * Pairs with {@see \PHPolygon\Rendering\ScaledRenderer2D} on the same UIScale.
 */
final class ScaledInput implements InputInterface
{
    public function __construct(
        private readonly InputInterface $inner,
        private readonly UIScale $scale,
    ) {}

    public function inner(): InputInterface
    {
        return $this->inner;
    }

    public function isKeyDown(int $key): bool
    {
        return $this->inner->isKeyDown($key);
    }

    public function isKeyPressed(int $key): bool
    {
        return $this->inner->isKeyPressed($key);
    }

    public function isKeyTyped(int $key): bool
    {
        return $this->inner->isKeyTyped($key);
    }

    public function isKeyReleased(int $key): bool
    {
        return $this->inner->isKeyReleased($key);
    }

    public function isMouseButtonDown(int $button): bool
    {
        return $this->inner->isMouseButtonDown($button);
    }

    public function isMouseButtonPressed(int $button): bool
    {
        return $this->inner->isMouseButtonPressed($button);
    }

    public function isMouseButtonReleased(int $button): bool
    {
        return $this->inner->isMouseButtonReleased($button);
    }

    public function getMousePosition(): Vec2
    {
        $p = $this->inner->getMousePosition();
        $s = $this->scale->factor;

        return $s === 1.0 ? $p : new Vec2($p->x / $s, $p->y / $s);
    }

    public function getMouseX(): float
    {
        return $this->inner->getMouseX() / $this->scale->factor;
    }

    public function getMouseY(): float
    {
        return $this->inner->getMouseY() / $this->scale->factor;
    }

    public function getScrollX(): float
    {
        return $this->inner->getScrollX();
    }

    public function getScrollY(): float
    {
        return $this->inner->getScrollY();
    }

    public function getCharsTyped(): array
    {
        return $this->inner->getCharsTyped();
    }

    public function getTextInput(): string
    {
        return $this->inner->getTextInput();
    }

    public function getBackspaceCount(): int
    {
        return $this->inner->getBackspaceCount();
    }

    public function showSoftKeyboard(): void
    {
        $this->inner->showSoftKeyboard();
    }

    public function hideSoftKeyboard(): void
    {
        $this->inner->hideSoftKeyboard();
    }

    public function suppress(int $frames = 0, float $seconds = 0.0): void
    {
        $this->inner->suppress($frames, $seconds);
    }

    public function unsuppress(): void
    {
        $this->inner->unsuppress();
    }

    public function isSuppressed(): bool
    {
        return $this->inner->isSuppressed();
    }

    public function clearKeyEdges(): void
    {
        $this->inner->clearKeyEdges();
    }

    public function endFrame(): void
    {
        $this->inner->endFrame();
    }
}
