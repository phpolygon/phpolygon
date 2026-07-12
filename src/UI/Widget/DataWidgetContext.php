<?php

declare(strict_types=1);

namespace PHPolygon\UI\Widget;

/**
 * A general-purpose {@see WidgetContext} over a plain data root (array or
 * object) plus an optional action map. Handy for tests and simple panels; games
 * with richer behaviour implement WidgetContext directly.
 *
 * Path resolution walks dotted segments, trying in order: an array key, a public
 * property, then a zero-argument method — so `selectedClient.companyName` and
 * `clients` (a getter) both resolve. Two-way {@see set()} writes back to object
 * properties or `setX()` setters; array roots are value types, so only a
 * top-level key on an array root is writable.
 */
final class DataWidgetContext implements WidgetContext
{
    /** @param  array<string, callable>  $actions  action name => handler */
    public function __construct(
        private mixed $root,
        private array $actions = [],
    ) {}

    public function get(string $path): mixed
    {
        $cur = $this->root;
        foreach ($this->segments($path) as $seg) {
            $cur = $this->step($cur, $seg);
            if ($cur === null) {
                return null;
            }
        }

        return $cur;
    }

    public function set(string $path, mixed $value): void
    {
        $segs = $this->segments($path);
        if ($segs === []) {
            return;
        }
        $last = array_pop($segs);
        $target = $segs === [] ? $this->root : $this->get(implode('.', $segs));

        if (is_object($target)) {
            if (property_exists($target, $last)) {
                $target->{$last} = $value;

                return;
            }
            $setter = 'set'.ucfirst($last);
            if (method_exists($target, $setter)) {
                $target->{$setter}($value);
            }

            return;
        }

        if (is_array($this->root) && $segs === []) {
            $this->root[$last] = $value;
        }
    }

    public function call(string $action, array $args = []): void
    {
        if (isset($this->actions[$action])) {
            ($this->actions[$action])(...$args);

            return;
        }
        if (is_object($this->root) && method_exists($this->root, $action)) {
            $this->root->{$action}(...$args);
        }
    }

    /** @return list<string> */
    private function segments(string $path): array
    {
        $path = trim($path);

        return $path === '' ? [] : explode('.', $path);
    }

    private function step(mixed $cur, string $seg): mixed
    {
        if (is_array($cur)) {
            return $cur[$seg] ?? null;
        }
        if (is_object($cur)) {
            if (property_exists($cur, $seg)) {
                return $cur->{$seg} ?? null;
            }
            if (method_exists($cur, $seg)) {
                return $cur->{$seg}();
            }
        }

        return null;
    }
}
