import { COMPONENT_META, type ComponentName } from '../generated/components'
import type { ComponentJson, EntityJson, SceneJson } from '../runtime/types'
import {
  toColor, toQuat, toRect, toVec2, toVec3, toVec4,
  type ColorInput, type QuatInput, type RectInput, type Vec2Input, type Vec3Input, type Vec4Input,
} from './inputs'

/**
 * Declarative authoring DSL - the practical Vue/TS realisation of the
 * "JSX-style" prototyping idea. Authoring a scene = building the canonical
 * scene-JSON model (render = build); the same model drives the WebGL preview
 * and the export. Component props are typed and generated from the engine
 * schema (see ../generated), so the vocabulary cannot drift.
 */
export function buildComponent(name: ComponentName, props: Record<string, unknown>): ComponentJson {
  const meta = COMPONENT_META[name]
  const typeByName = new Map(meta.properties.map((p) => [p.name, p.type]))

  // Start from engine defaults so the JSON is complete like a real export,
  // then override with the props the author actually set.
  const out: Record<string, unknown> = { ...meta.defaults }
  for (const [key, value] of Object.entries(props)) {
    if (value === undefined) continue
    out[key] = normalize(typeByName.get(key), value)
  }

  return { _class: meta.class, ...out }
}

function normalize(type: string | undefined, value: unknown): unknown {
  if (value === null) return null
  switch (type) {
    case 'Vec2': return toVec2(value as Vec2Input)
    case 'Vec3': return toVec3(value as Vec3Input)
    case 'Vec4': return toVec4(value as Vec4Input)
    case 'Quaternion': return toQuat(value as QuatInput)
    case 'Color': return toColor(value as ColorInput)
    case 'Rect': return toRect(value as RectInput)
    default: return value
  }
}

export function entity(name: string, components: ComponentJson[] = [], children: EntityJson[] = []): EntityJson {
  const node: EntityJson = { name, components }
  if (children.length > 0) node.children = children
  return node
}

export interface SceneOptions {
  systems?: string[]
  /** Optional canonical Scene FQN, drives the generated PHP namespace. */
  scene?: string
}

export function defineScene(name: string, entities: EntityJson[], options: SceneOptions = {}): SceneJson {
  return {
    _version: 1,
    ...(options.scene ? { _scene: options.scene } : {}),
    name,
    systems: options.systems ?? [],
    entities,
  }
}
