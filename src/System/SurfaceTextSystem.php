<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\SurfaceText;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Locale\LocaleManager;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\Text\SurfaceTextBaker;

/**
 * Keeps every {@see SurfaceText} on its current text: the only writer of the
 * material of those surfaces.
 *
 * New surfaces, {@see SurfaceText::setText()} and a change of language all end
 * up here, and nothing else in the world is touched. Textures come in within a
 * time budget per tick, so changing the language does not stall a frame: a
 * surface shows its old text until the new one is ready. Call {@see prewarm()}
 * from a loading phase to have everything ready on the first frame.
 *
 * Register before Renderer3DSystem. The system reads the active pools: a dormant
 * surface catches up when it wakes, as a plain plate if its texture went meanwhile.
 *
 * Textures nothing shows go once no surface changed for RETIRE_FRAMES frames. A
 * text that changes every single frame therefore keeps its old textures for as
 * long as it does: use a screen overlay for that, not a baked surface.
 */
class SurfaceTextSystem extends AbstractSystem
{
    /**
     * Rendered frames an old texture stays alive after the switch: frames still
     * in flight may read it, and php-vio's D3D12 backend releases at once.
     */
    private const int RETIRE_FRAMES = 8;

    /** Bumped by every language change; surfaces with another stamp are resolved again. */
    private int $stamp = 1;

    private string $locale;

    /** Rendered frame at which unused textures go; null = none pending. */
    private ?int $retireAt = null;

    /** A pass ran out of budget: surfaces still show the textures of the old one. */
    private bool $passOpen = false;

    private int $frame = 0;

    /**
     * Every surface resolved so far by entity id. The pools leave dormant ones
     * out, and a dormant surface on the current language still needs its texture
     * when it wakes.
     *
     * @var array<int, SurfaceText>
     */
    private array $resolved = [];

    public function __construct(
        private readonly LocaleManager $locales,
        private readonly SurfaceTextBaker $baker,
        private readonly float $bakeBudgetMs = 4.0,
    ) {
        $this->locale = $locales->getLocale();
    }

    public function update(World $world, float $dt): void
    {
        $this->refresh($world, $this->bakeBudgetMs);
    }

    /** Bake everything now, without a budget. */
    public function prewarm(World $world): void
    {
        $this->refresh($world, INF);
    }

    public function render(World $world): void
    {
        $this->frame++;
        if ($this->passOpen || $this->retireAt === null || $this->frame < $this->retireAt) {
            return;
        }
        $this->retireAt = null;

        // An active surface shows what it shows. A dormant one needs its texture
        // only if it is current: otherwise it resolves again on waking.
        $active = $world->componentPool(SurfaceText::class);
        $keep = [];
        $stale = [];
        foreach ($this->resolved as $id => $surface) {
            if (!$world->isAlive($id) || $world->tryGetComponent($id, SurfaceText::class) !== $surface) {
                unset($this->resolved[$id]);
            } elseif (isset($active[$id]) || $surface->resolvedStamp === $this->stamp) {
                $keep[$surface->textureId] = true;
            } else {
                $stale[$id] = $surface;
            }
        }
        $this->baker->unloadExcept($keep);

        // Its material must not point at a texture that went: the renderer would
        // draw it white for the frames between waking and resolving.
        foreach ($stale as $id => $surface) {
            $mesh = $world->tryGetComponent($id, MeshRenderer::class);
            if (!isset($keep[$surface->textureId]) && $mesh instanceof MeshRenderer) {
                $mesh->materialId = SurfaceTextBaker::plainMaterial($surface);
                $surface->textureId = '';
            }
        }
    }

    public function onWorldClear(World $world): void
    {
        $this->resolved = [];
        $this->passOpen = false;
        $this->retireAt = $this->frame + self::RETIRE_FRAMES;
    }

    private function refresh(World $world, float $budgetMs): void
    {
        $locale = $this->locales->getLocale();
        if ($locale !== $this->locale) {
            $this->locale = $locale;
            $this->stamp++;
        }

        $meshes = $world->componentPool(MeshRenderer::class);
        $start = hrtime(true);
        $worked = false;
        $finished = true;
        foreach ($world->componentPool(SurfaceText::class) as $id => $surface) {
            /** @var SurfaceText $surface */
            if ($surface->resolvedStamp === $this->stamp) {
                continue;
            }
            if ($worked && (hrtime(true) - $start) / 1e6 >= $budgetMs) {
                $finished = false;
                break;
            }
            $worked = true;
            $mesh = $meshes[$id] ?? null;
            if ($mesh instanceof MeshRenderer) {
                $mesh->materialId = $this->baker->materialFor($surface, $locale);
                $surface->textureId = MaterialRegistry::get($mesh->materialId)->albedoTexture ?? '';
            }
            $surface->resolvedStamp = $this->stamp;
            $this->resolved[$id] = $surface;
        }

        // Every surface that changed just stopped showing its old texture, so the
        // wait starts over; it must outlast the last one, not the first.
        $this->passOpen = !$finished;
        if ($worked) {
            $this->retireAt = $this->frame + self::RETIRE_FRAMES;
        }
    }
}
