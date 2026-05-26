#!/usr/bin/env node
// Layered scene importer.
//
//   node scripts/scene-extract.mjs prototype.tsx [--out file.import.json]
//
// Tier 1 (static, no execution): declarative react-three-fiber JSX
//   (delegates to r3f-import.mjs).
// Tier 2 (fallback, executes the file): imperative Three.js. The file is run
//   with a mocked React (useEffect runs synchronously) and a patched THREE
//   (WebGLRenderer stubbed out, Scene instrumented), then the built scene graph
//   is traversed - geometry.parameters, world transforms, material colours and
//   lights are read straight off the real objects. Only the initial frame is
//   captured; the render/game loop never runs.
//
// Caveat: tier 2 EXECUTES the input file. Run it only on code you trust.

import { readFileSync, writeFileSync, existsSync } from 'node:fs'
import { basename } from 'node:path'
import { transformSync } from '@babel/core'
import presetReact from '@babel/preset-react'
import presetTypescript from '@babel/preset-typescript'
import pluginCommonjs from '@babel/plugin-transform-modules-commonjs'
import * as THREE from 'three'
import { importR3f } from './r3f-import.mjs'

const round = (n) => Math.round(n * 1e5) / 1e5
const slug = (parts) => parts.map((n) => String(n).replace(/-/g, 'n').replace(/\./g, '_').replace(/[^0-9a-z_]/gi, '')).join('x')

function materialId(props) {
  let h = 5381
  const s = JSON.stringify(props)
  for (let i = 0; i < s.length; i++) h = ((h * 33) ^ s.charCodeAt(i)) >>> 0
  return `mat_${h.toString(16).padStart(8, '0')}`
}

export function importScene(src, file = 'scene') {
  // --- Tier 1: declarative R3F / static, no execution ---
  const declarative = importR3f(src)
  if (declarative.entities.length > 0) {
    return { ...declarative, _method: 'declarative' }
  }

  // --- Tier 2: execute and traverse ---
  const headless = runHeadless(src, file)
  // Carry over the declarative warnings (e.g. why nothing matched) for context.
  headless.warnings = [...declarative.warnings.filter((w) => !/not importable/.test(w)), ...headless.warnings]
  return headless
}

function runHeadless(src, file) {
  const warnings = []
  const meshes = {}
  const materials = {}
  const entities = []
  let counter = 0
  const nextName = (prefix) => `${prefix}_${counter++}`

  const { code } = transformSync(src, {
    filename: file,
    babelrc: false,
    configFile: false,
    presets: [
      [presetReact, { runtime: 'classic' }],
      [presetTypescript, { allExtensions: true, isTSX: true }],
    ],
    plugins: [pluginCommonjs],
    sourceType: 'module',
  })

  // --- patched THREE (namespace is read-only, so wrap a mutable copy) ---
  const scenes = []
  const ThreeWrap = { ...THREE, __esModule: true }
  ThreeWrap.Scene = class extends THREE.Scene {
    constructor(...a) { super(...a); scenes.push(this) }
  }
  ThreeWrap.WebGLRenderer = function FakeRenderer() {
    return new Proxy(
      { shadowMap: {}, domElement: makeFakeCanvas(), info: {}, capabilities: {}, properties: {}, outputColorSpace: '', toneMapping: 0, xr: { addEventListener() {} } },
      { get: (t, p) => (p in t ? t[p] : () => undefined), set: () => true },
    )
  }

  const makeFakeCtx = () => new Proxy({}, {
    get: (_t, p) => {
      if (p === 'createLinearGradient' || p === 'createRadialGradient' || p === 'createPattern') return () => ({ addColorStop() {} })
      if (p === 'measureText') return () => ({ width: 0 })
      if (p === 'getImageData') return () => ({ data: [] })
      if (p === 'canvas') return undefined
      return () => {}
    },
  })
  function makeFakeCanvas() {
    return new Proxy({ width: 1280, height: 720, clientWidth: 1280, clientHeight: 720, style: {} }, {
      get(t, p) {
        if (p in t) return t[p]
        if (p === 'getContext') return () => makeFakeCtx()
        if (p === 'getBoundingClientRect') return () => ({ left: 0, top: 0, width: 1280, height: 720, right: 1280, bottom: 720 })
        return () => {}
      },
      set: () => true,
    })
  }

  // --- mocked React: effects run synchronously, refs default to a fake canvas ---
  const mockReact = {
    __esModule: true,
    createElement: () => null,
    Fragment: Symbol('Fragment'),
    StrictMode: Symbol('StrictMode'),
    useRef: (v) => ({ current: v === null || v === undefined ? makeFakeCanvas() : v }),
    useState: (v) => [typeof v === 'function' ? v() : v, () => {}],
    useEffect: (fn) => { try { fn() } catch (e) { warnings.push(`effect threw: ${e.message}`) } },
    useLayoutEffect: (fn) => { try { fn() } catch (e) { warnings.push(`layout effect threw: ${e.message}`) } },
    useCallback: (f) => f,
    useMemo: (f) => (typeof f === 'function' ? f() : f),
    useContext: () => ({}),
    memo: (c) => c,
    forwardRef: (c) => c,
  }
  mockReact.default = mockReact

  const fakeRequire = (name) => {
    if (name === 'react' || name === 'react/jsx-runtime' || name === 'react/jsx-dev-runtime') return mockReact
    if (name === 'three') return ThreeWrap
    if (name.startsWith('@react-three')) return new Proxy({ __esModule: true, Canvas: () => null, useThree: () => ({}), useFrame: () => {} }, { get: (t, p) => (p in t ? t[p] : () => null) })
    if (name.includes('three/examples') || name.includes('three/addons')) return new Proxy({ __esModule: true }, { get: () => class { constructor() {} dispose() {} update() {} } })
    return new Proxy({ __esModule: true }, { get: () => () => {} })
  }

  // --- run ---
  const win = new Proxy({ innerWidth: 1280, innerHeight: 720, devicePixelRatio: 1, location: { href: '' }, navigator: { userAgent: 'node', maxTouchPoints: 0 } }, { get: (t, p) => (p in t ? t[p] : () => {}), set: () => true })
  const doc = new Proxy({ body: {}, documentElement: { style: {} } }, { get(t, p) { if (p in t) return t[p]; if (p === 'createElement') return () => makeFakeCanvas(); if (p === 'getElementById' || p === 'querySelector') return () => null; return () => {} } })
  const prevRO = globalThis.ResizeObserver
  globalThis.ResizeObserver = class { observe() {} unobserve() {} disconnect() {} }

  try {
    const moduleObj = { exports: {} }
    const runner = new Function(
      'require', 'module', 'exports', 'React', 'window', 'document', 'navigator',
      'requestAnimationFrame', 'cancelAnimationFrame', 'performance', 'globalThis',
      code,
    )
    runner(
      fakeRequire, moduleObj, moduleObj.exports, mockReact, win, doc, win.navigator,
      () => 0, () => {}, (globalThis.performance ?? { now: () => 0 }), globalThis,
    )
    const Component = moduleObj.exports.default
    if (typeof Component !== 'function') {
      warnings.push('no default-exported component function found')
    } else {
      try { Component({}) } catch (e) { warnings.push(`component threw: ${e.message}`) }
    }
  } catch (e) {
    warnings.push(`failed to run: ${e.message}`)
  } finally {
    globalThis.ResizeObserver = prevRO
  }

  // --- traverse captured scenes ---
  for (const scene of scenes) {
    scene.updateMatrixWorld(true)
    scene.traverse((obj) => {
      if (obj.isMesh) {
        const ent = meshEntity(obj)
        if (ent) entities.push(ent)
      } else if (obj.isLight) {
        const ent = lightEntity(obj)
        if (ent) entities.push(ent)
      }
    })
  }
  if (scenes.length === 0) warnings.push('no THREE.Scene was constructed - nothing to extract')

  return { meshes, materials, entities, warnings, _method: 'headless' }

  // --- helpers (closures over meshes/materials/warnings/nextName) ---

  function geometryToMesh(g) {
    if (!g || !g.parameters) return null
    const p = g.parameters
    const d = (v) => Math.round(num(v) * 1000) / 1000 // clean float noise from constructed dims
    switch (g.type) {
      case 'BoxGeometry': { const a = [d(p.width), d(p.height), d(p.depth)]; return { id: `box_${slug(a)}`, generator: 'BoxMesh', args: a } }
      case 'SphereGeometry': { const a = [d(p.radius), int(p.heightSegments, 12), int(p.widthSegments, 16)]; return { id: `sphere_${slug(a)}`, generator: 'SphereMesh', args: a } }
      case 'CylinderGeometry': { const a = [d(p.radiusTop ?? p.radiusBottom), d(p.height), int(p.radialSegments, 16)]; return { id: `cyl_${slug(a)}`, generator: 'CylinderMesh', args: a } }
      case 'PlaneGeometry': { const a = [d(p.width), d(p.height)]; return { id: `plane_${slug(a)}`, generator: 'PlaneMesh', args: a } }
      case 'TorusGeometry': { const a = [d(p.radius), d(p.tube), int(p.radialSegments, 12), int(p.tubularSegments, 24)]; return { id: `torus_${slug(a)}`, generator: 'TorusMesh', args: a } }
      case 'OctahedronGeometry': { const a = [d(p.radius)]; return { id: `octa_${slug(a)}`, generator: 'OctahedronMesh', args: a } }
      default: return null
    }
  }

  function meshEntity(obj) {
    const mesh = geometryToMesh(obj.geometry)
    if (!mesh) {
      warnings.push(`mesh "${obj.name || '?'}" uses ${obj.geometry?.type || 'unknown/custom geometry'} - not a procedural primitive, skipped`)
      return null
    }
    meshes[mesh.id] = { generator: mesh.generator, args: mesh.args }

    const pos = new THREE.Vector3(), quat = new THREE.Quaternion(), scl = new THREE.Vector3()
    obj.matrixWorld.decompose(pos, quat, scl)

    return {
      name: obj.name || nextName('mesh'),
      components: [
        {
          _class: 'PHPolygon\\Component\\Transform3D',
          position: { x: round(pos.x), y: round(pos.y), z: round(pos.z) },
          rotation: { x: round(quat.x), y: round(quat.y), z: round(quat.z), w: round(quat.w) },
          scale: { x: round(scl.x), y: round(scl.y), z: round(scl.z) },
        },
        { _class: 'PHPolygon\\Component\\MeshRenderer', meshId: mesh.id, materialId: materialOf(obj.material) },
      ],
    }
  }

  function materialOf(material) {
    const m = Array.isArray(material) ? material[0] : material
    const props = {}
    if (m?.color?.getHexString) props.albedo = '#' + m.color.getHexString()
    if (typeof m?.roughness === 'number') props.roughness = round(m.roughness)
    if (typeof m?.metalness === 'number') props.metallic = round(m.metalness)
    if (m?.emissive?.getHex && m.emissive.getHex() !== 0) props.emission = '#' + m.emissive.getHexString()
    const id = materialId(props)
    materials[id] = props
    return id
  }

  function lightEntity(obj) {
    if (obj.isDirectionalLight || obj.isSpotLight) {
      const dir = new THREE.Vector3()
      const target = obj.target ? obj.target.position : new THREE.Vector3()
      dir.copy(target).sub(obj.position)
      if (dir.lengthSq() < 1e-9) dir.set(0, -1, 0); else dir.normalize()
      const comp = { _class: 'PHPolygon\\Component\\DirectionalLight', direction: { x: round(dir.x), y: round(dir.y), z: round(dir.z) } }
      if (obj.color) comp.color = { r: round(obj.color.r), g: round(obj.color.g), b: round(obj.color.b), a: 1 }
      if (typeof obj.intensity === 'number') comp.intensity = round(obj.intensity)
      return { name: obj.name || nextName('light'), components: [comp] }
    }
    if (obj.isPointLight) {
      const p = new THREE.Vector3()
      obj.getWorldPosition(p)
      const ent = { name: obj.name || nextName('light'), components: [{ _class: 'PHPolygon\\Component\\Transform3D', position: { x: round(p.x), y: round(p.y), z: round(p.z) } }] }
      const comp = { _class: 'PHPolygon\\Component\\PointLight' }
      if (obj.color) comp.color = { r: round(obj.color.r), g: round(obj.color.g), b: round(obj.color.b), a: 1 }
      if (typeof obj.intensity === 'number') comp.intensity = round(obj.intensity)
      if (typeof obj.distance === 'number' && obj.distance > 0) comp.radius = round(obj.distance)
      ent.components.push(comp)
      return ent
    }
    if (obj.isAmbientLight) {
      const comp = { _class: 'PHPolygon\\Component\\AmbientLight' }
      if (obj.color) comp.color = { r: round(obj.color.r), g: round(obj.color.g), b: round(obj.color.b), a: 1 }
      if (typeof obj.intensity === 'number') comp.intensity = round(obj.intensity)
      return { name: obj.name || nextName('light'), components: [comp] }
    }
    if (obj.isHemisphereLight) {
      // No hemisphere gradient in the forward renderer - realise as ambient
      // with the sky/ground colours blended.
      const sky = obj.color
      const ground = obj.groundColor
      const blend = (a, b) => (typeof b === 'number' ? (a + b) / 2 : a)
      const comp = { _class: 'PHPolygon\\Component\\AmbientLight' }
      if (sky) comp.color = { r: round(blend(sky.r, ground?.r)), g: round(blend(sky.g, ground?.g)), b: round(blend(sky.b, ground?.b)), a: 1 }
      if (typeof obj.intensity === 'number') comp.intensity = round(obj.intensity)
      return { name: obj.name || nextName('light'), components: [comp] }
    }
    return null
  }
}

const num = (v) => (typeof v === 'number' && Number.isFinite(v) ? v : 1)
const int = (v, d) => (typeof v === 'number' && Number.isFinite(v) ? Math.round(v) : d)

// --- CLI ---
import { pathToFileURL } from 'node:url'
const isMain = process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href
if (isMain) {
  const input = process.argv[2]
  const outIdx = process.argv.indexOf('--out')
  const outFile = outIdx !== -1 ? process.argv[outIdx + 1] : null
  if (!input || !existsSync(input)) {
    console.error('Usage: node scripts/scene-extract.mjs <file.tsx> [--out file.import.json]')
    process.exit(1)
  }
  const { meshes, materials, entities, warnings, _method } = importScene(readFileSync(input, 'utf8'), input)
  const result = {
    name: basename(input).replace(/\.(t|j)sx?$/, ''),
    systems: ['PHPolygon\\System\\Camera3DSystem', 'PHPolygon\\System\\Renderer3DSystem'],
    meshes, materials, entities, warnings,
  }
  const json = JSON.stringify(result, null, 2)
  if (outFile) {
    writeFileSync(outFile, json)
    console.error(`[${_method}] wrote ${outFile}: ${entities.length} entities, ${Object.keys(meshes).length} meshes, ${Object.keys(materials).length} materials, ${warnings.length} warnings`)
  } else {
    process.stdout.write(json + '\n')
  }
  for (const w of warnings) console.error(`! ${w}`)
}
