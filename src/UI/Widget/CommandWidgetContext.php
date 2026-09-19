<?php

declare(strict_types=1);

namespace PHPolygon\UI\Widget;

use PHPolygon\Command\CommandBus;
use PHPolygon\Command\CommandRegistry;

/**
 * Lets a panel layout name a command where it would name a handler.
 *
 * `{"$on": {"click": "cmd:projects.accept"}}` builds that command and hands it
 * to the {@see CommandBus} - so the click is checked, applied and, in a
 * session, sent to the host, without the game writing a handler for it. A
 * layout stays free of logic either way: it names what is to happen, never how.
 *
 * Where the command's data come from, in this order:
 * - what the layout says: `cmd:mail.set_speed?speed=2`, read as the
 *   constructor declares it (int, float, bool, string);
 * - the row the button belongs to: a {@see Repeater} hands its item along, and
 *   a constructor parameter is filled from the item's field of that name. With
 *   nested repeaters the innermost row wins, as it is the one clicked.
 *
 * Anything that cannot become a command - an unknown name, a missing value, a
 * value of the wrong type - does nothing at all: a layout is data from disk,
 * and a game must not crash over one. Every other action goes on to the
 * context this one wraps.
 */
final class CommandWidgetContext implements WidgetContext
{
    /** What marks an action as a command rather than a handler. */
    public const PREFIX = 'cmd:';

    public function __construct(
        private readonly WidgetContext $inner,
        private readonly CommandRegistry $registry,
        private readonly CommandBus $bus,
        private readonly object $state,
    ) {}

    public function get(string $path): mixed
    {
        return $this->inner->get($path);
    }

    public function set(string $path, mixed $value): void
    {
        $this->inner->set($path, $value);
    }

    public function call(string $action, array $args = []): void
    {
        if (!str_starts_with($action, self::PREFIX)) {
            $this->inner->call($action, $args);
            return;
        }
        $command = $this->build(substr($action, strlen(self::PREFIX)), $args);
        if ($command !== null) {
            $this->bus->dispatch($this->state, $command);
        }
    }

    /** @param list<mixed> $args */
    private function build(string $action, array $args): ?\PHPolygon\Command\Command
    {
        [$type, $query] = array_pad(explode('?', $action, 2), 2, '');
        $class = $this->registry->classFor($type);
        if ($class === null) {
            return null;
        }
        parse_str($query, $literals);
        $row = new DataWidgetContext(self::rowOf($args));

        $data = [];
        foreach (self::parameters($class) as $name => $type) {
            $value = array_key_exists($name, $literals) && is_string($literals[$name])
                ? self::read($literals[$name], $type)
                : $row->get($name);
            if ($value !== null) {
                $data[$name] = $value;
            }
        }
        // Anything named that the command does not take is a layout that means
        // something else; fromArray() turns it away, and so must this.
        foreach ($literals as $name => $value) {
            if (!array_key_exists($name, $data)) {
                $data[(string) $name] = $value;
            }
        }
        return $class::fromArray($data);
    }

    /**
     * The row this click came from: repeaters hand their item along, the
     * innermost one last.
     *
     * @param list<mixed> $args
     */
    private static function rowOf(array $args): mixed
    {
        $row = null;
        foreach ($args as $arg) {
            if (is_array($arg) || is_object($arg)) {
                $row = $arg;
            }
        }
        return $row;
    }

    /**
     * @param class-string<\PHPolygon\Command\Command> $class
     * @return array<string, string> constructor parameter => its declared type
     */
    private static function parameters(string $class): array
    {
        $parameters = [];
        foreach ((new \ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();
            $parameters[$parameter->getName()] = $type instanceof \ReflectionNamedType ? $type->getName() : '';
        }
        return $parameters;
    }

    /** A value the layout wrote down, as the constructor declares it; null when it does not fit. */
    private static function read(string $value, string $type): int|float|bool|string|null
    {
        return match ($type) {
            'int'    => preg_match('/^-?\d+$/', $value) === 1 ? (int) $value : null,
            'float'  => is_numeric($value) ? (float) $value : null,
            'bool'   => match (strtolower($value)) {
                'true', '1', 'yes'  => true,
                'false', '0', 'no'  => false,
                default             => null,
            },
            'string' => $value,
            default  => null,
        };
    }
}
