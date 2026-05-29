// Mirrors the JSON shapes written by `bin/phpolygon prototype:export`.

export interface MeshManifestEntry {
  file: string
  vertexCount: number
  triangleCount: number
  bytes: number
}

export interface BundleManifest {
  _version: number
  schema: string
  materials: string
  meshFormat: string
  meshes: Record<string, MeshManifestEntry>
  materialIds: string[]
  scenes: Record<string, string>
}

export interface ColorJson { r: number; g: number; b: number; a: number }

export interface MaterialJson {
  albedo?: ColorJson
  roughness?: number
  metallic?: number
  emission?: ColorJson
  alpha?: number
  shader?: string
  clearcoat?: number
  clearcoatRoughness?: number
  [key: string]: unknown
}

export interface MaterialsFile {
  _version: number
  materials: Record<string, MaterialJson>
}

export interface Vec3Json { x: number; y: number; z: number }
export interface QuatJson { x: number; y: number; z: number; w: number }

export interface ComponentJson {
  _class: string
  [key: string]: unknown
}

export interface EntityJson {
  name: string
  components?: ComponentJson[]
  children?: EntityJson[]
}

export interface SceneJson {
  _version: number
  _scene?: string
  name: string
  config?: Record<string, unknown>
  systems?: string[]
  entities: EntityJson[]
}
