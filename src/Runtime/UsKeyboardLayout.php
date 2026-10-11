<?php

declare(strict_types=1);

namespace PHPolygon\Runtime;

/** The US layout the key codes are named after: 'z' is key 90. */
final class UsKeyboardLayout implements KeyboardLayoutInterface
{
    public function keyFor(string $char): int
    {
        if (strlen($char) !== 1 || !ctype_alnum($char)) {
            return -1;
        }
        return ord(strtoupper($char));
    }
}
