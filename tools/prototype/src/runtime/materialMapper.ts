import * as THREE from 'three'
import type { MaterialJson } from './types'

/**
 * Map a PHPolygon Material to an approximate Three.js material. This is the
 * 3D analogue of GdRenderer2D: a structural approximation for fast iteration,
 * NOT a reference renderer. albedo/roughness/metallic/emission map cleanly to
 * the glTF-style standard material; procedural patterns, cloth, SSS and
 * wetness are intentionally dropped. Clearcoat upgrades to a physical material.
 */
export function mapMaterial(material: MaterialJson | undefined): THREE.Material {
  const albedo = material?.albedo ?? { r: 0.8, g: 0.8, b: 0.8, a: 1 }

  const params: THREE.MeshStandardMaterialParameters = {
    color: new THREE.Color(albedo.r, albedo.g, albedo.b),
    roughness: clamp01(material?.roughness ?? 0.5),
    metalness: clamp01(material?.metallic ?? 0.0),
  }

  if (material?.emission) {
    const e = material.emission
    if (e.r > 0 || e.g > 0 || e.b > 0) {
      params.emissive = new THREE.Color(e.r, e.g, e.b)
      params.emissiveIntensity = 1.0
    }
  }

  const alpha = material?.alpha ?? 1.0
  if (alpha < 1.0) {
    params.transparent = true
    params.opacity = alpha
  }

  if ((material?.clearcoat ?? 0) > 0) {
    const physical = new THREE.MeshPhysicalMaterial(params)
    physical.clearcoat = material!.clearcoat as number
    physical.clearcoatRoughness = (material?.clearcoatRoughness as number | undefined) ?? 0.05
    return physical
  }

  return new THREE.MeshStandardMaterial(params)
}

function clamp01(v: number): number {
  return Math.min(1, Math.max(0, v))
}
