<?php

declare(strict_types=1);

namespace PHPolygon;

/**
 * How a running game shares its world with an editor.
 *
 * @see Engine::enableEditorSync()
 */
enum EditorSyncMode: string
{
    /**
     * Export when the world structurally advances; import an editor's save when
     * it has not.
     *
     * Code stays authoritative: whatever the game does wins, and edits are
     * picked up only while the game is not changing the world itself (paused,
     * or an idle scene). Cheap — a still world writes nothing.
     */
    case Reconcile = 'reconcile';

    /**
     * Export every interval regardless of whether the world advanced, and never
     * import.
     *
     * What a live VIEW needs: movement changes component values without
     * changing the world's structure, so {@see Reconcile} would show a world
     * that never moves. The cost is a full serialization per interval, so this
     * is for watching a running game, not for leaving on in a shipped build.
     */
    case Stream = 'stream';
}
