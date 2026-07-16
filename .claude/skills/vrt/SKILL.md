---
name: vrt
description: PHPolygon Visual Regression Testing (VRT) & Test-Infrastruktur — 2D-Pixel-Snapshots (GdRenderer2D), Headless-3D-CommandList-Tests (NullRenderer3D), native 3D-Pixel-VRT (MetalRenderer3D/VioRenderer3D renderToImage), Docker-OpenGL-Versionsmatrix, GameTestCase fuer Spiele, Snapshot-Workflow. Nutze diesen Skill bei jeder Aenderung an Tests, Snapshots, VRT-Infrastruktur oder wenn Rendering visuell abgesichert werden soll.
---

# Skill: Visual Regression Testing (VRT) & Test-Infrastruktur

Verbindliche Anleitung fuer Tests in PHPolygon. Vollreferenz: `docs/testing.md`.
Infrastruktur liegt in `src/Testing/`, Docker-Images in `.docker/`.

## Drei Test-Ebenen

1. **Unit-Tests** — PHPUnit, reine Logik (Math, ECS, Value Objects).
2. **Headless-Integration** — `Engine(headless: true)` → `Null*`-Backends, kein GPU/Display. ECS, Szenen, Events, Save-Games, und die **3D-`RenderCommandList`** pruefbar.
3. **VRT** — Pixel-Snapshot-Diffing gegen committete Referenzen.

## Grundregeln (immer beachten)

- **Kein GPU/Display in CI-Unit-Tests** → `headless: true` + `Null*`-Backends.
- **3D wird ueber die CommandList geprueft**, nicht pixelweise (kein GPU in CI): `NullRenderer3D::getLastCommandList()` inspizieren.
- **2D-Pixel-VRT** laeuft ueber `GdRenderer2D` (Software-Rasterizer, GPU-frei).
- **Font-Snapshots sind deterministisch nur im Alpine-Container** → siehe Docker.
- **Snapshots aktualisieren:** `PHPOLYGON_UPDATE_SNAPSHOTS=1 vendor/bin/phpunit`.
- **Niemals** `*.actual.png` / `*.diff.png` committen (nur bei Fehlern, lokal regenerieren).
- **Niemals** VRT fuer animierte Inhalte ohne Maskierung/eingefrorene Zeitquelle.

---

## 2D-Pixel-VRT (`VisualTestCase`)

```php
use PHPolygon\Testing\GdRenderer2D;
use PHPolygon\Testing\VisualTestCase;

class MyVisualTest extends \PHPUnit\Framework\TestCase
{
    use VisualTestCase;

    public function testPanel(): void
    {
        $r = new GdRenderer2D(400, 300);
        $r->beginFrame();
        $r->clear(new Color(0.1, 0.1, 0.15));
        $r->drawRect(20, 20, 120, 80, new Color(0.9, 0.2, 0.2));
        $r->endFrame();

        // Erste Ausfuehrung: speichert Referenz + besteht (Playwright-Verhalten).
        // Danach: Diff gegen Referenz, faellt bei Abweichung.
        $this->assertScreenshot($r, 'panel', maxDiffPixelRatio: 0.001);
    }
}
```

- Snapshots liegen neben dem Test: `MyVisualTest.php-snapshots/panel.png`.
- Toleranzen: `threshold` (YIQ-Farbschwelle), `maxDiffPixels`, `maxDiffPixelRatio`.
- `mask: [['x'=>,'y'=>,'w'=>,'h'=>]]` blendet dynamische Regionen aus.
- **Fonts:** `@group font-vrt` setzen (Host-FreeType weicht ab → im Container erzeugen).
- Szenen bequem rendern: `renderScene(SceneClass::class, 'name')` / `createVisualTestEngine()`.

## Headless-3D-Tests (CommandList)

```php
$engine = new Engine(new EngineConfig(headless: true, is3D: true));
// ... Szene laden + Systeme wiren, dann:
$engine->world->render();
$commands = $engine->renderer3D->getLastCommandList(); // NullRenderer3D-Snapshot
$this->assertCount(20, $commands->ofType(DrawMesh::class));
```

`NullRenderer3D` nimmt vor dem post-render `clear()` einen Snapshot → Assertions
ueberleben. Query-API: `getCommands()`, `ofType()`, `lastOfType()`, `count()`.

## Test-Basisklasse fuer Spiele: `GameTestCase`

Fuer Spiele, die `phpolygon/phpolygon` einbinden (im shipped Autoload):

```php
final class WorldTest extends \PHPolygon\Testing\GameTestCase
{
    protected function registerScenes(Engine $e): void {
        $e->scenes->register('overworld', OverworldScene::class);
    }
    public function testSpawn(): void {
        $this->loadScene('overworld');
        $this->assertEntityExists('overworld', 'Player');
        $this->tick();
        $this->assertDrawsMesh('player_body');
    }
}
```

Bietet: Headless-Engine-Lifecycle, `loadScene()`/`tick()`/`renderCommands()`,
`assertEntityExists/Count`, `assertSceneLoaded`, `assertDrawsMesh`,
`assertMeshDrawCount`, `assertPointLightCount`. Bindet `VisualTestCase` fuer 2D ein.
Config via `engineConfig()` ueberschreiben (2D-only / Groesse).

---

## Native 3D-Pixel-VRT (echter GPU-Readback)

Fuer echte 3D-Pixel gibt es `renderToImage()` — rendert headless offscreen und
liest den Framebuffer als RGBA zurueck. Vergleich ueber `assertRgbaScreenshot()`
mit **per-Backend-Baseline** (Metal ≠ WARP ≠ lavapipe teilen nie eine Referenz).

**macOS / Metal** (`ext-metal` / php-metal-gpu, voll verifiziert):
```php
$r = new MetalRenderer3D($w, $h, 0);           // Handle 0 = headless
$rgba = $r->renderToImage($commandList, $w, $h, new Color(0.1, 0.5, 0.9));
$this->assertRgbaScreenshot($rgba, $w, $h, 'scene', 'metal', maxDiffPixelRatio: 0.03);
```

**Generisch / vio** (D3D11/D3D12/Vulkan/OpenGL):
```php
$ctx = vio_create('auto', ['width'=>$w,'height'=>$h,'headless'=>true,'vsync'=>false]);
$rgba = (new VioRenderer3D($ctx, $w, $h))->renderToImage($commandList, $w, $h, $clear);
```

Regeln & Grenzen:
- Test mit `#[RequiresPhpExtension('metal')]` / `#[RequiresPhpExtension('vio')]`
  (+ `#[RequiresOperatingSystem('Darwin')]` fuer Metal) gaten → skippt sonst.
- **Geometrie** rendert nur auf Backends mit verdrahteter 3D-Pipeline:
  D3D12 / Vulkan / OpenGL. **vio-Metal-3D ist gestubbt** → dort kommt nur die
  Clear-Farbe zurueck (macOS 3D laeuft ueber den nativen `MetalRenderer3D`).
- GPU-Output variiert je Modell → grosszuegige Toleranz statt exaktem Pixelmatch;
  bevorzugt strukturelle Asserts (Ecke = Clear, Zentrum = Geometrie) wo moeglich.
- Beispiele: `tests/Rendering/MetalRenderToImageTest.php`, `VioRenderToImageTest.php`.

## Docker-OpenGL-Versionsmatrix (GL 3.0–4.6, GPU-frei)

Der Standalone-php-glfw-Backend wird ueber Mesa `llvmpipe` + Xvfb gegen **jede**
GL-Version geprueft (`MESA_GL_VERSION_OVERRIDE` erzwingt sie pro Stufe):

```sh
docker build -f .docker/gl.Dockerfile -t phpolygon-gl .
docker run --rm -v "$(pwd)":/app -w /app phpolygon-gl .docker/gl-matrix.sh
```

Validiert Context-Erzeugung, `#version`-Injektion (150 core / 140 / 130),
Shader-Compile und Instancing-Fallback auf 3.0/3.1/3.3/4.1/4.6. Mesas strenger
GLSL-Compiler findet Portabilitaets-Bugs, die GPU-Treiber schlucken.

## Deterministische 2D-Snapshots im Alpine-Container

FreeType/libgd sind pro OS unterschiedlich → committete Snapshots im gepinnten
Alpine-Image erzeugen (das tut auch die CI):

```sh
docker build -f .docker/vrt.Dockerfile -t phpolygon-vrt .
docker run --rm -v "$(pwd)":/app -w /app phpolygon-vrt \
  sh -c 'composer install --ignore-platform-reqs && PHPOLYGON_UPDATE_SNAPSHOTS=1 vendor/bin/phpunit tests/Testing/'
```

---

## CI-Jobs (`.github/workflows/ci.yml`)

| Job | Prueft |
|-----|--------|
| `tests` | PHPUnit (ohne `font-vrt`) auf ubuntu + macOS; best-effort `pie install` von php-metal-gpu → Metal-VRT laeuft auf macOS |
| `tests-gpu` | php-glfw + xvfb, PHPUnit mit GPU |
| `gl-matrix` | OpenGL-Versionsmatrix 3.0–4.6 (Mesa) |
| `vrt` | Alpine-Container: `tests/Testing/` inkl. Fonts + Snapshot-Drift-Check |

**Snapshot-Drift-Check faellt** wenn committete Snapshots veralten → im Alpine-
Container regenerieren und committen.

## Workflow bei Aenderungen

1. Betrifft es Rendering-Ausgabe? → passenden VRT-Test schreiben/aktualisieren.
2. Fonts/UI mit Text? → `@group font-vrt` + Snapshots im Alpine-Container erzeugen.
3. 3D-Szene? → primaer CommandList-Assert (CI-tauglich); optional nativer
   Pixel-Test (`renderToImage`, gated).
4. Snapshots erzeugen: `PHPOLYGON_UPDATE_SNAPSHOTS=1` (Fonts/UI im Container).
5. `actionlint` bei Workflow-Aenderungen; `vendor/bin/phpstan analyse` (Level 10);
   volle Suite gruen halten.

## Anti-Patterns

- **Kein** 3D-Pixel-VRT in Headless-CI (kein GPU) → CommandList inspizieren.
- **Keine** identische Pixel-Gleichheit zwischen `GdRenderer2D` und GPU-Renderer
  erwarten (GD ist strukturelle Naeherung fuer Layout-Regression).
- **Keine** GPU-spezifischen Pixel-Snapshots ohne Toleranz (GPU-Varianz).
- **Keine** `*.actual.png` / `*.diff.png` committen.
- **Kein** VRT fuer legitim animierte Inhalte ohne Maske / eingefrorene Zeit.
