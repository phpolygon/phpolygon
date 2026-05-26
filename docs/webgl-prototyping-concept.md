# WebGL/JSX Prototyping Path

Status: **in progress** (PHP export pipeline landed; Vue/TresJS playground scaffolding).

A browser-based prototyping accelerator for PHPolygon scenes. You compose a
scene declaratively (JSX/TSX), see it live in WebGL, then export it to
canonical PHP. JSX is a **scratchpad**, never a second source of truth - the
committed artefact is always the PHP `Scene` class.

```
   JSX / TresJS (Vue)                    Browser, live, HMR
          |  ^
   (build)|  | (preview)
          v  |
   Scene JSON  (_version:1)              the existing scene format
          |  ^
          |  |  SceneTranspiler + PhpCodeGenerator
          v  |
   PHP Scene class                       canonical, version-controlled
```

The JSX layer knows no PHP. Its only job is to read/write the existing scene
JSON and render an approximate WebGL preview. The PHP connection runs entirely
through the transpiler machinery that already existed in the engine.

## Why this fits the settled architecture

| Settled rule (CLAUDE.md) | How this respects it |
|---|---|
| PHP is always canonical | JSX -> JSON -> PHP. The commit is PHP; JSX is a throwaway sketch. |
| JSON is the intermediate format | Reuses the existing `_version:1` scene JSON verbatim. No new format. |
| No external 3D tools / model files | No Blender, no .fbx. Geometry stays procedural in PHP; WebGL only renders exported `MeshData` buffers, never imports models. JSX is code, not a binary asset. |
| Editor is a data editor, not a real-time viewport | The prototyping tool is deliberately separate from the shipped NativePHP editor. It is a dev accelerator. |
| No built-in HTTP server for editor communication | The round-trip is file-based (see below). The browser only touches JSON/binary files; PHP runs as a CLI before (export) and after (transpile). |

## Decisions

- **Transport: file-based.** `prototype:export` writes a static bundle; Vite
  serves it statically; "export to PHP" writes a `.scene.json` that
  `scene:transpile` turns into PHP. The browser never talks to PHP at runtime.
- **Framework: Vue + TresJS.** The existing editor is Vue 3 + TS + Pinia +
  Vite. TresJS is declarative Three.js for Vue (JSX/TSX-friendly), so the
  playground shares the editor's toolchain instead of introducing a second
  frontend framework. "JSX" is realised as Vue TSX.

## Fidelity strategy: geometry exact, shading approximate

For prototyping you care about layout, proportions, composition, camera
framing and rough material look - not pixel-identical PBR. The WebGL renderer
is the 3D analogue of `GdRenderer2D`: "a structural approximation ... not a
reference renderer".

| Aspect | Strategy | Reason |
|---|---|---|
| Geometry | **Exact.** PHP generators run in PHP; `MeshData` is exported as a binary buffer in the engine's own `MeshCacheIO` format and loaded into a Three.js `BufferGeometry`. No generator is reimplemented in JS. | Reimplementing generators in JS would be a divergence nightmare; this shows the exact engine geometry. |
| Material/shading | **Approximate.** `Material` props map to Three.js `MeshStandardMaterial` (`albedo`/`roughness`/`metallic`/`emission`). Procedural patterns, cloth, SSS and clearcoat flakes are approximated or stubbed. | The mesh shader already has three synced copies (GL/Vio/Metal); a fourth WebGL copy would violate the "keep the shader copies in sync" anti-pattern and cost on every shader change. |
| Lights / fog / camera | Mapped 1:1 (`SetDirectionalLight` -> `DirectionalLight`, `AddPointLight` -> `PointLight`, `SetFog` -> `scene.fog`). | RenderCommandList semantics map cleanly onto Three.js. |

Escalation (deferred): if fidelity becomes the bottleneck, transpile
`mesh3d.frag.glsl` to WebGL2/GLSL-ES-3.0 in a `ShaderMaterial`. This is the
fourth shader copy - intentionally postponed, not in the core.

## The data loop

```
(1) bin/phpolygon prototype:export        (PHP CLI; once, and on geometry change)
        .phpolygon/prototype/
          schema.json          <- #[Serializable] component vocabulary
          materials.json        <- MaterialRegistry dump
          meshes/<slug>.bin     <- MeshData (MeshCacheIO format)
          scenes/<name>.scene.json
          manifest.json         <- top-level index
                |
                v  (Vite serves statically, HMR)
(2) scene.tsx (TSX scratchpad)  <-> Pinia scene store  -->  TresJS / WebGL preview
                |
                v  "Export to PHP" (File System Access API writes foo.scene.json)
(3) bin/phpolygon scene:transpile foo.scene.json  -->  src/Scene/Foo.php  (canonical)
```

The drift guard: the JSX component vocabulary is **generated from PHP**
(`schema.json`), not hand-maintained. Add a `#[Property]` in PHP and it appears
in the playground after the next export.

## Bundle format (what `prototype:export` writes)

`manifest.json`:

```json
{
  "_version": 1,
  "schema": "schema.json",
  "materials": "materials.json",
  "meshFormat": "meshcache/v1",
  "meshes": {
    "box_1x1x1": { "file": "meshes/box_1x1x1-7fb0c858.bin",
                   "vertexCount": 24, "triangleCount": 12, "bytes": 940 }
  },
  "materialIds": ["brick_wall", "default"],
  "scenes": { "php_district": "scenes/php_district.scene.json" }
}
```

`schema.json` (per component): `class`, `category`, `properties[]`
(`name`, `type`, `phpType`, `editorHint`, `nullable`, `enum`, `range`),
and `defaults` (engine defaults captured by no-arg construct + serialise).

Mesh binary = `MeshCacheIO` format: a 28-byte little-endian header
(`PHMC` magic, uint16 formatVersion, uint16 reserved, uint32 versionHash,
uint32 vertex/normal/uv/index counts) followed by float32 vertices, normals,
uvs and uint32 indices. Tangents are omitted (the approximate path does not
need them). The browser decoder is a thin `DataView` slice; see
`tools/prototype/src/runtime/meshLoader.ts`.

## CLI reference

```bash
# Write the static bundle (default: .phpolygon/prototype, override with --out)
php bin/phpolygon prototype:export
php bin/phpolygon prototype:export --out web

# Transpile a scene JSON back to canonical PHP
php bin/phpolygon scene:transpile foo.scene.json            # print to stdout
php bin/phpolygon scene:transpile foo.scene.json --out src/Scene/Foo.php
```

`prototype:export` registers a default primitive palette
(`box_1x1x1`, `sphere_r1`, `cylinder_r1`, `plane_1x1`), then runs an optional
project bootstrap and gathers everything currently registered.

### Project bootstrap (`prototype.php`)

If `prototype.php` exists in the project root it is `require`d during export.
It may register meshes/materials as a side effect and return a list of `Scene`
instances to export:

```php
<?php
use PHPolygon\Geometry\{MeshRegistry, BoxMesh};
use PHPolygon\Rendering\{MaterialRegistry, Material, Color};

MeshRegistry::register('building_php_4f', BoxMesh::generate(6, 12, 5));
MaterialRegistry::register('brick_wall', Material::color(new Color(0.6, 0.3, 0.2)));

return [ new \App\Scene\PhpDistrict() ];
```

## JSX/TSX mapping

`<Entity>` -> entity node, component children -> `components[]`, nested
`<Entity>` -> `children`:

```tsx
<Scene name="php_district" systems={[Camera3DSystem, Renderer3DSystem]}>
  <Entity name="Ground">
    <Transform3D scale={[200, 1, 200]} />
    <MeshRenderer mesh="plane_1x1" material="cobblestone" />
  </Entity>
  <Entity name="Building_0">
    <Transform3D position={[10, 0, 5]} />
    <MeshRenderer mesh="building_php_4f" material="brick_wall" />
  </Entity>
</Scene>
```

The generated components render via TresJS *and* register into a Pinia scene
store (render = build, mirroring `SceneBuilder`). "Export" serialises the store
to the canonical scene JSON.

## Round-trip and its honest limit

- **JSX -> PHP:** clean for declarative scenes (explicit entities).
- **PHP -> JSX:** works for declarative scenes. For generative `build()`
  methods (`foreach`, `rand()`), `SceneBuilder::getDeclarations()` yields the
  materialised tree (great for preview), but exporting flattens the loop to
  explicit entities. Keep heavy procedural generation in hand-written PHP; use
  JSX for layout/composition.

## Phases and status

| Phase | Content | Status |
|---|---|---|
| 0 | `ComponentSchemaGenerator` + `SerializableScanner` (schema from `#[Serializable]`) | done |
| 1 | `PrototypeExporter` (mesh buffers + materials + scenes + manifest) | done |
| 4 | CLI `prototype:export` + `scene:transpile` | done |
| 2 | WebGL JSON renderer (mesh loader, material mapper, TresJS scene renderer) | done (scaffold) |
| 3 | Authoring layer + generated component vocabulary + write-back | done (scaffold) |
| 5 (optional) | dev bridge for live geometry regen; GLSL->WebGL2 fidelity upgrade | deferred |

Phase 3 is realised as a **typed declarative builder** (`defineScene` / `entity`
+ generated per-component factories), the practical Vue/TS form of the
"JSX-style" idea, rather than literal `<Entity>` JSX tags. The builder
vocabulary is generated from `schema.json` (`npm run gen`), so it cannot drift
from the engine's components; props are normalised and merged with engine
defaults, and the output transpiles cleanly back to canonical PHP. Literal JSX
tag components remain a future refinement (the vueJsx plugin is already wired).

Prerequisite bugfixes found and fixed along the way (both directly on this
path): `AttributeSerializer` did not handle `Quaternion`/`Vec4`, so
`Transform3D.rotation` serialized to null; `PhpCodeGenerator` emitted
`Quaternion`/`Color` as raw arrays, producing PHP that type-errors at runtime
(affecting every scene via `SceneConfig.clearColor`).
