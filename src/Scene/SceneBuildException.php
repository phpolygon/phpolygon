<?php

declare(strict_types=1);

namespace PHPolygon\Scene;

use RuntimeException;
use Throwable;

/**
 * Thrown when a scene fails to construct — i.e. {@see Scene::build()} /
 * {@see Scene::buildProgressive()} or the subsequent entity materialisation
 * raises any Throwable (including Errors such as calling an undefined method).
 *
 * The SceneManager catches the original failure, rolls back the partially
 * registered scene state, and rethrows it wrapped in this exception so the
 * error surfaces loudly with the scene name attached instead of leaving the
 * world in a half-built, silently-empty state.
 */
final class SceneBuildException extends RuntimeException
{
    public function __construct(
        public readonly string $sceneName,
        Throwable $previous,
    ) {
        parent::__construct(
            sprintf(
                "Scene '%s' failed to build: %s: %s",
                $sceneName,
                $previous::class,
                $previous->getMessage(),
            ),
            0,
            $previous,
        );
    }
}
