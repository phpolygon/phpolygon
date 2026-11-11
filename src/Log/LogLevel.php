<?php

declare(strict_types=1);

namespace PHPolygon\Log;

enum LogLevel: string
{
    case Info = 'info';
    /** Written only in trace mode ({@see Log::setTrace()}). */
    case Trace = 'trace';
    case Warn = 'warn';
    case Error = 'error';
}
