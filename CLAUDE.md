# CLAUDE.md — PHPolygon Engine

PHP-native game engine with AI-first authoring. This file governs how Claude Code
works in this repository. Read it fully before writing any code.

---

## Engine identity

**PHPolygon** is a PHP-native game engine built on top of VISU (forked from
phpgl/visu). The primary authoring tool is Claude Code. The primary render backend
is OpenGL 4.1 via php-glfw/NanoVG for 2D, Vulkan via php-vulkan for 3D (Phase 6).

Active games on this engine:
- **Code Tycoon** — 2D business simulation, Phase 1–5 target
- **Netrunner: Uprising** — 3D cyberpunk RPG, Phase 6 target

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
- `Renderer2D extends RenderContextInterface` — NanoVG backend, used by Code Tycoon
- `Renderer3D extends RenderContextInterface` — Vulkan backend, used by Netrunner

`Renderer3D` uses a **Command Buffer abstraction** from day one. PHP builds a
`RenderCommandList`; the backend (Vulkan) executes it. The OpenGL 3D backend
(if ever needed) emulates command buffers. Do not design `Renderer3D` around
the OpenGL state-machine model.

### GPU backends
| Backend | Status | Target |
|---|---|---|
| OpenGL 4.1 via php-glfw | Active | Code Tycoon (2D), all phases |
| Vulkan via php-vulkan | Phase 6 | Netrunner: Uprising (3D) |
| D3D11 / D3D12 | Cancelled | — |
| Metal | Not planned | — |

**D3D is permanently out of scope.** Do not add D3D stubs, interfaces, or comments.
Vulkan covers Windows natively; MoltenVK covers macOS.

### Shaders
- Authoring language: **GLSL** (human- and AI-readable plaintext)
- Compiled to **SPIR-V** at build time via `glslangValidator` or `shaderc`
- SPIR-V binaries are committed to `assets/shaders/compiled/`
- Claude Code writes GLSL; the build step produces SPIR-V. Never write SPIR-V by hand.

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
| Component classes | Noun, no suffix | `MeshRenderer`, `BoxCollider2D` |
| System classes | Noun + `System` | `EconomySystem`, `PhysicsSystem` |
| Events | Past tense noun | `EntitySpawned`, `SceneLoaded` |
| Interfaces | `*Interface` | `RenderContextInterface` |
| JSON scene files | `snake_case.scene.json` | `main_menu.scene.json` |
| PHP scene files | `PascalCase.php` | `MainMenu.php` |
| Shader source | `name.vert.glsl` / `name.frag.glsl` | `terrain.vert.glsl` |
| Compiled shaders | `name.vert.spv` / `name.frag.spv` | `terrain.vert.spv` |

---

## Anti-patterns — never do these

- **Do not** put cross-entity logic in a Component method.
- **Do not** implement `toJson()` / `fromJson()` manually on Components — use Attributes.
- **Do not** design `Renderer3D` around OpenGL state-machine patterns.
- **Do not** add D3D, Metal, or Vulkan stubs inside `Renderer2D`.
- **Do not** start a built-in HTTP server for editor communication — the editor has
  direct filesystem access.
- **Do not** modify `game.phar` or compiled SPIR-V by hand.
- **Do not** store runtime game state in PHP files — use JSON.
- **Do not** use FFI for frame-critical calls (e.g. `SteamAPI_RunCallbacks()`) —
  use native C-extensions.

---

## C-extensions (available, do not reimplement in PHP)

| Extension | Purpose | Status |
|---|---|---|
| php-glfw | OpenGL 4.1 + NanoVG (2D rendering) | Active |
| php-vulkan | Vulkan (3D rendering) | Available, Phase 6 |
| php-steamworks | Steamworks SDK integration | Published on Packagist |

When writing engine code that touches GPU, Steam, or audio — use the extension.
Do not wrap extension calls in FFI unless there is an explicit reason.

---

## Distribution model

```
codetycoon          ← native launcher binary (C/Go/Rust)
runtime/php         ← static PHP binary (static-php-cli, includes all extensions)
game.phar           ← engine + game logic, Opcache bytecode, not human-readable
assets/             ← open: sprites, sounds, JSON scenes, UI layouts
saves/              ← user data: JSON save files
mods/               ← open: PHP + assets, scanned by ModLoader
```

- Game core ships as PHAR with Opcache bytecode (comparable protection to C# IL).
- `mods/` is intentionally open — modders and Claude Code use the same tools.
- Build target: macOS `.app`/DMG, Linux AppImage, Windows installer, Steam depot.

---

## AI authoring workflow

Claude Code is the primary authoring tool. When generating content:

1. **Scenes** — write PHP files (canonical). JSON is derived by the transpiler.
2. **Components** — PHP classes with `#[Component]` attribute, Lifecycle hooks.
3. **UI layouts** — JSON (transpiled to PHP at dev time, zero runtime parser overhead).
4. **Game logic** — PHP Systems and Components.
5. **Shaders** — GLSL source files in `assets/shaders/source/`.
6. **Physics materials** — JSON definitions in `assets/physics/`.
7. **Mods** — `mod.json` + PHP class implementing `ModInterface` + assets.

Every generated file is a Git commit. Every step is reviewable. No black-box state.
