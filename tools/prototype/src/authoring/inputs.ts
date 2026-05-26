import type { ColorJson, QuatJson, Vec3Json } from '../runtime/types'

// Ergonomic input forms for the authoring DSL: every vector/colour accepts
// either a tuple or an object (and Color also a hex string). They are
// normalised to the canonical {x,y,z(,w)} / {r,g,b,a} JSON the engine expects.

export type Vec2Input = [number, number] | { x: number; y: number }
export type Vec3Input = [number, number, number] | { x: number; y: number; z: number }
export type Vec4Input = [number, number, number, number] | { x: number; y: number; z: number; w: number }
export type QuatInput = [number, number, number, number] | { x: number; y: number; z: number; w: number }
export type RectInput =
  | [number, number, number, number]
  | { x: number; y: number; width: number; height: number }
export type ColorInput =
  | [number, number, number]
  | [number, number, number, number]
  | { r: number; g: number; b: number; a?: number }
  | string

export function toVec2(v: Vec2Input): { x: number; y: number } {
  return Array.isArray(v) ? { x: v[0], y: v[1] } : { x: v.x, y: v.y }
}

export function toVec3(v: Vec3Input): Vec3Json {
  return Array.isArray(v) ? { x: v[0], y: v[1], z: v[2] } : { x: v.x, y: v.y, z: v.z }
}

export function toVec4(v: Vec4Input): QuatJson {
  return Array.isArray(v) ? { x: v[0], y: v[1], z: v[2], w: v[3] } : { x: v.x, y: v.y, z: v.z, w: v.w }
}

export function toQuat(v: QuatInput): QuatJson {
  return Array.isArray(v) ? { x: v[0], y: v[1], z: v[2], w: v[3] } : { x: v.x, y: v.y, z: v.z, w: v.w }
}

export function toRect(v: RectInput): { x: number; y: number; width: number; height: number } {
  return Array.isArray(v)
    ? { x: v[0], y: v[1], width: v[2], height: v[3] }
    : { x: v.x, y: v.y, width: v.width, height: v.height }
}

export function toColor(v: ColorInput): ColorJson {
  if (typeof v === 'string') return hexToColor(v)
  if (Array.isArray(v)) return { r: v[0], g: v[1], b: v[2], a: v[3] ?? 1 }
  return { r: v.r, g: v.g, b: v.b, a: v.a ?? 1 }
}

function hexToColor(hex: string): ColorJson {
  let h = hex.replace('#', '')
  if (h.length === 3) h = h.split('').map((c) => c + c).join('')
  const n = Number.parseInt(h, 16)
  return { r: ((n >> 16) & 255) / 255, g: ((n >> 8) & 255) / 255, b: (n & 255) / 255, a: 1 }
}
