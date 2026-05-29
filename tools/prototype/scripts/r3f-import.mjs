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
//   (box/sphere/cylinder/plane) -> the matching *Mesh generators,
//   <meshStandardMaterial> -> Material, and directional/point lights.
// Anything that can't map to a procedural generator (imported models, raw
// buffer geometry, arbitrary JS, ambient/spot lights) is reported in
// `warnings` rather than silently dropped.
//
// The core is exported as importR3f(code) so it can be unit-tested
// (scripts/r3f-import.test.mjs) and reused; the CLI wrapper is at the bottom.

import { parse } from '@babel/parser'

/**
 * @param {string} code  TSX source
 * @returns {{meshes: object, materials: object, entities: object[], warnings: string[]}}
 */
export function importR3f(code) {
  const ast = parse(code, { sourceType: 'module', plugins: ['jsx', 'typescript'] })

  const warnings = []
  const meshes = {}
  const materials = {}
  const counters = {}

  const tagName = (el) => (el.openingElement?.name?.type === 'JSXIdentifier' ? el.openingElement.name.name : null)
  const elementChildren = (el) => (el.children ?? []).filter((c) => c.type === 'JSXElement')
  const lineOf = (el) => el.loc?.start?.line ?? '?'
  const nextName = (prefix) => `${prefix}_${(counters[prefix] = (counters[prefix] ?? 0) + 1) - 1}`
  const num = (v, d) => (typeof v === 'number' && Number.isFinite(v) ? v : d)

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

  // The scene is the JSX returned by the default export; only fall back to a
  // bare file scan (with a warning) when there is no default export.
  function findSceneRoot() {
    for (const node of ast.program.body) {
      if (node.type === 'ExportDefaultDeclaration') {
        const jsx = firstJsx(node.declaration)
        if (jsx) return jsx
      }
    }
    const jsx = firstJsx(ast)
    if (jsx) warnings.push('no default export with JSX; using the first JSX element in the file')
    return jsx
  }

  // Evaluate a literal expression. Warns (rather than silently defaulting)
  // when a present node can't be evaluated statically - variables, member
  // access, template literals, spreads, etc.
  function evalExpr(node) {
    if (!node) return undefined
    switch (node.type) {
      case 'NumericLiteral': return node.value
      case 'StringLiteral': return node.value
      case 'BooleanLiteral': return node.value
      case 'UnaryExpression': {
        if (node.operator !== '-') break
        const inner = evalExpr(node.argument)
        return typeof inner === 'number' ? -inner : undefined
      }
      case 'ArrayExpression':
        return node.elements.map(evalExpr)
    }
    warnings.push(`expression at line ${node.loc?.start?.line ?? '?'} (${node.type}) is not statically evaluable; default used`)
    return undefined
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

  // --- value helpers --------------------------------------------------------

  const vec3 = (a) => ({ x: num(a[0], 0), y: num(a[1], 0), z: num(a[2], 0) })

  // Keep the sign so box(-2.5,..) and box(2.5,..) don't collide to one id.
  const slugNum = (n) => String(n).replace(/-/g, 'n').replace(/\./g, '_').replace(/[^0-9a-z_]/gi, '')
  const slug = (parts) => parts.map(slugNum).join('x')

  function hexToRgba(hex) {
    let h = hex.replace('#', '')
    if (h.length === 3) h = h.split('').map((c) => c + c).join('')
    const n = Number.parseInt(h, 16)
    return { r: ((n >> 16) & 255) / 255, g: ((n >> 8) & 255) / 255, b: (n & 255) / 255, a: 1 }
  }

  function normalize3(x, y, z) {
    const len = Math.hypot(x, y, z)
    // `+ 0` normalises -0 to 0 so the direction never carries a negative zero.
    return len < 1e-9 ? [0, -1, 0] : [x / len + 0, y / len + 0, z / len + 0]
  }

  // three.js 'XYZ' Euler order -> quaternion.
  function eulerToQuat(a) {
    const [x, y, z] = [num(a[0], 0), num(a[1], 0), num(a[2], 0)]
    const cx = Math.cos(x / 2), sx = Math.sin(x / 2)
    const cy = Math.cos(y / 2), sy = Math.sin(y / 2)
    const cz = Math.cos(z / 2), sz = Math.sin(z / 2)
    return {
      x: sx * cy * cz + cx * sy * sz,
      y: cx * sy * cz - sx * cy * sz,
      z: cx * cy * sz + sx * sy * cz,
      w: cx * cy * cz - sx * sy * sz,
    }
  }

  // --- mapping --------------------------------------------------------------

  function geometryToMesh(el) {
    const a = Array.isArray(getAttr(el, 'args')) ? getAttr(el, 'args') : []
    switch (tagName(el)) {
      case 'boxGeometry': {
        const args = [num(a[0], 1), num(a[1], 1), num(a[2], 1)]
        return { id: `box_${slug(args)}`, generator: 'BoxMesh', args }
      }
      case 'sphereGeometry': {
        // R3F [radius, widthSeg, heightSeg] -> generate(radius, stacks, slices)
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

  function materialId(props) {
    // Content-addressed: identical materials share an id (deterministic, no crypto dep).
    let hash = 5381
    const str = JSON.stringify(props)
    for (let i = 0; i < str.length; i++) hash = ((hash * 33) ^ str.charCodeAt(i)) >>> 0
    return `mat_${hash.toString(16).padStart(8, '0')}`
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
      const entity = { name: (typeof getAttr(el, 'name') === 'string' && getAttr(el, 'name')) || nextName('group'), components: [] }
      const transform = transformComponent(el)
      if (transform) entity.components.push(transform)
      const children = elementChildren(el).map(processElement).filter(Boolean)
      if (children.length) entity.children = children
      return entity
    }

    if (tag === 'mesh') {
      const entity = { name: (typeof getAttr(el, 'name') === 'string' && getAttr(el, 'name')) || nextName('mesh'), components: [] }
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

  const root = findSceneRoot()
  if (!root) {
    return { meshes, materials, entities: [], warnings: [...warnings, 'no JSX found in the file'] }
  }

  // If the outer element is a Canvas/fragment, its children are the scene roots.
  const outerTag = root.type === 'JSXElement' ? tagName(root) : null
  const roots = outerTag === 'Canvas' || root.type === 'JSXFragment' ? elementChildren(root) : [root]
  const entities = roots.map(processElement).filter(Boolean)

  return { meshes, materials, entities, warnings }
}

// --- CLI --------------------------------------------------------------------

import { pathToFileURL } from 'node:url'

const isMain = process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href
if (isMain) {
  const { readFileSync, writeFileSync, existsSync } = await import('node:fs')
  const { basename } = await import('node:path')

  const input = process.argv[2]
  const outIdx = process.argv.indexOf('--out')
  const outFile = outIdx !== -1 ? process.argv[outIdx + 1] : null

  if (!input || !existsSync(input)) {
    console.error('Usage: node scripts/r3f-import.mjs <file.tsx> [--out file.import.json]')
    process.exit(1)
  }

  const { meshes, materials, entities, warnings } = importR3f(readFileSync(input, 'utf8'))
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
}
