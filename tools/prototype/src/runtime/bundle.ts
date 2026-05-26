import * as THREE from 'three'
import { decodeMesh } from './meshLoader'
import { mapMaterial } from './materialMapper'
import type { BundleManifest, MaterialsFile, SceneJson } from './types'

/**
 * Loads the static bundle written by `bin/phpolygon prototype:export` over
 * plain HTTP(S) static fetches - no PHP at runtime. Geometry is fetched and
 * decoded lazily per mesh id and cached; materials are loaded up front.
 */
export class Bundle {
  private geometryCache = new Map<string, Promise<THREE.BufferGeometry>>()
  private fallbackMaterial = new THREE.MeshStandardMaterial({ color: 0xff00ff, wireframe: true })

  private constructor(
    public readonly baseUrl: string,
    public readonly manifest: BundleManifest,
    private readonly materials: MaterialsFile,
    public readonly schema: Record<string, unknown>,
  ) {}

  static async load(baseUrl: string): Promise<Bundle> {
    const base = baseUrl.replace(/\/$/, '')
    const manifest = await fetchJson<BundleManifest>(`${base}/manifest.json`)
    const [materials, schema] = await Promise.all([
      fetchJson<MaterialsFile>(`${base}/${manifest.materials}`),
      fetchJson<Record<string, unknown>>(`${base}/${manifest.schema}`),
    ])
    return new Bundle(base, manifest, materials, schema)
  }

  sceneNames(): string[] {
    return Object.keys(this.manifest.scenes)
  }

  async loadScene(name: string): Promise<SceneJson> {
    const relative = this.manifest.scenes[name]
    if (!relative) throw new Error(`Unknown scene: ${name}`)
    return fetchJson<SceneJson>(`${this.baseUrl}/${relative}`)
  }

  material(id: string): THREE.Material {
    const json = this.materials.materials[id]
    return json ? mapMaterial(json) : this.fallbackMaterial
  }

  geometry(meshId: string): Promise<THREE.BufferGeometry> | null {
    const entry = this.manifest.meshes[meshId]
    if (!entry) return null
    let cached = this.geometryCache.get(meshId)
    if (!cached) {
      cached = fetch(`${this.baseUrl}/${entry.file}`)
        .then((r) => r.arrayBuffer())
        .then(decodeMesh)
      this.geometryCache.set(meshId, cached)
    }
    return cached
  }
}

async function fetchJson<T>(url: string): Promise<T> {
  const res = await fetch(url)
  if (!res.ok) throw new Error(`Failed to fetch ${url}: ${res.status}`)
  return (await res.json()) as T
}
