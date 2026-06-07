<?php

declare(strict_types=1);

namespace PHPolygon\Scene;

use PHPolygon\Engine;

abstract class Scene
{
    abstract public function getName(): string;

    abstract public function build(SceneBuilder $builder): void;

    /**
     * Progressive build: yields between phase groups so a caller (e.g. a
     * loading screen) can render between phases. The default runs the full
     * synchronous build() and yields once, so non-progressive scenes are
     * unaffected. Progressive scenes override this and yield per phase; their
     * build() should drive this generator to completion to avoid duplicating
     * phase logic.
     *
     * @return \Generator<int, mixed, mixed, void>
     */
    public function buildProgressive(SceneBuilder $builder): \Generator
    {
        $this->build($builder);
        yield;
    }

    /**
     * Ordered i18n keys (or plain labels) describing the load phases for a
     * progressive build, used to drive a phased loading-screen UI. Default
     * empty: no phased UI.
     *
     * @return list<string>
     */
    public function getLoadPhaseLabels(): array
    {
        return [];
    }

    /**
     * @return list<class-string<\PHPolygon\ECS\SystemInterface>>
     */
    public function getSystems(): array
    {
        return [];
    }

    public function getConfig(): SceneConfig
    {
        return new SceneConfig();
    }

    public function onLoad(Engine $engine): void {}

    public function onUnload(Engine $engine): void {}

    public function onActivate(Engine $engine): void {}

    public function onDeactivate(Engine $engine): void {}
}
