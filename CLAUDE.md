# CLAUDE.md — PHPolygon Engine

PHP-native game engine with AI-first authoring. This file governs how Claude Code
works in this repository. Read it fully before writing any code.

---

## Engine identity

**PHPolygon** is a standalone PHP-native game engine. The primary authoring tool
is Claude Code. Worlds, characters, and game logic are written entirely in PHP —
no external 3D modelling tools (Blender, Maya, etc.) and no imported model files
(.fbx, .obj, .gltf) are part of the workflow. Geometry is generated procedurally
from PHP code.

Render backends:
- **2D:** php-vio (primary) or OpenGL via php-glfw / NanoVG (fallback)
- **3D:** php-vio, Vulkan via php-vulkan, Metal via MoltenVK, or OpenGL via
  php-glfw — all behind a unified `Renderer3DInterface` / `RenderCommandList`

The OpenGL (php-glfw) backend is not pinned to a single GL version. The window
probes a context ladder from 4.6 down to a **GL 3.0 floor** (see
`GlCapabilities` + `Window::createOpenGLWindow()`); GLSL is authored at
`150 core` and the `#version` directive is rewritten per detected context
(150 core / 140 / 130). Features above the obtained tier degrade automatically:
GPU instancing (core since GL 3.3) falls back to per-instance CPU draws, and the
off-screen post-process chain (render-scale, MSAA resolve, FXAA/TAA/SSR) is
disabled below GL 3.3.

The engine auto-detects php-vio at startup. When available, it provides the window,
input, audio, 2D renderer, 3D renderer, and texture manager through a single
unified backend. When php-vio is not loaded, the engine falls back to php-glfw
(2D/3D) or php-vulkan (3D).

Games are built in separate repositories and require `phpolygon/phpolygon`
via Composer.

### Rule: never reference a specific game (code OR comments)

The engine is game-agnostic. **Nothing in this repository may name or describe a
specific game** — not in code, comments, docblocks, shaders, tests, build
templates, identifiers, strings, or magic constants. This includes (but is not
limited to) "Code Rescue", "Code Tycoon", "CodeCity", "Tutorial Island", and any
district/scene/feature names from a downstream game.

- Describe behaviour generically ("the consuming game", "the SDF volume", "a
  reflection probe") — never by the game that happens to use it.
- Motivate a perf note or fix by the *mechanism*, not the game it surfaced in
  (write "HUD-heavy panels with dynamic strings", not "<game>'s money panel").
- **No game-specific values baked into engine code or shaders.** Coordinates,
  world offsets, water heights, layout constants etc. that belong to one game's
  scene must come from the game via uniforms/parameters/config — never hardcoded
  here. A hardcoded game constant is a coupling bug, not just a naming one.

If you find an existing reference, remove/genericize it. If genericizing would
change behaviour (e.g. a hardcoded scene constant), surface it as a coupling to
parameterize rather than silently leaving it.

---

## Architecture decisions (settled — do not revisit without explicit instruction)

### ECS: Hybrid model
- **Entities** are PHP objects with a component array. They have identity and lifecycle.
- **Components** own *per-entity* behaviour: `onAttach()`, `onUpdate()`, `onDetach()`,
  `onInspectorGUI()`. They may hold data and per-entity logic.
- **Systems** own *cross-entity* logic: physics, collision, economy, pathfinding.
  A System iterates components across multiple entities.
- **Discipline rule:** never put cross-entity logic in a Component. Never put
  per-entity render or state logic in a System. When in doubt, ask which boundary
  the code crosses.

### Scene authoring: PHP-canonical / split
- **PHP is always the canonical source of truth** for scene structure (entities,
  components, configuration).
- **JSON is the intermediate format** for the Vue/NativePHP editor. It is generated
  from PHP and consumed by the editor. The editor writes JSON back; a bidirectional
  transpiler converts to/from PHP.
- **Runtime state** (save games, dynamic positions, live game state) is always JSON.
- Use PHP 8.x `#[Attribute]` annotations to drive serialisation via Reflection.
  New components must never implement manual `toJson()` / `fromJson()` methods —
  the serialiser handles this automatically.
- Scene PHP files are version-controlled as code. JSON files are derived artefacts.

### Render interface: Layered
- `RenderContextInterface` — base: `beginFrame()`, `endFrame()`, `clear()`,
  `setViewport()`
- `Renderer2DInterface extends RenderContextInterface` — 2D backend (VioRenderer2D or NanoVG Renderer2D)
- `Renderer3DInterface extends RenderContextInterface` — 3D backend (Vio, Vulkan, Metal, or OpenGL)

`Renderer3DInterface` is driven by a **RenderCommandList** from day one. PHP builds
the command list; the backend executes it. This keeps game code fully backend-agnostic:

```
Game Code / Scene
      ↓  (builds)
RenderCommandList        ← pure PHP data, no GPU calls
      ↓  (executed by)
┌───────────────┬──────────────────┬──────────────────┬──────────────────┬──────────────────┐
VioRenderer3D   OpenGLRenderer3D   VulkanRenderer3D   NullRenderer3D
(primary)       (fallback)         (native Vulkan)    (headless/tests)
```

**Do not design `Renderer3DInterface` around the OpenGL state-machine model.**
The OpenGL 3D backend *emulates* command buffers — it iterates the command list
and issues the necessary GL calls internally. Vulkan natively maps to this model.

### RenderCommandList — available commands

All commands are plain PHP value objects (no methods, only constructor properties):

| Command class | Purpose |
|---|---|
| `SetCamera` | `viewMatrix: Mat4`, `projectionMatrix: Mat4`, `cut: bool` (first frame / teleport / camera switch: temporal passes drop their history; camera systems set it via `CameraCutDetector`) |
| `SetAmbientLight` | `color: Color`, `intensity: float` |
| `SetDirectionalLight` | `direction: Vec3`, `color: Color`, `intensity: float` |
| `AddPointLight` | `position: Vec3`, `color: Color`, `intensity: float`, `radius: float` |
| `DrawMesh` | `meshId: string`, `materialId: string`, `modelMatrix: Mat4`, `prevModelMatrix: ?Mat4` (last frame's matrix when the entity moved, else null; set by `Renderer3DSystem`) |
| `DrawMeshInstanced` | `meshId: string`, `materialId: string`, `matrices: Mat4[]` |
| `SetSkybox` | `cubemapId: string` |
| `SetFog` | `color: Color`, `near: float`, `far: float` |
| `SetShader` | `shaderId: ?string` — override active shader for subsequent draws; `null` resets to material-driven |
| `DrawOutline` | `meshId: string`, `modelMatrix: Mat4`, `color: Color`, `widthPx: float` — screen-space outline, drawn after the scene (see "Outline pass") |

Commands are appended to `RenderCommandList` during the scene tick. The
`Renderer3DSystem` flushes the list once per frame.

**Shader animation time (`u_time`)** follows the simulated time, not the frame
count: the engine sums every update step and passes it to renderers that
implement `AnimationClockInterface` (Vio, OpenGL, Null) before `beginFrame()`.
Never advance animation time by a constant per rendered frame - water and
vertex animation would run 2.4x too fast at 144 fps. Screenshot tests pin it with
`Engine::setAnimationTime()`; an undriven renderer falls back to 1/60 s per frame.

### Shader management

Games control shaders via the `Shader` facade or `$engine->shaders`:

```php
use PHPolygon\Support\Facades\Shader;

Shader::available();           // ['default', 'unlit', 'normals', 'depth', 'shadow', 'skybox', 'fxaa']
Shader::use('unlit');          // global override — all draws use 'unlit'
Shader::active();              // 'unlit'
Shader::isOverridden();        // true
Shader::reset();               // back to material-driven selection

// Register a custom shader
Shader::register('toon', new ShaderDefinition(
    'resources/shaders/source/toon.vert.glsl',
    'resources/shaders/source/toon.frag.glsl',
));

// Per-material shader assignment
MaterialRegistry::register('debug_flat', new Material(
    albedo: new Color(1.0, 0.0, 1.0),
    shader: 'unlit',
));
```

**Architecture:**
- **`ShaderManager`** (`$engine->shaders`) — game-facing service, emits `SetShader`
  commands into the `RenderCommandList`
- **`Shader` facade** — static proxy to `ShaderManager`
- **`ShaderDefinition`** — value object: `vertexPath`, `fragmentPath` (GLSL sources)
- **`ShaderRegistry`** — static registry mapping shader IDs to definitions
- **`Material::$shader`** — string ID (defaults to `'default'`), per-material shader
- **`SetShader` command** — render command for frame-level override

Priority: `SetShader` override > `Material::$shader` > `'default'`

Built-in shaders (registered automatically by the renderer):

| Shader ID | Purpose | Source files |
|---|---|---|
| `default` | Full PBR: lighting, shadows, fog, 10 procedural modes | `mesh3d.vert/frag.glsl` |
| `unlit` | Albedo + emission + fog only, no lighting (perf baseline) | `unlit.vert/frag.glsl` |
| `normals` | Debug: visualize surface normals as RGB | `normals.vert/frag.glsl` |
| `depth` | Debug: visualize depth buffer (white=near, black=far) | `depth.vert/frag.glsl` |
| `shadow` | Depth-only pass for shadow maps (used internally by renderer) | `shadow.vert/frag.glsl` |
| `skybox` | Cubemap skybox (used internally by SetSkybox command) | `skybox.vert/frag.glsl` |
| `fxaa` | Fullscreen post-process AA (used internally when AntiAliasing::FXAA is selected; fullscreen-triangle vertex stage, no VBO) | `fxaa.vert/frag.glsl` |

All built-in shaders can be overridden by registering a shader with the same ID
before the renderer is constructed. Custom shaders are compiled lazily on first
use and cached. Unknown shader IDs fall back to `'default'`.

Custom shaders must use the same vertex attribute layout as built-in shaders
(location 0–2: position/normal/uv, 3–6: instance matrix). Uniforms that the
shader does not declare are silently ignored — a minimal shader only needs
`u_model`, `u_view`, `u_projection`, and `u_use_instancing`.

### GPU backends
| Backend | Status | Target |
|---|---|---|
| php-vio (2D + 3D unified) | **Primary** | All platforms when php-vio is loaded. vio internally dispatches to Metal (macOS), D3D11/D3D12 (Windows), Vulkan, or OpenGL via `vio_create('auto', ...)` |
| OpenGL via php-glfw (2D/NanoVG) | Fallback | 2D games when php-vio unavailable (GL 3.0–4.6 ladder) |
| OpenGL via php-glfw (3D) | Fallback | 3D games when php-vio unavailable (GL 3.0–4.6 ladder, feature-tiered) |
| Vulkan via php-vulkan | Standalone | 3D native Vulkan backend, used when vio is not loaded |

vio is the production path on every platform — on macOS vio's own Metal backend
(php-metal-gpu / `MetalRenderer3D` were retired once it reached parity). The standalone
Vulkan / OpenGL backends exist for environments where vio is unavailable (older PHP
builds, CI without GPU, headless tooling) or when a game explicitly opts into a native
backend via `EngineConfig::$useNative3D`. When extending the renderer (new
features, post-effects, render-target work), prioritise VioRenderer3D first
because it reaches all GPUs including D3D11 / D3D12.

D3D11 and D3D12 are reached exclusively through vio - there is no standalone
D3D backend class. vio's runtime backend can be inspected via `vio_backend_name($ctx)`,
which returns `'metal'`, `'d3d11'`, `'d3d12'`, `'vulkan'`, or `'opengl'`. Backend-specific
quirks (like the Y-flipped render-target convention on D3D) live in VioRenderer3D
behind a `vio_backend_name()` switch.

The engine selects the backend automatically at startup:
1. If `extension_loaded('vio')` → Vio backends for window, input, audio, 2D, 3D, textures
2. Otherwise → GLFW window/input, NanoVG 2D, OpenGL/Vulkan/Metal 3D (per `renderBackend3D` config)

### VioRenderer3D scene passes — forward vs. MRT

`VioRenderer3D::render()` has two ways to produce the scene (`docs/rfcs/mrt-gbuffer.md`):

- **Forward** (every backend): shadow cascades → G-buffer geometry pass (`gbuffer.vert/frag`,
  only when SSAO / SDF-AO / SSR need it) → SSAO + SDF-AO → sky → opaque → transparent →
  SSR → post. The mesh shader samples the AO maps inside its lighting.
- **MRT** (php-vio ≥ 2.11 with `VIO_FEATURE_MRT`, taken whenever the frame needs the
  G-buffer, the built-in shader is active and the scene target is single-sampled):
  shadow → sky + opaque + transparent into a 4×RGBA16F target (`o_sun`, `o_local`,
  `o_ambient`, `o_gbuffer` — `mesh3d.frag` compiled with `PHPOLYGON_MRT`) → SSAO + SDF-AO
  read attachment 3 → `composite.frag` writes `sun·sdfShadow + local + ambient·ssao·sdfAo`
  into the real scene target → SSR → post. One geometry submission less per frame; the
  image is the forward image up to FP16 rounding (`VioRendererMrtTest`,
  `MrtMeshShaderHeadlessTest` pin both).

Rules that follow from it:
- Anything added to the mesh shader's lighting must be routed into one of the three
  accumulators (`sunAcc` = primary directional light, `ambientAcc` = AO-modulated terms,
  `localAcc` = everything else) — never into a separate output.
- Game snippets keep writing `frag_color` (it is `o_local` under MRT); the post-colour hook
  (`applyPostColor`) must stay affine in the colour — it is evaluated at 0 and 1 and
  distributed over the split outputs.
- New pipelines that draw into the scene target need an MRT variant (see `bindPipeline`,
  `bindSkyPipeline`: `attachments` + `attachment_blend` / `attachment_color_mask`).
- Escape hatches: `PHPOLYGON_VIO_MRT=0` forces the forward path, `=1` skips the php-vio
  version gate. `mrtActive()` tells tests which path a frame took.

**Outline pass** (`DrawOutline`, php-vio >= 2.12 with `VIO_FEATURE_STENCIL`): runs on both paths after SSR
and before post-processing, into the bound scene target. Pass 1 draws every outlined mesh into the
stencil buffer (alpha-0 blend, colour untouched), pass 2 redraws them pushed outward by a constant
pixel width (`outline.vert` extrudes from the projected centre of the mesh AABB, not along split
normals) where the stencil is still 0. All parts are masked before any ring, so touching parts of one
object share a single outline. Depth test is off: the outline shows through geometry and looks the
same forward and MRT. Backends without stencil (Metal) skip it; `outlineSupported()` reports it.
`VioRendererOutlineTest` pins ring position, seam-free parts and the MRT path.

**Temporal AA / TAAU** (`AntiAliasing::Taa`, `Upscaler::Taau`; needs MRT plus
`VIO_FEATURE_RENDER_TARGET_DEPTH_SAMPLE`, gated per feature, not per php-vio version):
- The frame takes the MRT path even without G-buffer consumers. `Quality\TemporalCamera` jitters the
  projection (`TemporalJitter`, phases scale with the upscale ratio) and keeps last frame's UNJITTERED
  view-projection; shadow and environment passes stay unjittered. History resets on `SetCamera::$cut`,
  render/display resize, `applySettings()` and `resetTemporalHistory()`; `temporalFrame()` reports it.
- `mesh3d` with `PHPOLYGON_MOTION` (only together with `PHPOLYGON_MRT`) adds `o_motion` (RG16F,
  attachment 4, `prevUv - uv` in texture space - the Y sign follows `flipRenderTargetClipY()`) and
  `o_reactive` (R8, attachment 5, transparent coverage, additive). Opaque pipelines mask 5, transparent
  ones mask 4, sky/skybox both; their cache keys carry `:mv`. Moved entities upload `u_prev_model`
  behind a sticky `u_has_prev` (from `DrawMesh::$prevModelMatrix`); vertex animation replays at
  `u_time_prev`. Without a temporal technique nothing of this exists (4 attachments, same shaders).
- `PostProcess\VioTaaPass` runs in the present stage after composite/SSR/outlines and before bloom:
  `taa_dilate` (render size: nearest-depth motion, sky motion reconstructed from depth, farthest depth)
  and `taa_resolve` (display size: narrow jitter-aware reconstruction, Catmull-Rom history, YCoCg
  variance clip - wider where nothing moves -, disocclusion, weighted accumulation in Karis space).
  History ping-pongs in RGBA16F; RCAS sharpens the finished frame (`upscaleSharpness`); FXAA never
  runs on top. Without the feature TAA falls back to FXAA and TAAU along `Upscaler::fallbackChain()`.
- Debug: `PHPOLYGON_VIO_DEBUG_VIEW=motion|reactive|depth|history` (`VioMotionDebugPass`, also listed in
  the DevMonitor); `PHPOLYGON_VIO_TEMPORAL=0` switches the path off. Tests: `VioRendererMotionVectorTest`
  (vector error against the analytic reprojection), `VioTaaResolveTest` (SSIM against 4x SSAA, trail,
  cut), `VioTemporalDebugViewTest`.
- **Native upscalers** (`Upscaler::Fsr3`, `Upscaler::Dlss`; php-vio `vio_upscaler_*`, D3D12 / Vulkan):
  offered only with `VIO_FEATURE_UPSCALER_NATIVE` and `vio_upscaler_supported()` - otherwise the note is
  php-vio's `reason`. `PostProcess\VioNativeUpscalerPass` holds the upscaler and its display-size storage
  output; it is synced in `beginFrame()` (recreated on provider / preset / size / HDR change, destroyed
  when left), never mid-frame. A preset renders at `vio_upscaler_render_size()` (DLSS optimal settings)
  with the provider's jitter cycle (`TemporalCamera::begin(..., $phaseCount)`). `resolveTemporal()`
  dispatches it before `VioTaaPass`; conventions live in `Quality\UpscalerDispatch` (jitter y negated,
  `mv_scale` = render size with the y sign of the target orientation, unjittered motion, near/far/fov from
  the unjittered projection). FSR's RCAS replaces the engine's. A failed create/dispatch is logged once,
  the provider leaves the capabilities and the chain moves on; the stored setting stays
  (`PHPOLYGON_VIO_UPSCALER_FAIL=create|dispatch` forces it). CPU section `render3d.post.upscale`.
  Game side: `EngineConfig::$dlssProjectId` (GUID, -> `vio.dlss_project_id`, overrides the build's baked
  one), `$upscalerRuntimePath` (-> `vio.ffx_path` / `vio.dlss_path` / `vio.dlss_plugin_path`; '' for a built
  game - php-vio looks next to the exe first), runtime DLLs shipped by the build (`build.json` `upscalers`,
  see "Native upscaler runtimes" under Build system). Tests:
  `VioNativeUpscalerTest` (needs `'headless_hardware' => true`: WARP refuses), `UpscalerDispatchTest`.

**Storage-buffer instances** (`DrawMeshInstanced::fromStorageBuffer`, e.g. GPU particles): the matrices never
leave GPU memory, but the regular programs read instance matrices from vertex attributes. The draw
therefore switches to `default_storage` (`storageInstancingVertexSource()`: mesh3d.vert with
`instanceModels[gl_InstanceIndex]` from the SSBO at binding 0, same mesh3d.frag, MRT twin) for the current
pass and back. Compiled lazily or via `warmStorageInstancing()`. They cast no shadow and stay out of the
forward G-buffer. A count-only test cannot catch a wrong instance source — `VioRendererIndirectDrawTest`
checks the position.

**3D textures:** `resolveTexture()` binds the `VioTextureManager` upload (`vioTexture()`: mip chain,
anisotropy, KTX2 container) — never decode the file a second time for 3D.

**KTX2 files:** a `.ktx2` beside an image path wins (`VioTextureManager::ktx2Sibling()`).
`PHPolygon\Rendering\Ktx2Container` writes them for pre-bakes (`bc7()`, `build()`, `ddsPayload()`)
and, with `zlibLevel` 1-9, compresses each mip level with zlib (KTX2 supercompression scheme 3).
php-vio only takes plain containers, so `loadKtx2()` runs `Ktx2Container::inflate()` first
(plain = untouched; Basis/Zstd = refused, the image path loads instead). Zstd is not offered: the
shipped runtimes have zlib (the gzip PHAR needs it), not zstd. Measured on 329 BC7 hologram
textures: 144.5 MB -> 18.0 MB, inflating all of them +0.3 s (~1 ms per texture).

### Shaders
- Authoring language: **GLSL** (human- and AI-readable plaintext)
- 2D: used directly by NanoVG / OpenGL at runtime
- 3D OpenGL: GLSL loaded and compiled at runtime via `glCreateShader`
- 3D Vulkan: GLSL compiled to **SPIR-V** at build time via `glslangValidator` or
  `shaderc`; SPIR-V binaries committed to `resources/shaders/compiled/`
- Claude Code writes GLSL. Never write SPIR-V by hand.
- Shader naming: `name.vert.glsl` / `name.frag.glsl` → `name.vert.spv` / `name.frag.spv`

### 3D Math
The following value objects exist or must be added before any 3D rendering work:

| Class | Status | Purpose |
|---|---|---|
| `Vec2` | Done | 2D vector |
| `Vec3` | Done | 3D vector, cross/dot product |
| `Vec4` | Needed | Homogeneous coordinates, RGBA |
| `Mat3` | Done | 2D transforms |
| `Mat4` | **Needed** | 3D transforms, MVP matrix |
| `Quaternion` | **Needed** | 3D rotation without Gimbal Lock |
| `Rect` | Done | 2D axis-aligned rectangle |

`Mat4` and `Quaternion` are pure PHP value objects — no GPU dependency. They must
be implemented and fully tested before any 3D backend work begins.

### 3D Components
Components follow the same ECS discipline as 2D. 3D-specific components:

| Component | Purpose |
|---|---|
| `Transform3D` | `position: Vec3`, `rotation: Quaternion`, `scale: Vec3`, world/local matrix |
| `Camera3DComponent` | `fov: float`, `near: float`, `far: float`, projection type |
| `MeshRenderer` | `meshId: string`, `materialId: string`, `castShadows: bool` |
| `DirectionalLight` | `direction: Vec3`, `color: Color`, `intensity: float` |
| `PointLight` | `color: Color`, `intensity: float`, `radius: float` |
| `CharacterController3D` | Capsule collision, gravity, slope detection, step height |
| `Outline` | `enabled`, `color`, `widthPx`, `includeChildren` — outline this entity's mesh (and its children's) |

`Transform3D` replaces `Transform2D` in 3D scenes. Never mix 2D and 3D transform
components on the same entity.

### 3D Systems

| System | Purpose |
|---|---|
| `Renderer3DSystem` | Collects `MeshRenderer` + `Transform3D`, builds `RenderCommandList`, flushes |
| `Camera3DSystem` | Updates view/projection matrices, pushes `SetCamera` command |
| `Physics3DSystem` | Capsule vs AABB collision, gravity integration (Phase 7+) |
| `OutlineSystem` | Emits `DrawOutline` for every enabled `Outline`; register BEFORE `Renderer3DSystem` |

### Physics queries — rays and line of sight

`Raycaster3D` answers two kinds of question:

- `raycast()` / `raycastAll()` — nearest / all hits with point, normal and
  distance. Box colliders only; recomputes each box's world AABB from its
  `Transform3D` on every call, so it works without a `Physics3DSystem`.
- `segmentBlocked(World, Vec3 $from, Vec3 $to): bool` — occlusion only ("is
  anything solid between these points?"), meant for per-frame use (line of
  sight, camera/anchor checks). Tests mesh colliders and boxes (rotated ones
  exactly, via their triangles) against the data `Physics3DSystem` already
  caches on them (`MeshCollider3D::$bvh`, `BoxCollider3D::$cachedWorldAabb` /
  `$bvh` / `$cachedRotated`) — it never transforms or rebuilds anything.
  Triggers never block, a box containing `$from` does not block, touching a
  surface counts, heightmaps are ignored, and a collider the physics system has
  not processed yet is invisible to it.

The segment primitives behind it are public: `CollisionMath::segmentIntersectsTriangle()`
(double-sided Möller–Trumbore on [0, 1]) and `CollisionMath::segmentIntersectsAabb()`
(slab test). Do not reimplement either in a game.

### Procedural geometry — code-driven worlds

**PHPolygon does not use external 3D model files.** All geometry is generated
programmatically in PHP. This is a core design principle, not a limitation.

The `ProceduralMesh` system generates vertex/index buffers from PHP:

```php
// Primitives — generate and register once, draw many times
MeshRegistry::register('box_1x1x1',    BoxMesh::generate(1.0, 1.0, 1.0));
MeshRegistry::register('cylinder_r1',  CylinderMesh::generate(radius: 1.0, height: 2.0, segments: 16));
MeshRegistry::register('sphere_r1',    SphereMesh::generate(radius: 1.0, stacks: 12, slices: 16));
MeshRegistry::register('plane_10x10',  PlaneMesh::generate(10.0, 10.0));

// Composite geometry — buildings, terrain, districts from code
MeshRegistry::register('building_php', BuildingMesh::generate(
    floors: 4, width: 6.0, depth: 5.0, style: BuildingStyle::Industrial
));
```

Procedural mesh generators live in `src/Geometry/`. They return a `MeshData`
value object (vertices, normals, UVs, indices) that the backend uploads to the GPU.
Meshes are uploaded once and referenced by string ID. Instance-drawing
(`DrawMeshInstanced`) is used whenever the same mesh appears multiple times in a scene.

Benefits of this approach over file-based assets:
- Entire world is version-controlled as PHP code
- Parameters change in one place — world updates everywhere
- No external tool dependency (no Blender, no FBX pipeline)
- Claude Code can generate and iterate geometry directly
- `DrawMeshInstanced` makes large worlds cheap to render

### Mesh assets (`*.mesh.json` / `*.mesh.bin`)

Meshes authored in the editor or imported live as `<id>.mesh.json` (the versioned,
editable source; layouts: `raw` arrays, top-level arrays from `MeshSerializer`, or a
`nodes` graph). `PHPolygon\Geometry\MeshAssetLoader` loads them:
`registerAll($dir, $onError)` registers every asset lazily by id, `loadFile()` reads one.

- A build with `build.json` `"assets": {"meshes": "binary"}` compiles every staged
  `*.mesh.json` into `<id>.mesh.bin` and drops the JSON from the build (project tree
  untouched). `"meshZlib": 0-9` (default 6) sets the zlib level.
- `.mesh.bin` = `MeshCacheIO` format 2: 32-byte header (`PHMC`, format, flags, crc32 of
  the source JSON, counts), float32 vertices/normals/uvs/tangents, uint16 indices when
  all fit (`FLAG_INDEX16`) else uint32, payload optionally zlib (`FLAG_ZLIB`). Format 1
  (`encode()`, uint32, no flags) stays for the mesh cache and the prototype exporter.
- `loadFile('<id>.mesh.json')` prefers the `.mesh.bin` beside it while it is current:
  the JSON is gone, or its crc32 matches. A stale binary is ignored in development.
  Inside a PHAR the binary wins unchecked (a JSON there is an older build's extracted
  leftover); `MeshAssetLoader::$verifySources` overrides either way.
- Measured on 792 assets / 1.49 M vertices: 222 MB JSON -> 52.5 MB raw / 15.7 MB zlib;
  loading all of them 5.2 s (JSON) -> 0.31 s (raw) / 0.48 s (zlib). 791 of 792 use
  uint16 indices.

### Editor
- The editor is a **NativePHP desktop application** (Electron wrapper + Vue SPA).
- It has **direct filesystem access** to project directories — no HTTP server, no IPC.
- Multiple game projects are opened as workspaces (Unity-style), each in its own
  directory.
- The Game Loop runs **on demand** inside the editor's play mode. It is not
  continuously running.
- The editor is a **data editor**, not a real-time viewport. The game renders in a
  separate native OpenGL/Vulkan window when play mode is active.
- The transpiler is called directly by the editor process — no network boundary.

---

## Naming conventions

| Concept | Convention | Example |
|---|---|---|
| Engine namespace | `PHPolygon\` | `PHPolygon\ECS\Entity` |
| Component classes | Noun, no suffix | `MeshRenderer`, `BoxCollider2D`, `Transform3D` |
| System classes | Noun + `System` | `Renderer3DSystem`, `Physics3DSystem` |
| Events | Past tense noun | `EntitySpawned`, `SceneLoaded` |
| Interfaces | `*Interface` | `RenderContextInterface`, `Renderer3DInterface` |
| JSON scene files | `snake_case.scene.json` | `main_menu.scene.json` |
| PHP scene files | `PascalCase.php` | `MainMenu.php` |
| Shader source | `name.vert.glsl` / `name.frag.glsl` | `terrain.vert.glsl` |
| Compiled shaders | `name.vert.spv` / `name.frag.spv` | `terrain.vert.spv` |
| Geometry generators | `*Mesh` | `BoxMesh`, `BuildingMesh`, `TerrainMesh` |
| Mesh IDs | `snake_case` string | `'box_1x1x1'`, `'building_php_4f'` |
| Material IDs | `snake_case` string | `'stone_wall'`, `'neon_glass'` |

---

## Anti-patterns — never do these

- **Do not** put cross-entity logic in a Component method.
- **Do not** implement `toJson()` / `fromJson()` manually on Components — use Attributes.
- **Do not** design `Renderer3DInterface` around the OpenGL state-machine model —
  game code builds a `RenderCommandList`; backends execute it.
- **Do not** import 3D model files (.fbx, .obj, .gltf, .blend) — generate geometry
  in PHP via the `ProceduralMesh` system.
- **Do not** add D3D, Metal, or Vulkan stubs inside `OpenGLRenderer3D`.
- **Do not** start a built-in HTTP server for editor communication — the editor has
  direct filesystem access.
- **Do not** modify `game.phar` or compiled SPIR-V by hand.
- **Do not** store runtime game state in PHP files — use JSON.
- **Do not** use FFI for frame-critical calls (e.g. `SteamAPI_RunCallbacks()`) —
  use native C-extensions.
- **Do not** mix `Transform2D` and `Transform3D` on the same entity.
- **Do not** call GPU APIs (glDraw*, vkCmd*) from Systems or Components — only
  backends touch the GPU.

---

## C-extensions (available, do not reimplement in PHP)

| Extension | Purpose | Status |
|---|---|---|
| php-vio | Unified backend: window, input, audio, 2D/3D rendering, textures | **Primary** |
| php-glfw | OpenGL (3.0–4.6 ladder) + NanoVG (2D and 3D rendering) | Fallback when php-vio unavailable |
| php-vulkan | Vulkan (3D native backend) | Active |
| php-steamworks | Steamworks SDK integration | Published on Packagist |

When writing engine code that touches GPU, Steam, or audio — use the extension.
Do not wrap extension calls in FFI unless there is an explicit reason.

### Vio backend classes

When php-vio is loaded, the engine uses these implementations:

| Standard class | Vio replacement | Notes |
|---|---|---|
| `Window` (GLFW) | `VioWindow` | `vio_create('auto', ...)` — auto-selects best backend per platform |
| `Input` (GLFW callbacks) | `VioInput` | Unified input from Vio context |
| `Renderer2D` (NanoVG) | `VioRenderer2D` | `vio_rect()`, `vio_text()`, `vio_sprite()` etc. |
| `Renderer3D` (OpenGL) | `VioRenderer3D` | 3D rendering through Vio context |
| `TextureManager` (GL) | `VioTextureManager` | `vio_texture()`, registers with VioRenderer2D |
| `GLFWAudioBackend` | `VioAudioBackend` | Audio through Vio context |

---

## Distribution model

```
mygame              ← native launcher binary (C/Go/Rust)
runtime/php         ← static PHP binary (static-php-cli, includes all extensions)
game.phar           ← engine + game logic, Opcache bytecode, not human-readable
assets/             ← open: sounds, JSON scenes, UI layouts
resources/          ← shaders (GLSL source + compiled SPIR-V), fonts
saves/              ← user data: JSON save files
mods/               ← open: PHP + assets, scanned by ModLoader
```

No `assets/models/` directory exists. Geometry lives in PHP code, not in files.

- Game core ships as PHAR with Opcache bytecode (comparable protection to C# IL).
- `mods/` is intentionally open — modders and Claude Code use the same tools.
- Build target: macOS `.app`/DMG, Linux AppImage, Windows installer, Steam depot.

---

## AI authoring workflow

Claude Code is the primary authoring tool. When generating content:

1. **Scenes** — write PHP files (canonical). JSON is derived by the transpiler.
2. **Components** — PHP classes with `#[Component]` attribute, lifecycle hooks.
3. **Geometry** — PHP classes in `src/Geometry/` or the game repo's `src/World/`.
   Use `BoxMesh`, `CylinderMesh`, `SphereMesh` as primitives. Build composite
   geometry (buildings, terrain, props) as named generators.
4. **Materials** — PHP value objects (`MaterialDefinition`) registered by string ID.
   Properties: albedo color, roughness, metallic, emission. No texture files required
   for procedural worlds; add texture support only when explicitly needed.
5. **Game logic** — PHP Systems and Components.
6. **UI layouts** — JSON (transpiled to PHP at dev time, zero runtime parser overhead).
7. **Shaders** — GLSL source files in `resources/shaders/source/`.
8. **Physics materials** — JSON definitions in `assets/physics/`.
9. **Mods** — `mod.json` + PHP class implementing `ModInterface` + assets.

Every generated file is a Git commit. Every step is reviewable. No black-box state.

**Code-driven world example:**

```php
// A PHP district scene — no model files, no Blender
class PhpDistrictScene extends Scene
{
    public function build(SceneBuilder $b): void
    {
        // Ground plane
        $b->entity('Ground')
            ->with(new Transform3D(scale: new Vec3(200, 1, 200)))
            ->with(new MeshRenderer('plane_1x1', 'cobblestone'));

        // 20 procedural buildings via instancing
        foreach ($this->buildingLayout() as $i => $pos) {
            $b->entity("Building_{$i}")
                ->with(new Transform3D(position: $pos))
                ->with(new MeshRenderer(
                    BuildingMesh::id(floors: rand(2, 5), style: BuildingStyle::Industrial),
                    'brick_wall'
                ));
        }

        // Player start
        $b->entity('Player')
            ->with(new Transform3D(position: new Vec3(0, 2, 0)))
            ->with(new CharacterController3D(height: 1.8, radius: 0.4))
            ->with(new ThirdPersonCamera(distance: 5.0, pitch: -20.0));
    }
}
```

---

## Graphics Quality Settings

Player-facing quality system: immutable `GraphicsSettings` value object,
persistent `saves/graphics.json`, first-launch calibration, optional adaptive
controller, and a drop-in `GraphicsOptionsPanel`. Full reference + integration
guide in **`docs/graphics-settings.md`**.

```php
$engine->graphics->setMode(QualityMode::Adaptive);
$engine->graphics->update(fn(GraphicsSettings $s) => $s->with(
    shadowQuality: ShadowQuality::Low,
    bloom: false,
));
```

All four 3D backends (`OpenGL`, `Vio`, `Metal`, `Vulkan`) implement
`applySettings()` with off-screen render-scale + MSAA + FXAA. Fast path:
`renderScale == 1.0 && AA == Off` bypasses the off-screen pipeline so
default-settings games render byte-identically. Standalone Vulkan FXAA falls
back to a plain blit on older ext-vulkan builds without `Vk\Sampler`.

### Visual quality additions

The mesh shader runs a number of analytic visual-quality features beyond
the original render-scale / shadow / AA tier knobs. All are shader-side
so they cost only uniforms + a few ALU ops per fragment, no texture
uploads, no extra passes. All are documented in
**`docs/graphics-settings.md`**; the engine-side bullet points:

| Feature | Surface area |
|---|---|
| `NormalPattern` | `Material::$normalPattern`, 9 procedural patterns (bricks, bumps, orange peel, hammered, hexagons, wood grain, scratches, cracked, fbm noise). Tangent space derived per-fragment via dFdx/dFdy. |
| `SurfacePattern` | `Material::$surfacePattern`, 4 wear patterns that modulate albedo/roughness/metallic (worn paint, rust, brushed metal, polished rings). |
| Surface relief | `Material::$cavity` (darkens pattern recesses) + `$parallaxDepth` (parallax occlusion, world units) over a height field per `NormalPattern` (`nh_*` in both mesh3d.frag copies - add a height function with every new pattern). Gated per player by `GraphicsSettings::$surfaceRelief` (Off/Cavity/Parallax). |
| `Upscaler` | `GraphicsSettings::$upscaler`: `Fsr1` = AMD FSR 1 (EASU + RCAS, `fsr_easu/fsr_rcas.frag.glsl`, MIT notice kept) in the vio present when renderScale < 1; `$upscaleSharpness`. Temporal: `Taau` (engine resolve), `Fsr3` / `Dlss` (php-vio native module) with `$upscaleQuality` presets - see "Temporal AA / TAAU" and "Native upscalers" above. |
| `GraphicsCapabilities` | `$engine->graphics->capabilities()` from the renderer's `graphicsCapabilities()`: VRS, HDR10, low latency, MSAA, TAA, SSR, SDF fieldtracing, relief, upscalers. Settings UIs disable what is unsupported (GraphicsOptionsPanel does). |
| `Material::$wetness` | Forward-renderer SSR surrogate. Up-facing fragments get smoother + darker + brighter-IBL pass. |
| `Material::$clearcoat` + `$flakes` | Carpaint extras consumed by `proc_mode == 10`. `Material::carpaint()` factory wires them in. |
| `ScreenSpaceAO` | Curvature-based AO via `dFdx(N)`. Tiers `Off/Low/Medium/High`. Shader uniform `u_ao_strength`. |
| `ColorGradingPreset` | Lift/Gamma/Gain + saturation. Six presets. Applied in linear space before ACES. |
| `GraphicsSettings::$vignetteIntensity` | Radial darkening evaluated against `gl_FragCoord / u_viewport_size`. |
| `GraphicsSettings::$volumetricFog` | 8-step in-shader ray-march with sun-aligned phase function. Independent from `SetFog`. |
| ACES tone mapping | Every mesh-shader exit path runs `toneMapACES()` before gamma. Vio's HDR tonemap pass uses the same curve. |
| Camera-following shadow + texel-snap | `ShadowMapRenderer::updateLightMatrix($dir, $cameraTarget)` and the equivalent on Vio centre the shadow frustum on the camera and snap to the shadow-map grid. |
| `AreaLightHelper` | Forward-pipeline rectangular area light = grid of point-light samples summing to total radiance. |
| `ParticleEmitter` + `ParticleSystem` | Three tiers, best the context supports: (1) GPU simulation — spawn ring (`GpuParticleLedger`), `GpuParticleBaker::inject()` + `stepIndirect()` compaction, one `vio_draw_indirect` per emitter, only new rows cross the bus (needs php-vio >= `GpuParticleBaker::MIN_VIO_VERSION`, compute + vertex storage + indirect draws, an indexed mesh, `ParticleSimulation::Auto`). All emitters share one kernel per shader, dispatches are recorded into the open frame (`['async' => true]`), and CPU-written upload buffers rotate (`GpuParticleState::UPLOAD_ROTATION`) so no frame in flight reads the next frame's rows; (2) CPU simulation with GPU billboards; (3) CPU storage + flat `DrawMeshInstanced`. All tiers spawn through `spawnRows()`, so the `mt_rand` order is identical. `ParticleSystem(..., gpuSimulation: false)` or `simulation: Cpu` opts out. |
| `ScreenSpaceReflections` | Quality enum (Off/Low/High). On OpenGL the `OpenGLSsrPass` runs a 24-step world-space ray-march from the resolved depth buffer (via `OpenGLOffscreenTarget::depthTextureId()`); on Vio + Metal it scales the wetness IBL lobe via the shared `u_ssr_intensity` uniform. |
| `AntiAliasing::Taa` | Temporal AA. OpenGL ships a real `OpenGLTaaPass` with per-frame Halton jitter on the projection matrix, neighbourhood-clamped composite, and a private history color target. Vio/Metal still fall back to FXAA via `AntiAliasing::fallback()` until their post-process chains are migrated. |

### Anti-patterns

- **Do not** mutate `GraphicsSettings` fields directly - use `with(...)` and
  `$engine->graphics->update(...)` so the immutable round-trip emits events
  and persists deterministically.
- **Do not** call `$renderer3D->applySettings()` from game code. Always go
  through `$engine->graphics` so events, persistence, and texture-manager
  updates stay in sync.
- **Do not** put `TextureQuality` or `MeshLodTier` into the adaptive stack -
  hot-swap cost (re-upload textures, regenerate meshes) dominates any
  frame-time gain.
- **Do not** add `applySettings()` paths that bypass `GraphicsSettings::with()`
  - the immutable round-trip is what makes change events deterministic.
- **Do not** add new procedural normal/surface patterns to one shader
  copy only. The three parallel copies must stay in sync:
  `resources/shaders/source/mesh3d.frag.glsl` (OpenGL),
  `resources/shaders/source/vio/mesh3d.frag.glsl` (Vio - all backends),
  and `resources/shaders/source/mesh3d.metal` (standalone Metal).
  Bump `NormalPattern::codeFor()` / `SurfacePattern::codeFor()` and
  patch all three shader copies in the same change.
- **Do not** ship gamma-only output paths in mesh-shader exits. Every
  exit must call `finalize()` (GLSL) / `outputColor()` (Vio) /
  `finalizeColor()` (Metal) so colour grading + tone-map + vignette
  stay consistent across paths.
- **Do not** sidestep `AreaLightHelper` by emitting many `AddPointLight`
  commands from game code "to look like an area light". The helper
  preserves total radiance across sample counts; manually-placed grids
  do not.

---

## Build system

The build pipeline (`src/Build/`) compiles a game project into a standalone
executable. CLI entry point: `bin/phpolygon`.

### Usage

```bash
php -d phar.readonly=0 vendor/bin/phpolygon build                # auto-detect platform
php -d phar.readonly=0 vendor/bin/phpolygon build macos-arm64     # specific target
php -d phar.readonly=0 vendor/bin/phpolygon build all              # every platform
php vendor/bin/phpolygon build --dry-run                           # show config only
php -d phar.readonly=0 vendor/bin/phpolygon build all --phar /tmp/game.phar  # reuse one PHAR across targets
```

The optional `--phar PATH` flag enables a cross-target PHAR cache. The staged
PHP code is identical for every desktop target of the same variant/build-type,
so the PHAR only needs to be built once: the first target builds it and persists
it at `PATH`; every later target reuses it, skipping the vendor + stage + PHAR
phases (only micro.sfx + combine + package run). This roughly triples
all-four-target throughput. iOS never participates (it links the staged tree and
has no PHAR). The flag is optional and additive — omitting it preserves the
classic per-target build.

### 7-phase pipeline

1. **Vendor** — `ProductionVendor`: copies composer.json + composer.lock (path
   repositories made absolute, root autoload paths mirrored for the classmap)
   into `<build temp>/composer` and runs `composer install --no-dev
   --optimize-autoloader --no-scripts` there. The project's `vendor/` and
   `composer.lock` are only read, so parallel or failed builds never touch the
   dev install, and a failing Composer fails the build with its output.
   `PHPOLYGON_COMPOSER` overrides the Composer command. The temp dir is unique
   per build call.
2. **Stage** — copy src/, the production vendor/, assets/, resources/ into temp dir, resolve
   symlinks, exclude tests/docs/editor via glob patterns; `resources.external`
   and `resources.exclude` paths stay out (see Configuration). Then 2b build-type
   constants, 2c UI transpile, 2d `assets.meshes = binary` (staged `*.mesh.json`
   → `*.mesh.bin`; `PharBuilder::pruneExcluded()` afterwards holds the compiled
   files to `resources.exclude` too)
3. **PHAR** — create game.phar with a custom stub that handles micro SAPI
   detection, macOS .app bundle paths, resource extraction, and engine bootstrap.
   The PHAR carries a build id (`.phpolygon-build-id`, hash of the staged
   assets/ + resources/), the stub the same id

> Phases 1–3 are PHAR-producing and identical across desktop targets of the same
> variant/build-type. With `--phar PATH`, they run once for the first target and
> are skipped on every later target (the cached PHAR is reused).
4. **micro.sfx** — resolve static PHP binary (explicit path → cache
   `~/.phpolygon/build-cache/` → download from GitHub Release)
5. **Combine** — concatenate micro.sfx + game.phar into single executable
   (macOS: micro.sfx + a small launcher, see "macOS bundle layout")
6. **Package** — platform-specific: macOS `.app` bundle with Info.plist,
   Linux/Windows flat directory
7. **Report** — PHAR size, binary size, bundle size

### Configuration

`build.json` in game project root (optional, falls back to composer.json):

```json
{
  "name": "MyGame",
  "identifier": "com.studio.mygame",
  "version": "1.0.0",
  "entry": "game.php",
  "run": "\\App\\Game::start();",
  "php": { "extensions": ["glfw", "mbstring", "zip", "phar"] },
  "phar": { "exclude": ["**/tests", "**/docs"] },
  "resources": {
    "external": ["assets/audio", "resources/video"],
    "exclude": ["assets/audio/raw-takes", "assets/**/*.wav"]
  },
  "platforms": {
    "macos": { "icon": "icon.icns", "minimumVersion": "12.0" }
  }
}
```

`phar.exclude` adds per-segment patterns to `BuildConfig::DEFAULT_PHAR_EXCLUDE`
(tests, docs, examples, benchmarks, `*.log`, …) — it never replaces them. The
patterns filter the staged `vendor/` only; `src/` ships in full, `assets/` and
`resources/` minus `resources.external` / `resources.exclude` (below). On top, `PharBuilder::VENDOR_CLEANUP` drops whole vendor paths:
Composer's `vendor/composer/tmp-*` download leftovers, development files in
every package root (dotfiles, `*.md`, `composer.lock`, phpunit/phpstan/psalm
configs — a path-repo checkout carries all of them) and the engine's tooling
directories and `Generated/*.json` sidecars. A package root's LICENSE/COPYING/
NOTICE file always ships.

`resources.external` lists project-relative paths (folders or single files,
under any root: `assets/…`, `resources/…`, `runtimes`, …) that ship loose next
to the executable (macOS: `Contents/Resources/`) and **never** in the PHAR. A
bare name without `/` (`"shaders"`) still means `resources/shaders`. Globs are
rejected here (they belong in `exclude`). Native loaders that cannot read
`phar://` (audio, big textures) need their folders external.

`resources.exclude` lists project-relative glob patterns (`PathGlob`: `*`/`?`
inside one segment, `**` across segments, `[...]` classes; a match on a folder
covers everything below it) that ship **nowhere** - neither in the PHAR nor among
the external files. For raw sources and tool inputs that sit inside a shipped
folder (recording sources, reference takes, `.psd`). It applies to every way a
file ships: PHAR staging, the compiled meshes of phase 2d, the external copy
(and with it what `resources.trimPython` later trims - trimming only removes),
the iOS bundle. Both lists are validated when build.json loads: relative paths
only, no `..`, no absolute/drive paths.

`phar.compression` (`"gzip"` default, or `"none"`) decides how the PHAR stores
its entries. gzip compresses every entry (`Phar::compressFiles(Phar::GZ)`); asset-heavy
archives (mesh JSON, BC7 texture data) shrink to roughly a sixth, while the stub's
start-up extraction costs the same, because inflating is paid back by reading far
fewer bytes. The build PHP needs zlib (the build fails loudly otherwise), and so
does the runtime: the stub refuses to start without `ext-zlib` and says so in
`game.log`, before it reads anything from the archive. The engine's static runtimes
carry zlib (static-php-cli pulls it in for phar/openssl, and for zip/curl on
Windows). Any other runtime must too, or the game sets `"compression": "none"`.

### Start-up extraction (stub + `Runtime\BundleExtractor`)

Native loaders cannot read `phar://`, so the stub copies the PHAR's `assets/` and
`resources/` to disk - **once per build id**, not per start. A marker
(`.phpolygon-extracted.json`: build id + file list) is removed first and written
last via temp+rename; every file lands as `*.part-<pid>` and is renamed into
place, so an aborted start just extracts again. Target:

- writable install dir (Windows/Linux, Steam library): in place next to the
  executable; nothing is ever deleted there (a stale name may be a store file);
- signed macOS `.app` or a read-only install: the build pre-extracts into the
  bundle (`PlatformPackager::preExtract`), so the marker already matches and
  nothing is written; otherwise the user cache (`BundleExtractor::userCacheDir`:
  `~/Library/Caches/<identifier>`, `%LOCALAPPDATA%\<identifier>`,
  `$XDG_CACHE_HOME/<identifier>`), with the install's loose (external) entries
  symlinked in, so `PHPOLYGON_PATH_ASSETS` still sees one tree.

`PHPOLYGON_PATH_ASSETS` / `_RESOURCES` are defined after the extraction and point
at wherever the files are. Inside a `.app` the stub never writes to the bundle:
`PHPOLYGON_PATH_LOGS` = `~/Library/Logs/<name>` (game.log; `Engine::log()` and
`Engine::logFilePath()` follow it), `PHPOLYGON_PATH_SAVES` / `_MODS` =
`~/Library/Application Support/<identifier>/{saves,mods}`. `PHPOLYGON_PATH_ROOT`
stays `Contents/Resources` (external resources live there). Outside a bundle all
of these stay next to the executable. The stub locates itself via `__FILE__`
(binary in micro SAPI, the `.phar` otherwise), so a built PHAR also runs with
`php game.phar` - the tests do exactly that.

### macOS bundle layout

```
<Name>.app/Contents/MacOS/<Name>            micro.sfx (signed) + php.ini block + launcher
<Name>.app/Contents/Resources/<name>.phar   the one PHAR (PlatformPackager::macPharFile())
<Name>.app/Contents/Resources/assets|resources|<external>   pre-extracted + external, with marker
```

The launcher (`PharBuilder::generateMacLauncher()`) writes
`~/Library/Logs/<name>/boot.log` before it requires the PHAR, so a start that
dies early is still visible. Because the binaries carry only the launcher, the
universal bundle (`docker/steam-upload.sh steam_make_universal`) shares one PHAR
between `<Name>-arm64` and `<Name>-x86_64`. Its shell launcher
(`docker/macos-launcher.sh`, or the game's own via build.json
`platforms.macos.launcher`) picks arm64 via `sysctl hw.optional.arm64` +
`arch -arm64` (Rosetta-safe), forwards SIGTERM/INT/HUP and logs to
`~/Library/Logs/<name>/launch.log` (binary, stderr, exit status - 137 = SIGKILL,
e.g. a rejected signature; previous start as `.1`). Log files rotate once per
start: `game.log` → `game.log.1`, `boot.log` → `boot.log.1`. The upload stages
all depots on the container's own filesystem (`STEAM_STAGE`, default
`/tmp/phpolygon-steam-content`: chmod on a Windows bind mount may not stick),
sets and verifies the executable bits, and aborts when a binary or `.dylib`
lacks its arm64/x86_64 slice (a `.dylib` below a folder named for one architecture -
`runtimes/macos-x86_64/…`, `lib-arm64/…` - needs only that one) or when a depot holds two paths
that differ only in case (Steam maps them case-insensitively and fails the depot).
`steam_upload_many <target>...` (used by `build-all.sh`; `steam_upload` is its
one-target case) prepares every target first (builds, staging - each build type
once, side by side -, optional `STEAM_STAGE_HOOK`, file check, VDFs; any failure
uploads nothing), then runs ONE `steamcmd +login … +run_app_build <vdf>… +quit`.
steamcmd's output goes complete to `steam/output/steamcmd-<date>.log`; the terminal
shows a plain-line status block per app (label = `uploads.<t>.label` or the target
name) parsed from this run's `depot_build_*/app_build_*` logs (mtime ≥ start),
upload MB/s from `/proc/net/dev`, then a per-depot summary (manifest, chunks,
files, duration) and the log tail on failure. Tested without Steam by
`docker/tests/steam-upload-test.sh` + `fake-steamcmd.sh`; details in `docker/README.md` §4. Signing (`docker/build-all.sh`): the bare micro.sfx is
ad-hoc signed before the launcher is appended, with JIT/library entitlements
(`allow-jit`, `allow-unsigned-executable-memory`, `disable-library-validation`);
`MAC_HARDENED=1` adds the hardened-runtime flag. **Unverified on hardware** -
run a build once on an Apple Silicon Mac.

### Build classes

| Class | Purpose |
|---|---|
| `BuildConfig` | Loads build.json + composer.json, provides all settings |
| `ProductionVendor` | `composer install --no-dev` into a working copy of composer.json/lock; the project's vendor/ and lock stay untouched |
| `PharBuilder` | Stages sources (minus external/excluded paths), builds PHAR with custom stub + build id, macOS launcher |
| `PathGlob` | build.json path patterns (`resources.external` / `resources.exclude`): validation + matching |
| `Runtime\BundleExtractor` | Stub-side: extract PHAR assets/resources once per build id (marker, atomic, cache fallback with links) |
| `StaticPhpResolver` | Finds/downloads/caches micro.sfx binary + Windows runtime libs (vulkan-1, d3dcompiler_47, DXC `dxcompiler.dll`/`dxil.dll` from the latest DirectXShaderCompiler release for Shader Model 6, Steam API) |
| `PlatformPackager` | Creates .app bundle, Linux dir, Windows .exe |
| `GameBuilder` | Orchestrates the 7-phase pipeline |
| `BuildHookRunner` | Runs build.json `hooks.beforeBuild` before staging (see below) |
| `BuildConstants` | Build type constants: rewrite `define()` in the staged bootstrap files, resolve what a set of sources defines |
| `BuildVerifier` / `BuildVerification` | Checks a built PHAR / package against build.json (see "Build verification") |
| `MicroIni` | Encodes build.json `php.ini` as the ini section between micro.sfx and PHAR (see below) |
| `UpscalerConfig` | Parses/validates build.json `upscalers` (see below) |
| `NativeUpscalerResolver` | FSR 3 / DLSS runtimes for a target: FidelityFX SDK tag (checksummed) or folder; DLSS plugin from a folder or a private GitHub release; cached; warnings, never a failed build |
| `ThirdPartyNotices` | `THIRD-PARTY-NOTICES.txt` next to the binary (AMD MIT text, NVIDIA notices) |
| `HttpClient` / `StreamHttpClient` | GET without auto-redirect (the resolver decides who gets the token); fake it in tests |
| `PrebakeProgress` | Progress lines of a pre-bake hook (see "Pre-bake progress and worker pool") |
| `PrebakePool` / `PrebakeTask` / `PrebakeTaskResult` | Runs a pre-bake's worker processes, logs in files, dead-worker detection |

### Embedded php.ini

The combined executable ignores php.ini files, and startup-only settings (OPcache, its JIT) cannot be changed with `ini_set()`. `build.json` `php.ini` embeds them: `GameBuilder` writes the section `spc micro:combine -I` would (`"\xfd\xf6\x69\xe6"`, big-endian length, `key=value` lines) between micro.sfx and the PHAR. No runtime rebuild is needed; OPcache is compiled into PHP 8.5 runtimes.

```json
"php": { "ini": { "opcache.enable_cli": "1", "opcache.jit": "tracing", "opcache.jit_buffer_size": "128M" } }
```

Once OPcache is on (`opcache.enable_cli` or `opcache.enable` truthy), `MicroIni::settings()` forces `opcache.validate_timestamps=0` and `opcache.file_update_protection=0`, over whatever build.json says: the embedded script has no file behind it, and the timestamp check (`zend_get_file_handle_timestamp`) read script text as a pointer – SIGSEGV before the first line on macOS. The script never changes, so the check is useless on every platform.

### Build hooks

`build.json` `hooks.beforeBuild` lists commands a build runs in the project root before it stages the game (asset pre-bakes, generated data). They run once per build that creates a PHAR; `--phar` reuse targets skip them. A failing hook fails the build; `PHPOLYGON_SKIP_HOOKS=1` skips them.

`hooks.afterPackage` (same form) runs **per target**, also on `--phar` reuse, once the bundle is assembled: `PHPOLYGON_BUILD_OUTPUT` = the `.app` or flat dir, `PHPOLYGON_BUILD_RESOURCES` = where external resources went (`Contents/Resources` or the flat dir), plus the `PHPOLYGON_BUILD_*` target vars. The place for per-target files (platform runtimes) and checks before upload.

```json
"hooks": { "beforeBuild": [
  { "run": ["@php", "-d", "memory_limit=-1", "tools/prebake.php"], "requires": ["imagettftext"], "runtimeVariant": "steam" }
] }
```

`@php` resolves to `PHPOLYGON_HOOK_PHP`, else the PHP running the build when it has every `requires` function, else the game's static runtime (micro.sfx for the host, variant `runtimeVariant`) wrapped as a runner taking `[-d key=value]... script [args]` with the runtime's libraries (Windows DLLs, Steam API) copied next to it - so a hook can use the shipped game's extensions inside a build container whose PHP lacks them. Hooks see `PHPOLYGON_BUILD_PLATFORM/ARCH/VARIANT/TYPE`, and `PHPOLYGON_HOOK_PHP` names the interpreter of an `@php` hook: start further PHP processes with it, because `PHP_BINARY` is empty inside a micro runtime.

The runner's `-d` options are `ini_set()` calls, so startup-only settings (`opcache.enable_cli`, `opcache.jit_buffer_size`) cannot come from them. The runner therefore embeds a startup ini section (`MicroIni`) of `BuildHookRunner::RUNNER_INI` (OPcache on, tracing JIT, 128M buffer) overridden by build.json `php.ini`: hooks and the workers they start run with JIT (`opcache_get_status()['jit']['on']`), and a `-d opcache.jit=...` still switches the mode per process. A `-d` the runner cannot apply prints `runner: <key> is fixed at startup, keeping <key>=<value>` to stderr. php-vio starts its window system at module startup, before any PHP code runs: without a display (a build container) every hook and worker printed two `GLFW` warnings (`error 65544 ... DISPLAY ... missing`, `Failed to initialize GLFW`). On a Linux host with neither `DISPLAY` nor `WAYLAND_DISPLAY`, hooks therefore get `VIO_PLATFORM=null` unless the caller sets `VIO_PLATFORM` (`BuildHookRunner::withoutWindowSystem()`); workers inherit it. The null platform has no windows and no OpenGL context, but `vio_audio_encode` and `vio_text_bitmap` work. Windows and macOS hooks keep the native platform.

### Pre-bake progress and worker pool

A pre-bake hook reports through `PrebakeProgress` (STDERR, one flushed line per message, no ANSI/`\r`, so STDOUT stays the script's own): `plan()` at the start (`voice: 673 to render, 14738 up to date, 8 processes` - the text is the hook's), then at most one line per `stepPercent` step (5 %) at least `minInterval` (10 s) apart, a heartbeat after `heartbeat` (60 s) without one (`voice: 263/673 (39 %) – 52 s, ~1 min 20 s left`), and `finish($detail)` with the duration (`voice: done – 673/673 in 2 min (3 failed)`, `done – nothing to do in 0.4 s`). Clock and output are injectable. `PrebakeProgress::strict($env = 'PHPOLYGON_PREBAKE_STRICT')` says whether failed parts should fail the hook (`1`/`true`/`yes`/`on`); a game passes its own variable name.

`PrebakePool($label, $jobs, $progress, failFast: ...)->run(list<PrebakeTask>)` starts the workers, at most `$jobs` at a time. Each `PrebakeTask` is a command (no shell), a log file that gets STDOUT and STDERR through **one** handle (two handles on one file overwrite each other on Windows), STDIN from the null device, optional `env` over the parent's, and `parts` (default 1). **No pipes:** `stream_select()` on pipes does not work on Windows. A worker that settles many parts reports through `poll` (a closure, typically counting the entries of a result file the worker rewrites after each part; polled every `pollInterval` s, `null` keeps the last count); without one its parts count when it ends. A task fails when it cannot start, exits ≠ 0, dies by a signal (9 hints at the OOM killer) or exits 0 below its `parts`; the pool reports it at once (`ERROR <label>: <name> failed (exit code 255) – 212 of 400 not done, log (<path>):` + the last `logTail` lines), keeps its log and counts its parts as settled. A successful task's log goes to `onFinish` and is deleted. Default: the others go on; `failFast` terminates the running workers (`stopped`) and starts no more. `run()` returns one `PrebakeTaskResult` per task (`failed()`, `reason()`, `exitCode`, `signal`, `done`, `missing()`, `log`); the exit code is the caller's decision. Tests: `tests/Build/PrebakeProgressTest.php`, `tests/Build/PrebakePoolTest.php` (real PHP workers).

### Build types and verification

`buildTypes.<type>.constants` are written into the staged bootstrap files - `bootstrap.php` and every
file of `phar.additionalRequires`, wherever one has `define('NAME', ...)` (`BuildConstants::apply`).
A constant none of them defines fails the build (it used to be skipped silently when the game kept its
flags outside `bootstrap.php`, shipping a demo as the full game).

Every `phpolygon build` then checks the artifact (`BuildVerifier`, phase 6c, also for a reused
`--phar`); a problem fails the target, `PHPOLYGON_SKIP_VERIFY=1` turns it into a warning:

- each constant any build type sets resolves, inside the PHAR, to the requested type's value; the ones
  the type leaves alone keep the project's own value (a full build never carries a demo flag). Only the
  `define()` calls are read, first one in require order wins, values must be constant expressions;
- `verify.versionConstant` equals build.json `version`;
- every path of `verify.files` (`"*"` plus the type) ships non-empty, in the PHAR or among the external
  resources - for git-ignored files a fresh checkout silently lacks (tokens, keys).

```json
"verify": { "versionConstant": "GAME_VERSION", "files": { "*": ["resources/bugreport/token"] } }
```

`phpolygon verify-build [path] [--type T]` checks finished packages: a `.phar`, a macOS `.app`, a package
dir or a combined binary (its PHAR is found by the stub's `PharBuilder::STUB_MARKER`, since the runtime
itself contains `__HALT_COMPILER`); without a path every `build/<platform>-<arch>*` dir, the type taken
from the dir name. `docker/build-all.sh` runs it on every built target before an upload.

**One PHAR for every build type.** Types differ only in their constants, so `phpolygon build --phar NEW
--derive-phar BUILT` makes a missing `NEW` from a PHAR already built for another type
(`PharBuilder::deriveBuildType`: copy, rewrite the bootstrap files with `BuildVerifier::typeConstants()` -
the type's values and the project's for the rest, so deriving from another non-full type is safe). No
hooks, no production install, no staging, no compression of the archive: ~2 s instead of a full PHAR
build; assets and the build id stay the same. `docker/build-all.sh` builds the first type and derives the
others. A type whose hooks produce type-specific files sets `"sharedPhar": false` in its `buildTypes`
entry and is built from scratch.

**Build in a volume (`PHPOLYGON_WORKDIR`).** `docker/sync-workdir.sh` (sourced by `build-all.sh`) mirrors
`/app` into the volume with rsync, minus `build/`, `.git` and `PHPOLYGON_SYNC_EXCLUDE`; then each path in
`PHPOLYGON_SYNC_SEED` gets `rsync -a --update` (host file only when newer, no delete) - for hook outputs that
are excluded so a stale host copy cannot overwrite the container's, but a newer one saves the re-render.
Progress is rsync `--info=progress2` turned into plain log lines (every 5 % / 10 s, then a summary; nothing
changed = one line), no `\r` in the log. Test: `docker/tests/sync-workdir-test.sh`, run in the image.

### Embedded Python runtimes

A game that runs player code in a shipped CPython (python-build-standalone, Windows `Lib/`+`DLLs/`
or Unix `lib/python3.X/`) slims it with `PHPolygon\Build\PythonRuntimeTrimmer::trim($root, $groups,
$remove, $keep, $dryRun)`, or declaratively for external resources in the package (project copy untouched):

```json
"resources": { "external": ["runtimes"],
  "trimPython": [ { "path": "runtimes/*/python", "groups": ["default", "network"], "remove": [], "keep": [] } ] }
```

- `default` = `tooling` (pip, ensurepip, venv, Scripts, bin/pip|idle|pydoc|-config), `gui` (tkinter,
  idlelib, turtle, Tcl/Tk, pythonw, `share/terminfo` - its names differ only in case, which a Steam
  depot refuses), `tests` (stdlib test dirs, `_test*`/`xx*` modules), `devel`
  (include, libs, pkgconfig, config-*, man pages), `bytecode-variants` (`*.opt-1/2.pyc`; plain `.pyc`
  stay, a `-B` run could not rebuild them). Nothing a script run imports.
- Opt-in: `network` (OpenSSL, `_ssl`, `_hashlib` - `hashlib` keeps its built-in md5/sha*/blake2),
  `sqlite`, `docs` (`pydoc_data`, i.e. `help()` topics).
- Patterns: relative to the Python root, `*`/`?` within a segment, `**` across, `{lib}`/`{dynload}` for
  the layout's stdlib/extension dirs, **case-sensitive** (Unix `lib/thread3*` vs Windows
  `Lib/threading.py`); `keep` wins, also inside a directory a removal names.
- Measured (3.14.8, Windows): 61.6 MB -> 36.0 MB `default`, 23.9 MB with `network`+`sqlite`+`docs`;
  Linux/macOS -20.7 / -19.5 MB `default`. Trimmed interpreter passes `-I -S -B` imports of json, re,
  hashlib, random, decimal, unittest, threading, ...
- A step that replaces the runtimes after packaging (e.g. per-target placement in a
  `hooks.afterPackage` hook, which runs after the trim) bypasses `trimPython` and
  `resources.exclude` - such a game calls `trim()` itself where it unpacks the runtime.

### Native upscaler runtimes

`build.json` `upscalers` ships FSR 3 / DLSS runtimes next to the binary. Details: `docs/build-native-upscalers.md`.

```json
"upscalers": {
  "fsr": true,
  "dlss": { "plugin": "github:<owner>/<private-repo>@<tag>", "projectId": "<random GUID>" }
}
```

- `fsr`: `true` = `amd_fidelityfx_dx12.dll` / `_vk.dll` (MIT) from the FidelityFX SDK tag `v1.1.4` (`PrebuiltSignedDLL/`, fetched raw by tag; the release asset is the whole 470 MB SDK), SHA-256 pinned, cached in `~/.phpolygon/build-cache/upscalers/`. A folder string / `{"path"}` = from there. Windows only, unless a folder has `libamd_fidelityfx_vk.so`.
- `dlss.plugin`: a folder with `vio_dlss.dll` + `nvngx_dlss.dll`, or `github:owner/repo@tag`: a release of a private repository (`GITHUB_TOKEN` / `GH_TOKEN` / `gh auth token`; a public repository is refused; the token never goes to the redirect host; cached per tag). Linux only with `libvio_dlss.so` + `libnvidia-ngx-dlss.so.*`, otherwise "not supported"; never macOS.
- `dlss.projectId` -> embedded ini `vio.dlss_project_id`. `EngineConfig::$dlssProjectId` overrides it when set; it must not contradict `php.ini`.
- Anything missing is a warning, and the game builds without that upscaler.
- `THIRD-PARTY-NOTICES.txt` lands next to the binary: the AMD MIT text, plus for DLSS NVIDIA's copyright and trademark lines, "This software contains source code provided by NVIDIA Corporation." and the RTX SDK restrictions passed on to users.
- At runtime php-vio looks next to the executable first (`GetModuleFileName(NULL)` = the game's exe), so a built game needs no ini.
- **Game duties (licences):** NVIDIA attribution in the splash or credits (RTX UI Developer Guidelines), notify NVIDIA before the commercial release (https://developer.nvidia.com/sw-notification), EULA terms that protect NVIDIA's components (§2c), its own project id. NVIDIA files travel **only inside the game**: never in php-vio, the engine or a public release. Keep the AMD notice.

### PHAR stub constants

The stub defines these at runtime:
- `PHPOLYGON_PATH_ROOT` — resource base directory
- `PHPOLYGON_PATH_ASSETS` — extracted assets
- `PHPOLYGON_PATH_RESOURCES` — extracted resources (shaders, fonts)
- `PHPOLYGON_PATH_SAVES` — user save data
- `PHPOLYGON_PATH_MODS` — mod directory

---

## UIContext — immediate-mode UI

`UIContext` (`src/UI/UIContext.php`) is PHPolygon's immediate-mode UI toolkit.
Games must use it for interactive widgets rather than reimplementing hit-testing.

```php
use PHPolygon\UI\UIContext;
use PHPolygon\UI\UIStyle;

$ui = new UIContext($renderer, $input, new UIStyle(...));

// In render():
$ui->begin($x, $y, $width);              // vertical flow (default)
if ($ui->button('id', 'Label', $w)) { }  // returns true on release
$val = $ui->checkbox('id', 'Label', $val);
$ui->label('Text');
$ui->separator();
$curY = $ui->getCursorY();               // snapshot for chained begin()s
$ui->end();

$ui->begin($x, $curY, $width, 'horizontal');
$ui->button('id', 'Label', $btnW, $disabled);
$ui->end();
```

- Constructor accepts `InputInterface`, not the concrete `Input` class.
- `button()` uses `isMouseButtonReleased` internally — safe on macOS.
- `disabled=true` makes a button non-clickable; styled via `UIStyle::disabledColor` /
  `disabledTextColor` (use a distinct colour to indicate "currently selected").
- `UIContext` must be called from `render()`, not `update()` (input state uses
  `mousePrev` snapshotted by `endFrame()`, which runs between update and render).

### UIContext — text areas (code editing)

`textArea($id, $value, $rows, $underlay, $indentRules)` edits through
`UI\TextEdit\TextEditModel` (pure: buffer, caret, selection, undo; positions are
code points). Tab / Shift+Tab (also over a line selection), Enter auto-indent
(`IndentRules`: width, tabs, openers / closers; `IndentRules::braces()` /
`offside()` per language), smart Home, Ctrl+Home/End, Shift selection, drag,
double-click word, Ctrl+A/C/X/V, Ctrl+Z / Ctrl+Y / Ctrl+Shift+Z (typing runs are
one undo step; Enter, paste, cut, indent each one), Ctrl word jumps; macOS:
Command for shortcuts, Option for words. Modifiers come from `KeyModifiers`
(key state, AltGr = Ctrl+Alt is never a shortcut). Shortcut letters go through a
`KeyboardLayoutInterface` (`VioKeyboardLayout` = php-vio `vio_key_name`, so Ctrl+Z
follows the keycap on QWERTZ). The clipboard is a `ClipboardInterface`:
`VioClipboard` (system clipboard, php-vio >= 2.33) when the input is `VioInput`,
else `MemoryClipboard` - tests never touch the user's clipboard. The selection is
drawn after the underlay and before the text; `TextAreaView` reports `caret` and
`selectionStart/End`.

### UIContext — dropdown overlays

Dropdown option lists are rendered as deferred overlays via `flushOverlays()`.
Call this once at the end of the frame, after all `begin()`/`end()` pairs:

```php
// After all UI rendering
GameUI::$ctx->flushOverlays();
```

This ensures dropdown lists render on top of all other widgets regardless of
draw order. Without it, widgets drawn after the dropdown occlude its option list.

### UIContext — text fields

Text fields support:
- Blinking cursor (1Hz) at the insertion point
- Character insertion at cursor position (not just append)
- Arrow key navigation, backspace at cursor, delete forward

### UIContext — modal interaction gate

Use `setInteractive(false)` to gate an underlying scene while a modal overlay
draws on top. Hover misses for every widget rendered while interactive=false,
which also blocks click triggers (click requires hover):

```php
$ui->setInteractive(false);
drawPanelsBehindModal();   // visible but unresponsive
$ui->setInteractive(true);
ConfirmDialog::draw($engine, $w, $h);   // own widgets respond again
```

Widgets still render — only input is gated. Defaults to `true` on construction;
restore to `true` before the modal itself draws or its own buttons are dead.
Suppressing via `Input::suppress()` only blocks the click/release edges, not
hover state, so the underlying button still flashes its hover color without
this. `setInteractive()` is the right tool when something is layered on top.

### VioInput — non-consuming events

`isMouseButtonPressed()` and `isMouseButtonReleased()` do **not** consume events.
All callers within the same frame see the same state. This is required for
immediate-mode UI where multiple widgets check the same button per frame.

Scroll values are cached via `snapshotScroll()` before `vio_begin()` resets them.
The Engine calls this automatically. `getScrollX()`/`getScrollY()` return cached values.

### InputMap — rebindable actions

The bindings given to `addAction()` are the action's **defaults**. Rebinding is
built in; the engine never persists it – the game stores the exported array
in its own settings file.

```php
$map->addAction('replay', InputBinding::key(71))      // G
    ->reserve(87, 65, 83, 68)                          // keys the game reads directly
    ->setRebindable('interact', false)                 // locked: its keys count as reserved
    ->setBindableFilter(fn (InputBinding $b) => $b->type === InputBindingType::Key)
    ->setConflictPolicy(RebindConflictPolicy::Reject); // or Swap
$r = $map->rebind('replay', 84);          // RebindResult: status + otherAction
$map->checkRebind('replay', 84);          // same answer, nothing applied
$map->resetBinding('replay'); $map->resetAllBindings();
$settings['keys'] = $map->exportOverrides();          // only non-default actions
$dropped = $map->importOverrides($settings['keys']);  // re-validated on load
```

- `InputMap::poll()` / `InputMapSystem` take any `InputInterface` (also
  `VioInput`) and read only `isKeyDown()` – they never consume press edges.
- Axis bindings, reserved keys and keys of locked actions are refused
  (`RebindStatus::Reserved`). A conflict with another action is `Conflict`
  (with `otherAction`) or, with policy `Swap`, the other action gets the old key.
- `resetBinding()` cascades: an action that took the reset action's default
  is reset as well (defaults are assumed conflict-free).
- `importOverrides()` accepts bare ints as key codes, drops invalid entries and
  resolves clashes by dropping the later action back to its defaults.
- `KeyLabel` names keys for the UI: printable keys through the current layout
  (`vio_key_name`), named keys/mouse buttons via `input.key.<slug>`
  translations (`KeyLabel::slug()`), English fallback.
- `KeyCapture` is the "press a key" prompt: polls `isKeyDown()` only, so it
  never consumes a press edge (`isKeyPressed()` would). Keys held at `start()`
  count only after a release; Escape is excluded by default so the game can
  read it as cancel. Gate gameplay while capturing or call `clearKeyEdges()`
  after a capture, otherwise the captured key also reaches gameplay.

### VioRenderer2D — fallback font chain

Register fallback fonts for locales that need them (e.g. CJK):

```php
$r2d->addFallbackFont('inter-semibold', 'noto-sans-sc');
$r2d->preloadFonts([15.0, 26.0]);  // pre-bake atlas to avoid stutter
$r2d->clearFallbackFonts();         // when switching to a non-CJK locale
```

Games should only register CJK fallbacks when the active locale requires them.
The primary font renders first; fallback fonts only render glyphs the primary
doesn't cover. `measureText()` uses the full chain for width calculation.

---

## Window — mode switching (macOS notes)

`Window` (`src/Runtime/Window.php`) wraps GLFW and provides:
- `setFullscreen()` — exclusive fullscreen via `glfwSetWindowMonitor(monitor, ...)`
- `setBorderless()` — decorations removed + `glfwMaximizeWindow()` (macOS-safe)
- `setWindowed()` — restore saved windowed geometry; calls `glfwRestoreWindow()` first when coming from borderless
- `toggleFullscreen()` — convenience

**macOS-specific**: `glfwSetWindowMonitor()` (exclusive fullscreen) and
`glfwSetWindowAttrib(DECORATED, false)` can trigger a deferred AppKit
"window will close" notification. `Window` arms a `suppressCloseUntil` timer
before each mode switch; `shouldClose()` resets the close flag and returns `false`
for 2 seconds after any transition.

**Never use `glfwSetWindowPos` + `glfwSetWindowSize` to fill the screen for
borderless mode.** This triggers AppKit's automatic Spaces-fullscreen entry and
fires a deferred close event that terminates the game loop.
Use `glfwMaximizeWindow()` instead.

**Never pass `false` (bool) to `glfwSetWindowShouldClose()`** — the binding
requires `int`. Use `glfwSetWindowShouldClose($handle, 0)`.

---

## Headless mode

The engine can run without a GPU, display server, or OpenGL context.
This enables CI testing, scene validation, and visual regression testing.

```php
$engine = new Engine(new EngineConfig(headless: true));
// All subsystems work: ECS, Scenes, Events, Audio, Locale, Saves
```

### How it works

| Normal mode (Vio) | Normal mode (GLFW) | Headless mode |
|---|---|---|
| `VioWindow` | `Window` (GLFW) | `NullWindow` (no-op) |
| `VioRenderer2D` | `Renderer2D` (NanoVG) | `NullRenderer2D` (no-op) |
| `VioRenderer3D` | `OpenGLRenderer3D` / `VulkanRenderer3D` | `NullRenderer3D` (no-op) |
| `VioTextureManager` | `TextureManager` (GL) | `NullTextureManager` (dummy) |
| `VioAudioBackend` | `GLFWAudioBackend` | `null` (no audio) |
| `VioInput` | `Input` (GLFW callbacks) | `Input` (no-op) |

The `headless` flag in `EngineConfig` switches all backends automatically.

`EngineConfig::$offscreen` is different: real vio backends, but an invisible
surface (php-vio `headless`) - for screenshot tests and offline rendering. It
renders on the hardware GPU (`headless_hardware`; `$offscreenHardware`, default
true) and falls back to the D3D software adapter (WARP) only when no hardware
context opens. Set `offscreenHardware: false` for images that must match across
machines.

### Null objects

- `NullWindow` — returns configured width/height, `shouldClose()` returns false
  until `requestClose()` is called, all other methods are no-ops
- `NullRenderer2D` — implements `Renderer2DInterface`, every draw method is a no-op
- `NullRenderer3D` — implements `Renderer3DInterface`, accepts `RenderCommandList`,
  executes nothing; command list is readable for test assertions
- `NullTextureManager` — `load()` auto-creates dummy `Texture` objects with
  `glId: 0` and configurable width/height; `register(id, w, h)` pre-registers
  textures for tests that need specific dimensions

---

## Multiplayer — the primitives live here

`PHPolygon\Net` and `PHPolygon\Command` are the engine's answer to "two copies
of this game, talking". They are framework-free like everything else here, and
they are the only place in the PHPolygon stack where sockets, wire framing and
sessions belong. `phpolygon/multiplayer` builds rooms, tick-based ECS
replication and a control plane **on top of them** — it does not carry its own
transports. Nothing may rebuild these:

| Class | What it is |
|---|---|
| `Net\Transport` | The seam: `poll()` returns `NetEvent`s (connected / message / disconnected), `send()`, `disconnect()`, `close()`. Reliable and ordered throughout. |
| `Net\SteamTransport` | Steam's relay network; joining over Rich Presence. |
| `Net\SocketTransport` | TCP via ext-sockets, 4-byte length prefix, non-blocking, an 8-byte account on connect. Two processes, a LAN, two containers. |
| `Net\LoopbackTransport` | In-process pair (`host()` / `connectClient()`) for tests. |
| `Net\Protocol` + `Reassembler` | The wire format: gzip, `MAGIC` + version, chunked up to 32 MB so a whole save game fits. |
| `Net\HostSession` / `ClientSession` | The only end that simulates, and the end that sends commands and takes state. Heartbeats, keepalive, silence timeout. |
| `Net\StateChannel` | The game's side: capture state, restore it, carry news and refusals. |
| `Command\Command` + `CommandBus` + `CommandRegistry` + `Refusal` | Serializable player actions that check their own preconditions. Single-player takes the same path, so there is one code path, not two. |

What deliberately is **not** here: ticks, client prediction, interpolation,
lag compensation, unreliable datagrams, ECS delta replication. A game whose
state is a save file rather than a world of moving entities does not need them;
one that does takes `phpolygon/multiplayer`.

---

## Start language — Steam, then the system

A game's first start should speak the player's language without asking. The
engine resolves it; the game only passes the locales it ships and a fallback.
No ext-intl needed.

```php
SteamSession::start();                                  // no-op without php-steamworks / Steam
$start = (new LanguageResolver($shippedCodes, 'en'))->resolve($savedChoiceOrNull);
$engine->locale->setLocale($start->locale);
// Persist only a choice the player made in the options – never $start when
// !$start->wasChosen(), or a later Steam/OS language change is never followed.
```

| Class | What it is |
|---|---|
| `Locale\LocaleMatcher` | `normalize()` any tag (`de_DE.UTF-8@euro` → `de-DE`, C/POSIX → null) and `match()`/`matchFirst()` it against a catalogue: exact → regional rules → language → any region of that language. es outside Spain → `es-419`, bare es → `es-ES`; pt outside Brazil → `pt-PT`; zh by script (Hans: CN/SG, Hant: TW/HK/MO; other script last); `no`/`nb`/`nn` interchangeable. Returns the catalogue's own spelling. Catalogue codes that are not tags take part through `$aliases` (`['latam' => 'es-419']`; also on `LanguageResolver`). |
| `Platform\SteamSession` | Idempotent `start()` (memoised, warning-free, false without extension/client/app id; `SteamAPI_InitFlat` returns at once there), `runCallbacks()` per frame, `shutdown()` (also registered at exit). |
| `Platform\SteamLanguage` | `API_CODES` (all 31 Steam API language codes → tags, `latam` → `es-419`, `brazilian` → `pt-BR`, `schinese` → `zh-CN` …), `detect()` = per-game language, then client UI language. Empty before `steam_init()`. |
| `Platform\SystemLanguage` | Windows: CRT user locale (`German_Germany.1252`, LC_CTYPE restored) then env; Unix: `LANGUAGE`/`LC_ALL`/`LC_MESSAGES`/`LANG` (C wins = nothing); macOS: env then `defaults read -g AppleLanguages` (child process, 1 s cap). |
| `Platform\LanguageResolver` + `ResolvedLanguage` | saved > Steam > system > fallback, lazily; throwing sources count as silent. `source` says which won, `wasChosen()` whether to persist. |

Never: hardcode a game's locale list here, persist a detected language as the
player's choice, or call `steam_*` language functions unguarded (they warn
before `steam_init()`).

---

## Audio channels — volume, master, ducking

`AudioManager` computes each voice as
`voice × channel × master × duck(channel) × duck(master)`; mute on the channel
or Master forces 0. Master is folded in here exactly once (never forwarded to
the backend's output gain), and every voice keeps its own volume, so a channel,
master, mute or duck change recomputes it without losing it.

Ducking is a separate gain stage – the player's channel volume
(`getChannelVolume()`) is never overwritten:

```php
$audio->setChannelDuck(AudioChannel::Music, -12.0, 0.3, 'speech'); // dB, fade s, reason
$audio->setChannelDuck(AudioChannel::Music, -20.0, 0.5, 'focus');  // strongest wins
$audio->releaseChannelDuck(AudioChannel::Music, 'speech', 0.6);    // ramps to the next one
$audio->clearChannelDucks();                                       // all channels
$audio->getChannelDuck(AudioChannel::Music);   // current dB incl. ramp
```

- Several reasons per channel; the most negative one is effective. Positive
  values clamp to 0 dB, `DUCK_FLOOR_DB` (-80) or below silences.
- Ramps are linear in dB and advance in `AudioManager::update($dt)`, which the
  engine calls every update tick. Code driving an `AudioManager` outside the
  engine loop (tools, tests) calls it itself; fade 0 applies immediately.
- Ducking Master lowers every channel.

## Stem player — layered music against run-time marks

`StemPlayer` plays pre-rendered stems (ids → file paths) that come and go as
the game reaches marks (a cutscene's beats, a fight's phases). The arrangement
is a `StemSchedule` – pure arithmetic, shared by the live player and an offline
mix (`gainAt()`), so a game and its rendered video use one arrangement:

```php
$player = new StemPlayer($audio, AudioChannel::Music, new GainFollower(0.3, 0.6, -8.0), $log);
$player->addStem('calm', $pathOrNull);            // null / failed load or play = silent stem, reported once
$player->preload();
$s = $player->schedule();
$s->start('calm', $t, fadeIn: 1.0, gain: 0.3);    // a stem starts once
$s->crossfade('calm', 'tense', $t, 1.0, gain: 0.3, fadeIn: 1.5);
$s->fadeOut('tense', $t, 2.0);
$s->cut($t);                                      // StemSchedule::CUT_SECONDS (60 ms); only stems started before $t
$player->duck(-8.0); $player->duck(0.0);          // all stems, or duck($db, 'stem') per stem
$player->update($now);                            // every frame, monotonic seconds; steps capped at MAX_STEP
$handoffs = $player->release(2.0);                // list<StemHandoff>: playback, volume, fade, channel
$menuPlayer->adopt($handoffs[0]);                 // the new owner fades it out on its clock
```

- Gain at t = `gain × fade-in × Π fade-outs` – a cut also shortens a running
  fade; once any fade-out reaches 0 the stem stops for good.
- Volumes go through `setPlaybackVolume()`, so channel/Master volume, mute and
  `setChannelDuck()` stack on top. The player's own duck is a `GainFollower`
  on the player's clock (amplitude-linear, separate down/up times) – for code
  that runs where `AudioManager::update()` does not tick yet, or per stem.
  Never duck the same reason in both places.
- `release()` stops silent stems and stops driving the rest; `reset()` starts
  a new run (loaded clips stay). Marks, gains and the composition belong to
  the game.

## Audio DSP — offline signal processing

`PHPolygon\Audio\Dsp` and `PHPolygon\Audio\Codec` are plain-PHP tools for audio a
game renders **ahead of time** (build hooks, prebake scripts): read and write WAV
and FLAC, read any source (`AudioReader`), encode Opus and Vorbis, resample, measure loudness, reshape recorded voices. Nothing
here runs per frame – playback stays with `AudioManager`; only the Opus/Vorbis writers
and `AudioReader`'s compressed path call php-vio (its codecs), everything else is plain PHP. Every class works
on `list<float>` mono (or two lists for stereo) at a sample rate that defaults to
`Pcm::SAMPLE_RATE` (44.1 kHz) and is a parameter everywhere. All of it is
deterministic: same input, same bytes.

| Class | What it is |
|---|---|
| `Dsp\Pcm` | Shared conventions: `SAMPLE_RATE`, `BITS_PER_SAMPLE`, `toInt16()` (rounded, ±32767), `packSample()` (truncated), `lpCoeff()`, `hz()` (MIDI → Hz), `db()` |
| `Dsp\Fft` | In-place radix-2 FFT with cached twiddles |
| `Dsp\Resampler` | Band-limited Kaiser-sinc `convert()` (rate change) and `stretch()` (length and pitch by a factor) |
| `Dsp\Loudness` | ITU-R BS.1770 integrated loudness (LUFS), stereo and mono |
| `Dsp\Limiter` | Offline look-ahead peak limiter: `new Limiter(ceilingDb, lookaheadMs, releaseMs, sampleRate, truePeak)`, `processMono()` / `processStereo()` / `process(list<list<float>>)` (linked channels), `gains()` for the curve. Linear ramps: a full gain swing takes `lookaheadMs` down (complete when the peak arrives) and `releaseMs` back up. No sample ends above the ceiling; a buffer that never exceeds it comes back bit-identical. `truePeak` adds a 4× Catmull-Rom inter-sample estimate (an approximation, ~0.1 %) |
| `Dsp\GainFollower` | Stateful gain that follows a dB target on a linear (amplitude) ramp with separate down/up speeds: `new GainFollower(downSeconds, upSeconds, swingDb)` – the times are for a full 0 dB ↔ `swingDb` swing, so they are rates; a target change mid-ramp continues from the current gain. `step(dt, ?targetDb)` per frame, `envelope(frames, sampleRate, Closure(t): targetDb, block)` → control gains at every block boundary (value before the step, as live), `expand()` → per sample. `FLOOR_DB` (−80) silences. The one class here that is also meant for per-frame use (pure state, no audio) |
| `Codec\WavReader` | RIFF/WAVE → mono floats at a target rate: 16/24-bit PCM, 32-bit float, any channel count, `WAVE_FORMAT_EXTENSIBLE` |
| `Codec\WavWriter` | 16-bit PCM RIFF header, stereo interleave |
| `Codec\FlacWriter` | Lossless FLAC (fixed predictors, Rice coding, stereo decorrelation), stereo and mono, STREAMINFO MD5; php-vio decodes it |
| `Codec\FlacReader` + `FlacInfo` | Complete RFC 9639 decoder in plain PHP: fixed and variable block sizes, every block size / rate / sample size code, 4–32 bit, 1–8 channels, all stereo modes, CONSTANT / VERBATIM / FIXED / LPC 1–32, wasted bits, Rice/Rice2 incl. escapes, leading ID3v2 skipped; CRC-8/CRC-16 always checked, STREAMINFO MD5 on request (`verifyMd5`). `read()`/`decode()` → mono floats at a target rate with `WavReader`'s arithmetic (FLAC and WAV of the same PCM read identically); `readSamples()`/`decodeSamples()` → exact ints per channel + `FlacInfo`; `readInfo()` reads only the head. Errors name source, frame and byte. ≈ 1.5–2.4 M samples/s without JIT, 3.5–6 M with tracing JIT |
| `Codec\AudioReader` | "Any source" → mono floats at a target rate: sniffs the bytes (`RIFF…WAVE`, `fLaC`, ID3 + `fLaC`), else the extension (`format(path)`); WAV/FLAC in plain PHP, Opus/Vorbis/MP3 through php-vio's `vio_audio_decode()` – `RuntimeException` without it |
| `Codec\OpusWriter` + `OpusSettings` | Ogg Opus through php-vio's libopus (`vio_audio_encode`); input off the encode rate (8/12/16/24/48 kHz) is resampled with `Resampler` first – slow for long material, so render at the encode rate. Sample-exact length (pre-skip, end trim), decodes at 48 kHz |
| `Codec\VorbisWriter` + `VorbisSettings` | Ogg Vorbis through php-vio's libvorbis: quality −0.1…1.0 (0.5 = `oggenc -q5`) or ABR, any rate, gapless |
| `Codec\AudioFormats` | What this process decodes/encodes (`detect()`: php-vio's `vio_audio_formats()`, else WAV/FLAC/MP3 decode + WAV/FLAC encode), `choose(preferred, player)` → first format encodable here and decodable by the player, else FLAC; `resolve(path)` → the smallest decodable sibling (`line.opus` before `line.flac`); `extension()`/`forPath()` |
| `Codec\AudioEncoder` | Preference list + settings → picks via `choose()` and writes Opus/Vorbis/FLAC/WAV; `extension()`, `isFallback()`, `fingerprint()` for cache keys |
| `Dsp\Voice\PitchDetector` / `PitchTrack` | YIN f0 tracker, ≈ 10 ms hop, voiced/unvoiced per frame |
| `Dsp\Voice\VoiceShaper` | TD-PSOLA pitch + resample formant shift + WSOLA tempo, RBJ tilt shelves, envelope-following breath noise |
| `Dsp\Voice\VoiceReference` / `VoiceMatcher` | A voice's measurable fingerprint (f0 stats, LPC formants, 1/6-octave LTAS; JSON round trip) and the matcher that steers a recording toward it (pitch, range, formants, 13-band EQ) |
| `Dsp\Voice\Crusher` | Sample-and-hold, bit reduction and faded dropouts ("broken machine") |
| `Dsp\Voice\Vocoder` + `VocoderCarrier` | Channel vocoder; the carrier is pluggable (`SawChordCarrier` default) so a game can let speech play its own synthesiser |
| `Dsp\Voice\VoiceProfile` | Value object: pitch, formant, tempo, tilt, breath, vocoder, crush; `withOverrides()`, `fingerprint()` |
| `Dsp\Voice\VoiceChain` | match → shape → crush → vocode → −16 LUFS (peak ≤ −1 dBFS) |

Compressed formats (Opus/Vorbis):
- php-vio decodes and encodes them from the release that has `vio_audio_formats()`; `AudioFormats::detect()` is the feature test. Older php-vio or php-glfw audio: WAV/FLAC/MP3 only.
- A build step picks with `new AudioEncoder(preferred, settings)`; when the process cannot encode the codec, or the `player` formats it is given cannot decode it, it writes FLAC. A hook that runs on the shipped runtime needs no `player`.
- `VioAudioBackend::load()` resolves the path through `AudioFormats::resolve()`, so a game may ship `x.opus` next to an `x.flac` fallback and keep asking for either name.
- Recommended starting points (a game's choice, not engine presets): speech Opus 24 kHz mono 24–48 kbit/s `voip`; music Vorbis q0.5 or Opus 48 kHz 96–128 kbit/s. Both loop gaplessly.
- Ogg files and `phar://` paths are decoded from memory by php-vio, so compressed audio also plays from inside the PHAR.

Rules:
- **Presets belong to the game.** The engine ships the parameter object, not named
  voices; a game keeps its own factory of profiles.
- **Cache keys:** `VoiceChain::CHAIN_VERSION` is bumped whenever the chain's output
  for the same input, profile and carrier changes; `VoiceProfile::fingerprint()`
  hashes the fields in declared order, so fields are never reordered. A game that
  prebakes audio also hashes everything else that shapes the sound (its carrier,
  the engine version or the DSP sources).
- **Output stability is a contract.** A refactor here must leave rendered samples
  bit-identical (or bump `CHAIN_VERSION`); tests pin behaviour, not bytes, so compare
  renders before and after.

### Speech synthesis — spoken lines from the translation files

`PHPolygon\Audio\Speech` generates voice lines offline: every key of the
`{locale}.json` translation files that matches build.json `speech.keys` is spoken
in each locale with a cloned voice and written as `{output}/{locale}/{key}.flac`
(44.1 kHz mono, `VoiceChain`, −16 LUFS). The generated files are local build
inputs, not Git content: list `output` in `.gitignore` and in `resources.external`.

```json
"speech": {
  "keys": ["dialog.*"],
  "voices": {
    "narrator": { "reference": "resources/voice-ref/narrator.wav" },
    "guide": { "reference": null, "profile": { "pitch": 0.8 } },
    "robot": { "reference": "resources/voice-ref/robot.wav", "keys": ["dialog.robot.*"], "seed": 7, "profile": { "crush": 0.2 },
               "params": { "temperature": 0.6, "cfmSteps": 16, "referenceStart": 2.5, "referenceLength": 8 } }
  },
  "build": "generate", "runner": "auto", "gpu": false,
  "workers": ["cuda", "cuda", "cpu", "ssh://me@mac.local?device=mps"], "device": "auto", "cpuThreads": null,
  "remoteRetries": 5
}
```

```bash
vendor/bin/phpolygon speech:setup                  # Python env in ~/.phpolygon/speech/ (uv or Python 3.10–3.12)
vendor/bin/phpolygon speech:setup --docker [--gpu] # or the Docker image (CPU / CUDA)
vendor/bin/phpolygon speech:setup --remote=ssh://me@mac.local  # environment on a worker host (uv + chatterbox-tts over SSH)
vendor/bin/phpolygon speech:status                 # per locale: current / missing / stale / unsupported / unspeakable
vendor/bin/phpolygon speech:sync [--locale=de,en] [--key=dialog.*] [--check]
```

| Class | What it is |
|---|---|
| `SpeechConfig` / `SpeechVoice` | build.json `speech`; the first voice whose `keys` match speaks a line, else a voice without `keys`. `reference` null or absent = the synthesizer's built-in voice (Chatterbox ships exactly one, `conds.pt`); voices on it differ only by seed, exaggeration, cfg weight, the sampling/decoder `params` and `profile` |
| `SpeechParams` | a voice's fine controls (`speech.voices.<v>.params`, camelCase; unknown keys, wrong types and values out of range are errors). Every field null = the model's default. `FIELDS` is the one table of types, defaults, ranges, groups and worker keys: validation, build.json parsing and `schema()` read it. `toWorker()` = the set fields in snake_case |
| `SpeechSync` | `plan()` compares every line with `speech.lock.json`, `sync()` generates missing/stale lines and saves the manifest after each one (an interrupted run keeps its progress) |
| `SpeechSynthesizerInterface` | `id()` (part of the cache key), `language()` (locale → model language or null), `synthesize()` reporting each job as it finishes |
| `ChatterboxSynthesizer` | Chatterbox Multilingual 0.1.7 (MIT) via `tools/speech/chatterbox_worker.py` (a job without reference restores the built-in voice). `fromOptions($root, $runner, $gpu, $workers, $device, $cpuThreads, $freeMemory, $remoteRetries)` serves tools without build.json `speech`, with the same env overrides as `fromConfig()`. Native env or Docker (`phpolygon-speech:chatterbox-*`, models in volume `phpolygon-speech-models`, `--gpus all` with `gpu`; the CUDA image when `gpu` or a worker is on `cuda`). `workerPlan()` = the workers a run starts (device, CPU threads, remote). `parameterSchema()` = every parameter for editor UIs. 22 of the 31 Steam languages; not bg, cs, hu, id, ro, th, uk, vi, zh-TW |
| `SpeechWorkerPool` | N worker processes at once, one job in flight each, from a shared queue: a worker keeps its reference voice while the queue has more of it (prepare_conditionals is expensive), else takes the largest reference nobody is on; near the end a worker is left idle when the others, at their measured pace, finish the queue first (a CPU worker never holds the tail). stdout of all workers via `stream_select`, `$onResult` per finished job, stderr line-wise as `[wN]` / `[wN user@host]`. A `requeue` worker (remote) that ends while lines are queued is started again after `BACKOFF` (5, 15, 45, then 120 s), up to `$remoteRetries` times in a row without a finished line, without blocking the others; `run(…, $onRestart)` tells the caller (worker index) |
| `SpeechRemote` | a worker host `ssh://user@host[:port][?device=mps&python=/path&workers=2]`: `ssh -o BatchMode=yes -o ServerAliveInterval=15 -o ServerAliveCountMax=8` (a network gap up to ~2 min keeps the connection) runs the engine's own worker script (base64 in the command, so host and engine never differ) in `~/.phpolygon/speech/chatterbox-{version}` with `--inline --require {version}` and `PYTORCH_ENABLE_MPS_FALLBACK=1` |
| `SpeechFiles` | `path()` / `find()` – the game locates a line the way the generator wrote it |

- **Voice parameters** (`ChatterboxSynthesizer::parameterSchema()` / `SpeechParams::schema()`: `{name, type float|int|bool, default, min, max, step, group, description}`):

  | Group | Parameter (build.json → worker job) | Default | Range |
  |---|---|---|---|
  | voice | `seed` (on the voice) | 0 | 0 – 4294967295 |
  | voice | `exaggeration` (on the voice) | 0.5 | 0 – 2 |
  | voice | `cfgWeight` (on the voice) → `cfg_weight` | 0.5 | 0 – 1 |
  | sampling | `temperature` | 0.8 | 0.05 – 2 |
  | sampling | `topP` → `top_p` | 1.0 | 0.01 – 1 |
  | sampling | `minP` → `min_p` | 0.05 | 0 – 1 |
  | sampling | `repetitionPenalty` → `repetition_penalty` | 2.0 | 1 – 3 |
  | sampling | `maxNewTokens` → `max_new_tokens` (25 tokens/s; longer lines are cut) | 1000 | 1 – 4096 |
  | decoder | `cfmSteps` → `cfm_steps` (S3Gen flow-matching steps) | 10 | 1 – 50 |
  | reference | `referenceStart` / `referenceLength` → `reference_start` / `reference_length` (excerpt of the WAV in seconds, cut before conditioning; the speaker embedding hears all of it) | 0 / whole file | 0 – 3600 / 0.5 – 3600 |
  | reference | `promptSeconds` → `prompt_seconds` (T3 speech prompt, `ENC_COND_LEN`; capped at 150 tokens = 6 s) | 6 | 0.5 – 6 |
  | reference | `voiceSeconds` → `voice_seconds` (S3Gen timbre reference, `DEC_COND_LEN`; trained on ≤ 10 s) | 10 | 0.5 – 10 |
  | output | `watermark` | true | bool |

  The reference group needs a `reference` WAV (an error on the built-in voice). Only set fields go into the job line (local and inline/remote alike). **No `speed`:** Chatterbox 0.1.7 has no speaking-rate control (an unimplemented TODO in the model) – use `profile.tempo`. The worker re-implements `ChatterboxMultilingualTTS.generate` of 0.1.7 step by step to reach the hidden knobs (`max_new_tokens` is hard-coded there, `n_cfm_timesteps` is not passed, the watermark is always applied); with no field set the output is bit-identical to `model.generate()` (same seed, verified on CPU). It is pinned to 0.1.7: on another installed version a job that sets one of these fields fails with a clear message, jobs without them run through `model.generate()`. Reference excerpt and windows are part of the worker's conditioning cache key; exaggeration is applied per job onto the cached conditionals, as the model itself does.
- **Cache key** = `SpeechSync::VERSION`, `VoiceChain::CHAIN_VERSION`, synthesizer id, language, text, reference WAV hash (`builtin` for the built-in voice), seed, exaggeration, cfg weight, profile fingerprint, and the set `params` – only when one is set, so voices without params keep their cached lines. Bump `VERSION` when the written files change for the same synthesizer output.
- **Not spoken:** texts with `:placeholders` or plural forms (`a|b`) – only known at runtime – and locales the synthesizer cannot speak. `speech:status` lists them; they never fail a build.
- **Build:** `phpolygon build` runs the sync before the targets: `build: generate` (default) generates what is missing, `check` fails on missing/stale lines, `off` skips. `PHPOLYGON_SPEECH=generate|check|off` overrides; `PHPOLYGON_SPEECH_RUNNER`, `PHPOLYGON_SPEECH_GPU`, `PHPOLYGON_SPEECH_PYTHON`, `PHPOLYGON_SPEECH_WORKERS` (`2` or `cuda,cuda,cpu,ssh://…`), `PHPOLYGON_SPEECH_DEVICE` (auto|cuda|cpu|mps), `PHPOLYGON_SPEECH_CPU_THREADS`, `PHPOLYGON_SPEECH_REMOTE_RETRIES` override the runner. Inside the cross-build container (no Python, no Docker) generate on the host first; the bind-mounted files then pass.
- The worker is a batch process, not a server: `--jobs -` (what the engine uses) reads one JSON job per line from stdin and answers each with one JSON result line until EOF; `--jobs FILE` still runs a whole JSONL file. `--device auto|cuda|cpu|mps` (the native runner passes it too), `--threads N` for CPU workers. Off CUDA every `torch.load` maps to the CPU, so `--device cpu` works without a visible GPU. Seeds are fixed, but CPU, MPS and CUDA still sound slightly different.
- **Parallel workers** (`workers`): Chatterbox generates token by token with batch 1, so one instance leaves a GPU mostly idle; several processes, each with its own model, fill it. An int = that many on `device` (`auto` with `gpu` = cuda), a list = one device per worker. Budget: ~3.3 GiB VRAM per CUDA worker (not checked – an 8 GiB card fits two), system RAM ~4.5 GB per CPU worker and ~2 GB per GPU worker – checked before the start, workers are dropped (CPU first, at least one stays) with a warning when the free RAM is short. `cpuThreads` null = CPU workers share half the logical cores next to GPU workers (those need CPU for their step loop), all cores without them; one worker alone keeps torch's default. No more workers start than there are lines.
- **Docker with workers:** one container per worker (`docker run -i --rm`, own stdin, device and exit code; `--gpus all` except for `cpu` workers) – simpler and as correct as one container with several processes; every container loads its own model.
- **Remote workers** (`ssh://…` in `workers`): SSH must log in without a prompt (key/agent). Reference WAVs travel inline (base64, once per reference and worker, cached on the host by SHA-1 in its temp dir), generated WAVs come back base64 in the result line and are written to the job's path – the rest of the pipeline sees no difference. A remote worker that drops (network, SSH end) puts its line back into the queue for the others (once per line, while another worker can take it) and, while lines are queued, reconnects: new SSH connection, the model loads anew, references go inline again. Backoff 5, 15, 45, then 120 s per attempt (the others work on meanwhile; a pending reconnect is dropped when the queue empties), at most `remoteRetries` (build.json, default 5, env `PHPOLYGON_SPEECH_REMOTE_RETRIES`; 0 = no reconnect) attempts in a row without a finished line – a finished line resets the count; exit 127 (no environment on the host) is not retried. Log: `speech: worker w2 user@host lost (exit 255) – reconnecting in 5 s (attempt 1/5)`, `… reconnected`, `… gave up after 5 attempts`. A local worker that dies fails its line (it may be the line that killed it) and is not restarted (a crash there is a bug, not a network gap). **Out of device memory** is the exception: the worker frees its cache and retries the line once, then exits with code 75 (`SpeechWorkerPool::EXIT_OUT_OF_MEMORY`) without reporting it; it also recycles itself between lines once more than 60 % of the device memory is in tensors (`RECYCLE_AT`; MPS crept to 28 GB over ~500 lines). Exit 75 restarts any worker, local or remote, like a dropped remote one (backoff, `remoteRetries`), and its line goes back to the queue – before, a worker out of MPS memory failed every line it took and drained the queue. Remote errors carry `user@host`. `speech:setup --remote=ssh://user@host` installs the environment on the host (uv, else Python 3.10–3.12; PyPI torch = MPS on macOS, CUDA on Linux) and prints the versions; safe to repeat.
- Chatterbox embeds an inaudible Perth watermark in every file (`params.watermark: false` skips it – only where your own licence and AI-disclosure policy allow it). Steam's content survey asks about AI-generated content.

---

## Splash screen

The engine displays a branded splash screen before `onInit` runs. It shows
"Developed with" above the PHPolygon logo on a black background with fade-in/out.
The active renderer backends are displayed below the logo in grey text
(e.g. "Metal 2D · Metal 3D", "OpenGL 2D · Vulkan").

### Configuration

```php
// Default: splash enabled, 2.5 seconds
$engine = new Engine(new EngineConfig());

// Skip splash (development)
$engine = new Engine(new EngineConfig(skipSplash: true));

// Custom duration
$engine = new Engine(new EngineConfig(splashDuration: 1.5));
```

### Behaviour

- Runs after renderer/font init, before `onInit` callback
- Skipped in headless mode and when `skipSplash: true`
- Logo loaded from `resources/branding/logo.png` (filesystem and PHAR)
- Falls back to text-only rendering if logo file is missing
- Splash texture is unloaded after display
- Closing the window during splash exits cleanly
- `buildRendererInfo()` returns the active backends as a human-readable string

### Studio splash (optional)

Pass a `StudioSplashInterface` implementation via `EngineConfig::$studioSplash`
to play a studio-branding splash **before** the engine's own splash. The engine
drives frame lifecycle (begin/clear/swap/poll) and skip-input (ESC / Enter /
Space / left-click); the implementation only paints into the active frame and
reports its own `getDuration()` + `isSkippable(elapsed)`. Skipped together with
the engine splash in headless mode or when `skipSplash: true`.

```php
$engine = new Engine(new EngineConfig(
    studioSplash: new MyStudioSplash(),  // implements StudioSplashInterface
));
```

Contract (see `src/Branding/StudioSplashInterface.php`):
- `getDuration(): float` — hard cap, engine ends the splash unconditionally
  once this many seconds have elapsed
- `render(Renderer2DInterface $r, float $elapsed): void` — paint one frame.
  Must NOT call `beginFrame`/`endFrame`/`swapBuffers` — engine owns those.
- `isSkippable(float $elapsed): bool` — guards a short opening "sting" from
  being killed by a stray keystroke; once `true`, engine ends on next
  ESC/Enter/Space/left-click.

Engine fonts (`regular`, `semibold`) are loaded **before** the studio splash
starts, so implementations can `setFont('semibold')` without warmup concerns.

### Init progress: single label vs. task checklist

Two compatible ways to surface progress during `onInit`:

```php
// (1) Single progress bar + label - simple linear init
$engine->setSplashProgress(0.4, 'Loading fonts...');
$engine->setSplashProgress(0.8, 'Compiling shaders...');

// (2) Task checklist - granular multi-step init
$engine->setSplashTasks([
    'Loading fonts',
    'Compiling shaders',
    'Building scene graph',
    'Spawning entities',
]);
$engine->advanceSplashTask();                  // marks first done, second active
$engine->advanceSplashTask('Loading PHP');      // override next label dynamically
$engine->completeSplashTasks();                 // mark every remaining task done
```

When `setSplashTasks()` has been called, the renderer draws a left-aligned
checklist between the logo/renderer-info and the progress bar (green square =
done, pulsing white = active, dim grey = pending). The single label below the
bar is suppressed in that mode to avoid duplication. Aufrufer, die ausschließlich
`setSplashProgress()` nutzen, sehen unverändert nur Bar + Label.

`advanceSplashTask()` is safe to call past the end of the list (no-op). The
progress bar auto-fills based on how many tasks are `done`.

### Cooperative init (generators)

`onInit` callbacks may be **generator functions**. Each `yield` is a chunk
boundary — the engine renders one splash frame and pumps window events on
each yield. This keeps `_NET_WM_PING` answered when a single init chunk
would otherwise block the main thread for >5 s, so Linux compositors
(Mutter/KWin) don't flag the window as "not responding" during heavy
startup work (font atlas pre-warming, large content generation, Steam
runtime handshake, etc.) on slow GPUs (e.g. Intel HD 3000 + Mesa).

### Warm rendering — off-screen pre-rasterisation

`Engine::warmRender(callable $renderFn): void` runs a render callback whose
draws are routed into a **private off-screen target that is never presented**.
Used during splash to pre-warm font atlases, sprite textures, and panel
layouts so the first real frame doesn't stutter while glyph rasterisation
catches up.

```php
$engine->warmRender(function () use ($engine, $state) {
    // Inside this block, beginFrame()/endFrame() route into a private
    // VioRenderTarget instead of the swapchain. Glyph atlas + texture
    // uploads survive into the next real frame; the warmed pixels never
    // appear on screen.
    GameApp::renderScene($engine, 1280, 720, $state);
});
```

Contract:
- Target is sized to the current framebuffer (falls back to the renderer's
  logical size if the window isn't initialised yet).
- The `VioRenderTarget` is released (PHP GC) before `warmRender()` returns,
  so the next real frame is unaffected.
- Safe to call repeatedly — every call allocates and releases its own target.
- Safe to call from inside generator-based `onInit` chunks. No `yield` is
  required during warm-render itself.
- Backend behaviour:
  - **vio (primary):** redirects via `vio_bind_render_target`. Genuinely
    off-screen, no flash.
  - **NanoVG / GLFW fallback:** falls back to the swapchain. Brief flash
    possible; acceptable because vio is the shipping path.
  - **NullRenderer2D / GdRenderer2D:** no-op redirect; the callback still
    runs so glyph and texture book-keeping paths execute.

Underlying primitives on the renderer interface:
- `Renderer2DInterface::beginOffscreenFrame(int $w, int $h): void`
- `Renderer2DInterface::endOffscreenFrame(): void`

The interface methods are stateful: between `beginOffscreenFrame()` and
`endOffscreenFrame()`, every `beginFrame()` rebinds the warm target and
every `endFrame()` unbinds it. Mixing the two pairs in the same frame is
undefined; always run `warmRender()` outside of the main game loop's
render call.

```php
$engine->onInit(function (Engine $engine) {
    $engine->setSplashTasks(['Loading fonts', 'Building world']);

    $engine->advanceSplashTask();
    loadFonts();
    yield;                          // splash redraws, events pump

    $engine->advanceSplashTask();
    foreach ($chunkedWork as $chunk) {
        process($chunk);
        yield;                      // pump between chunks
    }

    $engine->completeSplashTasks();
});
```

Backward-compatible: void-returning callbacks bypass the generator branch
entirely and run synchronously as before. The same generator-driving
contract holds on the headless / `skipSplash` path — chunks are iterated
to completion (without rendering between yields).

---

## Character DNA

Procedural humanoid characters are built from an 18-byte / 72-base / 24-codon
strand (`CharacterDNA`). A reflection-driven decoder (`GeneDecoder`) maps each
codon (0..63) through a `GeneMapping` strategy (`ContinuousRange`, `EnumChoice`,
`Palette`) onto a typed trait class. The built-in trait class is
`PlayerProportions` (all 24 loci active). The strand round-trips through a
72-character ACGT string for save games and sharing.

```php
$dna = CharacterDNA::random();
$root = $world->createEntity();
$root->attach(new Transform3D(position: new Vec3(0, 0, 0)));
$root->attach(new CharacterDnaComponent($dna));

$parts = CharacterMeshBuilder::buildOn($world, $root);  // ~80-120 entities
```

`CharacterDnaComponent` is `#[Serializable]` — only the 72-char ACGT field is
persisted; `dna()` / `proportions()` decode lazily on first call and cache.
`CharacterMeshBuilder` is a stateless static helper; `registerDefaults()` is
idempotent and auto-runs on the first `buildOn()` call. Full reference in
**`docs/dna-system.md`**.

### Anti-patterns

- **Do not** rewrite character rig construction inside game code. Always go
  through `CharacterMeshBuilder::buildOn()` so the engine can evolve the mesh
  set + material naming centrally.
- **Do not** change locus assignments on `PlayerProportions`. Existing ACGT
  save strings depend on the codon-at-locus mapping. New traits go on unused
  loci or a `CharacterDNAv2` class — never by reordering the existing v1
  constructor parameters.
- **Do not** extend `STRAND_BYTES` / `STRAND_BASES` on `CharacterDNA` itself.
  Add a versioned subclass instead, and migrate at the component layer.
- **Do not** mutate `CharacterDnaComponent::$acgt` directly. Call `setDna()`
  so the cached `dna()` / `proportions()` are invalidated.
- **Do not** swap `EnumChoice` for `Palette` on a stable locus to insert a
  value in the middle. The modulo wrap shifts every existing strand's
  decoded value. Append at the end of the enum / palette only.

---

## Testing and visual regression testing (VRT)

Three layers: PHPUnit unit tests, headless integration tests
(`Engine(headless: true)` → `Null*` backends), and Playwright-style VRT
against committed snapshots. Test infrastructure lives in `src/Testing/`
(`GdRenderer2D`, `ScreenshotComparer`, `VisualTestCase`,
`NullTextureManager`). Full guide in **`docs/testing.md`**.

```php
class MyGameTest extends TestCase {
    use VisualTestCase;
    public function testMainMenu(): void {
        $renderer = new GdRenderer2D(800, 600);
        $renderer->beginFrame(); /* ... draw ... */ $renderer->endFrame();
        $this->assertScreenshot($renderer, 'main-menu');
    }
}
```

3D scene tests inspect the `RenderCommandList` from `NullRenderer3D` rather
than pixel-comparing (no GPU in CI). Update snapshots with
`PHPOLYGON_UPDATE_SNAPSHOTS=1 vendor/bin/phpunit`.

### Anti-patterns

- **Do not** add VRT tests for legitimately animated content. Mask the
  dynamic region or freeze the time source.
- **Do not** commit `*.actual.png` / `*.diff.png` artefacts - they exist
  only on failure and should be regenerated locally.
- **Do not** mix 3D pixel VRT into headless CI - inspect the
  `RenderCommandList` from `NullRenderer3D` instead.
- **Do not** rely on identical pixel output between `GdRenderer2D` and the
  GPU `Renderer2D`. GD is a structural approximation for layout regression,
  not a reference renderer.

---

## Performance profiling

Dev-only profiling: SPX (`SPX_ENABLED=1`), Excimer (`PHPOLYGON_EXCIMER=1`),
PHPBench micro-benchmarks, and a custom frame-loop runner under `benchmarks/`
with CI gating on > 15% p95 regression. Full guide in **`docs/profiling.md`**.

`src/Runtime/PerfProfiler.php` is the engine-wide section facade. With no
extension active, every call collapses to a single bool check, so markers
are safe to leave in shipping code.

```php
PerfProfiler::section('mesh.generate.box', fn() => BoxMesh::generate(1, 1, 1));
PerfProfiler::begin('render3d.flush');
$this->renderer3d->endFrame();
PerfProfiler::end();
```

Standard section names (use the same when adding new markers):
`engine.update`, `engine.render`, `ecs.update`, `ecs.system.<class>`,
`render3d.build_commands`, `render3d.flush`, `render2d.frame`,
`mesh.generate.<id>`, `texture.upload`, `physics.tick`.

`EngineConfig::$devMode` enables the F3 performance overlay
(`src/UI/PerfOverlay.php`). Independent from SPX/Excimer.

### Performance contract for pull requests

Every PR that touches a hot-path (`src/Math`, `src/Rendering`,
`src/System`, `src/Component/Particle*`) is benchmarked twice by CI -
once on `main`, once on the PR HEAD - and the deltas are surfaced in
the workflow output. Two pipelines run:

1. **Scenario benches** (`.github/workflows/perf-bench.yml::bench`):
   six end-to-end frame-loop scenarios (`empty-scene`, `boxes-1000`,
   `boxes-1000-instanced`, `mixed-scene`, `mesh-gen-stress`,
   `physics-stack`). > 15% p95 regression breaks the build. The
   gated metrics are p50/p95/mean; p99/min/max are reported but
   informational only - p99 is the worst 3 frames of 300, so on
   sub-millisecond scenarios it is dominated by a single GC/scheduler
   hiccup (especially across a runtime bump) and swings 40-60% while
   the real signals stay flat. CI raises the threshold to 30% and
   adds a 0.5ms absolute-delta floor on shared runners.

2. **Micro benches** (`.github/workflows/perf-bench.yml::micro-bench`):
   PHPBench suite under `benchmarks/micro/`, scope-filtered against the
   PR diff. Math changes run `Math/`, Particle changes run `System/`,
   Rendering changes run `Rendering/`. Soft-fail by default (warnings,
   not errors) until the runner-side variance is tight enough to gate.

**The contract**:

- **If you change a hot-path file and no micro-bench covers it, write
  one.** Add a `*Bench.php` under `benchmarks/micro/<area>/` following
  the pattern in `benchmarks/micro/Math/Mat4Bench.php` and
  `benchmarks/micro/System/ParticleStorageBench.php`. The latter
  shows the side-by-side legacy-vs-new pattern for refactor PRs:
  reproduce the old implementation inline so the comparison is on
  the same machine in the same run.

- **Don't claim a perf win without a benchmark.** "Should be faster"
  is wrong as often as it is right - the SoA particle refactor in
  this repo's history was 5x *slower* than the legacy nested-array
  path at small N, despite looking like an obvious win on paper. The
  bench caught it; the PR landed only after the storage was reverted
  and only the render-buffer optimisation kept.

- **Add the path filter.** When you wire up a new bench area, add a
  `src_prefix:bench_subdir` line to the `mapping=()` array in the
  `Detect changed micro-bench scopes` step of `perf-bench.yml`.
  Otherwise the new bench only runs on workflow-file changes, which
  is too coarse to be useful.

### Anti-patterns

- **Do not** ship a build with SPX or Excimer enabled - they are dev-only.
- **Do not** add `PerfProfiler` markers inside per-vertex / per-pixel loops or
  per-component System inner loops - marker overhead distorts measurements
  and pollutes flamegraphs. Mark the calling System instead.
- **Do not** add markers in Components or game-side logic unless explicitly
  profiling that path - prefer instrumenting the calling System.
- **Do not** compare benchmarks across mesh count / scene complexity changes
  without updating the baseline scenario.
- **Do not** profile on battery or under thermal throttle - pin macOS to
  High Performance, otherwise numbers are meaningless.
- **Do not** run benchmarks without warm-up frames - the runner discards
  the first 30-60 frames by default; do not turn that off.
