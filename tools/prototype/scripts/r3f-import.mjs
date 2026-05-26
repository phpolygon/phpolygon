#!/usr/bin/env node
// Import a react-three-fiber TSX prototype into a PHPolygon import.json.
//
//   node scripts/r3f-import.mjs prototype.tsx [--out prototype.import.json]
//
// Then turn it into canonical PHP:
//   php bin/phpolygon scene:import prototype.import.json --out src/Scene/X.php
//
// Maps the R3F subset that corresponds to PHPolygon's procedural model:
//   <mesh>/<group> + position/rotation/scale, the primitive geometries
//   (box/sphere/cylinder/plane) -> the matching *Mesh generators, and
//   <meshStandardMaterial> -> Material. Anything that can't map to a procedural
//   generator (imported models, raw buffer geometry, arbitrary JS) is reported
//   in `warnings` rather than silently dropped.

import { readFileSync, writeFileSync, existsSync } from 'node:fs'
import { basename } from 'node:path'
import { createHash } from 'node:crypto'
import { parse } from '@babel/parser'

const input = process.argv[2]
const outIdx = process.argv.indexOf('--out')
const outFile = outIdx !== -1 ? process.argv[outIdx + 1] : null

if (!input || !existsSync(input)) {
  console.error(`Usage: node scripts/r3f-import.mjs <file.tsx> [--out file.import.json]`)
  process.exit(1)
}

const ast = parse(readFileSync(input, 'utf8'), {
  sourceType: 'module',
  plugins: ['jsx', 'typescript'],
})

const warnings = []
const meshes = {}
const materials = {}
const counters = {}

// --- AST helpers ------------------------------------------------------------

function firstJsx(node, seen = new Set()) {
  if (!node || typeof node !== 'object' || seen.has(node)) return null
  seen.add(node)
  if (node.type === 'JSXElement' || node.type === 'JSXFragment') return node
  for (const key of Object.keys(node)) {
    if (key === 'loc' || key === 'leadingComments' || key === 'trailingComments') continue
    const value = node[key]
    if (Array.isArray(value)) {
      for (const item of value) {
        const found = firstJsx(item, seen)
        if (found) return found
      }
    } else if (value && typeof value === 'object') {
      const found = firstJsx(value, seen)
      if (found) return found
    }
  }
  return null
}

const tagName = (el) => (el.openingElement?.name?.type === 'JSXIdentifier' ? el.openingElement.name.name : null)
const elementChildren = (el) => (el.children ?? []).filter((c) => c.type === 'JSXElement')

function evalExpr(node) {
  if (!node) return undefined
  switch (node.type) {
    case 'NumericLiteral': return node.value
    case 'StringLiteral': return node.value
    case 'BooleanLiteral': return node.value
    case 'UnaryExpression':
      return node.operator === '-' ? -evalExpr(node.argument) : undefined
    case 'ArrayExpression':
      return node.elements.map(evalExpr)
    default:
      return undefined
  }
}

function getAttr(el, name) {
  for (const attr of el.openingElement?.attributes ?? []) {
    if (attr.type !== 'JSXAttribute' || attr.name?.name !== name) continue
    if (attr.value == null) return true
    if (attr.value.type === 'StringLiteral') return attr.value.value
    if (attr.value.type === 'JSXExpressionContainer') return evalExpr(attr.value.expression)
  }
  return undefined
}

const lineOf = (el) => el.loc?.start?.line ?? '?'
const nextName = (prefix) => `${prefix}_${(counters[prefix] = (counters[prefix] ?? 0) + 1) - 1}`

// --- Mapping ----------------------------------------------------------------

const num = (v, d) => (typeof v === 'number' ? v : d)
const slug = (parts) => parts.map((n) => String(n).replace(/[^0-9a-z.]/gi, '')).join('x').replace(/\./g, '_')

function geometryToMesh(el) {
  const a = Array.isArray(getAttr(el, 'args')) ? getAttr(el, 'args') : []
  switch (tagName(el)) {
    case 'boxGeometry': {
      const args = [num(a[0], 1), num(a[1], 1), num(a[2], 1)]
      return { id: `box_${slug(args)}`, generator: 'BoxMesh', args }
    }
    case 'sphereGeometry': {
      // R3F [radius, widthSeg, heightSeg] -> PHPolygon generate(radius, stacks, slices)
      const args = [num(a[0], 1), num(a[2], 12), num(a[1], 16)]
      return { id: `sphere_${slug(args)}`, generator: 'SphereMesh', args }
    }
    case 'cylinderGeometry': {
      // R3F [radiusTop, radiusBottom, height, radialSeg] -> generate(radius, height, segments)
      const args = [num(a[0], 1), num(a[2], 2), num(a[3], 16)]
      return { id: `cylinder_${slug(args)}`, generator: 'CylinderMesh', args }
    }
    case 'planeGeometry': {
      const args = [num(a[0], 1), num(a[1], 1)]
      return { id: `plane_${slug(args)}`, generator: 'PlaneMesh', args }
    }
    default:
      return null
  }
}

function materialFromEl(el) {
  if (tagName(el) !== 'meshStandardMaterial' && tagName(el) !== 'meshPhysicalMaterial') {
    warnings.push(`<${tagName(el)}> at line ${lineOf(el)}: unsupported material, using default`)
    return null
  }
  const props = {}
  const color = getAttr(el, 'color')
  if (typeof color === 'string') {
    if (color.startsWith('#')) props.albedo = color
    else warnings.push(`color "${color}" at line ${lineOf(el)} is not a hex value; ignored`)
  }
  const roughness = getAttr(el, 'roughness')
  if (typeof roughness === 'number') props.roughness = roughness
  const metalness = getAttr(el, 'metalness')
  if (typeof metalness === 'number') props.metallic = metalness
  const emissive = getAttr(el, 'emissive')
  if (typeof emissive === 'string' && emissive.startsWith('#')) props.emission = emissive
  return props
}

function transformComponent(el) {
  const comp = { _class: 'PHPolygon\\Component\\Transform3D' }
  const position = getAttr(el, 'position')
  const rotation = getAttr(el, 'rotation')
  const scale = getAttr(el, 'scale')
  if (Array.isArray(position)) comp.position = vec3(position)
  if (Array.isArray(rotation)) comp.rotation = eulerToQuat(rotation)
  if (Array.isArray(scale)) comp.scale = vec3(scale)
  else if (typeof scale === 'number') comp.scale = { x: scale, y: scale, z: scale }
  return Object.keys(comp).length > 1 ? comp : null
}

const vec3 = (a) => ({ x: num(a[0], 0), y: num(a[1], 0), z: num(a[2], 0) })

function eulerToQuat(a) {
  const [x, y, z] = [num(a[0], 0), num(a[1], 0), num(a[2], 0)]
  const cx = Math.cos(x / 2), sx = Math.sin(x / 2)
  const cy = Math.cos(y / 2), sy = Math.sin(y / 2)
  const cz = Math.cos(z / 2), sz = Math.sin(z / 2)
  return { // three.js 'XYZ' order
    x: sx * cy * cz + cx * sy * sz,
    y: cx * sy * cz - sx * cy * sz,
    z: cx * cy * sz - sx * sy * cz,
    w: cx * cy * cz - sx * sy * sz,
  }
}

function hexToRgba(hex) {
  let h = hex.replace('#', '')
  if (h.length === 3) h = h.split('').map((c) => c + c).join('')
  const n = Number.parseInt(h, 16)
  return { r: ((n >> 16) & 255) / 255, g: ((n >> 8) & 255) / 255, b: (n & 255) / 255, a: 1 }
}

function normalize3(x, y, z) {
  const len = Math.hypot(x, y, z)
  return len < 1e-9 ? [0, -1, 0] : [x / len, y / len, z / len]
}

function lightColor(el, comp) {
  const color = getAttr(el, 'color')
  if (typeof color === 'string') {
    if (color.startsWith('#')) comp.color = hexToRgba(color)
    else warnings.push(`light color "${color}" at line ${lineOf(el)} is not a hex value; ignored`)
  }
  const intensity = getAttr(el, 'intensity')
  if (typeof intensity === 'number') comp.intensity = intensity
}

function directionalLightEntity(el) {
  const comp = { _class: 'PHPolygon\\Component\\DirectionalLight' }
  const position = getAttr(el, 'position')
  if (Array.isArray(position)) {
    // R3F directional lights point from `position` toward the origin/target.
    const [x, y, z] = normalize3(-num(position[0], 0), -num(position[1], 0), -num(position[2], 0))
    comp.direction = { x, y, z }
  }
  lightColor(el, comp)
  return { name: getAttr(el, 'name') || nextName('light'), components: [comp] }
}

function pointLightEntity(el) {
  const entity = { name: getAttr(el, 'name') || nextName('light'), components: [] }
  const position = getAttr(el, 'position')
  if (Array.isArray(position)) {
    entity.components.push({ _class: 'PHPolygon\\Component\\Transform3D', position: vec3(position) })
  }
  const comp = { _class: 'PHPolygon\\Component\\PointLight' }
  lightColor(el, comp)
  const distance = getAttr(el, 'distance')
  if (typeof distance === 'number' && distance > 0) comp.radius = distance
  entity.components.push(comp)
  return entity
}

function processElement(el) {
  const tag = tagName(el)
  if (tag === 'group') {
    const entity = { name: getAttr(el, 'name') || nextName('group'), components: [] }
    const transform = transformComponent(el)
    if (transform) entity.components.push(transform)
    const children = elementChildren(el).map(processElement).filter(Boolean)
    if (children.length) entity.children = children
    return entity
  }

  if (tag === 'mesh') {
    const entity = { name: getAttr(el, 'name') || nextName('mesh'), components: [] }
    const transform = transformComponent(el)
    if (transform) entity.components.push(transform)

    const renderer = { _class: 'PHPolygon\\Component\\MeshRenderer' }
    for (const child of elementChildren(el)) {
      const mesh = geometryToMesh(child)
      if (mesh) {
        meshes[mesh.id] = { generator: mesh.generator, args: mesh.args }
        renderer.meshId = mesh.id
      } else if (tagName(child)?.endsWith('Material')) {
        const props = materialFromEl(child)
        if (props) {
          const id = materialId(props)
          materials[id] = props
          renderer.materialId = id
        }
      } else {
        warnings.push(`<${tagName(child)}> at line ${lineOf(child)} inside <mesh> is not importable`)
      }
    }
    if (!renderer.meshId) warnings.push(`<mesh> "${entity.name}" at line ${lineOf(el)} has no importable geometry`)
    entity.components.push(renderer)
    return entity
  }

  if (tag === 'directionalLight') return directionalLightEntity(el)
  if (tag === 'pointLight') return pointLightEntity(el)
  if (tag === 'ambientLight') {
    warnings.push(`<ambientLight> at line ${lineOf(el)}: no ambient component - set ambient lighting in code (SetAmbientLight / SceneConfig)`)
    return null
  }
  if (tag === 'spotLight' || tag === 'hemisphereLight') {
    warnings.push(`<${tag}> at line ${lineOf(el)}: not imported (no equivalent component)`)
    return null
  }

  warnings.push(`<${tag}> at line ${lineOf(el)} is not importable (only mesh/group, primitive geometry/material, directional/point lights)`)
  return null
}

function materialId(props) {
  const hash = createHash('sha1').update(JSON.stringify(props)).digest('hex').slice(0, 8)
  return `mat_${hash}`
}

// --- Drive ------------------------------------------------------------------

const root = firstJsx(ast)
if (!root) {
  console.error('No JSX found in the file.')
  process.exit(1)
}

// If the outer element is a Canvas/fragment, its children are the scene roots.
const outerTag = root.type === 'JSXElement' ? tagName(root) : null
const roots = outerTag === 'Canvas' || root.type === 'JSXFragment' ? elementChildren(root) : [root]

const entities = roots.map(processElement).filter(Boolean)

const result = {
  name: basename(input).replace(/\.(t|j)sx?$/, ''),
  systems: ['PHPolygon\\System\\Camera3DSystem', 'PHPolygon\\System\\Renderer3DSystem'],
  meshes,
  materials,
  entities,
  warnings,
}

const json = JSON.stringify(result, null, 2)
if (outFile) {
  writeFileSync(outFile, json)
  console.error(`Wrote ${outFile} (${entities.length} entities, ${Object.keys(meshes).length} meshes, ${warnings.length} warnings)`)
} else {
  process.stdout.write(json + '\n')
}
for (const w of warnings) console.error(`! ${w}`)
