import * as THREE from 'three'
import type { Bundle } from './bundle'
import type { ComponentJson, EntityJson, QuatJson, SceneJson, Vec3Json } from './types'

/**
 * Interpret a PHPolygon scene JSON into a Three.js object tree - the
 * "RenderCommandList" of the preview. Each entity becomes a Group; Transform3D
 * sets the transform; MeshRenderer attaches a Mesh built from the exported
 * geometry buffer + approximate material.
 *
 * Geometry is async (fetched per mesh id), so meshes are attached as they
 * resolve. The root group is returned synchronously and populated in place.
 */
export function buildSceneObject(scene: SceneJson, bundle: Bundle): THREE.Group {
  const root = new THREE.Group()
  root.name = scene.name
  for (const entity of scene.entities ?? []) {
    addEntity(root, entity, bundle)
  }
  return root
}

function addEntity(parent: THREE.Object3D, entity: EntityJson, bundle: Bundle): void {
  const node = new THREE.Group()
  node.name = entity.name

  const transform = findComponent(entity, 'Transform3D')
  if (transform) applyTransform(node, transform)

  const meshRenderer = findComponent(entity, 'MeshRenderer')
  if (meshRenderer) {
    const meshId = String(meshRenderer.meshId ?? '')
    const materialId = String(meshRenderer.materialId ?? '')
    const geometryPromise = bundle.geometry(meshId)
    if (geometryPromise) {
      geometryPromise
        .then((geometry) => {
          node.add(new THREE.Mesh(geometry, bundle.material(materialId)))
        })
        .catch((err) => console.warn(`mesh "${meshId}" failed to load`, err))
    }
  }

  parent.add(node)
  for (const child of entity.children ?? []) {
    addEntity(node, child, bundle)
  }
}

function findComponent(entity: EntityJson, shortClass: string): ComponentJson | undefined {
  return (entity.components ?? []).find((c) => c._class.endsWith(`\\${shortClass}`) || c._class.endsWith(shortClass))
}

function applyTransform(node: THREE.Object3D, t: ComponentJson): void {
  const position = t.position as Vec3Json | undefined
  const rotation = t.rotation as QuatJson | undefined
  const scale = t.scale as Vec3Json | undefined
  if (position) node.position.set(position.x, position.y, position.z)
  if (rotation) node.quaternion.set(rotation.x, rotation.y, rotation.z, rotation.w)
  if (scale) node.scale.set(scale.x, scale.y, scale.z)
}
