# Gameplay Architecture — Combat Core, Genre Hulls, and Data-Driven Behaviour

This document answers a recurring question: **does every genre need its own
systems in the engine?** Short answer: yes — the importer *wires* behaviour, it
does not *invent* it, so any mechanic must exist as an engine system (or be
authored in PHP). But the cost is sub-linear: genres are assembled from a shared
library, and most of a new genre is a thin "hull" over a reusable core.

This doc describes that layering, how to add a genre, the declared-intent import
contract, and a design sketch for a data-driven rule layer that turns "new
mechanic" from *engine code* into *data*.

---

## 1. Two layers: Combat Core vs Genre Hull

### Combat Core — genre-neutral, reused everywhere
These components/systems implement combat primitives and depend on **no**
genre-specific controller. They are reusable for a top-down shooter, FPS,
bullet-hell, tower defence, brawler, wave-survival, etc.

| Component | Role |
|---|---|
| `Health` (+ `Team`) | hit points, faction, i-frames, contact damage |
| `Weapon` (+ `WeaponMode`) | fire cadence + projectile/hitscan config; `firing` intent + `aim` |
| `Projectile` | a travelling shot (velocity, damage, lifetime, team) |
| `Mover` | constant velocity + optional homing toward a `Team` |
| `Spawner` | streams enemies from a volume over time (incl. homing) |
| `ShooterGameState` | combat-session score/lives/status (genre-neutral despite the name) |

| System | Role |
|---|---|
| `WeaponSystem` | fires any `Weapon` on `firing` **or** `autoFire` — **no** controller coupling |
| `ProjectileSystem` | integrates + expires projectiles |
| `DamageSystem` | projectile/contact damage, i-frames, death/score (single `damage()` chokepoint) |
| `MoverSystem` | advances movers, homing, despawn-at-range |
| `SpawnerSystem` | timed enemy spawning from the template |

Key invariant: **the core never references a controller.** `WeaponSystem`
fires on the `Weapon::$firing` flag — set by *whatever* controller — or on
`autoFire`. That is what makes the core drop into any genre unchanged.

### Genre Hull — thin, per-genre
A genre adds a small "hull" that translates input + feel into core intents:

| Piece | Example (shooter) |
|---|---|
| Controller component | `ShooterController` (+ `ShooterMovement`: Planar / FirstPerson) |
| Controller system | `ShooterControllerSystem` — input → movement + sets `Weapon::$firing`/`$aim` |
| Importer recipe | `classifyShooter` / `buildShooter` (arcade vs fps branch) |
| Entry wiring | `GameEntryGenerator::generateShooter` (system list, HUD) |

The platformer hull is analogous: `PlatformerController(System)` + the
`buildGameplay` recipe, over the same kind of core (`Collectible`, `Goal`,
`Patrol`, `Stompable`).

**Arcade vs FPS demonstrates the win:** both use the identical combat core; only
the controller *mode* and the importer *branch* differ.

---

## 2. How to add a genre

1. **Reuse the core.** Most combat/score/spawn needs are already covered.
2. **Write the hull controller**: one Component (its tunables) + one System that
   reads input and writes core intents (`Weapon::$firing`, a velocity, a target).
3. **Add only the genuinely-new generic systems** the mechanic needs (keep them
   genre-neutral so the *next* genre reuses them too).
4. **Add an importer recipe**: a builder that composes `core + hull` entities,
   driven by either declared intent (§3) or a heuristic classifier.
5. **Wire the entry**: add the systems to `GameEntryGenerator` (constructor args
   + a `*_MARKERS` entry) and a HUD.

The marginal cost falls as the generic library grows — you are extending a
toolkit, not forking the engine.

---

## 3. Declared intent — the robust import contract

Heuristics (guessing a genre from constant names) are convenient but brittle.
A prototype can instead **declare** its intent, which always wins:

```js
// anywhere at module scope in the prototype .jsx/.tsx
export const phpolygon = {
  genre: 'shooter',      // 'shooter' | 'platformer'
  mode:  'fps',          // shooter: 'fps' | 'arcade'
  // optional overrides merged over the scaffold defaults:
  arenaHalf: 28,
  eyeHeight: 1.8,
  boundX: 14,
};
```

Resolution order (in `scene-extract.mjs`):

1. `export const phpolygon` present → use it; merge its fields over the
   scaffold config; map `mode: 'fps' → firstPerson`, `'arcade' → planar`.
2. Otherwise → the world-constant heuristic (`classifyShooter`).
3. A declared *different* genre suppresses the shooter heuristic.

This keeps zero-config imports working while giving authors an explicit,
override-friendly path. `extractDeclaredIntent(src)` does the parsing.

---

## 4. Data-driven behaviour — the scaling lever (design sketch)

§1–3 still require an **engine system per genuinely-new mechanic**. To scale to
*many* mechanics/genres without compiling new systems, move behaviour into
**data the engine interprets**. This is the highest-leverage (and largest)
investment; sketched here, not yet built.

### The idea
Add a small set of generic, interpreted behaviours alongside the compiled core.
A mechanic becomes a **rule** (data), not a class.

Three interpreter tiers, increasing power and cost:

- **(a) Event → Action rules** (recommended first step). A `Rules` component
  holds `{ when, do }` entries; a single `RuleSystem` evaluates them each tick.
- **(b) FSM / behaviour trees** for AI states (patrol → chase → attack).
- **(c) A sandboxed expression/script VM** for arbitrary per-tick logic.

### Example (tier a)
```jsonc
// A pickup that adds score and despawns on player contact, then spawns an FX:
{
  "_class": "PHPolygon\\Component\\Rules",
  "rules": [
    { "when": { "overlap": { "team": "player", "radius": 1.2 } },
      "do": [ { "addScore": 100 }, { "spawn": "pickup_fx" }, { "destroySelf": true } ] },
    { "when": { "everyTicks": 30 },
      "do": [ { "spinY": 0.08 } ] }
  ]
}
```

### Engine integration
- One `RuleSystem` + a bounded **vocabulary**:
  - conditions: `overlap`, `distanceTo`, `everyTicks`, `timer`, `keyDown`,
    `healthBelow`, `onDeath`.
  - actions: `damage`, `spawn`, `destroySelf`, `addScore`, `setVelocity`,
    `applyImpulse`, `playSound`, `setStatus`.
- Conditions/actions are tiny, testable units — the same composability that
  makes the ECS core reusable, now exposed to data.

### Importer synergy
The importer stops needing a bespoke builder per genre: it emits **rules** from
the prototype's recognised data (or the prototype carries them under
`export const phpolygon = { rules: [...] }`). Genres become *"core systems + a
rules table"* instead of new controllers.

### Trade-offs
- **For**: new mechanics without recompiling; designer-authorable; one place to
  evaluate; great fit for the import pipeline.
- **Against**: interpretation overhead (keep hot paths — movement, physics,
  rendering — as compiled systems); harder to debug than straight code;
  the script VM (tier c) needs sandboxing for untrusted prototypes.

### Recommended path
1. Keep the compiled core for hot paths and feel-critical controllers.
2. Add **tier (a)** event→action rules as a bounded, high-value layer on top.
3. Reach for (b)/(c) only where (a) is genuinely insufficient.

The rule layer sits **above** the Combat Core — it composes the same primitives,
just from data instead of from PHP.
