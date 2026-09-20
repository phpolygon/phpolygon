<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Net;

/** The little game these sessions play: a number and what it was told. */
final class Tally
{
    public int $value = 0;

    /** @var list<string> */
    public array $told = [];

    /** @var list<string> news not yet passed on */
    public array $news = [];

    /** Something big enough that the state does not fit in one frame. */
    public string $blob = '';
}
