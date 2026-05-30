<?php

declare(strict_types=1);

namespace PHPolygon\Tests\System;

use PHPolygon\Component\Health;
use PHPolygon\Component\Mover;
use PHPolygon\Component\Projectile;
use PHPolygon\Component\ShooterController;
use PHPolygon\Component\ShooterGameState;
use PHPolygon\Component\ShooterMovement;
use PHPolygon\Component\Spawner;
use PHPolygon\Component\Team;
use PHPolygon\Component\Transform3D;
use PHPolygon\Component\Weapon;
use PHPolygon\Component\WeaponMode;
use PHPolygon\ECS\World;
use PHPolygon\Math\Vec2;
use PHPolygon\Math\Vec3;
use PHPolygon\Runtime\InputInterface;
use PHPolygon\System\DamageSystem;
use PHPolygon\System\MoverSystem;
use PHPolygon\System\ProjectileSystem;
use PHPolygon\System\ShooterControllerSystem;
use PHPolygon\System\SpawnerSystem;
use PHPolygon\System\WeaponSystem;
use PHPUnit\Framework\TestCase;

class ShooterSystemsTest extends TestCase
{
    private const DT = 1.0 / 60.0;

    public function testProjectileKillsEnemyAwardsScoreAndDespawns(): void
    {
        $world = new World();
        $world->addSystem(new WeaponSystem());
        $world->addSystem(new ProjectileSystem());
        $world->addSystem(new DamageSystem());

        $state = new ShooterGameState(lives: 3);
        $world->createEntity()->attach($state);

        // Player-team auto-firer at the origin, shooting forward (-Z).
        $world->createEntity()
            ->attach(new Transform3D(new Vec3(0.0, 0.0, 0.0)))
            ->attach(new Health(maxHp: 5, team: Team::Player))
            ->attach(new Weapon(
                mode: WeaponMode::Projectile,
                fireRate: 4,
                damage: 5.0,
                projectileSpeed: 1.0,
                projectileLifetime: 100,
                muzzleOffset: new Vec3(0.0, 0.0, 0.0),
                autoFire: true,
            ));

        $enemy = $world->createEntity();
        $enemy->attach(new Transform3D(new Vec3(0.0, 0.0, -5.0)))
            ->attach(new Health(maxHp: 5, team: Team::Enemy, scoreOnDeath: 250, contactRadius: 0.8));
        $enemyId = $enemy->id;

        // 5 units away at 1 unit/tick → ~6 ticks to reach and kill it.
        for ($i = 0; $i < 12 && $world->isAlive($enemyId); $i++) {
            $world->update(self::DT);
        }

        $this->assertFalse($world->isAlive($enemyId), 'enemy hit by the projectile and despawned');
        $this->assertSame(250, $state->score, 'kill awarded scoreOnDeath');
    }

    public function testHitscanKillsTargetInstantly(): void
    {
        $world = new World();
        $world->addSystem(new WeaponSystem());
        $world->addSystem(new DamageSystem());

        $state = new ShooterGameState();
        $world->createEntity()->attach($state);

        $world->createEntity()
            ->attach(new Transform3D(new Vec3(0.0, 0.0, 0.0)))
            ->attach(new Health(maxHp: 1, team: Team::Player))
            ->attach(new Weapon(mode: WeaponMode::Hitscan, fireRate: 1, damage: 10.0, range: 50.0, autoFire: true));

        $enemy = $world->createEntity();
        $enemy->attach(new Transform3D(new Vec3(0.0, 0.0, -20.0)))
            ->attach(new Health(maxHp: 3, team: Team::Enemy, scoreOnDeath: 100, contactRadius: 1.0));
        $enemyId = $enemy->id;

        $world->update(self::DT); // one shot down -Z, hits at t=20 < range

        $this->assertFalse($world->isAlive($enemyId), 'hitscan dropped the in-line enemy on the first tick');
        $this->assertSame(100, $state->score);
    }

    public function testHitscanMissesOffAxisTarget(): void
    {
        $world = new World();
        $world->addSystem(new WeaponSystem());
        $world->addSystem(new DamageSystem());
        $world->createEntity()->attach(new ShooterGameState());

        $world->createEntity()
            ->attach(new Transform3D(new Vec3(0.0, 0.0, 0.0)))
            ->attach(new Health(maxHp: 1, team: Team::Player))
            ->attach(new Weapon(mode: WeaponMode::Hitscan, fireRate: 1, damage: 10.0, range: 50.0, autoFire: true));

        // 5 units off the -Z firing line, well outside the 1.0 contact radius.
        $enemy = $world->createEntity();
        $enemy->attach(new Transform3D(new Vec3(5.0, 0.0, -20.0)))
            ->attach(new Health(maxHp: 3, team: Team::Enemy, contactRadius: 1.0));
        $enemyId = $enemy->id;

        $world->update(self::DT);

        $this->assertTrue($world->isAlive($enemyId), 'off-axis target is not hit');
        $this->assertSame(3, $world->getComponent($enemyId, Health::class)->hp, 'took no damage');
    }

    public function testSpawnerCreatesEnemiesUpToMaxAlive(): void
    {
        $world = new World();
        $world->addSystem(new SpawnerSystem());
        $world->createEntity()->attach(new ShooterGameState());

        $world->createEntity()
            ->attach(new Transform3D(new Vec3(0.0, 0.0, 0.0)))
            ->attach(new Spawner(
                interval: 1,
                areaMin: new Vec3(0.0, 0.0, -50.0),
                areaMax: new Vec3(0.0, 0.0, -50.0),
                maxAlive: 3,
                enemyHp: 1,
                enemyVelocity: new Vec3(0.0, 0.0, 0.5),
            ));

        for ($i = 0; $i < 20; $i++) {
            $world->update(self::DT);
        }

        $enemies = 0;
        foreach ($world->query(Health::class, Mover::class) as $e) {
            if ($e->get(Health::class)->team === Team::Enemy) {
                $enemies++;
            }
        }
        $this->assertSame(3, $enemies, 'spawner fills up to maxAlive concurrent enemies');
    }

    public function testEnemyRammingCostsThePlayerALife(): void
    {
        $world = new World();
        $world->addSystem(new DamageSystem());

        $state = new ShooterGameState(lives: 3);
        $world->createEntity()->attach($state);

        $world->createEntity()
            ->attach(new Transform3D(new Vec3(0.0, 0.0, 0.0)))
            ->attach(new Health(maxHp: 1, team: Team::Player, contactRadius: 0.5));

        $enemy = $world->createEntity();
        $enemy->attach(new Transform3D(new Vec3(0.4, 0.0, 0.0)))
            ->attach(new Health(maxHp: 1, team: Team::Enemy, contactRadius: 0.5, contactDamage: 1.0));
        $enemyId = $enemy->id;

        $world->update(self::DT);

        $this->assertSame(2, $state->lives, 'ramming contact cost a life');
        $this->assertFalse($world->isAlive($enemyId), 'the rammer is consumed');
    }

    public function testMoverAdvancesThenDespawnsBeyondDistance(): void
    {
        $world = new World();
        $world->addSystem(new MoverSystem());

        $e = $world->createEntity();
        $e->attach(new Transform3D(new Vec3(0.0, 0.0, 0.0)))
            ->attach(new Mover(velocity: new Vec3(0.0, 0.0, 5.0), despawnDistance: 12.0));
        $id = $e->id;

        $world->update(self::DT); // z = 5
        $this->assertEqualsWithDelta(5.0, $world->getComponent($id, Transform3D::class)->position->z, 1e-9);
        $this->assertTrue($world->isAlive($id));

        $world->update(self::DT); // z = 10, still within 12
        $this->assertTrue($world->isAlive($id));
        $world->update(self::DT); // z = 15 > 12 → despawn
        $this->assertFalse($world->isAlive($id), 'despawns once past despawnDistance');
    }

    public function testPlanarControllerStrafesWithinBoundsAndSetsFireIntent(): void
    {
        $input = new FakeShooterInput();
        $world = new World();
        $world->addSystem(new ShooterControllerSystem($input));

        $player = $world->createEntity();
        $player->attach(new Transform3D(new Vec3(0.0, 6.0, 8.0)))
            ->attach(new ShooterController(
                mode: ShooterMovement::Planar,
                moveSpeed: 0.5,
                boundsMin: new Vec3(-2.0, 2.0, 8.0),
                boundsMax: new Vec3(2.0, 13.0, 8.0),
            ))
            ->attach(new Weapon(autoFire: false));
        $id = $player->id;

        // Hold right + fire for many ticks; X must clamp at the +2 bound.
        $input->down = [68 => true]; // D
        $input->mouse = [0 => true]; // left mouse = fire
        for ($i = 0; $i < 30; $i++) {
            $world->update(self::DT);
        }

        $tf = $world->getComponent($id, Transform3D::class);
        $this->assertEqualsWithDelta(2.0, $tf->position->x, 1e-9, 'clamped to the +X bound');
        $this->assertEqualsWithDelta(8.0, $tf->position->z, 1e-9, 'Z is untouched in planar mode');
        $this->assertTrue($world->getComponent($id, Weapon::class)->firing, 'fire intent set from input');
        $aim = $world->getComponent($id, Weapon::class)->aim;
        $this->assertEqualsWithDelta(-1.0, $aim->z, 1e-9, 'planar aim points down -Z');
    }

    public function testFirstPersonFireIntentSpawnsATracerProjectile(): void
    {
        // Reproduces "Feuern geht nicht": the controller must translate a held
        // mouse button into a Weapon firing intent that WeaponSystem turns into
        // a visible projectile, in first-person mode, with no window attached.
        $input = new FakeShooterInput();
        $world = new World();
        $world->addSystem(new ShooterControllerSystem($input)); // window: null (headless)
        $world->addSystem(new WeaponSystem());

        $world->createEntity()
            ->attach(new Transform3D(new Vec3(0.0, 1.8, 0.0)))
            ->attach(new ShooterController(mode: ShooterMovement::FirstPerson, eyeHeight: 0.0))
            ->attach(new Health(maxHp: 100, team: Team::Player))
            ->attach(new Weapon(mode: WeaponMode::Projectile, fireRate: 5, damage: 10.0, projectileSpeed: 2.0));

        $input->mouse = [0 => true]; // hold left mouse = fire
        $world->update(self::DT);

        $shots = 0;
        foreach ($world->query(Projectile::class) as $p) {
            $shots++;
        }
        $this->assertGreaterThan(0, $shots, 'holding fire in first-person spawns a tracer projectile');
    }
}

/** Minimal scriptable {@see InputInterface} for headless controller tests. */
final class FakeShooterInput implements InputInterface
{
    /** @var array<int, bool> */
    public array $down = [];
    /** @var array<int, bool> */
    public array $mouse = [];

    public function isKeyDown(int $key): bool { return $this->down[$key] ?? false; }
    public function isKeyPressed(int $key): bool { return false; }
    public function isKeyReleased(int $key): bool { return false; }
    public function isMouseButtonDown(int $button): bool { return $this->mouse[$button] ?? false; }
    public function isMouseButtonPressed(int $button): bool { return false; }
    public function isMouseButtonReleased(int $button): bool { return false; }
    public function getMousePosition(): Vec2 { return new Vec2(0.0, 0.0); }
    public function getMouseX(): float { return 0.0; }
    public function getMouseY(): float { return 0.0; }
    public function getScrollX(): float { return 0.0; }
    public function getScrollY(): float { return 0.0; }
    /** @return list<string> */
    public function getCharsTyped(): array { return []; }
    public function getTextInput(): string { return ''; }
    public function getBackspaceCount(): int { return 0; }
    public function showSoftKeyboard(): void {}
    public function hideSoftKeyboard(): void {}
    public function suppress(int $frames = 0, float $seconds = 0.0): void {}
    public function unsuppress(): void {}
    public function isSuppressed(): bool { return false; }
    public function clearKeyEdges(): void {}
    public function endFrame(): void {}
}
