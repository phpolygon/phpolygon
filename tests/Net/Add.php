<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Net;

use PHPolygon\Command\Command;

final class Add extends Command
{
    public function __construct(public readonly int $amount) {}

    public static function type(): string { return 'tally.add'; }
}
