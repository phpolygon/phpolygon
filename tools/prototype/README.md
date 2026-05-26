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
npm run dev
```

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

## What's here

| File | Role |
|---|---|
| `src/runtime/meshLoader.ts` | Decodes the MeshCacheIO binary into a `BufferGeometry` (exact engine geometry). |
| `src/runtime/materialMapper.ts` | Maps a PHPolygon `Material` to an approximate `MeshStandardMaterial`. |
| `src/runtime/bundle.ts` | Loads `manifest.json` / `materials.json` / `schema.json`; fetches mesh buffers lazily. |
| `src/runtime/sceneBuilder.ts` | Interprets a scene JSON into a Three.js object tree. |
| `src/components/SceneView.vue` | TresJS canvas: camera, lights, orbit controls, the scene object. |

## Status / next steps (Phase 3)

This scaffold renders exported scenes (Phase 2). Still to come:
- Generated `<Entity>` / `<Transform3D>` / `<MeshRenderer>` TSX components from
  `schema.json` (the typed authoring vocabulary).
- A Pinia store where rendering a component also builds the canonical scene
  JSON (render = build), so live TSX edits export 1:1.
- Write-back via the File System Access API instead of a download.
