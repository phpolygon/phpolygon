<?php

declare(strict_types=1);

namespace PHPolygon\UI;

use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Rendering\Renderer2DInterface;
use PHPolygon\Runtime\Input;

/**
 * ECS System that manages the UI context lifecycle.
 *
 * Game code registers UI draw callbacks via addLayer(). Layers are
 * rendered back-to-front (lowest order first) during the render pass.
 */
class UISystem extends AbstractSystem
{
    private UIContext $ctx;

    /** @var array<string, array{callback: callable, order: int}> */
    private array $layers = [];

    /** @var list<string> sorted layer keys */
    private array $sortedKeys = [];
    private bool $dirty = true;

    public function __construct(
        Renderer2DInterface $renderer,
        Input $input,
        ?UIStyle $style = null,
    ) {
        $this->ctx = new UIContext($renderer, $input, $style);
    }

    public function getContext(): UIContext
    {
        return $this->ctx;
    }

    /**
     * Register a named UI layer.
     *
     * @param callable(UIContext): void $callback
     */
    public function addLayer(string $name, callable $callback, int $order = 0): void
    {
        $this->layers[$name] = ['callback' => $callback, 'order' => $order];
        $this->dirty = true;
    }

    public function removeLayer(string $name): void
    {
        unset($this->layers[$name]);
        $this->dirty = true;
    }

    public function hasLayer(string $name): bool
    {
        return isset($this->layers[$name]);
    }

    public function render(World $world): void
    {
        if ($this->layers === []) {
            return;
        }

        if ($this->dirty) {
            $this->sortedKeys = array_keys($this->layers);
            usort($this->sortedKeys, fn(string $a, string $b) =>
                $this->layers[$a]['order'] <=> $this->layers[$b]['order']
            );
            $this->dirty = false;
        }

        $this->ctx->beginFrame();
        foreach ($this->sortedKeys as $key) {
            ($this->layers[$key]['callback'])($this->ctx);
        }
    }
}
