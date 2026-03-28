<?php

declare(strict_types=1);

namespace PHPolygon\Thread;

enum ThreadingMode: string
{
    case SingleThreaded = 'single';
    case MultiThreaded = 'multi';
}
