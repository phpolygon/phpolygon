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
- **2D:** OpenGL 4.1 via php-glfw / NanoVG — active, all phases
- **3D (Phase 7):** OpenGL 4.1 via php-glfw — first 3D backend, validates the
  `RenderCommandList` abstraction
- **3D (Phase 8):** Vulkan via php-vulkan — high-performance backend, drop-in
  replacement behind the same interface

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
- `Renderer2DInterface extends RenderContextInterface` — NanoVG backend for 2D games
- `Renderer3DInterface extends RenderContextInterface` — 3D backend (OpenGL or Vulkan)

`Renderer3DInterface` is driven by a **RenderCommandList** from day one. PHP builds
the command list; the backend executes it. This keeps game code fully backend-agnostic:

```
Game Code / Scene
      ↓  (builds)
RenderCommandList        ← pure PHP data, no GPU calls
      ↓  (executed by)
┌──────────────────┬──────────────────┬──────────────────┐
OpenGLRenderer3D   VulkanRenderer3D   NullRenderer3D
(Phase 7, active)  (Phase 8, planned)  (headless / tests)
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
| OpenGL 4.1 via php-glfw (2D/NanoVG) | Active | 2D games, all phases |
| OpenGL 4.1 via php-glfw (3D) | **Phase 7 — active development** | 3D games, first backend |
| Vulkan via php-vulkan | Phase 8 | 3D games, production backend |
| D3D11 / D3D12 | Cancelled | — |
| Metal | Not planned | — |

**D3D is permanently out of scope.** Do not add D3D stubs, interfaces, or comments.
Vulkan covers Windows natively; MoltenVK covers macOS.

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
| php-glfw | OpenGL 4.1 + NanoVG (2D and 3D rendering) | Active |
| php-vulkan | Vulkan (3D production backend) | Available, Phase 8 |
| php-steamworks | Steamworks SDK integration | Published on Packagist |

When writing engine code that touches GPU, Steam, or audio — use the extension.
Do not wrap extension calls in FFI unless there is an explicit reason.

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

| Normal mode | Headless mode |
|---|---|
| `Window` (GLFW) | `NullWindow` (no-op, configurable dimensions) |
| `Renderer2D` (NanoVG/OpenGL) | `NullRenderer2D` (accepts all draws, no output) |
| `Renderer3D` (OpenGL/Vulkan) | `NullRenderer3D` (accepts all commands, no output) |
| `TextureManager` (GL textures) | `NullTextureManager` (dummy textures with dimensions) |

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

## Testing and visual regression testing (VRT)

### Test infrastructure (`src/Testing/`)

| Class | Purpose |
|---|---|
| `GdRenderer2D` | Software renderer using PHP GD — draws to `GdImage`, no GPU |
| `ScreenshotComparer` | Pixel-level comparison using YIQ color space (Pixelmatch algorithm) |
| `ComparisonResult` | Result object with `passes()`, tolerances, diff path |
| `VisualTestCase` | PHPUnit trait — Playwright-style `assertScreenshot()` |
| `NullTextureManager` | Headless texture stubs for scene rendering tests |

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
