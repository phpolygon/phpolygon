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
    private const TRANSIENT = ['hovered', 'pressed', 'focused', 'open', 'scrollOffset', 'styleOverride',
        'bindings', 'eventBindings', 'each', 'template'];

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
        $widgetClass = $node['_widget'] ?? null;
        $class = is_string($widgetClass) && is_a($widgetClass, Widget::class, true) ? $widgetClass : Widget::class;
        $this->uses[$class] = true;
        $var = '$w'.$this->counter++;

        [$ctorArgs, $consumed] = $this->constructorArgs($class, $node);
        $body .= "        {$var} = new {$this->short($class)}({$ctorArgs});\n";

        foreach ($node as $key => $value) {
            if ($key === '_widget' || $key === '_id' || $key === 'children') {
                continue;
            }
            // Event/action bindings: { "$on": { "click": "confirmSetup" } }
            if ($key === '$on' && is_array($value)) {
                $body .= "        {$var}->eventBindings = {$this->renderArrayLiteral($value)};\n";

                continue;
            }
            // Repeater collection path: { "$each": "clients" }
            if ($key === '$each') {
                $each = is_scalar($value) ? (string) $value : '';
                $body .= "        {$var}->each = ".var_export($each, true).";\n";

                continue;
            }
            // Repeater row template -> a zero-parse row factory. The raw `template`
            // array is left empty so WidgetBinder builds rows by calling the
            // factory instead of reflecting the array once per item per frame.
            if ($key === 'template' && is_array($value)) {
                /** @var array<string, mixed> $value */
                $body .= $this->emitTemplateFactory($var, $value);

                continue;
            }
            // Value binding: { "text": { "$bind": "selectedClient.companyName" } }
            if (is_array($value) && array_key_exists('$bind', $value) && is_string($value['$bind'])) {
                $body .= "        {$var}->bindings[".var_export($key, true)."] = ".var_export($value['$bind'], true).";\n";

                continue;
            }
            if (in_array($key, self::TRANSIENT, true) || in_array($key, $consumed, true)) {
                continue;
            }
            $expr = $this->renderProp($class, $key, $value);
            if ($expr !== null) {
                $body .= "        {$var}->{$key} = {$expr};\n";
            }
        }

        /** @var list<array<string, mixed>> $children */
        $children = is_array($node['children'] ?? null) ? $node['children'] : [];
        foreach ($children as $child) {
            $childVar = $this->emitNode($child, $body);
            $body .= "        {$var}->addChild({$childVar});\n";
        }

        return $var;
    }

    /**
     * Emit a Repeater's row template as a `static function (): Widget` factory,
     * re-indented one level deeper than the surrounding builder statements.
     *
     * @param  array<string, mixed>  $template
     */
    private function emitTemplateFactory(string $var, array $template): string
    {
        $tplBody = '';
        $tplRoot = $this->emitNode($template, $tplBody);
        // Push each non-empty builder line one indent level deeper for readability.
        $indented = (string) preg_replace('/^(?=.)/m', '    ', $tplBody);

        return "        {$var}->templateFactory = static function (): Widget {\n"
            .$indented
            ."            return {$tplRoot};\n"
            ."        };\n";
    }

    /**
     * Emit a PHP array literal for a plain data array (e.g. an `$on` map or an
     * `array`-typed widget property), recursing into nested arrays and scalars.
     * List arrays emit positionally; maps emit `key => value`.
     */
    private function renderArrayLiteral(mixed $value): string
    {
        if (is_array($value)) {
            $isList = array_is_list($value);
            $parts = [];
            foreach ($value as $k => $v) {
                $item = $this->renderArrayLiteral($v);
                $parts[] = $isList ? $item : var_export((string) $k, true).' => '.$item;
            }

            return '['.implode(', ', $parts).']';
        }
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            return $this->float($value);
        }

        return var_export(is_scalar($value) ? (string) $value : '', true);
    }

    /**
     * @param  class-string<Widget>  $class
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
            // A bound constructor arg ({ "$bind": ... }) is not a literal — leave
            // it for the property-binding path so it becomes a `bindings[...]`.
            if (is_array($node[$name]) && array_key_exists('$bind', $node[$name])) {
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

    /**
     * @param  class-string<Widget>  $class
     */
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

        if (in_array($typeName, [Color::class, Vec2::class, Rect::class, Sizing::class, EdgeInsets::class], true)) {
            if (! is_array($value)) {
                return null;
            }
            /** @var array<string, mixed> $value */
            return $this->renderValueObject($typeName, $value);
        }

        return match ($typeName) {
            'int' => (string) (int) (is_numeric($value) ? $value : 0),
            'float' => $this->float((float) (is_numeric($value) ? $value : 0)),
            'bool' => $value ? 'true' : 'false',
            'string' => var_export(is_scalar($value) ? (string) $value : '', true),
            'array' => is_array($value) ? $this->renderArrayLiteral($value) : '[]',
            default => null,
        };
    }

    /**
     * `new Class(named: args)`, emitting only args that differ from the class's
     * own constructor defaults (keeps e.g. Sizing terse).
     *
     * @param  class-string  $fqcn
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
