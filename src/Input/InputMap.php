<?php

declare(strict_types=1);

namespace PHPolygon\Input;

use PHPolygon\Runtime\InputInterface;
use RuntimeException;

/**
 * Named actions and axes over raw key / mouse-button input.
 *
 * Actions are rebindable: the bindings passed to {@see addAction()} are the
 * action's defaults, {@see rebind()} replaces one of them after checking it
 * against reserved keys, locked actions, axes, an optional filter and the
 * other actions (conflict policy). {@see exportOverrides()} /
 * {@see importOverrides()} hand the player's changes to the game as a plain
 * array - the engine does not persist them itself.
 */
class InputMap
{
    /** @var array<string, InputAction> */
    private array $actions = [];

    /** @var array<string, list<InputBinding>> */
    private array $bindings = [];

    /** @var array<string, list<InputBinding>> */
    private array $defaultBindings = [];

    /** @var array<string, true> actions the player may not rebind */
    private array $locked = [];

    /** @var list<InputBinding> */
    private array $reserved = [];

    /** @var (\Closure(InputBinding): bool)|null */
    private ?\Closure $bindableFilter = null;

    private RebindConflictPolicy $conflictPolicy = RebindConflictPolicy::Reject;

    /** @var array<string, array{positive: list<InputBinding>, negative: list<InputBinding>}> */
    private array $axes = [];

    /** @var array<string, float> */
    private array $axisValues = [];

    /**
     * Define an action. The bindings given here are its defaults - what
     * {@see resetBinding()} returns to.
     */
    public function addAction(string $name, InputBinding ...$bindings): self
    {
        $this->actions[$name] = new InputAction($name);
        $this->bindings[$name] = array_values($bindings);
        $this->defaultBindings[$name] = array_values($bindings);
        return $this;
    }

    /**
     * @param list<InputBinding> $positive
     * @param list<InputBinding> $negative
     */
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

    /** @return list<string> */
    public function getActionNames(): array
    {
        return array_keys($this->actions);
    }

    // ---- Rebinding ------------------------------------------------------

    /** @return list<InputBinding> the action's current bindings */
    public function getBindings(string $action): array
    {
        $this->requireAction($action);
        return $this->bindings[$action];
    }

    /** @return list<InputBinding> the bindings the action was defined with */
    public function getDefaultBindings(string $action): array
    {
        $this->requireAction($action);
        return $this->defaultBindings[$action];
    }

    /** The current binding in $slot (0 = primary), null when the slot is empty. */
    public function getBinding(string $action, int $slot = 0): ?InputBinding
    {
        $this->requireAction($action);
        return $this->bindings[$action][$slot] ?? null;
    }

    /** True when the action still has exactly its default bindings. */
    public function isDefault(string $action): bool
    {
        $this->requireAction($action);
        return self::sameList($this->bindings[$action], $this->defaultBindings[$action]);
    }

    /**
     * Mark an action (not) rebindable. A locked action keeps its bindings and
     * they count as reserved: no other action can take them.
     */
    public function setRebindable(string $action, bool $rebindable): self
    {
        $this->requireAction($action);
        if ($rebindable) {
            unset($this->locked[$action]);
        } else {
            $this->locked[$action] = true;
        }
        return $this;
    }

    public function isRebindable(string $action): bool
    {
        return isset($this->actions[$action]) && !isset($this->locked[$action]);
    }

    /**
     * Keys or buttons no action may be rebound to - ones the game reads
     * directly (pause, a debug key) rather than through this map. A bare int
     * is a key code.
     */
    public function reserve(InputBinding|int ...$bindings): self
    {
        foreach ($bindings as $binding) {
            $this->reserved[] = is_int($binding) ? InputBinding::key($binding) : $binding;
        }
        return $this;
    }

    /**
     * True when $binding is off limits for rebinding: reserved, held by a
     * locked action, or used by an axis.
     */
    public function isReserved(InputBinding|int $binding): bool
    {
        $binding = self::normalize($binding);
        if (self::contains($this->reserved, $binding)) {
            return true;
        }
        foreach ($this->locked as $action => $_) {
            if (self::contains($this->bindings[$action], $binding)) {
                return true;
            }
        }
        foreach ($this->axes as $axis) {
            if (self::contains($axis['positive'], $binding) || self::contains($axis['negative'], $binding)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Restrict what may be bound at all, e.g. letters only. Null allows
     * everything that is not reserved.
     *
     * @param (\Closure(InputBinding): bool)|null $filter
     */
    public function setBindableFilter(?\Closure $filter): self
    {
        $this->bindableFilter = $filter;
        return $this;
    }

    public function setConflictPolicy(RebindConflictPolicy $policy): self
    {
        $this->conflictPolicy = $policy;
        return $this;
    }

    public function getConflictPolicy(): RebindConflictPolicy
    {
        return $this->conflictPolicy;
    }

    /** The action other than $except whose bindings include $binding, or null. */
    public function findActionFor(InputBinding|int $binding, ?string $except = null): ?string
    {
        $binding = self::normalize($binding);
        foreach ($this->bindings as $name => $list) {
            if ($name !== $except && self::contains($list, $binding)) {
                return $name;
            }
        }
        return null;
    }

    /**
     * What {@see rebind()} would answer, without changing anything. Useful to
     * grey out candidates in a key-binding menu.
     */
    public function checkRebind(string $action, InputBinding|int $binding, int $slot = 0): RebindResult
    {
        return $this->rebindInternal($action, self::normalize($binding), $slot, apply: false);
    }

    /**
     * Bind $binding to the action's $slot (0 = primary; slot == count appends).
     *
     * Rejected when the action is unknown or locked, the binding is reserved
     * or filtered out, or another action holds it and the policy is Reject.
     * With policy Swap the other action receives this action's previous
     * binding of that slot (or loses the binding if the slot was empty). A
     * binding the action already holds in another slot is moved, not doubled.
     */
    public function rebind(string $action, InputBinding|int $binding, int $slot = 0): RebindResult
    {
        return $this->rebindInternal($action, self::normalize($binding), $slot, apply: true);
    }

    /**
     * Back to the defaults. Defaults never conflict with each other, so any
     * other action that has since taken one of them through a rebind is
     * reset too.
     */
    public function resetBinding(string $action): void
    {
        $this->requireAction($action);
        $this->resetCascade($action, []);
    }

    public function resetAllBindings(): void
    {
        foreach ($this->defaultBindings as $name => $defaults) {
            $this->bindings[$name] = $defaults;
        }
    }

    /**
     * The player's changes: only actions that differ from their defaults.
     * Store it however the game stores settings; feed it back through
     * {@see importOverrides()}.
     *
     * @return array<string, list<array{type: string, code: int}>>
     */
    public function exportOverrides(): array
    {
        $out = [];
        foreach ($this->bindings as $name => $list) {
            if (!self::sameList($list, $this->defaultBindings[$name])) {
                $out[$name] = array_map(static fn (InputBinding $b): array => $b->toArray(), $list);
            }
        }
        return $out;
    }

    /**
     * Restore overrides from {@see exportOverrides()}. Starts from the
     * defaults, so actions missing from $data are default afterwards.
     *
     * Each entry is a list of {@see InputBinding::toArray()} arrays; a bare
     * int (or a list of ints) is read as key codes. Entries that would not
     * pass {@see rebind()} today - unknown or locked action, reserved or
     * filtered key, unreadable data - are dropped, and when two imported
     * actions end up sharing a binding the later one falls back to its
     * defaults.
     *
     * @param array<array-key, mixed> $data
     * @return list<string> actions whose stored override was dropped
     */
    public function importOverrides(array $data): array
    {
        $this->resetAllBindings();
        $dropped = [];
        $imported = [];

        foreach ($data as $name => $entry) {
            $name = (string) $name;
            if (!isset($this->actions[$name]) || isset($this->locked[$name])) {
                $dropped[] = $name;
                continue;
            }
            $list = $this->parseBindings($entry);
            if ($list === null) {
                $dropped[] = $name;
                continue;
            }
            $this->bindings[$name] = $list;
            $imported[] = $name;
        }

        // Shared bindings: walk the imported actions in order and drop the
        // later of each clashing pair back to defaults until nothing clashes.
        $changed = true;
        while ($changed) {
            $changed = false;
            foreach (array_reverse($imported, true) as $i => $name) {
                foreach ($this->bindings[$name] as $binding) {
                    if ($this->findActionFor($binding, $name) !== null) {
                        $this->bindings[$name] = $this->defaultBindings[$name];
                        $dropped[] = $name;
                        unset($imported[$i]);
                        $changed = true;
                        continue 3;
                    }
                }
            }
        }

        return $dropped;
    }

    /** @internal Called by InputMapSystem each frame */
    public function poll(InputInterface $input): void
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

    private function isBindingDown(InputInterface $input, InputBinding $binding): bool
    {
        return match ($binding->type) {
            InputBindingType::Key => $input->isKeyDown($binding->code),
            InputBindingType::MouseButton => $input->isMouseButtonDown($binding->code),
        };
    }

    private function rebindInternal(string $action, InputBinding $binding, int $slot, bool $apply): RebindResult
    {
        if (!isset($this->actions[$action])) {
            return new RebindResult(RebindStatus::UnknownAction);
        }
        if (isset($this->locked[$action])) {
            return new RebindResult(RebindStatus::Locked);
        }
        $list = $this->bindings[$action];
        if ($slot < 0 || $slot > count($list)) {
            return new RebindResult(RebindStatus::InvalidSlot);
        }
        $old = $list[$slot] ?? null;
        if ($old !== null && $old->equals($binding)) {
            return new RebindResult(RebindStatus::Unchanged);
        }
        if ($this->bindableFilter !== null && !($this->bindableFilter)($binding)) {
            return new RebindResult(RebindStatus::NotBindable);
        }
        if ($this->isReserved($binding)) {
            return new RebindResult(RebindStatus::Reserved);
        }

        $other = $this->findActionFor($binding, $action);
        if ($other !== null && $this->conflictPolicy === RebindConflictPolicy::Reject) {
            return new RebindResult(RebindStatus::Conflict, $other);
        }
        if (!$apply) {
            return new RebindResult($other !== null ? RebindStatus::Swapped : RebindStatus::Bound, $other);
        }

        // Already bound to another slot of the same action: move it there.
        $ownSlot = self::indexOf($list, $binding);
        if ($ownSlot !== null) {
            $list = self::replaceOrRemove($list, $ownSlot, $old);
            if ($old === null) {
                $slot = min($slot, count($list));
            }
        }
        $list[$slot] = $binding;
        $this->bindings[$action] = array_values($list);

        if ($other !== null) {
            $theirs = $this->bindings[$other];
            $index = self::indexOf($theirs, $binding);
            if ($index !== null) {
                $this->bindings[$other] = self::replaceOrRemove($theirs, $index, $old);
            }
            return new RebindResult(RebindStatus::Swapped, $other);
        }
        return new RebindResult(RebindStatus::Bound);
    }

    /**
     * @param array<string, true> $visited
     * @return array<string, true>
     */
    private function resetCascade(string $action, array $visited): array
    {
        $visited[$action] = true;
        $this->bindings[$action] = $this->defaultBindings[$action];
        foreach ($this->defaultBindings[$action] as $binding) {
            foreach ($this->bindings as $name => $list) {
                if (!isset($visited[$name]) && self::contains($list, $binding)) {
                    $visited = $this->resetCascade($name, $visited);
                }
            }
        }
        return $visited;
    }

    /** @return list<InputBinding>|null */
    private function parseBindings(mixed $entry): ?array
    {
        if (is_int($entry) || (is_array($entry) && isset($entry['type']))) {
            $entry = [$entry];
        }
        if (!is_array($entry) || $entry === []) {
            return null;
        }
        $list = [];
        foreach ($entry as $item) {
            $binding = InputBinding::fromArray($item);
            if ($binding === null
                || self::contains($list, $binding)
                || $this->isReserved($binding)
                || ($this->bindableFilter !== null && !($this->bindableFilter)($binding))) {
                return null;
            }
            $list[] = $binding;
        }
        return $list;
    }

    private function requireAction(string $action): void
    {
        if (!isset($this->actions[$action])) {
            throw new RuntimeException("Input action '{$action}' not found");
        }
    }

    private static function normalize(InputBinding|int $binding): InputBinding
    {
        return is_int($binding) ? InputBinding::key($binding) : $binding;
    }

    /** @param list<InputBinding> $list */
    private static function contains(array $list, InputBinding $binding): bool
    {
        return self::indexOf($list, $binding) !== null;
    }

    /** @param list<InputBinding> $list */
    private static function indexOf(array $list, InputBinding $binding): ?int
    {
        foreach ($list as $i => $candidate) {
            if ($candidate->equals($binding)) {
                return $i;
            }
        }
        return null;
    }

    /**
     * @param list<InputBinding> $list
     * @return list<InputBinding>
     */
    private static function replaceOrRemove(array $list, int $index, ?InputBinding $replacement): array
    {
        if ($replacement === null) {
            array_splice($list, $index, 1);
            return $list;
        }
        $list[$index] = $replacement;
        return array_values($list);
    }

    /**
     * @param list<InputBinding> $a
     * @param list<InputBinding> $b
     */
    private static function sameList(array $a, array $b): bool
    {
        if (count($a) !== count($b)) {
            return false;
        }
        foreach ($a as $i => $binding) {
            if (!$binding->equals($b[$i])) {
                return false;
            }
        }
        return true;
    }
}
