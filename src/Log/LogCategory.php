<?php

declare(strict_types=1);

namespace PHPolygon\Log;

/**
 * Where a log line comes from. The value is the tag in the written line
 * (`[ui]`, `[system]` ...), so a category is one grep away.
 */
enum LogCategory: string
{
    /** Engine start-up, shutdown and everything written through Engine::log(). */
    case Engine = 'engine';
    /** ECS systems: added, removed, first run, slow, failed, per-frame times. */
    case System = 'system';
    /** Events sent through the EventDispatcher. */
    case Event = 'event';
    /** Key, mouse button and gamepad edges. */
    case Input = 'input';
    /** Widgets of the immediate-mode UI. */
    case Ui = 'ui';
    /** Scenes loaded and unloaded. */
    case Scene = 'scene';
    /** Changed settings. */
    case Settings = 'settings';
    /** Window size, mode and focus. */
    case Window = 'window';
    /** Save slots written, read and deleted. */
    case Save = 'save';
    /** PHP warnings and errors, failures reported by the engine. */
    case Error = 'error';
    /** Lines the game writes itself. */
    case Game = 'game';
}
