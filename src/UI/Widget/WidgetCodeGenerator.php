<?php

declare(strict_types=1);

namespace PHPolygon\UI\Widget;

use PHPolygon\Math\Rect;
use PHPolygon\Math\Vec2;
use PHPolygon\Rendering\Color;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Transpiles a serialized widget tree (the {@see WidgetSerializer} array form,
 * e.g. an editor-authored `*.ui.json`) into PHP source that rebuilds the tree.
 *
 * This is the UI counterpart to the scene {@see \PHPolygon\Scene\Transpiler\PhpCodeGenerator}:
 * the editor authors JSON; this generates the zero-parse-overhead PHP a game
 * runs. The output is a class with a static `build(): Widget` factory:
 *
 *   $root = MainMenuLayout::build();
 *   $tree = new WidgetTree($root, $renderer, $input, $w, $h);
 */
final class WidgetCodeGenerator
{
    /** Public properties that hold transient runtime state — never emitted. */
    private const TRANSIENT = ['hovered', 'pressed', 'focused', 'open', 'scrollOffset', 'styleOverride'];

    /** @var array<class-string, true> FQCNs to import. */
    private array $uses = [];

    private int $counter = 0;

    /**
     * @param  array<string, mixed>  $tree  Serialized root widget node.
     */
    public function generate(array $tree, string $className, string $namespace = ''): string
    {
        $this->uses = [Widget::class => true];
        $this->counter = 0;

        $body = '';
        $rootVar = $this->emitNode($tree, $body);

        $uses = array_keys($this->uses);
        sort($uses);

        $code = "<?php\n\ndeclare(strict_types=1);\n\n";
        if ($namespace !== '') {
            $code .= "namespace {$namespace};\n\n";
        }
        foreach ($uses as $use) {
            $code .= "use {$use};\n";
        }
        $code .= "\nfinal class {$className}\n{\n";
        $code .= "    public static function build(): Widget\n    {\n";
        $code .= $body;
        $code .= "\n        return {$rootVar};\n";
        $code .= "    }\n}\n";

        return $code;
    }

    /**
     * Emit statements that build one node, returning the variable holding it.
     *
     * @param  array<string, mixed>  $node
     */
    private function emitNode(array $node, string &$body): string
    {
        $class = is_string($node['_widget'] ?? null) ? $node['_widget'] : Widget::class;
        $this->uses[$class] = true;
        $var = '$w'.$this->counter++;

        [$ctorArgs, $consumed] = $this->constructorArgs($class, $node);
        $body .= "        {$var} = new {$this->short($class)}({$ctorArgs});\n";

        foreach ($node as $key => $value) {
            if (in_array($key, ['_widget', '_id', 'children'], true)
                || in_array($key, self::TRANSIENT, true)
                || in_array($key, $consumed, true)
            ) {
                continue;
            }
            $expr = $this->renderProp($class, $key, $value);
            if ($expr !== null) {
                $body .= "        {$var}->{$key} = {$expr};\n";
            }
        }

        $children = is_array($node['children'] ?? null) ? $node['children'] : [];
        foreach ($children as $child) {
            if (is_array($child)) {
                $childVar = $this->emitNode($child, $body);
                $body .= "        {$var}->addChild({$childVar});\n";
            }
        }

        return $var;
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array{0: string, 1: list<string>} Rendered args and consumed keys.
     */
    private function constructorArgs(string $class, array $node): array
    {
        $ctor = (new ReflectionClass($class))->getConstructor();
        if ($ctor === null) {
            return ['', []];
        }

        $args = [];
        $consumed = [];
        foreach ($ctor->getParameters() as $param) {
            $name = $param->getName();
            if (! array_key_exists($name, $node)) {
                continue;
            }
            if ($param->isDefaultValueAvailable() && $this->scalarEquals($node[$name], $param->getDefaultValue())) {
                $consumed[] = $name; // matches default — no need to pass it
                continue;
            }
            $expr = $this->renderByType($this->typeName($param->getType()), $node[$name]);
            if ($expr !== null) {
                $args[] = "{$name}: {$expr}";
                $consumed[] = $name;
            }
        }

        return [implode(', ', $args), $consumed];
    }

    private function renderProp(string $class, string $key, mixed $value): ?string
    {
        $ref = new ReflectionClass($class);
        if (! $ref->hasProperty($key)) {
            return null;
        }
        $type = $ref->getProperty($key)->getType();

        return $this->renderByType($this->typeName($type), $value);
    }

    private function renderByType(?string $typeName, mixed $value): ?string
    {
        if ($value === null) {
            return 'null';
        }

        return match ($typeName) {
            'int' => (string) (int) (is_numeric($value) ? $value : 0),
            'float' => $this->float((float) (is_numeric($value) ? $value : 0)),
            'bool' => $value ? 'true' : 'false',
            'string' => var_export((string) $value, true),
            Color::class, Vec2::class, Rect::class, Sizing::class, EdgeInsets::class => is_array($value)
                ? $this->renderValueObject($typeName, $value)
                : null,
            default => null,
        };
    }

    /**
     * `new Class(named: args)`, emitting only args that differ from the class's
     * own constructor defaults (keeps e.g. Sizing terse).
     *
     * @param  array<string, mixed>  $data
     */
    private function renderValueObject(string $fqcn, array $data): string
    {
        $this->uses[$fqcn] = true;
        $ctor = (new ReflectionClass($fqcn))->getConstructor();
        if ($ctor === null) {
            return "new {$this->short($fqcn)}()";
        }

        $args = [];
        foreach ($ctor->getParameters() as $param) {
            $name = $param->getName();
            if (! array_key_exists($name, $data)) {
                continue;
            }
            if ($param->isDefaultValueAvailable() && $this->scalarEquals($data[$name], $param->getDefaultValue())) {
                continue;
            }
            $expr = $this->renderByType($this->typeName($param->getType()), $data[$name]);
            if ($expr !== null) {
                $args[] = "{$name}: {$expr}";
            }
        }

        return "new {$this->short($fqcn)}(".implode(', ', $args).')';
    }

    private function scalarEquals(mixed $a, mixed $b): bool
    {
        if (is_bool($a) || is_bool($b)) {
            return (bool) $a === (bool) $b;
        }
        if (is_numeric($a) && is_numeric($b)) {
            return (float) $a === (float) $b;
        }

        return $a === $b;
    }

    private function float(float $value): string
    {
        $out = var_export($value, true);

        return str_contains($out, '.') || str_contains($out, 'E') ? $out : $out.'.0';
    }

    private function typeName(?\ReflectionType $type): ?string
    {
        return $type instanceof ReflectionNamedType ? $type->getName() : null;
    }

    private function short(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
    }
}
