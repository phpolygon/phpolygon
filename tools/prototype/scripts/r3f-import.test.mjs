// Regression tests for the R3F TSX importer. Run with: npm test
// (requires `npm install` so @babel/parser is available).
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { importR3f } from './r3f-import.mjs'

const meshOf = (entity) => entity.components.find((c) => c._class.endsWith('MeshRenderer'))
const transformOf = (entity) => entity.components.find((c) => c._class.endsWith('Transform3D'))

test('compound Euler rotation -> correct quaternion (XYZ order)', () => {
  const { entities } = importR3f(`
    export default () => (
      <mesh rotation={[0.3, 0.5, 0.7]}>
        <boxGeometry args={[1, 1, 1]} />
      </mesh>
    )
  `)
  const q = transformOf(entities[0]).rotation
  // three.js reference for euler(0.3,0.5,0.7) XYZ; the old z-sign bug gave z=0.2937.
  assert.ok(Math.abs(q.x - 0.21990) < 1e-3, `x=${q.x}`)
  assert.ok(Math.abs(q.y - 0.18014) < 1e-3, `y=${q.y}`)
  assert.ok(Math.abs(q.z - 0.36317) < 1e-3, `z=${q.z}`)
  assert.ok(Math.abs(q.w - 0.88708) < 1e-3, `w=${q.w}`)
})

test('present-but-unevaluable arg warns and falls back', () => {
  const { meshes, warnings } = importR3f(`
    const W = 5
    export default () => (
      <mesh><boxGeometry args={[4, W, 6]} /></mesh>
    )
  `)
  const box = Object.values(meshes)[0]
  assert.deepEqual(box.args, [4, 1, 6]) // W not evaluable -> default 1
  assert.ok(warnings.some((w) => /not statically evaluable/.test(w)), 'should warn about W')
})

test('negative args do not collide with positive ones', () => {
  const { meshes } = importR3f(`
    export default () => (
      <group>
        <mesh><boxGeometry args={[-2.5, 1, 1]} /></mesh>
        <mesh><boxGeometry args={[2.5, 1, 1]} /></mesh>
      </group>
    )
  `)
  assert.equal(Object.keys(meshes).length, 2, 'distinct ids for -2.5 and 2.5')
})

test('scene root comes from the default export, not the first JSX in the file', () => {
  const { meshes, entities } = importR3f(`
    function Icon() { return <mesh><sphereGeometry args={[0.1]} /></mesh> }
    export default function Scene() {
      return (
        <Canvas>
          <mesh name="Real"><boxGeometry args={[3, 3, 3]} /></mesh>
        </Canvas>
      )
    }
  `)
  assert.equal(entities.length, 1)
  assert.equal(entities[0].name, 'Real')
  assert.ok(meshes['box_3x3x3'], 'the default export box, not the helper sphere')
})

test('geometry maps to the right generator + arg order', () => {
  const { meshes } = importR3f(`
    export default () => (
      <group>
        <mesh><sphereGeometry args={[2, 24, 12]} /></mesh>
        <mesh><cylinderGeometry args={[1, 1, 4, 20]} /></mesh>
      </group>
    )
  `)
  // sphere R3F [radius, widthSeg=24, heightSeg=12] -> SphereMesh(radius, stacks=12, slices=24)
  assert.deepEqual(meshes['sphere_2x12x24'], { generator: 'SphereMesh', args: [2, 12, 24] })
  // cylinder [radiusTop, radiusBottom, height=4, radialSeg=20] -> CylinderMesh(radius, height, segments)
  assert.deepEqual(meshes['cylinder_1x4x20'], { generator: 'CylinderMesh', args: [1, 4, 20] })
})

test('lights map; ambient/unknown warn', () => {
  const { entities, warnings } = importR3f(`
    export default () => (
      <Canvas>
        <ambientLight intensity={0.5} />
        <directionalLight position={[0, 10, 0]} color="#ffffff" intensity={2} />
        <pointLight position={[1, 2, 3]} distance={12} />
        <fog attach="fog" />
      </Canvas>
    )
  `)
  const dir = entities.find((e) => e.components.some((c) => c._class.endsWith('DirectionalLight')))
  const dirComp = dir.components.find((c) => c._class.endsWith('DirectionalLight'))
  assert.deepEqual(dirComp.direction, { x: 0, y: -1, z: 0 }) // points at origin
  assert.equal(dirComp.intensity, 2)

  const point = entities.find((e) => e.components.some((c) => c._class.endsWith('PointLight')))
  assert.ok(transformOf(point), 'point light carries a Transform3D')
  assert.equal(point.components.find((c) => c._class.endsWith('PointLight')).radius, 12)

  assert.ok(warnings.some((w) => /ambientLight/.test(w)))
  assert.ok(warnings.some((w) => /<fog>/.test(w)))
})

test('material is content-addressed and deduplicated', () => {
  const { materials } = importR3f(`
    export default () => (
      <group>
        <mesh><boxGeometry args={[1,1,1]} /><meshStandardMaterial color="#ff0000" roughness={0.5} /></mesh>
        <mesh><boxGeometry args={[2,2,2]} /><meshStandardMaterial color="#ff0000" roughness={0.5} /></mesh>
      </group>
    )
  `)
  assert.equal(Object.keys(materials).length, 1, 'identical materials share one id')
})
