<?php

declare(strict_types=1);

namespace PHPolygon\UI;

use PHPolygon\Math\Rect;
use RuntimeException;

/**
 * Data-driven layout for immediate-mode panels.
 *
 * Unlike the retained-mode {@see Widget} tree (which owns its own rendering and
 * input), a PanelLayout carries only *data*: named elements, each a design-space
 * rectangle plus arbitrary props (labels/i18n keys, style ids, colors). The
 * game's existing immediate-mode `draw($engine, $w, $h, $state)` panels keep
 * their rendering logic and simply read positions/sizes from here instead of
 * hardcoding constants:
 *
 *   $layout = PanelLayout::loadFile('assets/ui/main_menu.layout.json');
 *   $r = $layout->rect('play_button');
 *   if ($ui->button('play', \T($layout->str('play_button', 'label')), $r->width)) { ... }
 *
 * Coordinates are in the game's design space (e.g. 1280×720); the panel applies
 * its own transform/scissor as today. This is the editor-authorable data the
 * roadmap's "UI layouts — JSON" refers to, without requiring a WidgetTree.
 *
 * @phpstan-type Element array<string, mixed>
 */
final class PanelLayout
{
    /** @var array<string, array<string, mixed>> id => element props (incl. x/y/width/height) */
    private array $elements;

    private string $name;

    /**
     * @param array<string, array<string, mixed>> $elements
     */
    public function __construct(array $elements = [], string $name = '')
    {
        $this->elements = $elements;
        $this->name = $name;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $rawElements = is_array($data['elements'] ?? null) ? $data['elements'] : [];
        $elements = [];
        foreach ($rawElements as $id => $props) {
            if (is_string($id) && is_array($props)) {
                /** @var array<string, mixed> $props */
                $elements[$id] = $props;
            }
        }

        return new self($elements, is_string($data['name'] ?? null) ? $data['name'] : '');
    }

    public static function loadFile(string $path): self
    {
        if (! is_file($path)) {
            throw new RuntimeException("Panel layout file not found: {$path}");
        }
        $raw = file_get_contents($path);
        $data = $raw !== false ? json_decode($raw, true) : null;
        if (! is_array($data)) {
            throw new RuntimeException("Invalid panel layout JSON: {$path}");
        }

        /** @var array<string, mixed> $data */
        return self::fromArray($data);
    }

    public function saveFile(string $path): void
    {
        file_put_contents($path, json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['_format' => 1, 'name' => $this->name, 'elements' => $this->elements];
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function has(string $id): bool
    {
        return isset($this->elements[$id]);
    }

    /** @return list<string> */
    public function ids(): array
    {
        return array_keys($this->elements);
    }

    /**
     * The design-space rectangle of an element. Throws for an unknown id so a
     * typo fails fast in development rather than silently drawing at (0,0).
     */
    public function rect(string $id): Rect
    {
        $e = $this->element($id);

        return new Rect($this->f($e, 'x'), $this->f($e, 'y'), $this->f($e, 'width'), $this->f($e, 'height'));
    }

    public function float(string $id, string $key, float $default = 0.0): float
    {
        $v = $this->element($id)[$key] ?? null;

        return is_numeric($v) ? (float) $v : $default;
    }

    public function int(string $id, string $key, int $default = 0): int
    {
        $v = $this->element($id)[$key] ?? null;

        return is_numeric($v) ? (int) $v : $default;
    }

    public function str(string $id, string $key, string $default = ''): string
    {
        $v = $this->element($id)[$key] ?? null;

        return is_string($v) ? $v : $default;
    }

    public function bool(string $id, string $key, bool $default = false): bool
    {
        $v = $this->element($id)[$key] ?? null;

        return is_bool($v) ? $v : $default;
    }

    // ── Mutation (editor-side authoring) ─────────────────────────

    /**
     * @param array<string, mixed> $props
     */
    public function set(string $id, array $props): void
    {
        $this->elements[$id] = array_merge($this->elements[$id] ?? [], $props);
    }

    public function setRect(string $id, Rect $rect): void
    {
        $this->set($id, ['x' => $rect->x, 'y' => $rect->y, 'width' => $rect->width, 'height' => $rect->height]);
    }

    public function remove(string $id): void
    {
        unset($this->elements[$id]);
    }

    public function rename(string $oldId, string $newId): void
    {
        if (! isset($this->elements[$oldId]) || $oldId === $newId) {
            return;
        }
        $this->elements[$newId] = $this->elements[$oldId];
        unset($this->elements[$oldId]);
    }

    /**
     * @return array<string, mixed>
     */
    private function element(string $id): array
    {
        if (! isset($this->elements[$id])) {
            throw new RuntimeException("Unknown panel layout element: {$id}");
        }

        return $this->elements[$id];
    }

    /**
     * @param array<string, mixed> $element
     */
    private function f(array $element, string $key): float
    {
        return is_numeric($element[$key] ?? null) ? (float) $element[$key] : 0.0;
    }
}
