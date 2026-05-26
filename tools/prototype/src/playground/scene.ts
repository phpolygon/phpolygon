import { defineScene, entity } from '../authoring/dsl'
import { MeshRenderer, Transform3D } from '../generated/builders'

// ---------------------------------------------------------------------------
// Scratchpad. Edit this; the preview updates on save (Vite HMR). Hit "Save
// scene" to write a .scene.json, then turn it into canonical PHP with:
//   php bin/phpolygon scene:transpile playground.scene.json --out src/Scene/X.php
//
// Component builders (Transform3D, MeshRenderer, ...) are generated from the
// engine schema and fully typed. Mesh ids come from the exported bundle
// (default palette: box_1x1x1, sphere_r1, cylinder_r1, plane_1x1).
// ---------------------------------------------------------------------------
export default defineScene(
  'playground',
  [
    entity('Ground', [
      Transform3D({ position: [0, 0, 0], scale: [40, 1, 40] }),
      MeshRenderer({ meshId: 'plane_1x1', materialId: 'default' }),
    ]),
    entity('Pillar', [
      Transform3D({ position: [0, 1, 0], scale: [1, 2, 1] }),
      MeshRenderer({ meshId: 'cylinder_r1', materialId: 'default' }),
    ]),
    entity('Orb', [
      Transform3D({ position: [3, 1.5, 0] }),
      MeshRenderer({ meshId: 'sphere_r1', materialId: 'default' }),
    ]),
    entity('Crate', [
      Transform3D({ position: [-3, 0.5, 1], rotation: [0, 0.38, 0, 0.92] }),
      MeshRenderer({ meshId: 'box_1x1x1', materialId: 'default' }),
    ]),
  ],
  { systems: ['PHPolygon\\System\\Camera3DSystem', 'PHPolygon\\System\\Renderer3DSystem'] },
)
