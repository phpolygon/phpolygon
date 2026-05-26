# PHPolygon Prototype Playground

A file-based WebGL prototyping playground for PHPolygon scenes, built with
Vue 3 + [TresJS](https://tresjs.org) (declarative Three.js). It reads the
static bundle written by `bin/phpolygon prototype:export` and renders an
**approximate** preview of your scenes - the 3D analogue of `GdRenderer2D`,
a structural approximation, not a reference renderer.

The browser never talks to PHP at runtime. See the full design in
[`docs/webgl-prototyping-concept.md`](../../docs/webgl-prototyping-concept.md).

## Quick start

```bash
# 1. Export the static bundle from your project root into the playground's
#    public dir (Vite serves public/ at the web root, so it lands at /bundle).
php bin/phpolygon prototype:export --out tools/prototype/public/bundle

# 2. Run the playground.
cd tools/prototype
npm install
npm run gen      # generate the typed component vocabulary from the exported schema
npm run dev
```

`src/generated/` is produced by `npm run gen` (not committed); run it after
`npm install` and after every `prototype:export`.

Open the dev URL, pick a scene, orbit with the mouse.

To preview your own scenes, drop a `prototype.php` in your project root that
registers meshes/materials and returns `Scene` instances (see the concept doc),
then re-run `prototype:export`.

## Round-trip back to PHP

1. Pick a scene and click **Download .scene.json**.
2. Transpile it to canonical PHP:
   ```bash
   php bin/phpolygon scene:transpile ~/Downloads/your.scene.json --out src/Scene/Your.php
   ```

## Import an R3F TSX prototype

Have a react-three-fiber `.tsx` (e.g. from Claude Desktop)? Import it into a
canonical PHP Scene:

```bash
node scripts/r3f-import.mjs examples/prototype.tsx --out prototype.import.json
php ../../bin/phpolygon scene:import prototype.import.json --out ../../src/Scene/Prototype.php
```

The importer maps `<mesh>`/`<group>` + transforms, primitive geometries
(`boxGeometry`/`sphereGeometry`/`cylinderGeometry`/`planeGeometry`) → the
matching `*Mesh` generators, and `<meshStandardMaterial>` → `Material`. Anything
that can't map to PHPolygon's procedural model (imported models, raw buffer
geometry, lights, arbitrary JS) is listed under `warnings` instead of being
silently dropped. See `examples/prototype.tsx`.

## Authoring (Scratchpad)

The "Scratchpad" tab previews `src/playground/scene.ts`, authored with a typed
declarative builder - the practical Vue/TS realisation of the "JSX-style" idea:

```ts
import { defineScene, entity } from '../authoring/dsl'
import { Transform3D, MeshRenderer } from '../generated/builders'

export default defineScene('playground', [
  entity('Ground', [
    Transform3D({ position: [0, 0, 0], scale: [40, 1, 40] }),
    MeshRenderer({ meshId: 'plane_1x1', materialId: 'default' }),
  ]),
])
```

`Transform3D`, `MeshRenderer`, ... are **generated from the engine schema**
(`npm run gen` reads `public/bundle/schema.json` -> `src/generated/`), so the
vocabulary and its prop types cannot drift from the engine's components. Editing
`scene.ts` updates the preview (Vite HMR); **Save scene** writes a
`.scene.json` (File System Access API, download fallback) to transpile.

Regenerate the vocabulary after an export:

```bash
npm run gen        # reads public/bundle/schema.json -> src/generated/{components,builders}.ts
```

## What's here

| File | Role |
|---|---|
| `src/runtime/meshLoader.ts` | Decodes the MeshCacheIO binary into a `BufferGeometry` (exact engine geometry). |
| `src/runtime/materialMapper.ts` | Maps a PHPolygon `Material` to an approximate `MeshStandardMaterial`. |
| `src/runtime/bundle.ts` | Loads `manifest.json` / `materials.json` / `schema.json`; fetches mesh buffers lazily. |
| `src/runtime/sceneBuilder.ts` | Interprets a scene JSON into a Three.js object tree. |
| `src/components/SceneView.vue` | TresJS canvas: camera, lights, orbit controls, the scene object. |
| `scripts/generate.mjs` | Codegen: schema.json -> typed prop interfaces, `COMPONENT_META`, builder factories. |
| `src/authoring/dsl.ts` | `defineScene` / `entity` / `buildComponent` (merges engine defaults, normalises props). |
| `src/authoring/inputs.ts` | Input helper types + normalisers (tuple/object/hex -> canonical JSON). |
| `src/authoring/saveScene.ts` | Write-back via File System Access API (download fallback). |
| `src/playground/scene.ts` | The scratchpad scene. |

## Next steps

- Literal `<Entity>` / `<Transform3D>` JSX tag components (the vueJsx plugin is
  already wired) as an alternative to the function-call DSL.
- A two-way live editor (inspector panel) backed by a reactive store.
