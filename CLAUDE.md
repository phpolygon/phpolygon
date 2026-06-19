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
- **2D:** php-vio (primary) or OpenGL 4.1 via php-glfw / NanoVG (fallback)
- **3D:** php-vio, Vulkan via php-vulkan, Metal via MoltenVK, or OpenGL 4.1 via
  php-glfw — all behind a unified `Renderer3DInterface` / `RenderCommandList`

The engine auto-detects php-vio at startup. When available, it provides the window,
input, audio, 2D renderer, 3D renderer, and texture manager through a single
unified backend. When php-vio is not loaded, the engine falls back to php-glfw
(2D/3D) or php-vulkan (3D).

Games are built in separate repositories and require `phpolygon/phpolygon`
via Composer.

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
VioRenderer3D   OpenGLRenderer3D   VulkanRenderer3D   MetalRenderer3D   NullRenderer3D
(primary)       (fallback)         (native Vulkan)    (MoltenVK/macOS)  (headless/tests)
```

**Do not design `Renderer3DInterface` around the OpenGL state-machine model.**
The OpenGL 3D backend *emulates* command buffers — it iterates the command list
and issues the necessary GL calls internally. Vulkan natively maps to this model.

### RenderCommandList — available commands

All commands are plain PHP value objects (no methods, only constructor properties):

| Command class | Purpose |
|---|---|
| `SetCamera` | `viewMatrix: Mat4`, `projectionMatrix: Mat4` |
| `SetAmbientLight` | `color: Color`, `intensity: float` |
| `SetDirectionalLight` | `direction: Vec3`, `color: Color`, `intensity: float` |
| `AddPointLight` | `position: Vec3`, `color: Color`, `intensity: float`, `radius: float` |
| `DrawMesh` | `meshId: string`, `materialId: string`, `modelMatrix: Mat4` |
| `DrawMeshInstanced` | `meshId: string`, `materialId: string`, `matrices: Mat4[]` |
| `SetSkybox` | `cubemapId: string` |
| `SetFog` | `color: Color`, `near: float`, `far: float` |
| `SetShader` | `shaderId: ?string` — override active shader for subsequent draws; `null` resets to material-driven |

Commands are appended to `RenderCommandList` during the scene tick. The
`Renderer3DSystem` flushes the list once per frame.

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
| OpenGL 4.1 via php-glfw (2D/NanoVG) | Fallback | 2D games when php-vio unavailable |
| OpenGL 4.1 via php-glfw (3D) | Fallback | 3D games when php-vio unavailable |
| Vulkan via php-vulkan | Standalone | 3D native Vulkan backend, used when vio is not loaded |
| Metal via php-metal | Standalone | 3D native Metal backend on macOS, used when vio is not loaded |

vio is the production path on every platform. The standalone Metal / Vulkan / OpenGL
backends exist for environments where vio is unavailable (older PHP builds, CI
without GPU, headless tooling) or when a game explicitly opts into a native
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

`Transform3D` replaces `Transform2D` in 3D scenes. Never mix 2D and 3D transform
components on the same entity.

### 3D Systems

| System | Purpose |
|---|---|
| `Renderer3DSystem` | Collects `MeshRenderer` + `Transform3D`, builds `RenderCommandList`, flushes |
| `Camera3DSystem` | Updates view/projection matrices, pushes `SetCamera` command |
| `Physics3DSystem` | Capsule vs AABB collision, gravity integration (Phase 7+) |

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
| php-glfw | OpenGL 4.1 + NanoVG (2D and 3D rendering) | Fallback when php-vio unavailable |
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
codetycoon          ← native launcher binary (C/Go/Rust)
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
| `Material::$wetness` | Forward-renderer SSR surrogate. Up-facing fragments get smoother + darker + brighter-IBL pass. |
| `Material::$clearcoat` + `$flakes` | Carpaint extras consumed by `proc_mode == 10`. `Material::carpaint()` factory wires them in. |
| `ScreenSpaceAO` | Curvature-based AO via `dFdx(N)`. Tiers `Off/Low/Medium/High`. Shader uniform `u_ao_strength`. |
| `ColorGradingPreset` | Lift/Gamma/Gain + saturation. Six presets. Applied in linear space before ACES. |
| `GraphicsSettings::$vignetteIntensity` | Radial darkening evaluated against `gl_FragCoord / u_viewport_size`. |
| `GraphicsSettings::$volumetricFog` | 8-step in-shader ray-march with sun-aligned phase function. Independent from `SetFog`. |
| ACES tone mapping | Every mesh-shader exit path runs `toneMapACES()` before gamma. Vio's HDR tonemap pass uses the same curve. |
| Camera-following shadow + texel-snap | `ShadowMapRenderer::updateLightMatrix($dir, $cameraTarget)` and the equivalent on Vio centre the shadow frustum on the camera and snap to the shadow-map grid. |
| `AreaLightHelper` | Forward-pipeline rectangular area light = grid of point-light samples summing to total radiance. |
| `ParticleEmitter` + `ParticleSystem` | Inline particle storage, single `DrawMeshInstanced` per emitter per frame. Camera-facing billboard rotation in render(). |
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
```

### 7-phase pipeline

1. **Vendor** — `composer update --no-dev` (restored after build)
2. **Stage** — copy src/, vendor/, assets/, resources/ into temp dir, resolve
   symlinks, exclude tests/docs/editor via glob patterns
3. **PHAR** — create game.phar with a custom stub that handles micro SAPI
   detection, macOS .app bundle paths, resource extraction, and engine bootstrap
4. **micro.sfx** — resolve static PHP binary (explicit path → cache
   `~/.phpolygon/build-cache/` → download from GitHub Release)
5. **Combine** — concatenate micro.sfx + game.phar into single executable
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
  "resources": { "external": ["resources/audio"] },
  "platforms": {
    "macos": { "icon": "icon.icns", "minimumVersion": "12.0" }
  }
}
```

### Build classes

| Class | Purpose |
|---|---|
| `BuildConfig` | Loads build.json + composer.json, provides all settings |
| `PharBuilder` | Stages sources, builds PHAR with custom stub |
| `StaticPhpResolver` | Finds/downloads/caches micro.sfx binary |
| `PlatformPackager` | Creates .app bundle, Linux dir, Windows .exe |
| `GameBuilder` | Orchestrates the 7-phase pipeline |

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
| `VioRenderer3D` | `OpenGLRenderer3D` / `VulkanRenderer3D` / `MetalRenderer3D` | `NullRenderer3D` (no-op) |
| `VioTextureManager` | `TextureManager` (GL) | `NullTextureManager` (dummy) |
| `VioAudioBackend` | `GLFWAudioBackend` | `null` (no audio) |
| `VioInput` | `Input` (GLFW callbacks) | `Input` (no-op) |

The `headless` flag in `EngineConfig` switches all backends automatically.

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
