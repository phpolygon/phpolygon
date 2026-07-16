# Testing and Visual Regression Testing (VRT)

PHPolygon ships with three test layers:

1. **Unit tests** — PHPUnit, no GPU, no window. Run by default in CI.
2. **Headless integration tests** — `Engine` constructed with `headless: true`,
   exercises the full ECS / event / scene / save pipeline against null-object
   backends (`NullWindow`, `NullRenderer2D`, `NullRenderer3D`,
   `NullTextureManager`).
3. **Visual regression tests** — Playwright-style snapshot diffing against
   committed reference images, locally and (for 2D) in CI.

This guide covers integration + VRT. For unit-test conventions, follow the
existing patterns in `tests/` (PSR-4, namespaced under `PHPolygon\Tests\`).

---

## Test infrastructure (`src/Testing/`)

| Class | Purpose |
|---|---|
| `GdRenderer2D` | Software renderer using PHP GD — draws to `GdImage`, no GPU |
| `ScreenshotComparer` | Pixel-level comparison using YIQ colour space (Pixelmatch algorithm) |
| `ComparisonResult` | Result object with `passes()`, tolerances, diff path |
| `VisualTestCase` | PHPUnit trait — Playwright-style `assertScreenshot()` |
| `NullTextureManager` | Headless texture stubs for scene rendering tests |

---

## Backend-agnostic VRT (live engine)

```php
$engine = Engine::initVrt(new EngineConfig(
    title: 'VRT', width: 1280, height: 720, vsync: false,
));
// ... load fonts, render ...
$img = $engine->captureFramebuffer();  // GdImage, works with VIO and GLFW
```

`initVrt()` creates a fully initialised Engine with window, renderer, and
engine fonts. `captureFramebuffer()` returns a `GdImage` using
`vio_read_pixels` (vio) or `glReadPixels` (GLFW). Games should expose a shared
`renderScene()` method so VRT tests exercise the exact same code path as the
live game.

---

## 3D scene testing

3D scenes are tested via `NullRenderer3D` — the command list is inspected
structurally rather than pixel-compared (no GPU in CI):

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

Pixel-level VRT for 3D scenes (OpenGL framebuffer capture) is performed
locally, not in CI headless mode.

### OpenGL version matrix (Docker + Mesa)

The standalone php-glfw OpenGL backend is exercised across every GL version
(3.0 → 4.6) without a GPU using Mesa's `llvmpipe` software rasteriser under
Xvfb, with `MESA_GL_VERSION_OVERRIDE` forcing the reported version per rung.
This validates context creation, GLSL `#version` injection (150 core / 140 /
130), shader compilation and rendering (plain + instanced) at each version —
the CI-friendly substitute for real old-hardware testing. See
`.docker/README.md` (`gl.Dockerfile` + `gl-matrix.sh` + `gl-harness.php`):

```sh
docker build -f .docker/gl.Dockerfile -t phpolygon-gl .
docker run --rm -v "$(pwd)":/app -w /app phpolygon-gl .docker/gl-matrix.sh
```

Mesa's stricter GLSL compiler also catches shader portability bugs that lenient
GPU drivers silently accept (e.g. call-before-definition).

### Native-backend pixel VRT (Metal / D3D / Vulkan) — status & plan

Docker (Linux) can only reach the OpenGL and Vulkan software stacks. Windows
D3D11/D3D12 and macOS Metal render through **vio** and need their own runners:

- **Windows** `windows-latest`: D3D via **WARP** (`D3D_DRIVER_TYPE_WARP`) — vio
  already selects WARP when the context is created with `headless: true`
  (`src/backends/d3d11/vio_d3d11.c`, `d3d12` via `EnumWarpAdapter`). D3D12 +
  Vulkan ship a purpose-built golden-compare `read_pixels` readback.
- **Linux** Vulkan via **lavapipe** (software Vulkan) — same readback path.
- **macOS** `macos-14`: Metal on the runner's real GPU.

The engine primitive this needs is a `renderToImage(RenderCommandList, w, h)` on
`VioRenderer3D` (frame bracket + `vio_read_pixels`), then encode/compare with the
existing `ScreenshotComparer`, with **per-backend baselines** (Metal ≠ WARP ≠
lavapipe — like the font platform suffix).

**Blockers (why this is not wired into CI yet):**
1. **vio Metal headless readback is broken.** In a headless context `vio_read_pixels`
   returns a uniform buffer with `vsync: true` and segfaults with `vsync: false`.
   `vio_metal_setup_context_native()` also requires a non-NULL `CAMetalLayer`, so
   there is no layer-less offscreen path; the hidden-window render/present/readback
   interaction needs fixing in `php-vio` (`src/backends/metal/vio_metal.m`).
   D3D12/Vulkan readback are unaffected.
2. **Building php-vio on the CI runners** (it bundles Metal/D3D/Vulkan +
   SPIRV-Cross) is a large per-platform build, heavier than the php-glfw build.

Until both land, native-backend pixel VRT stays out of CI to avoid red,
unverifiable jobs. The Docker OpenGL matrix above is the shipping pixel-capable
path; `GdRenderer2D` covers 2D layout regression.

---

## VRT workflow (Playwright-style, 2D)

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

- **First run:** saves reference screenshot → test passes.
- **Subsequent runs:** compares against reference → fails on visual diff.
- **Update snapshots:** `PHPOLYGON_UPDATE_SNAPSHOTS=1 vendor/bin/phpunit`.

### Snapshot file structure

```
tests/MyTest.php
tests/MyTest.php-snapshots/
├── main-menu.png                    ← reference (no platform suffix by default)
├── main-menu.actual.png             ← only on failure
└── main-menu.diff.png               ← only on failure (red = mismatch)
```

Default: **no platform suffix**. Override `usePlatformSuffix() → true` for
font-dependent tests, which produces `name-gd-darwin.png` /
`name-gd-linux.png`.

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

---

## Fonts

```php
// Place .ttf files in resources/fonts/
$renderer->loadFont('inter', 'resources/fonts/Inter-Regular.ttf');
$renderer->setFont('inter');
$renderer->drawText('Score: 42,000', 20, 20, 24, Color::white());
```

Works identically for `Renderer2D` (NanoVG) and `GdRenderer2D` (GD/FreeType).
Font rendering may differ between platforms — use `usePlatformSuffix() → true`
for font-dependent VRT tests.

---

## GdRenderer2D capabilities

The GD software renderer supports: filled/outlined rectangles, rounded
rects, circles, lines, text (TrueType via `imagettftext`), centered text,
word-wrapped text, transform stack (`pushTransform` / `popTransform` via
`Mat3`), scissor stack, and sprite placeholders (grey rectangles with
outlines for textures).

It does **not** produce pixel-identical output to the OpenGL `Renderer2D`
— it is a structural approximation for layout and regression testing, not
a reference renderer.

---

## Anti-patterns

- **Do not** add VRT tests for content that legitimately changes every frame
  (animated UI, dynamic numbers). Mask the region or freeze the time source.
- **Do not** commit `*.actual.png` / `*.diff.png` artefacts — they only
  exist on failure and should be regenerated locally.
- **Do not** mix 3D pixel VRT into headless CI — there is no GPU there;
  inspect the `RenderCommandList` instead.
- **Do not** rely on identical pixel output between `GdRenderer2D` and the
  GPU `Renderer2D`. Use GD only for layout / regression checks.
