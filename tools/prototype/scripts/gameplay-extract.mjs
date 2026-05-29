// Gameplay extraction for the layered scene importer (Tier 3).
//
// The headless scene-extract pass (Tier 2) captures geometry, materials and
// world transforms, but loses *semantics*: which mesh is the player, a coin,
// an enemy, the goal — and the movement ranges and physics tuning that live in
// the source as module-level `const` data, not in the rendered scene graph.
//
// This module statically evaluates those module-level `const` literals from
// the source AST, classifies them (by variable-name hint, then by shape), and
// annotates the extracted entities with the engine's generic platformer
// gameplay components (PlatformerController, FollowCamera, BoxCollider3D,
// Collectible, Patrol, Stompable, Goal, SpinBob, PlatformerGameState). Player
// and enemies are recognised from the top-level group marker the headless pass
// attaches; coins / goal are matched by world position.
//
// It only fires for data-driven games (world built from `const` arrays). A
// purely procedural builder yields nothing here and the caller keeps the
// static-only import (with a warning).

import { parse } from '@babel/parser'

const C = (cls) => `PHPolygon\\Component\\${cls}`

// ---- static evaluation of module-level const literals ----------------------

/**
 * Evaluate a Babel expression node to a plain JS value, resolving references to
 * already-evaluated module constants. Returns `undefined` for anything not
 * statically computable (function calls, JSX, unknown identifiers, …).
 *
 * @param {object} node
 * @param {Record<string, any>} scope  already-evaluated constants
 */
function evalNode(node, scope) {
  if (!node) return undefined
  switch (node.type) {
    case 'NumericLiteral':
    case 'StringLiteral':
    case 'BooleanLiteral':
      return node.value
    case 'NullLiteral':
      return null
    case 'Identifier':
      return Object.prototype.hasOwnProperty.call(scope, node.name) ? scope[node.name] : undefined
    case 'UnaryExpression': {
      const v = evalNode(node.argument, scope)
      if (typeof v !== 'number') return undefined
      if (node.operator === '-') return -v
      if (node.operator === '+') return +v
      return undefined
    }
    case 'BinaryExpression': {
      const l = evalNode(node.left, scope)
      const r = evalNode(node.right, scope)
      if (typeof l !== 'number' || typeof r !== 'number') return undefined
      switch (node.operator) {
        case '+': return l + r
        case '-': return l - r
        case '*': return l * r
        case '/': return r === 0 ? undefined : l / r
        default: return undefined
      }
    }
    case 'ArrayExpression': {
      const out = []
      for (const el of node.elements) {
        if (el === null) { out.push(null); continue }
        const v = evalNode(el, scope)
        if (v === undefined) return undefined
        out.push(v)
      }
      return out
    }
    case 'ObjectExpression': {
      const out = {}
      for (const prop of node.properties) {
        if (prop.type !== 'ObjectProperty' || prop.computed) continue
        const key = prop.key.type === 'Identifier' ? prop.key.name
          : prop.key.type === 'StringLiteral' ? prop.key.value : null
        if (key === null) continue
        const v = evalNode(prop.value, scope)
        if (v !== undefined) out[key] = v // skip non-evaluable props (Math.PI, …)
      }
      return out
    }
    default:
      return undefined
  }
}

/**
 * Collect statically-evaluable top-level `const NAME = <literal>` bindings.
 * @param {string} src
 * @returns {{ constants: Record<string, any>, names: string[], playerSpawn: ({x:number,y:number,z:number}|null) }}
 */
export function extractConstants(src) {
  const ast = parse(src, { sourceType: 'module', plugins: ['jsx', 'typescript'] })
  const constants = {}
  const names = []
  for (const node of ast.program.body) {
    const decl = node.type === 'ExportNamedDeclaration' ? node.declaration : node
    if (!decl || decl.type !== 'VariableDeclaration' || decl.kind !== 'const') continue
    for (const d of decl.declarations) {
      if (d.id.type !== 'Identifier' || !d.init) continue
      const value = evalNode(d.init, constants)
      if (value !== undefined) {
        constants[d.id.name] = value
        names.push(d.id.name)
      }
    }
  }
  return { constants, names, playerSpawn: findPlayerSpawn(ast, constants) }
}

/**
 * Hunt for the player's initial position anywhere in the file: a `const`/`let`
 * object literal carrying x,y,z plus a velocity-ish field (vx/vy/vz/onG…),
 * which is the conventional player-state struct. Returns {x,y,z} or null.
 */
function findPlayerSpawn(ast, topConstants) {
  let spawn = null
  const visit = (node) => {
    if (!node || typeof node !== 'object' || spawn) return
    if (node.type === 'VariableDeclarator' && node.init && node.init.type === 'ObjectExpression') {
      const obj = evalNode(node.init, topConstants)
      if (obj && typeof obj === 'object') {
        const k = Object.keys(obj)
        const hasPos = ['x', 'y', 'z'].every((a) => typeof obj[a] === 'number')
        const hasVel = k.some((key) => /^v[xyz]$|^on[gG]/.test(key))
        if (hasPos && hasVel) { spawn = { x: obj.x, y: obj.y, z: obj.z }; return }
      }
    }
    for (const key of Object.keys(node)) {
      if (key === 'loc' || key === 'leadingComments' || key === 'trailingComments') continue
      const v = node[key]
      if (Array.isArray(v)) v.forEach(visit)
      else if (v && typeof v === 'object') visit(v)
    }
  }
  visit(ast)
  return spawn
}

// ---- classification --------------------------------------------------------

const isNum = (v) => typeof v === 'number' && Number.isFinite(v)
const isVec3Tuple = (v) => Array.isArray(v) && v.length === 3 && v.every(isNum)
const keysOf = (o) => (o && typeof o === 'object' && !Array.isArray(o) ? Object.keys(o) : [])
const has = (o, ...k) => k.every((key) => keysOf(o).includes(key))

/**
 * Bucket the evaluated constants into gameplay roles. Variable-name hints win;
 * otherwise the value's shape decides. Returns the picked source name per role.
 *
 * @param {Record<string, any>} constants
 * @param {string[]} names
 */
export function classify(constants, names) {
  const roles = { platforms: null, pipes: null, coins: null, enemies: null, goal: null, physics: {} }
  const picked = {}

  const nameHints = {
    platforms: /platform|ground|floor|slab|tile/i,
    pipes: /pipe|wall|pillar|obstacle|column/i,
    coins: /coin|gem|pickup|collect|ring|token/i,
    enemies: /enem|goomba|mob|foe|patrol/i,
    goal: /goal|star|finish|flag|exit/i,
  }

  for (const name of names) {
    const v = constants[name]
    if (isNum(v)) { roles.physics[name] = v; continue }

    const arr = Array.isArray(v) ? v : null
    const first = arr && arr.length ? arr[0] : null

    if (arr && arr.every(isVec3Tuple)) {
      if (!roles.coins || nameHints.coins.test(name)) { roles.coins = v; picked.coins = name }
      continue
    }
    if (arr && first && (has(first, 'axis') || has(first, 'a', 'b'))) {
      if (!roles.enemies || nameHints.enemies.test(name)) { roles.enemies = v; picked.enemies = name }
      continue
    }
    if (arr && first && has(first, 'base', 'top')) {
      if (!roles.pipes || nameHints.pipes.test(name)) { roles.pipes = v; picked.pipes = name }
      continue
    }
    if (arr && first && (has(first, 'w', 'd') || has(first, 'width', 'depth') || has(first, 'top'))) {
      if (!roles.platforms || nameHints.platforms.test(name)) { roles.platforms = v; picked.platforms = name }
      continue
    }
    if (!arr && has(v, 'x', 'y', 'z')) {
      if (!roles.goal || nameHints.goal.test(name)) { roles.goal = v; picked.goal = name }
      continue
    }
  }

  return { roles, picked }
}

// ---- physics-constant mapping ----------------------------------------------

/**
 * Map loosely-named physics scalars onto PlatformerController constructor args.
 * Unknowns are ignored (the component defaults stand in). Per-tick (60 Hz).
 *
 * @param {Record<string, number>} physics
 */
export function mapPhysics(physics) {
  const out = {}
  const pick = (re) => {
    for (const [k, v] of Object.entries(physics)) if (re.test(k)) return v
    return undefined
  }
  const set = (key, re) => { const v = pick(re); if (v !== undefined) out[key] = v }

  set('gravity', /^g$|gravity/i)
  set('jumpVelocity', /^jump$|jumpforce|jumpvel/i)
  set('moveAccel', /^accel$|moveaccel|^acc$/i)
  set('airAccel', /^air$|airaccel/i)
  set('friction', /^fr$|friction|damp/i)
  set('maxSpeed', /^maxspeed$|^speed$|runspeed/i)
  set('maxFall', /^maxfall$|terminal/i)

  const hx = pick(/^phx$|halfx|bodyx/i)
  const hy = pick(/^phy$|halfy|bodyy/i)
  const hz = pick(/^phz$|halfz|bodyz/i)
  if (hx !== undefined || hy !== undefined || hz !== undefined) {
    out.halfExtents = { x: hx ?? 0.45, y: hy ?? 0.85, z: hz ?? 0.45 }
  }
  return out
}

// ---- entity generation -----------------------------------------------------

const round = (n) => Math.round(n * 1e5) / 1e5
const num = (v, d = 0) => (typeof v === 'number' && Number.isFinite(v) ? v : d)

const compOf = (entity, suffix) =>
  (entity.components ?? []).find((c) => typeof c._class === 'string' && c._class.endsWith(suffix))
const posOf = (entity) => {
  const t = compOf(entity, 'Transform3D')
  return t && t.position ? t.position : null
}
const meshIdOf = (entity) => {
  const m = compOf(entity, 'MeshRenderer')
  return m && typeof m.meshId === 'string' ? m.meshId : null
}

/**
 * Build gameplay entities + components from the classified `const` data and the
 * headless-extracted mesh entities (each tagged with its top-level `_group`).
 *
 * Strategy:
 *  - platforms / pipes → standalone invisible {@link BoxCollider3D} entities
 *    placed exactly from the data (top surface at `top`); the visible meshes
 *    stay as-is.
 *  - coins → the nearest lone (ungrouped) mesh is annotated Collectible+SpinBob.
 *  - goal → the nearest lone mesh is annotated Goal + SpinBob.
 *  - enemies → the top-level group whose origin matches a spawn is re-parented
 *    under a Patrol + Stompable + SpinBob root.
 *  - player → the remaining (non-enemy) group is re-parented under a
 *    PlatformerController root, spawned above the nearest platform; a
 *    FollowCamera + PlatformerGameState are added.
 *
 * @param {{platforms:any,pipes:any,coins:any,enemies:any,goal:any}} roles
 * @param {{entities:any[], meshes:object}} headless
 * @param {Record<string,number>} physicsParams  mapPhysics() output
 * @param {{x:number,y:number,z:number}|null} playerSpawn
 */
export function buildGameplay(roles, headless, physicsParams, playerSpawn = null) {
  const warnings = []
  const slabHeight = 1.4
  const generated = []
  const reparented = new Set() // meshes pulled under a root (removed from top level)
  const annotated = new Set()  // lone meshes already given a role (stay top level)
  const half = physicsParams.halfExtents ?? { x: 0.45, y: 0.85, z: 0.45 }

  const meshEntities = (headless.entities ?? []).filter((e) => meshIdOf(e) !== null)

  // Group the meshes: those sharing a `_group` are a moving unit; the rest are
  // lone top-level props.
  const groups = new Map() // key -> { info, members: [] }
  const lone = []
  for (const e of meshEntities) {
    if (e._group) {
      if (!groups.has(e._group.key)) groups.set(e._group.key, { info: e._group, members: [] })
      groups.get(e._group.key).members.push(e)
    } else {
      lone.push(e)
    }
  }

  // --- platforms → colliders (top surface at `top`) ---
  if (Array.isArray(roles.platforms)) {
    roles.platforms.forEach((p, i) => {
      const w = num(p.w ?? p.width, 1)
      const d = num(p.d ?? p.depth, 1)
      if (p.top === undefined) warnings.push(`platforms[${i}]: 'top' not statically evaluable - defaulted to 0 (flat floor)`)
      const top = num(p.top, 0)
      generated.push({
        name: `collider_platform_${i}`,
        components: [
          { _class: C('Transform3D'), position: { x: round(num(p.x)), y: round(top - slabHeight / 2), z: round(num(p.z)) } },
          { _class: C('BoxCollider3D'), size: { x: round(w), y: slabHeight, z: round(d) } },
        ],
      })
    })
  }

  // --- pipes → colliders (base..top) ---
  if (Array.isArray(roles.pipes)) {
    roles.pipes.forEach((p, i) => {
      if (p.top === undefined) warnings.push(`pipes[${i}]: 'top' not statically evaluable - defaulted to 0`)
      if (p.base === undefined) warnings.push(`pipes[${i}]: 'base' not statically evaluable - defaulted to 0`)
      const top = num(p.top, 0)
      const base = num(p.base, 0)
      const h = Math.max(0.1, top - base)
      generated.push({
        name: `collider_pipe_${i}`,
        components: [
          { _class: C('Transform3D'), position: { x: round(num(p.x)), y: round(base + h / 2), z: round(num(p.z)) } },
          { _class: C('BoxCollider3D'), size: { x: 1.8, y: round(h), z: 1.8 } },
        ],
      })
    })
  }

  // --- coins → annotate nearest lone mesh (stays top-level, visible) ---
  if (Array.isArray(roles.coins)) {
    for (const c of roles.coins) {
      const [x, y, z] = c
      const e = nearestMesh(lone, x, y, z, 1.0, annotated)
      if (!e) { warnings.push(`coin at [${x},${y},${z}] had no nearby mesh — skipped`); continue }
      annotated.add(e)
      e.components.push({ _class: C('Collectible'), score: 100, coinValue: 1, radius: 1.2 })
      e.components.push({ _class: C('SpinBob'), spinSpeed: { x: 0, y: 0.08, z: 0 }, bobAmplitude: 0.12, bobFrequency: 0.0157, phaseOffset: round(x) })
    }
  }

  // --- goal → annotate nearest lone mesh ---
  if (roles.goal && typeof roles.goal === 'object') {
    const g = roles.goal
    const e = nearestMesh(lone, num(g.x), num(g.y), num(g.z), 2.0, annotated)
    if (e) {
      annotated.add(e)
      e.components.push({ _class: C('Goal'), radius: 1.8, score: 1000, lifeBonus: 200 })
      e.components.push({ _class: C('SpinBob'), spinSpeed: { x: 0.01, y: 0.03, z: 0 }, bobAmplitude: 0.3, bobFrequency: 0.0126 })
    } else {
      warnings.push('goal had no nearby mesh — add a Goal entity manually')
    }
  }

  // --- enemies → match a top-level group to each spawn, re-parent under root ---
  const usedGroups = new Set()
  if (Array.isArray(roles.enemies)) {
    roles.enemies.forEach((en, i) => {
      const axis = String(en.axis ?? 'x').toLowerCase()
      const a = num(en.a, -1), b = num(en.b, 1)
      const mid = (a + b) / 2
      const sx = axis === 'x' ? mid : num(en.x)
      const sz = axis === 'z' ? mid : num(en.z)
      const top = num(en.top, 0)

      const grp = nearestGroup(groups, usedGroups, sx, sz, 2.5)
      if (!grp) { warnings.push(`enemy ${i} at (${sx},${sz}) matched no mesh group`); return }
      usedGroups.add(grp.key)
      const gw = grp.info
      const children = grp.members.map((m) => { reparented.add(m); return rebase(m, gw.x, gw.y, gw.z) })

      generated.push({
        name: `enemy_${i}`,
        components: [
          { _class: C('Transform3D'), position: { x: round(sx), y: round(top), z: round(sz) } },
          { _class: C('Patrol'), axis, min: round(Math.min(a, b)), max: round(Math.max(a, b)), speed: 0.035 },
          { _class: C('Stompable'), contactRadius: 1.0, bodyHeight: 1.2, stompHeight: 0.6, bounceVelocity: 0.38, score: 200 },
          { _class: C('SpinBob'), bobAmplitude: 0.06, bobFrequency: 0.0349, bobAbsolute: true },
        ],
        children,
      })
    })
  }

  // --- player → the remaining (non-enemy) group ---
  let playerName = null
  const playerGroup = [...groups.values()].find((g) => !usedGroups.has(g.info.key))
  if (playerGroup) {
    usedGroups.add(playerGroup.info.key)
    const gw = playerGroup.info
    // Spawn: explicit player-state struct if found, else above the nearest platform.
    const spawn = playerSpawn ?? spawnAbovePlatform(roles.platforms, gw, half)
    // The group origin sits at the feet; raise the root to the body centre and
    // rebase children from the feet so the rig hangs correctly under the centre.
    const children = playerGroup.members.map((m) => { reparented.add(m); return rebase(m, gw.x, gw.y + half.y, gw.z) })
    annotateLegs(children, half, warnings)
    playerName = 'Player'
    generated.push({
      name: playerName,
      components: [
        { _class: C('Transform3D'), position: { x: round(spawn.x), y: round(spawn.y), z: round(spawn.z) } },
        { _class: C('PlatformerController'), halfExtents: half, ...physicsParams },
      ],
      children,
    })

    generated.push({
      name: 'Camera',
      components: [
        { _class: C('Transform3D'), position: { x: round(spawn.x), y: round(spawn.y + 8), z: round(spawn.z + 10) } },
        { _class: C('Camera3DComponent'), fov: 55, near: 0.1, far: 300, active: true },
        {
          _class: C('FollowCamera'), targetName: playerName, lerpFactor: 0.08,
          positionScale: { x: 0.6, y: 1, z: 1 }, positionOffset: { x: 0, y: 5.5, z: 9.5 },
          lookScale: { x: 0.5, y: 1, z: 1 }, lookOffset: { x: 0, y: 1, z: -2 },
        },
      ],
    })
  } else {
    warnings.push('no mesh group left for a player — add a PlatformerController entity manually')
  }

  // --- game state ---
  generated.push({
    name: 'GameState',
    components: [{ _class: C('PlatformerGameState'), lives: 3, deathPenalty: 50 }],
  })

  // Top-level = original entities minus those re-parented under a root.
  const topLevel = (headless.entities ?? []).filter((e) => !reparented.has(e))
  const all = [...topLevel, ...generated]
  stripGroupMarkers(all)
  return { entities: all, systems: GAMEPLAY_SYSTEMS, warnings }
}

// Update order matters: movement/gameplay first, then FollowCamera, then
// Transform3DSystem LAST so every world matrix (player rig, enemies, camera) is
// current for the render phase (Camera3DSystem / Renderer3DSystem).
const GAMEPLAY_SYSTEMS = [
  'PHPolygon\\System\\PlatformerControllerSystem',
  'PHPolygon\\System\\PatrolSystem',
  'PHPolygon\\System\\StompSystem',
  'PHPolygon\\System\\CollectibleSystem',
  'PHPolygon\\System\\GoalSystem',
  'PHPolygon\\System\\SpinBobSystem',
  'PHPolygon\\System\\PlatformerAnimationSystem',
  'PHPolygon\\System\\FollowCameraSystem',
  'PHPolygon\\System\\Transform3DSystem',
  'PHPolygon\\System\\Camera3DSystem',
  'PHPolygon\\System\\Renderer3DSystem',
]

/** Nearest lone mesh entity within `tol` of (x,y,z), or null. */
function nearestMesh(meshEntities, x, y, z, tol, consumed) {
  let best = null
  let bestD = tol * tol
  for (const e of meshEntities) {
    if (consumed.has(e)) continue
    const p = posOf(e)
    if (!p) continue
    const d = (p.x - x) ** 2 + (p.y - y) ** 2 + (p.z - z) ** 2
    if (d <= bestD) { bestD = d; best = e }
  }
  return best
}

/** Nearest unused group whose origin is within horizontal `tol` of (x,z). */
function nearestGroup(groups, used, x, z, tol) {
  let best = null
  let bestD = tol * tol
  for (const g of groups.values()) {
    if (used.has(g.info.key)) continue
    const d = (g.info.x - x) ** 2 + (g.info.z - z) ** 2
    if (d <= bestD) { bestD = d; best = { key: g.info.key, info: g.info, members: g.members } }
  }
  return best
}

/** Body-centre spawn above the platform nearest to the group origin. */
function spawnAbovePlatform(platforms, groupWorld, half) {
  if (Array.isArray(platforms) && platforms.length) {
    let best = platforms[0]
    let bestD = Infinity
    for (const p of platforms) {
      const d = (num(p.x) - groupWorld.x) ** 2 + (num(p.z) - groupWorld.z) ** 2
      if (d < bestD) { bestD = d; best = p }
    }
    return { x: num(best.x), y: num(best.top) + half.y, z: num(best.z) }
  }
  return { x: groupWorld.x, y: groupWorld.y + half.y, z: groupWorld.z }
}

/** Re-express a mesh entity's transform relative to a parent origin. */
function rebase(entity, ox, oy, oz) {
  const t = compOf(entity, 'Transform3D')
  if (t && t.position) {
    t.position = { x: round(t.position.x - ox), y: round(t.position.y - oy), z: round(t.position.z - oz) }
  }
  return entity
}

/** Drop internal `_group` markers so they don't leak into the output JSON. */
function stripGroupMarkers(entities) {
  for (const e of entities) {
    delete e._group
    if (e.children) stripGroupMarkers(e.children)
  }
}

/**
 * Tag the character's lower-body meshes as {@link PlatformerLegSegment}s so the
 * PlatformerAnimationSystem can give it a run cycle. The original JSX grouped
 * leg+foot under a hip `THREE.Group` and rotated it; the import flattens that,
 * so instead we mark each lower mesh with a shared hip pivot.
 *
 * Heuristic (children are local to the body centre): a mesh below the body
 * midpoint and offset to one side is a leg/foot. Each side rotates about the
 * top of its tallest member (the hip).
 *
 * @param {any[]} children   player child mesh entities (already rebased)
 * @param {{x:number,y:number,z:number}} half  player half-extents
 * @param {string[]} warnings
 */
export function annotateLegs(children, half, warnings) {
  const threshold = -num(half.y, 0.85) * 0.3 // below the body centre = lower body
  const sides = { L: [], R: [] }
  for (const c of children) {
    const p = posOf(c)
    // Reject NaN/Infinite coords up front: `NaN >= x` and `Math.abs(NaN) < x`
    // both evaluate false and would silently bucket malformed input as a leg,
    // poisoning the pivot accumulator further down.
    if (!p || !Number.isFinite(p.x) || !Number.isFinite(p.y)) continue
    if (p.y >= threshold || Math.abs(p.x) < 0.05) continue
    sides[p.x < 0 ? 'L' : 'R'].push(c)
  }

  for (const side of ['L', 'R']) {
    const members = sides[side]
    if (members.length === 0) continue
    const swingSign = side === 'L' ? 1.0 : -1.0

    // Hip pivot = top of the tallest member on this side (where the leg joins).
    let pivotX = 0, top = -Infinity, pivotZ = 0
    for (const m of members) {
      const p = posOf(m)
      pivotX += p.x
      const t = p.y + meshHalfHeightY(meshIdOf(m))
      if (t > top) { top = t; pivotZ = p.z }
    }
    const pivot = { x: round(pivotX / members.length), y: round(top), z: round(pivotZ) }

    for (const m of members) {
      // Idempotent: re-running the importer (watch mode, batched tests) must
      // not double-tag a leg with two PlatformerLegSegments.
      if (compOf(m, 'PlatformerLegSegment')) continue

      const p = posOf(m)
      const t = compOf(m, 'Transform3D')
      const r = (t && t.rotation) ? t.rotation : { x: 0, y: 0, z: 0, w: 1 }
      const rx = r.x ?? 0, ry = r.y ?? 0, rz = r.z ?? 0, rw = r.w ?? 1
      // Detect the degenerate zero quaternion and fall back to identity — the
      // animation system would otherwise multiply by zero each frame and the
      // leg would collapse to a degenerate transform.
      const restRotation = (rx === 0 && ry === 0 && rz === 0 && rw === 0)
        ? { x: 0, y: 0, z: 0, w: 1 }
        : { x: rx, y: ry, z: rz, w: rw }
      m.components.push({
        _class: C('PlatformerLegSegment'),
        restPosition: { x: p.x, y: p.y, z: p.z },
        restRotation,
        // Clone per member: a single shared object reference would propagate
        // any downstream mutation (sanitiser, unit converter, …) to every leg
        // on this side simultaneously.
        pivot: { x: pivot.x, y: pivot.y, z: pivot.z },
        swingSign,
      })
    }
  }

  if (sides.L.length === 0 && sides.R.length === 0) {
    warnings.push('player rig: no leg meshes detected — run animation will be skipped')
  }
}

/**
 * Half the Y extent of a procedural primitive mesh (`_` = decimal point), as
 * encoded in the meshId by the importer. Supports the shapes the extractor
 * actually emits — `box_WxHxD`, `cyl_RxHxSeg` and `sphere_RxWsegxHseg` — so a
 * character whose legs aren't boxes still gets a sensible hip pivot. Returns 0
 * (with a console warning) for anything else so the caller can fall back.
 */
function meshHalfHeightY(meshId) {
  if (typeof meshId !== 'string') return 0
  const parse = (s) => parseFloat(s.replace(/_/g, '.'))
  let m
  if ((m = meshId.match(/^box_(.+)$/))) {
    const dims = m[1].split('x').map(parse)
    return Number.isFinite(dims[1]) ? dims[1] / 2 : 0
  }
  if ((m = meshId.match(/^cyl_(.+)$/))) {
    const dims = m[1].split('x').map(parse)
    return Number.isFinite(dims[1]) ? dims[1] / 2 : 0 // second dim is height
  }
  if ((m = meshId.match(/^sphere_(.+)$/))) {
    const dims = m[1].split('x').map(parse)
    return Number.isFinite(dims[0]) ? dims[0] : 0 // radius == half-height
  }
  return 0
}
