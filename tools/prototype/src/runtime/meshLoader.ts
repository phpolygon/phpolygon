import * as THREE from 'three'

/**
 * Decode the engine's MeshCacheIO binary format into a Three.js
 * BufferGeometry. This must mirror PHPolygon\Geometry\MeshCacheIO exactly:
 *
 *   28-byte little-endian header:
 *     a4  magic          "PHMC"
 *     v   formatVersion  uint16
 *     v   reserved       uint16
 *     V   versionHash    uint32
 *     V   vertexCount    uint32  (number of floats, = vertices * 3)
 *     V   normalCount    uint32
 *     V   uvCount        uint32
 *     V   indexCount     uint32
 *   payload:
 *     float32[vertexCount]  positions   (x,y,z per vertex)
 *     float32[normalCount]  normals
 *     float32[uvCount]      uvs         (u,v per vertex)
 *     uint32[indexCount]    indices
 *
 * Tangents are not part of this format (the approximate preview path does not
 * need them; normals are recomputed when absent).
 */
const MAGIC = 'PHMC'
const HEADER_SIZE = 28

export function decodeMesh(buffer: ArrayBuffer): THREE.BufferGeometry {
  if (buffer.byteLength < HEADER_SIZE) {
    throw new Error('Mesh buffer too small for MeshCacheIO header')
  }

  const view = new DataView(buffer)
  const magic = String.fromCharCode(
    view.getUint8(0), view.getUint8(1), view.getUint8(2), view.getUint8(3),
  )
  if (magic !== MAGIC) {
    throw new Error(`Bad mesh magic: expected ${MAGIC}, got ${magic}`)
  }

  const vertexCount = view.getUint32(12, true)
  const normalCount = view.getUint32(16, true)
  const uvCount = view.getUint32(20, true)
  const indexCount = view.getUint32(24, true)

  let offset = HEADER_SIZE
  const positions = new Float32Array(buffer, offset, vertexCount)
  offset += vertexCount * 4
  const normals = new Float32Array(buffer, offset, normalCount)
  offset += normalCount * 4
  const uvs = new Float32Array(buffer, offset, uvCount)
  offset += uvCount * 4
  const indices = indexCount > 0 ? new Uint32Array(buffer, offset, indexCount) : null

  const geometry = new THREE.BufferGeometry()
  // Copy out of the source buffer so views can't be invalidated by GC of it.
  geometry.setAttribute('position', new THREE.BufferAttribute(positions.slice(), 3))
  if (normalCount > 0) {
    geometry.setAttribute('normal', new THREE.BufferAttribute(normals.slice(), 3))
  }
  if (uvCount > 0) {
    geometry.setAttribute('uv', new THREE.BufferAttribute(uvs.slice(), 2))
  }
  if (indices) {
    geometry.setIndex(new THREE.BufferAttribute(indices.slice(), 1))
  }
  if (normalCount === 0) {
    geometry.computeVertexNormals()
  }
  geometry.computeBoundingSphere()
  return geometry
}
