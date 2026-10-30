<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Prefab;

use PHPUnit\Framework\TestCase;
use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\SurfaceText;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\World;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Geometry\QuadMesh;
use PHPolygon\Math\Vec3;
use PHPolygon\Prefab\TextPlate;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\Text\SurfaceTextBaker;
use PHPolygon\Scene\SceneBuilder;

final class TextPlateTest extends TestCase
{
    protected function setUp(): void
    {
        MeshRegistry::clear();
        MaterialRegistry::clear();
    }

    protected function tearDown(): void
    {
        MeshRegistry::clear();
        MaterialRegistry::clear();
    }

    /** @return array<string, Transform3D> */
    private static function build(callable $place): array
    {
        $builder = new SceneBuilder();
        $place($builder);
        $world = new World();
        $map = $builder->materialize($world);

        return array_map(static fn(int $id): Transform3D => $world->getComponent($id, Transform3D::class), $map);
    }

    public function testFrontFacesTheNormalAndSitsOnTheFace(): void
    {
        $t = self::build(static fn(SceneBuilder $b) => TextPlate::place(
            $b, 'Sign', new Vec3(1.0, 2.0, 3.0), new Vec3(1.0, 0.0, 0.0), new SurfaceText(width: 2.0, height: 0.5),
        ))['Sign'];

        self::assertTrue((new Vec3(1.0, 0.0, 0.0))->equals($t->rotation->rotateVec3(new Vec3(0.0, 0.0, 1.0)), 1e-5));
        self::assertTrue((new Vec3(0.0, 1.0, 0.0))->equals($t->rotation->rotateVec3(new Vec3(0.0, 1.0, 0.0)), 1e-5));
        self::assertTrue((new Vec3(1.0 + TextPlate::STANDOFF, 2.0, 3.0))->equals($t->position, 1e-5));
        self::assertTrue((new Vec3(2.0, 0.5, 1.0))->equals($t->scale, 1e-5));
    }

    /**
     * Seen along -normal, +u of the quad must run to the reader's right, on both
     * sides. "Right" for a reader looking along d with up u is d x u.
     */
    public function testBothSidesReadLeftToRightAndNeitherIsMirrored(): void
    {
        $normal = (new Vec3(0.6, 0.0, 0.8))->normalize();
        $map = self::build(static fn(SceneBuilder $b) => TextPlate::place(
            $b, 'Arm', new Vec3(0.0, 2.0, 0.0), $normal, new SurfaceText(width: 2.0, height: 0.4), backAt: 0.1,
        ));
        self::assertCount(2, $map);

        foreach ([['Arm', $normal], ['Arm_Back', $normal->mul(-1.0)]] as [$name, $facing]) {
            $t = $map[$name];
            $up = new Vec3(0.0, 1.0, 0.0);
            $view = $facing->mul(-1.0);
            $right = $view->cross($up)->normalize();
            self::assertTrue($facing->equals($t->rotation->rotateVec3(new Vec3(0.0, 0.0, 1.0)), 1e-5), $name);
            self::assertTrue($right->equals($t->rotation->rotateVec3(new Vec3(1.0, 0.0, 0.0)), 1e-5), $name);
            self::assertTrue($up->equals($t->rotation->rotateVec3(new Vec3(0.0, 1.0, 0.0)), 1e-5), $name);
        }

        $gap = $map['Arm']->position->sub($map['Arm_Back']->position)->dot($normal);
        self::assertEqualsWithDelta(0.1 + 2.0 * TextPlate::STANDOFF, $gap, 1e-5);
    }

    /** Nothing behind the plate: its back is blank instead of the mirrored front. */
    public function testFreeStandingPlateCanHaveABlankBack(): void
    {
        $normal = new Vec3(0.0, 0.0, 1.0);
        $text = new SurfaceText(text: 'Sign', width: 2.0, height: 0.5);
        $builder = new SceneBuilder();
        TextPlate::place($builder, 'Sign', new Vec3(0.0, 2.0, 0.0), $normal, $text, blankBack: true);
        $world = new World();
        $map = $builder->materialize($world);

        self::assertFalse($world->hasComponent($map['Sign_Back'], SurfaceText::class), 'no text, so nothing to resolve');
        $mesh = $world->getComponent($map['Sign_Back'], MeshRenderer::class);
        self::assertSame(SurfaceTextBaker::plainMaterial($text), $mesh->materialId);
        self::assertTrue($mesh->batchable);

        $back = $world->getComponent($map['Sign_Back'], Transform3D::class);
        self::assertTrue($normal->mul(-1.0)->equals($back->rotation->rotateVec3(new Vec3(0.0, 0.0, 1.0)), 1e-5));
        self::assertLessThan(0.0, $back->position->z, 'behind the front, so it hides it from that side');
    }

    public function testFlatSignAndExplicitUp(): void
    {
        $down = TextPlate::facing(new Vec3(0.0, -1.0, 0.0));
        self::assertTrue((new Vec3(0.0, -1.0, 0.0))->equals($down->rotateVec3(new Vec3(0.0, 0.0, 1.0)), 1e-5));

        $custom = TextPlate::facing(new Vec3(0.0, 0.0, 1.0), new Vec3(1.0, 0.0, 0.0));
        self::assertTrue((new Vec3(1.0, 0.0, 0.0))->equals($custom->rotateVec3(new Vec3(0.0, 1.0, 0.0)), 1e-5));

        $this->expectException(\InvalidArgumentException::class);
        TextPlate::facing(new Vec3(0.0, 0.0, 1.0), new Vec3(0.0, 0.0, 2.0));
    }

    public function testSurfaceIsShadowlessBatchedAndAttachable(): void
    {
        $builder = new SceneBuilder();
        $parent = $builder->entity('Post')->with(new Transform3D());
        TextPlate::place($builder, 'Label', new Vec3(0.0, 0.0, 0.1), new Vec3(0.0, 0.0, 1.0), new SurfaceText(text: 'Sign'), parent: $parent);
        $world = new World();
        $map = $builder->materialize($world);

        $mesh = $world->getComponent($map['Label'], MeshRenderer::class);
        self::assertSame(QuadMesh::UNIT_ID, $mesh->meshId);
        self::assertTrue(MeshRegistry::has(QuadMesh::UNIT_ID));
        self::assertFalse($mesh->castShadows);
        self::assertTrue($mesh->batchable);
        self::assertTrue(MaterialRegistry::has($mesh->materialId));
        self::assertTrue($world->hasComponent($map['Label'], SurfaceText::class));
        self::assertSame($map['Post'], $world->getComponent($map['Label'], Transform3D::class)->parentEntityId);
    }
}
