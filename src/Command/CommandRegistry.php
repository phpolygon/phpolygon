<?php

declare(strict_types=1);

namespace PHPolygon\Command;

/**
 * Every command a game knows, by its wire name. A command class that is not
 * registered cannot arrive over the network.
 */
final class CommandRegistry
{
    /** @var array<string, class-string<Command>> wire name => class */
    private array $byType = [];

    /** @param iterable<class-string<Command>> $classes */
    public function __construct(iterable $classes = [])
    {
        foreach ($classes as $class) {
            $this->register($class);
        }
    }

    /** @param class-string<Command> $class */
    public function register(string $class): void
    {
        $type = $class::type();
        if (isset($this->byType[$type]) && $this->byType[$type] !== $class) {
            throw new \LogicException("Command type {$type} is taken by {$this->byType[$type]} and {$class}");
        }
        $this->byType[$type] = $class;
    }

    /** @return array<string, class-string<Command>> wire name => class */
    public function all(): array
    {
        return $this->byType;
    }

    /** @return null|class-string<Command> */
    public function classFor(string $type): ?string
    {
        return $this->byType[$type] ?? null;
    }

    /** @return array{type: string, data: array<string, mixed>} */
    public function encode(Command $command): array
    {
        return ['type' => $command::type(), 'data' => $command->toArray()];
    }

    /**
     * A command from the wire, or null when it is not one this registry knows
     * or its data do not fit.
     */
    public function decode(mixed $message): ?Command
    {
        if (!is_array($message) || !is_string($message['type'] ?? null) || !is_array($message['data'] ?? null)) {
            return null;
        }
        $class = $this->classFor($message['type']);
        if ($class === null) {
            return null;
        }
        /** @var array<string, mixed> $data */
        $data = $message['data'];
        return $class::fromArray($data);
    }
}
