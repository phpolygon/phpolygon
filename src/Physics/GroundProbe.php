<?php

declare(strict_types=1);

namespace PHPolygon\Physics;

use PHPolygon\Component\BoxCollider3D;
use PHPolygon\Component\CharacterController3D;
use PHPolygon\Component\HeightmapCollider3D;
use PHPolygon\Component\MeshCollider3D;
use PHPolygon\Component\MeshRenderer;
use PHPolygon\ECS\World;

/**
 * What is this character standing on? The physics already knows: it names the
 * contact in {@see CharacterController3D::$groundEntityId} every tick. This turns
 * that entity into the ids a game can map to a surface (footsteps, dust, tyre
 * marks), so nothing has to search the colliders again - constant cost, whatever
 * the size of the world.
 *
 * Null when there is no solid ground to name: in the air, on the bare ground
 * plane, or when the entity is gone or became a trigger since the last physics
 * tick (the id can be one tick old, depending on where the caller runs). World
 * reuses ids: should a new entity take over the id of a destroyed floor within
 * that tick, this reports the new one until the next physics tick corrects it.
 */
final class GroundProbe
{
    public static function under(World $world, CharacterController3D $controller): ?GroundHit
    {
        $id = $controller->groundEntityId;
        if ($id === null || !$world->isAlive($id)) {
            return null;
        }

        $mesh = $world->tryGetComponent($id, MeshCollider3D::class);
        $solidMesh = $mesh !== null && !$mesh->isTrigger ? $mesh : null;
        if ($solidMesh === null && !self::solidWithoutMesh($world, $id)) {
            return null;
        }

        $renderer = $world->tryGetComponent($id, MeshRenderer::class);

        return new GroundHit($id, $solidMesh->meshId ?? $renderer?->meshId, $renderer?->materialId);
    }

    private static function solidWithoutMesh(World $world, int $id): bool
    {
        $box = $world->tryGetComponent($id, BoxCollider3D::class);

        return ($box !== null && !$box->isTrigger) || $world->hasComponent($id, HeightmapCollider3D::class);
    }
}
