// Regression tests for the headless scene importer.
// Run with: npm test   (node --test scripts/*.test.mjs)
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { computeBounds, stripInternal } from './scene-extract.mjs'

const C = (cls) => `PHPolygon\\Component\\${cls}`

function mesh(x, y, z) {
  return {
    components: [
      { _class: C('Transform3D'), position: { x, y, z } },
      { _class: C('MeshRenderer'), meshId: 'box' },
    ],
  }
}

test('computeBounds returns the AABB of all geometry-bearing entities', () => {
  const b = computeBounds([
    mesh(-3, 0, -2),
    mesh(4, 8, 1),
    mesh(0, -1, 5),
  ])
  assert.deepEqual(b.min, [-3, -1, -2])
  assert.deepEqual(b.max, [4, 8, 5])
})

test('computeBounds ignores entities without a MeshRenderer (cameras, lights, …)', () => {
  // A camera-only entity must not stretch the bounds; otherwise the importer's
  // generated camera framing slides off the actual geometry.
  const camera = { components: [{ _class: C('Transform3D'), position: { x: 100, y: 100, z: 100 } }] }
  const b = computeBounds([camera, mesh(0, 0, 0), mesh(1, 2, 3)])
  assert.deepEqual(b.min, [0, 0, 0])
  assert.deepEqual(b.max, [1, 2, 3])
})

test('computeBounds returns null when nothing has geometry', () => {
  // Generator falls back to a hard-coded default camera in this case — the
  // explicit null signals "no extents to frame on", which is the right signal.
  assert.strictEqual(computeBounds([]), null)
  assert.strictEqual(computeBounds([{ components: [{ _class: C('Transform3D'), position: { x: 0, y: 0, z: 0 } }] }]), null)
})

test('computeBounds walks nested children so parented meshes still count', () => {
  // A scene authored as `<group position=…><mesh /></group>` shows up as a
  // parent entity with a children array. The bounds must follow that nesting.
  const b = computeBounds([{
    components: [{ _class: C('Transform3D'), position: { x: 0, y: 0, z: 0 } }],
    children: [mesh(-1, -1, -1), mesh(2, 3, 4)],
  }])
  assert.deepEqual(b.min, [-1, -1, -1])
  assert.deepEqual(b.max, [2, 3, 4])
})

test('stripInternal removes the _group marker from every entity (recursively)', () => {
  // Internal marker used by gameplay-extract to track multi-mesh groups —
  // never meant to leave the importer. A stray _group in the JSON would
  // poison the AttributeSerializer (unknown property → warning or worse).
  const entities = [
    { _group: { key: 'g0' }, components: [], children: [
      { _group: { key: 'g0' }, components: [] },
    ] },
    { components: [] },
  ]
  stripInternal(entities)
  for (const e of entities) {
    assert.strictEqual(e._group, undefined)
    for (const c of e.children ?? []) {
      assert.strictEqual(c._group, undefined)
    }
  }
})
