<?php

declare(strict_types=1);

namespace PHPolygon\Input;

use PHPolygon\Runtime\Input;
use RuntimeException;

class InputMap
{
    /** @var array<string, InputAction> */
    private array $actions = [];

    /** @var array<string, list<InputBinding>> */
    private array $bindings = [];

    /** @var array<string, array{positive: list<InputBinding>, negative: list<InputBinding>}> */
    private array $axes = [];

    /** @var array<string, float> */
    private array $axisValues = [];

    public function addAction(string $name, InputBinding ...$bindings): self
    {
        $this->actions[$name] = new InputAction($name);
        $this->bindings[$name] = $bindings;
        return $this;
    }

    public function addAxis(string $name, array $positive, array $negative): self
    {
        $this->axes[$name] = [
            'positive' => $positive,
            'negative' => $negative,
        ];
        $this->axisValues[$name] = 0.0;
        return $this;
    }

    public function getAction(string $name): InputAction
    {
        if (!isset($this->actions[$name])) {
            throw new RuntimeException("Input action '{$name}' not found");
        }
        return $this->actions[$name];
    }

    public function getAxis(string $name): float
    {
        return $this->axisValues[$name] ?? 0.0;
    }

    public function hasAction(string $name): bool
    {
        return isset($this->actions[$name]);
    }

    public function hasAxis(string $name): bool
    {
        return isset($this->axes[$name]);
    }

    /** @internal Called by InputMapSystem each frame */
    public function poll(Input $input): void
    {
        // Poll actions
        foreach ($this->bindings as $name => $bindings) {
            $down = false;
            foreach ($bindings as $binding) {
                if ($this->isBindingDown($input, $binding)) {
                    $down = true;
                    break;
                }
            }
            $this->actions[$name]->update($down);
        }

        // Poll axes
        foreach ($this->axes as $name => $axis) {
            $value = 0.0;
            foreach ($axis['positive'] as $binding) {
                if ($this->isBindingDown($input, $binding)) {
                    $value += 1.0;
                    break;
                }
            }
            foreach ($axis['negative'] as $binding) {
                if ($this->isBindingDown($input, $binding)) {
                    $value -= 1.0;
                    break;
                }
            }
            $this->axisValues[$name] = $value;
        }
    }

    private function isBindingDown(Input $input, InputBinding $binding): bool
    {
        return match ($binding->type) {
            InputBindingType::Key => $input->isKeyDown($binding->code),
            InputBindingType::MouseButton => $input->isMouseButtonDown($binding->code),
        };
    }
}
