<?php

declare(strict_types=1);

namespace PHPolygon\Tests\System;

use PHPUnit\Framework\TestCase;
use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\Entity;
use PHPolygon\ECS\World;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\NullRenderer3D;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\System\Renderer3DSystem;
use PHPolygon\System\Transform3DSystem;

/**
 * Per-object motion vectors need the world matrix an entity was drawn with in
 * the previous frame. Renderer3DSystem remembers the last rendered matrix per
 * entity and hands it out as DrawMesh::$prevModelMatrix only when the entity
 * moved; static entities (same matrix instance, as Transform3DSystem keeps it)
 * and entities without a previous frame stay null.
 */
final class Renderer3DSystemPrevModelMatrixTest extends TestCase
{
    private World $world;
    private Transform3DSystem $transforms;
    private Renderer3DSystem $renderer;
    /** @var object{draws: list<DrawMesh>} */
    private object $spy;

    protected function setUp(): void
    {
        $this->world = new World();
        $this->transforms = new Transform3DSystem();
        $this->spy = new class extends NullRenderer3D {
            /** @var list<DrawMesh> */
            public array $draws = [];
            public function render(RenderCommandList $commands): void
            {
                $this->draws = $commands->ofType(DrawMesh::class);
            }
        };
        /** @var NullRenderer3D $renderer */
        $renderer = $this->spy;
        $this->renderer = new Renderer3DSystem($renderer, new RenderCommandList());
    }

    private function spawn(string $meshId, Vec3 $at): Entity
    {
        return $this->world->createEntity()
            ->attach(new Transform3D($at))
            ->attach(new MeshRenderer(meshId: $meshId, materialId: 'm'));
    }

    /** @return array<string, DrawMesh> */
    private function frame(): array
    {
        $this->transforms->update($this->world, 1.0 / 60.0);
        $this->renderer->render($this->world);
        $byMesh = [];
        foreach ($this->spy->draws as $draw) {
            $byMesh[$draw->meshId] = $draw;
        }
        return $byMesh;
    }

    public function testFirstFrameHasNoPreviousMatrix(): void
    {
        $this->spawn('a', new Vec3(0.0, 0.0, 0.0));

        $this->assertNull($this->frame()['a']->prevModelMatrix);
    }

    public function testStaticEntityStaysNull(): void
    {
        $this->spawn('static', new Vec3(1.0, 0.0, 0.0));

        $this->frame();
        $this->assertNull($this->frame()['static']->prevModelMatrix);
        $this->assertNull($this->frame()['static']->prevModelMatrix);
    }

    public function testMovedEntityCarriesTheMatrixItWasDrawnWithLastFrame(): void
    {
        $mover = $this->spawn('mover', new Vec3(0.0, 0.0, 0.0));
        $first = $this->frame()['mover']->modelMatrix;

        $mover->get(Transform3D::class)->position = new Vec3(2.0, 0.0, 0.0);
        $second = $this->frame()['mover'];

        $this->assertNotSame($first, $second->modelMatrix);
        $this->assertSame($first, $second->prevModelMatrix);
        $this->assertEqualsWithDelta(0.0, $this->translationX($second->prevModelMatrix), 1e-9);
        $this->assertEqualsWithDelta(2.0, $this->translationX($second->modelMatrix), 1e-9);

        // Standing still again: no motion this frame.
        $this->assertNull($this->frame()['mover']->prevModelMatrix);
    }

    public function testEntityThatWasNotDrawnLastFrameHasNoPrevious(): void
    {
        $blinker = $this->spawn('blinker', new Vec3(0.0, 0.0, 0.0));
        $this->frame();

        $blinker->get(MeshRenderer::class)->visible = false;
        $blinker->get(Transform3D::class)->position = new Vec3(50.0, 0.0, 0.0);
        $this->assertArrayNotHasKey('blinker', $this->frame());

        $blinker->get(MeshRenderer::class)->visible = true;
        $this->assertNull(
            $this->frame()['blinker']->prevModelMatrix,
            'a stale matrix from frames ago would fake a huge motion vector',
        );
    }

    public function testWorldClearForgetsThePreviousMatrices(): void
    {
        $this->spawn('a', new Vec3(0.0, 0.0, 0.0));
        $this->frame();

        $this->world->clear();
        $this->transforms->onWorldClear($this->world);
        $this->renderer->onWorldClear($this->world);
        // Same entity id as before the clear, different place.
        $this->spawn('a', new Vec3(9.0, 0.0, 0.0));

        $this->assertNull($this->frame()['a']->prevModelMatrix);
    }

    public function testDrawMeshDefaultsToNoPreviousMatrix(): void
    {
        $this->assertNull((new DrawMesh('m', 'mat', Mat4::identity()))->prevModelMatrix);
    }

    private function translationX(?Mat4 $m): float
    {
        $this->assertNotNull($m);
        return $m->getTranslation()->x;
    }
}
