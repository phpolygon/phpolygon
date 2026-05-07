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

Shader::available();           // ['default', 'unlit', 'normals', 'depth', 'shadow', 'skybox']
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
| php-vio (2D + 3D unified) | **Primary** | All platforms when php-vio is loaded |
| OpenGL 4.1 via php-glfw (2D/NanoVG) | Fallback | 2D games when php-vio unavailable |
| OpenGL 4.1 via php-glfw (3D) | Fallback | 3D games when php-vio unavailable |
| Vulkan via php-vulkan | Active | 3D native Vulkan backend |
| Metal via MoltenVK | Active | 3D on macOS via MoltenVK |
| D3D11 / D3D12 | Cancelled | — |

**D3D is permanently out of scope.** Do not add D3D stubs, interfaces, or comments.
Vulkan covers Windows natively; MoltenVK covers macOS.

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

---

## Testing and visual regression testing (VRT)

### Test infrastructure (`src/Testing/`)

| Class | Purpose |
|---|---|
| `GdRenderer2D` | Software renderer using PHP GD — draws to `GdImage`, no GPU |
| `ScreenshotComparer` | Pixel-level comparison using YIQ color space (Pixelmatch algorithm) |
| `ComparisonResult` | Result object with `passes()`, tolerances, diff path |
| `VisualTestCase` | PHPUnit trait — Playwright-style `assertScreenshot()` |
| `NullTextureManager` | Headless texture stubs for scene rendering tests |

### Backend-agnostic VRT

```php
$engine = Engine::initVrt(new EngineConfig(
    title: 'VRT', width: 1280, height: 720, vsync: false,
));
// ... load fonts, render ...
$img = $engine->captureFramebuffer();  // GdImage, works with VIO and GLFW
```

`initVrt()` creates a fully initialized Engine with window, renderer, and
engine fonts. `captureFramebuffer()` returns a `GdImage` using `vio_read_pixels`
(VIO) or `glReadPixels` (GLFW). Games should use a shared `renderScene()` method
so VRT tests exercise the exact same code path as the live game.

### 3D scene testing

3D scenes are tested via `NullRenderer3D` — the command list is inspected
structurally rather than pixel-compared (no GPU available in CI):

```php
public function testPhpDistrictBuildsCorrectly(): void
{
    $engine = $this->create3DTestEngine();
    $engine->scenes->load(PhpDistrictScene::class);
    $engine->tick(0.016);

    $commands = $engine->renderer3d->getLastCommandList();
    $draws = $commands->ofType(DrawMesh::class);

    $this->assertCount(20, $draws); // 20 buildings
    $this->assertSame('cobblestone', $draws[0]->materialId);
}
```

Pixel-level VRT for 3D scenes (OpenGL framebuffer capture) is performed locally,
not in CI headless mode.

### VRT workflow (Playwright-style, 2D)

```php
class MyGameTest extends TestCase {
    use VisualTestCase;

    public function testMainMenu(): void {
        $renderer = new GdRenderer2D(800, 600);
        $renderer->beginFrame();
        // ... draw scene ...
        $renderer->endFrame();

        $this->assertScreenshot($renderer, 'main-menu');
    }
}
```

- **First run:** saves reference screenshot → test passes
- **Subsequent runs:** compares against reference → fails on visual diff
- **Update snapshots:** `PHPOLYGON_UPDATE_SNAPSHOTS=1 vendor/bin/phpunit`

### Snapshot file structure

```
tests/MyTest.php
tests/MyTest.php-snapshots/
├── main-menu.png                    ← reference (no platform suffix by default)
├── main-menu.actual.png             ← only on failure
└── main-menu.diff.png               ← only on failure (red = mismatch)
```

Default: **no platform suffix**. Override `usePlatformSuffix()` → `true` for
font-dependent tests, which produces `name-gd-darwin.png` / `name-gd-linux.png`.

### Comparison parameters

```php
$this->assertScreenshot($renderer, 'name',
    threshold: 0.1,          // per-pixel YIQ tolerance (0.0–1.0)
    maxDiffPixels: 50,       // absolute pixel count tolerance
    maxDiffPixelRatio: 0.01, // ratio tolerance (1% of pixels)
    mask: [                  // ignore dynamic regions (filled magenta)
        ['x' => 10, 'y' => 10, 'w' => 100, 'h' => 20],
    ],
);
```

### Fonts

```php
// Place .ttf files in resources/fonts/
$renderer->loadFont('inter', 'resources/fonts/Inter-Regular.ttf');
$renderer->setFont('inter');
$renderer->drawText('Score: 42,000', 20, 20, 24, Color::white());
```

Works identically for `Renderer2D` (NanoVG) and `GdRenderer2D` (GD/FreeType).
Font rendering may differ between platforms — use `usePlatformSuffix() → true`
for font-dependent VRT tests.

### GdRenderer2D capabilities

The GD software renderer supports: filled/outlined rectangles, rounded rects,
circles, lines, text (TrueType via `imagettftext`), centered text, word-wrapped
text, transform stack (`pushTransform`/`popTransform` via Mat3), scissor stack,
and sprite placeholders (grey rectangles with outlines for textures).

It does **not** produce pixel-identical output to the OpenGL `Renderer2D` — it is
a structural approximation for layout and regression testing, not a reference
renderer.

---

## Performance profiling

PHPolygon has dev-only profiling support. Full guide: `docs/profiling.md`.

### Tools

| Tool | Purpose | Activation |
|---|---|---|
| **SPX** (C-ext) | Deep-dive flamegraphs during a dev session | `SPX_ENABLED=1` |
| **Excimer** (C-ext) | Low-overhead sampling for CI regression | `PHPOLYGON_EXCIMER=1` |
| **PHPBench** (Composer) | Micro-benchmarks for hot leaf functions (Mat4, Quaternion, MeshGen) | `vendor/bin/phpbench` |
| **Custom benchmark runner** | Frame-loop scenarios over the full Engine | `php benchmarks/run.php <scenario>` |

SPX and Excimer are PHP C-extensions and cannot be installed via Composer.
Use `scripts/install-profilers.sh`. PHPBench is a regular dev dependency.

### `PerfProfiler` - section markers

`src/Runtime/PerfProfiler.php` is the engine-wide profiling facade. When no
profiler extension is active, every call collapses to a single bool check.

```php
use PHPolygon\Runtime\PerfProfiler;

PerfProfiler::section('mesh.generate.box', fn() => BoxMesh::generate(1, 1, 1));

PerfProfiler::begin('render3d.flush');
$this->renderer3d->endFrame();
PerfProfiler::end();
```

Standard section names: `engine.update`, `engine.render`, `ecs.update`,
`ecs.system.<class>`, `render3d.build_commands`, `render3d.flush`,
`render2d.frame`, `mesh.generate.<id>`, `texture.upload`, `physics.tick`.
`engine.update` may fire multiple times per frame (fixed-timestep loop);
`engine.render` fires once per visible frame. Use the same naming when you
add new markers.

### When to add markers

- Around any cross-system or per-frame work expected to be hot
- Around procedural mesh generation (typically one-shot but can spike)
- Around expensive per-frame asset I/O

### When NOT to add markers

- **Inside tight inner loops** (per-vertex, per-pixel, per-component-in-system).
  The marker overhead distorts measurements and pollutes flamegraphs.
- **In Components or game-side logic** unless explicitly profiling that path -
  prefer instrumenting the calling System.

### `EngineConfig::$devMode`

`devMode: true` enables developer-only features such as the F3 performance
overlay (Phase 2 work). It is independent from the SPX/Excimer extensions,
which are activated via env vars and need no flag.

### Benchmarks

Frame-loop scenarios live in `benchmarks/scenarios/` and run via
`php benchmarks/run.php <scenario>`. Results are JSON in
`benchmarks/results/<git-sha>.json` with p50/p95/p99 frame time per
`PerfProfiler` section, plus a per-scenario GC histogram (`gc_status()`
delta sampled per frame).

Baselines in `benchmarks/baselines/` are checked in. CI fails on > 15% p95
regression. Update baselines deliberately via
`php benchmarks/run.php <scenario> --accept` in the same PR.

PHPBench micro-benchmarks live in `benchmarks/micro/` and target hot leaf
code that benefits from rev/iteration statistics (`Mat4::multiply`,
`Quaternion::slerp`, `BoxMesh::generate`).

### Anti-patterns

- **Do not** ship a build with SPX or Excimer enabled - they are dev-only.
- **Do not** add `PerfProfiler` markers inside per-vertex / per-pixel loops.
- **Do not** compare benchmarks across mesh count / scene complexity changes
  without updating the baseline scenario.
- **Do not** profile on battery or under thermal throttle - pin macOS to
  High Performance, otherwise numbers are meaningless.
- **Do not** run benchmarks without warm-up frames - the first 30-60 frames
  are always slower (JIT, class loading, texture upload). The runner discards
  them by default; do not turn that off.
