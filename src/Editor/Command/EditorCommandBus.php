<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Command;

use PHPolygon\Editor\EditorContext;
use RuntimeException;

class EditorCommandBus
{
    /** @var array<string, class-string<CommandInterface>> */
    private array $commands = [];

    public function __construct(
        private readonly EditorContext $context,
    ) {}

    /**
     * @param class-string<CommandInterface> $commandClass
     */
    public function register(string $name, string $commandClass): void
    {
        $this->commands[$name] = $commandClass;
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function dispatch(string $name, array $args = []): array
    {
        if (!isset($this->commands[$name])) {
            throw new RuntimeException("Unknown editor command: {$name}");
        }

        $class = $this->commands[$name];
        $command = new $class($args);
        return $command->execute($this->context);
    }

    /** @return list<string> */
    public function getRegisteredCommands(): array
    {
        return array_keys($this->commands);
    }
}
