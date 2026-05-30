// Regression tests for the player-rig leg detection in gameplay-extract.
// Run with: npm test   (node --test scripts/*.test.mjs)
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { annotateLegs, buildGameplay, classifyShooter, buildShooter, SHOOTER_SYSTEMS, extractDeclaredIntent } from './gameplay-extract.mjs'

const C = (cls) => `PHPolygon\\Component\\${cls}`

/** A player child mesh: Transform3D (with position) + MeshRenderer. */
function child(x, y, meshId) {
  return {
    components: [
      { _class: C('Transform3D'), position: { x, y, z: 0 }, rotation: { x: 0, y: 0, z: 0, w: 1 } },
      { _class: C('MeshRenderer'), meshId },
    ],
  }
}
const segOf = (e) => e.components.find((c) => c._class.endsWith('PlatformerLegSegment'))
const posOf = (e) => e.components.find((c) => c._class.endsWith('Transform3D')).position

test('annotateLegs tags the lower-body L/R meshes as swinging legs', () => {
  const children = [
    child(-0.2, -0.525, 'box_0_26x0_55x0_3'), // left leg
    child(-0.2, -0.85, 'box_0_3x0_2x0_46'),   // left foot
    child(0.2, -0.525, 'box_0_26x0_55x0_3'),  // right leg
    child(0.2, -0.85, 'box_0_3x0_2x0_46'),    // right foot
    child(0.0, 0.4, 'box_0_8x0_4x0_56'),      // shirt — upper body, NOT a leg
    child(-0.5, 0.33, 'box_0_2x0_46x0_28'),   // arm — upper body, NOT a leg
  ]
  const warnings = []
  annotateLegs(children, { x: 0.45, y: 0.85, z: 0.45 }, warnings)

  const tagged = children.filter(segOf)
  assert.equal(tagged.length, 4, 'exactly the 4 leg/foot meshes are tagged')

  for (const c of tagged) {
    const seg = segOf(c)
    const side = posOf(c).x < 0 ? 'L' : 'R'
    assert.equal(seg.swingSign, side === 'L' ? 1 : -1, `${side} swingSign`)
    // Hip pivot = top of the tallest member (leg box: -0.525 + 0.55/2 = -0.25).
    assert.ok(Math.abs(seg.pivot.y - -0.25) < 1e-6, `pivot.y=${seg.pivot.y}`)
    assert.ok(Math.abs(seg.pivot.x - (side === 'L' ? -0.2 : 0.2)) < 1e-6, `pivot.x=${seg.pivot.x}`)
    // Rest position mirrors the mesh's own local position.
    assert.deepEqual(seg.restPosition, posOf(c))
  }
})

test('annotateLegs warns when no legs are detected', () => {
  const warnings = []
  annotateLegs([child(0.0, 0.4, 'box_1x1x1')], { x: 0.45, y: 0.85, z: 0.45 }, warnings)
  assert.ok(warnings.some((w) => /no leg meshes/.test(w)), 'should warn')
})

test('annotateLegs is idempotent — re-running does not duplicate components', () => {
  const children = [
    child(-0.2, -0.525, 'box_0_26x0_55x0_3'),
    child(0.2, -0.525, 'box_0_26x0_55x0_3'),
  ]
  annotateLegs(children, { x: 0.45, y: 0.85, z: 0.45 }, [])
  annotateLegs(children, { x: 0.45, y: 0.85, z: 0.45 }, [])
  for (const c of children) {
    const segs = c.components.filter((k) => k._class.endsWith('PlatformerLegSegment'))
    assert.equal(segs.length, 1, 'one — and only one — PlatformerLegSegment per re-run')
  }
})

test('annotateLegs gives each member its own pivot object (no shared reference)', () => {
  const children = [
    child(-0.2, -0.525, 'box_0_26x0_55x0_3'),
    child(-0.2, -0.85, 'box_0_3x0_2x0_46'),
  ]
  annotateLegs(children, { x: 0.45, y: 0.85, z: 0.45 }, [])
  const a = segOf(children[0]).pivot
  const b = segOf(children[1]).pivot
  assert.deepEqual(a, b)
  assert.notStrictEqual(a, b, 'pivot must be cloned per member, not shared by reference')

  a.y = 99
  assert.notEqual(b.y, 99, 'mutating one leg pivot must not leak into the sibling')
})

test('annotateLegs rejects NaN coordinates instead of bucketing them as legs', () => {
  const children = [
    // Sane right leg keeps the rig non-empty.
    child(0.2, -0.525, 'box_0_26x0_55x0_3'),
    // Malformed source position: NaN slips past `>=` / `<` (NaN compares false)
    // and would otherwise be tagged as a leg, poisoning the pivot with NaN.
    child(NaN, NaN, 'box_0_26x0_55x0_3'),
  ]
  annotateLegs(children, { x: 0.45, y: 0.85, z: 0.45 }, [])
  assert.equal(segOf(children[0]).swingSign, -1, 'sane right leg is still tagged')
  assert.equal(segOf(children[1]), undefined, 'NaN-positioned mesh is rejected')
})

test('buildGameplay warns when a platform.top is non-evaluable (silent y=0 footgun)', () => {
  // Previously `num(p.top, 0)` silently collapsed every platform whose `top`
  // expression failed to evaluate (e.g. `Math.PI * r`) to y=0 — the scene
  // imports as a "flat-floor" rather than failing loudly. Make sure the
  // warning is now emitted for both platforms and pipes.
  const headless = { entities: [], meshes: {}, materials: {} }
  const roles = {
    platforms: [
      { x: 0, z: 0, w: 1, d: 1, top: 3 },        // OK
      { x: 1, z: 0, w: 1, d: 1 /* top missing */ }, // warns
    ],
    pipes: [
      { x: 0, z: 0 /* top + base missing */ },   // warns twice
    ],
  }
  const gp = buildGameplay(roles, headless, { halfExtents: { x: 0.45, y: 0.85, z: 0.45 } })
  assert.ok(gp.warnings.some((w) => /platforms\[1\].*'top'/.test(w)), 'platform[1] missing-top must warn')
  assert.ok(gp.warnings.some((w) => /pipes\[0\].*'top'/.test(w)), 'pipe[0] missing-top must warn')
  assert.ok(gp.warnings.some((w) => /pipes\[0\].*'base'/.test(w)), 'pipe[0] missing-base must warn')
  // The platform[0] case (top: 3) must NOT trigger a warning.
  assert.ok(!gp.warnings.some((w) => /platforms\[0\]/.test(w)), 'fully-evaluable platform must stay silent')
})

test('annotateLegs handles cylinder and sphere leg meshes for the hip pivot', () => {
  // A robot with cylindrical shins: meshHalfHeightY must read the cyl's
  // 2nd dim as height, not silently return 0 (which collapses pivot.y to
  // the leg's own centre and produces a broken orbit).
  const children = [
    child(-0.2, -0.525, 'cyl_0_15x0_55x10'), // L thigh: r=0.15, h=0.55
    child(0.2, -0.525, 'cyl_0_15x0_55x10'),  // R thigh
  ]
  annotateLegs(children, { x: 0.45, y: 0.85, z: 0.45 }, [])
  for (const c of children) {
    // top = -0.525 + 0.55/2 = -0.25 (same as the box-leg pivot)
    assert.ok(Math.abs(segOf(c).pivot.y - -0.25) < 1e-6, `cyl pivot.y=${segOf(c).pivot.y}`)
  }
})

// ---- shooter genre -----------------------------------------------------------

test('classifyShooter recognises a shooter from its world constants', () => {
  // The neon-shooter shape: lateral bound, vertical band, player + spawn planes.
  const constants = { BOUND_X: 14, Y_MIN: 2, Y_MAX: 13, Y_BASE: 6, PLAYER_Z: 8, SPAWN_Z: -150 }
  const names = Object.keys(constants)
  const { isShooter, config } = classifyShooter(constants, names)
  assert.equal(isShooter, true, 'detected as a shooter')
  assert.equal(config.boundX, 14)
  assert.equal(config.playerZ, 8)
  assert.equal(config.spawnZ, -150)
  assert.equal(config.yMin, 2)
  assert.equal(config.yMax, 13)
  assert.equal(config.yBase, 6)
})

test('classifyShooter does not fire on unrelated constants', () => {
  const constants = { TILE_SIZE: 32, GRAVITY: 0.5, COLOR: 0xff00ff }
  const { isShooter } = classifyShooter(constants, Object.keys(constants))
  assert.equal(isShooter, false, 'no shooter signal → platformer/static path runs instead')
})

test('buildShooter scaffolds player + spawner + camera + state and assets', () => {
  const headless = {
    // One initial ship mesh becomes the player body.
    entities: [{ name: 'ship', components: [
      { _class: C('Transform3D'), position: { x: 0, y: 6, z: 8 } },
      { _class: C('MeshRenderer'), meshId: 'box_1x1x1', materialId: 'mat_x' },
    ] }],
    meshes: { box_1x1x1: { generator: 'BoxMesh', args: [1, 1, 1] } },
    materials: { mat_x: { albedo: '#33ecff' } },
  }
  const cfg = { boundX: 14, yMin: 2, yMax: 13, yBase: 6, playerZ: 8, spawnZ: -150 }
  const out = buildShooter(cfg, headless)

  const names = out.entities.map((e) => e.name)
  assert.deepEqual(names.slice(-4), ['Player', 'EnemySpawner', 'Camera', 'GameState'])

  const player = out.entities.find((e) => e.name === 'Player')
  const sc = player.components.find((c) => c._class.endsWith('ShooterController'))
  assert.equal(sc.mode, 'planar')
  assert.equal(sc.boundsMax.x, 14)
  assert.equal(player.children.length, 1, 'initial ship mesh re-parented under the player')

  const spawner = out.entities.find((e) => e.name === 'EnemySpawner')
  const sp = spawner.components.find((c) => c._class.endsWith('Spawner'))
  assert.equal(sp.areaMin.z, -150, 'enemies spawn on the far plane')

  // Synthetic runtime assets are registered (no live enemy/shot in frame 0).
  assert.ok(out.meshes.shot_default && out.meshes.enemy_default, 'projectile + enemy assets added')
  assert.equal(out.systems, SHOOTER_SYSTEMS)
})

test('extractDeclaredIntent reads an explicit `export const phpolygon`', () => {
  const src = `
    import * as THREE from 'three'
    export const phpolygon = { genre: 'shooter', mode: 'fps', arenaHalf: 30, playerHp: 120 }
    export default function Game() { return null }
  `
  const got = extractDeclaredIntent(src)
  assert.equal(got.genre, 'shooter')
  assert.equal(got.mode, 'fps')
  assert.equal(got.arenaHalf, 30)
  assert.equal(got.playerHp, 120)
})

test('extractDeclaredIntent returns null when the prototype declares nothing', () => {
  assert.equal(extractDeclaredIntent('export default function Game() { return null }'), null)
})

test('buildShooter honours a declared arcade mode + overrides', () => {
  // Declared intent is merged over the heuristic config in scene-extract; here
  // we feed buildShooter the merged result directly to lock the dispatch.
  const cfg = { mode: 'planar', boundX: 9, yMin: 2, yMax: 13, yBase: 6, playerZ: 8, spawnZ: -120 }
  const out = buildShooter(cfg, { entities: [], meshes: {}, materials: {} })
  const player = out.entities.find((e) => e.name === 'Player')
  const sc = player.components.find((c) => c._class.endsWith('ShooterController'))
  assert.equal(sc.mode, 'planar', 'arcade → planar controller')
  assert.equal(sc.boundsMax.x, 9, 'declared boundX override flows through')
})
