# PHPolygon — Procedural Global Illumination (SDF-GI)

> **Claude Code Implementierungs-Briefing**
> **Status:** Design-Doc, prä-Implementierung. Keine Code-Änderung in diesem Commit.
> **Ziel-Release:** offen — **nach** Code Tycoon Next Fest (15.–22. Juni 2026) und iPad-Port.
> **Harte Vorbedingung:** vio muss Compute-Dispatch **oder** einen HDR-Fragment-Pass
> plus eine Volume-Repräsentation zur PHP-API durchreichen (§5). Ohne die fällt das
> Feature auf den bestehenden Forward-Shader + Screen-Space-Pfad zurück (Tier C).
> **Verwandtes Projekt:** das parallel laufende PHPolygon Sky/Atmosphere-System
> (`ProceduralSky`, `SetSky`/`SetSkyColors`). Analytischer Himmel ist geteilte
> Infrastruktur — nicht doppelt bauen.

---

## 0. Worum es geht (und worum nicht)

Ziel ist raytracing-*ähnliche* Beleuchtung — diffuse Global Illumination, weiche
Schatten, Ambient Occlusion, Sonnenstand-abhängiger Himmel — **ohne** Hardware-RT
und ohne große Echtzeit-Rechenkapazität. Der Ansatz ist prozedural: Geometrie als
**Signed Distance Field** (SDF), Beleuchtung als billige Auswertung dieser Funktion
pro Sample (Sphere-/Cone-Tracing), plus analytische Closed-Form-Terme (Himmel,
Flächenlicht).

Das Leitprinzip ist dasselbe, das die Engine schon überall benutzt: **nicht den
Output backen, sondern die Struktur halten und on demand auswerten.** Ein SDF ist
für 3D, was ein gespeicherter Pfad für Vektorgrafik ist — eine kompakte
mathematische Beschreibung, die zur Laufzeit in der gebrauchten Auflösung
ausgewertet wird.

**Das ist KEIN:**

- **Kein echtes RT-Äquivalent.** RTs Qualität kommt vom tatsächlichen Sampeln des
  Lichttransport-Integrals. SDF-GI ist eine Approximation: überzeugende diffuse GI,
  weiche Schatten und AO — aber keine scharfen Spiegelreflexionen, keine Kaustiken,
  kein punktscharfes Kontaktlicht an dünnen Features. Wer mehr Marketing als das
  reinschreibt, lügt.
- **Kein Ersatz** für die bestehenden analytischen Forward-Shader-Features
  (Schattenkarten via `ShadowMapRenderer`, In-Shader-Volumetric-Fog, `ScreenSpaceAO`,
  `ScreenSpaceReflections`, ACES-Tonemap, Normal-/Surface-Pattern). SDF-GI *ergänzt*
  diesen Stack, es reißt ihn nicht raus.
- **Kein Hardware-RT-Pfad.** vio meldet heute `VIO_FEATURE_RAYTRACING = 0` (nur über
  NV/EXT-Vendor-Extensions, nicht gewired). Es gibt also vorerst nur den
  Software-/SDF-Boden, keine HW-RT-Decke. Die Decke ist später nachrüstbar (Tier A+),
  aber **nicht Scope dieser ersten Iteration.**

---

## 1. Einordnung in die bestehende Render-Architektur

Das Feature fügt sich in den vorhandenen Datenfluss ein, es bricht ihn nicht:

```
Game Code / Scene
      ↓  (baut)
RenderCommandList            ← reines PHP, neue GI-Kommandos als readonly value objects
      ↓  (ausgeführt von)
Renderer3DInterface::render(RenderCommandList)
      ↓
┌──────────────┬──────────────────┬──────────────────┬──────────────────┬──────────────┐
VioRenderer3D  OpenGLRenderer3D   VulkanRenderer3D   MetalRenderer3D   NullRenderer3D
(primary)      (fallback)         (native)           (native)          (headless)
```

Die GI-Logik lebt **oberhalb der Backend-Grenze**: ein neues GI-Subsystem baut/füttert
das SDF und emittiert GI-Kommandos in die `RenderCommandList`. Die Backends führen
gegen *Capabilities* aus, die sie über `vio_supports_feature()` melden — **nicht**
gegen Backend-Namen. Das ist exakt das Muster, das `BackendConventions`
(`src/Rendering/BackendConventions.php`) bereits für Depth-Range, Y-Flip und
Shader-Format etabliert: ein zentraler Ort besitzt jede Konvention.

**Präzedenz, dass der In-Shader-March-Ansatz hier schon zuhause ist:** Der
Forward-Shader macht bereits einen 8-Step-Ray-March für Volumetric Fog, einen
24-Step-World-Space-March für `OpenGLSsrPass`, und kurvaturbasiertes AO via `dFdx(N)`.
SDF-Tracing ist dieselbe Klasse von Arbeit, nur gegen eine Distanzfunktion statt
gegen Tiefenpuffer/Phasenfunktion.

---

## 2. Capability-Modell — der Kern

**Regel:** Gegen Capabilities gaten, niemals gegen Backend-Namen. Ein sechstes
Backend (WebGPU, GNM) erbt das Feature, sobald es seine Caps meldet — ohne dass
GI-Code angefasst wird.

Relevante Flags (`vio_supports_feature($ctx, …)`, Ladder in `php-vio/CLAUDE.md`):

| Flag | Bedeutung für GI | Floor 3.3 |
|---|---|---|
| `VIO_FEATURE_COMPUTE` | Compute-Trace möglich (Tier A). GL ≥ 4.3. **macOS-GL nie** (Cap 4.1). | 0 |
| `VIO_FEATURE_RENDER_TARGET` | Fragment-Trace in FBO möglich (Tier B). | **1** |
| `VIO_FEATURE_RENDER_TARGET_HDR` | GI-Result als HDR-Target (Pflicht für Bounce-Licht). | **1** |
| `VIO_FEATURE_TEXTURE_STORAGE` | Effizientes Volume-Storage (wenn Compute). | 0 |
| `VIO_FEATURE_RAYTRACING` | HW-RT-Decke. **Heute überall 0.** | 0 |

### Drei Ausführungspfade nach Capability-Tier

**Tier A — Compute-Trace (volle Qualität).**
Voraussetzung: `VIO_FEATURE_COMPUTE` + Volume-Textur + HDR-Target. SDF-Trace läuft als
Compute-Pass, schreibt Irradiance/AO in HDR-Targets, die der Mesh-Shader samplet.
Realistische Backends heute: **D3D12, D3D11 (CS 5.0), Vulkan, OpenGL ≥ 4.3
(Windows/Linux).** Metal erst, wenn die Metal-3D-Pipeline kein Stub mehr ist
(siehe `v2-architecture.md` §2: `create_pipeline`/`buffer`/`texture`/`draw` auf Metal
noch nicht implementiert).

**Tier B — Fragment-Fallback.**
Kein Compute, aber Render-Targets (überall ab Floor 3.3). Der Trace läuft als
Fullscreen-Fragment-Pass (Shadertoy-Stil) in ein HDR-Target. Unflexibler (kein Shared
Memory, Scatter-Writes für Probe-Updates umständlich), aber funktioniert auf **OpenGL
3.3–4.1 inkl. macOS** und auf Metal, sobald dessen 3D-Pipeline steht.

**Tier C — Degradation.**
Keine Volume-Repräsentation oder bewusst abgeschaltet → GI wird zum No-Op über den
*bestehenden* Forward-Features. SSAO/SSR/Schattenkarten/analytischer Himmel bleiben.
Das ist auch der **Headless-/Null-Pfad**: `NullRenderer3D` akzeptiert die GI-Kommandos,
führt nichts aus, die `RenderCommandList` bleibt für Test-Assertions lesbar.

---

## 3. Die zwei echten Vorbedingungen in vio

Beide betreffen `phpolygon/php-vio` und sind die kritische Abhängigkeit dieses Features.

**(a) Compute ist in der Vtable, aber nicht in der PHP-API.**
`include/vio_backend.h` hat laut `v2-architecture.md` §2 einen Compute-Abschnitt — die
C-Seite kennt das Konzept. Aber `vio.stub.php` exponiert **kein** `vio_compute_dispatch`,
kein Storage-Buffer-API. Tier A braucht: Compute-Shader-Compilation (der Pfad GLSL →
SPIR-V via glslang existiert), Storage-Buffer (SSBO) und einen Dispatch-Call, alle als
neue `vio_*`-Funktionen + Arginfo aus `vio.stub.php`.

**(b) vio kennt keine 3D-/Volume-Textur.**
`vio_texture()` ist 2D, `vio_cubemap()` ist Cube — beides reicht nicht für ein
SDF-Volumen. Optionen, in Reihenfolge der Sauberkeit:

1. **Echte 3D-Textur** in vio nachrüsten (`vio_texture_3d()` o. ä.) — sauberster Weg,
   aber neue Surface in allen Backends.
2. **2D-Atlas-Flipbook** (Volume-Slices in eine 2D-Textur gekachelt) — läuft auf der
   *bestehenden* 2D-Textur-Surface ohne vio-Änderung. Guter Interim für Tier B, um
   früh ein Ergebnis zu haben.
3. **SSBO als flaches Volume-Array** (nur Tier A) — wenn Compute ohnehin kommt.

> **Wichtig:** Das Surfacing von Compute fasst die C-Core an und ist damit konzeptionell
> mit dem libvio-v2-Split verzahnt (Phase 1/2 in `php-vio/v2-architecture.md`). Sauberer
> ist, (a) als Teil von / nach der v2-Zend-Trennung zu landen statt im 1.x-Monolithen.
> Tier B (Fragment + 2D-Atlas) braucht **keine** vio-Änderung und ist deshalb der
> empfohlene erste Schritt — siehe §9.

---

## 4. SDF-Quelle — passt zum prozeduralen Ethos der Engine

PHPolygon hat **keine Modell-Dateien**; Geometrie entsteht in PHP (`src/Geometry/`,
`BoxMesh`/`CylinderMesh`/`SphereMesh`/…, Ausgabe `MeshData`). Das ist ein Glücksfall für
SDFs, denn es gibt zwei Quellen — und die elegante ist die, die zum Engine-Prinzip passt:

**Analytische SDF-Primitive (bevorzugt).** Box, Sphere, Cylinder, Plane haben exakte,
bekannte Distanzfunktionen. Eine Welt aus prozeduralen Primitiven lässt sich direkt als
Komposition analytischer SDFs ausdrücken (smooth-min für weiche Übergänge), ganz ohne
Voxelisierung. Das ist „Geometrie als Mathe" konsequent zu Ende gedacht — dieselbe
Philosophie wie `ProceduralMesh`.

**Voxel-Baker (Fallback).** Für `MeshData`, die nicht aus Primitiven stammt
(Composite-Buildings, Terrain), ein `MeshData → SDF`-Baker, der das Distanzfeld in das
gewählte Volume rastert.

**Statisch vs. dynamisch.** Das globale SDF der statischen Welt wird **einmal** gebaut
(beim Laden bzw. Build-Zeit, nicht „beim Startup" zur Laufzeit). Dynamische Objekte
markieren über ihre AABB betroffene Volume-Regionen als *dirty* und triggern ein
inkrementelles Update — nicht das ganze Feld neu. Das ist die Invalidierung über einen
Radius, die wir im Konzept hatten.

**Wo der Build läuft:** CPU-seitig als `parallel`-Subsystem nach dem Muster in
`PHPOLYGON_MULTITHREADING.md` (`SubsystemInterface`) — ein `SdfBakeSystem`, das Deltas
an den Main Thread zurückgibt. Der **Trace** läuft auf der GPU (Tier A/B). PHP fasst den
Hot Path nie an, nur Orchestrierung. Das deckt sich mit der Latenz-Klassen-Tabelle:
SDF-Update verträgt 1 Frame Versatz, gehört also auf einen Worker-Thread.

---

## 5. Was vorberechnet wird vs. zur Laufzeit ausgewertet

| Term | Vorberechnet | Zur Laufzeit |
|---|---|---|
| Statische Geometrie | SDF-Volume (einmal) | Sphere-/Cone-Trace dagegen |
| Diffuse GI (statisch) | Probe-Irradiance-Feld (amortisiert über Frames) | Probe-Lookup pro Fragment |
| Weiche Schatten | — | SDF-min-Distanz-Penumbra entlang Schattenstrahl (1 Ray, analytische Aufweitung) |
| Ambient Occlusion | — | Cone-Trace gegen SDF |
| Himmel / Sonnenstand | analytische Koeffizienten | **Closed-Form pro Frame, beliebiger Winkel** (Hosek-Wilkie) → bestehendes `SetSky` |
| Dynamische Objekte | — | Probe-Sampling + Dirty-Region-Refresh |

Der Sonnenstand-Fall ist damit gelöst, ohne Lightmaps pro Winkel zu backen
(Speicher-Explosion): ein analytisches Himmelsmodell liefert Radiance für jeden Winkel
in geschlossener Form. **Das gehört in das bestehende Sky/Atmosphere-Projekt, nicht in
eine zweite Implementierung.**

---

## 6. Render-Command-Erweiterungen

Neue Kommandos folgen exakt dem bestehenden Muster: `readonly class` mit
constructor-promoted public properties, Namespace `PHPolygon\Rendering\Command`,
`declare(strict_types=1)`. Minimal halten.

```php
<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Command;

/**
 * Configure procedural global illumination for the frame.
 *
 * Mode is evaluated against renderer capability tiers; on backends that
 * can't satisfy it the renderer silently degrades (no crash) - see
 * GlobalIllumination tier table in PHPOLYGON_PROCEDURAL_GI.md.
 */
readonly class SetGlobalIllumination
{
    public function __construct(
        public GlobalIlluminationMode $mode,   // Off | ProbesOnly | SdfOcclusion | SdfBounce
        public float $intensity = 1.0,
        public int   $bounces   = 1,           // ignored unless mode == SdfBounce
        public float $aoRadius  = 1.5,
    ) {}
}
```

Vermutlich genügen 1–2 Kommandos (`SetGlobalIllumination`, optional `SetSdfShadows`).
Schatten könnten auch direkt an `SetDirectionalLight` andocken — entscheiden, wenn die
Schnittstelle steht, nicht vorgreifend. Kommandos werden wie gehabt während des
Scene-Ticks angehängt und vom `Renderer3DSystem` einmal pro Frame geflusht.

---

## 7. Shader-Integration — die Drei-Kopien-Regel

CLAUDE.md ist hier hart: dieselbe Shader-Logik existiert in **drei** parallelen Kopien,
die synchron bleiben müssen:

- `resources/shaders/source/mesh3d.frag.glsl` (OpenGL)
- `resources/shaders/source/vio/mesh3d.frag.glsl` (Vio — alle vio-Backends)
- `resources/shaders/source/mesh3d.metal` (standalone Metal)

Der GI-Beitrag wird im Mesh-Shader **gesamplet**, nicht dort getracet: Der Trace läuft
im separaten Compute-/Fragment-Pass (Tier A/B) und legt Irradiance + AO in HDR-Targets.
Der Mesh-Shader liest die nur noch — Kosten: ein paar Uniforms + Textur-Fetches pro
Fragment, konsistent mit der bestehenden „shader-side, cost only uniforms"-Philosophie.

Jede Änderung am Sampling muss **alle drei Kopien** in einem Commit patchen (gleiche
Regel wie bei `NormalPattern`/`SurfacePattern`). Jeder Shader-Exit muss weiter über
`finalize()` (GLSL) / `outputColor()` (Vio) / `finalizeColor()` (Metal) laufen, damit
Color-Grading + ACES-Tonemap + Vignette über alle Pfade konsistent bleiben — GI-Result
wird **vor** diesem Exit addiert, nicht danach.

---

## 8. GraphicsSettings & Quality-Tiers

Neuer Enum, integriert in das immutable `GraphicsSettings`-Value-Object:

```php
enum GlobalIlluminationMode { case Off; case ProbesOnly; case SdfOcclusion; case SdfBounce; }
```

| Tier | Inhalt | Ziel-Hardware |
|---|---|---|
| `Off` | nur bestehender Forward-Stack (SSAO/SSR/Schatten) | alles, Floor |
| `ProbesOnly` | statisches Probe-Irradiance-Feld | schwache GPUs, iPad |
| `SdfOcclusion` | + SDF-AO + weiche SDF-Schatten | Desktop-Mittelklasse |
| `SdfBounce` | + 1 Bounce diffuse GI via Cone-Trace | Desktop-Oberklasse |

- Änderung ausschließlich über `GraphicsSettings::with(...)` +
  `$engine->graphics->update(...)` — nie Felder direkt mutieren (bestehendes
  Anti-Pattern).
- `applySettings()` auf allen Backends implementiert die Tier-Auswahl, capability-gated.
- **NICHT in den Adaptive-Hot-Swap-Stack aufnehmen.** Wie `TextureQuality`/`MeshLodTier`
  dominiert der SDF-Rebuild-/Re-Bake-Kosten jeden Frame-Time-Gewinn. GI-Tier ist eine
  bewusste Settings-Entscheidung, kein Frame-für-Frame-Regler. Gleiche Begründung wie im
  bestehenden Adaptive-Anti-Pattern.
- Bandbreite ist der Skalierungsknopf für schwache Ziele: niedrigere SDF-Auflösung,
  weniger Cones, gröberes Probe-Gitter, Mip-abhängige Volume-Fetches (grobe Cones lesen
  niedrige Mips → billiger). `vio_thermal_state()` kann als Eingang dienen.

---

## 9. Abhängigkeiten & Implementierungs-Reihenfolge

Bewusst **Fragment-zuerst**: ein lauffähiges, cross-backend Ergebnis bekommen, *bevor*
die Compute-API-Arbeit (verzahnt mit v2) angefasst wird.

1. **Analytische SDF-Primitive + smooth-min-Komposition** (reines PHP/Math, headless
   testbar). Box/Sphere/Cylinder/Plane-Distanzfunktionen, gespiegelt zu `src/Geometry/`.
2. **`MeshData → SDF`-Baker** für Nicht-Primitive (headless testbar, kein GPU).
3. **2D-Atlas-Volume + Fragment-Trace (Tier B)** — läuft auf der bestehenden vio-2D-
   Textur-Surface, **keine** vio-Änderung nötig. Weiche Schatten + AO zuerst, weil
   höchstes Qualität-pro-Aufwand-Verhältnis. Hier Qualität validieren.
4. **Shader-Sampling in alle drei Kopien** integrieren (§7).
5. **GraphicsSettings-Tier + capability-gating + applySettings()** über alle Backends.
6. **vio Compute + Storage-Buffer + 3D-Textur** zur PHP-API durchreichen (§3) — der
   große, mit v2 verzahnte Schritt.
7. **Compute-Trace (Tier A)** für die fähigen Backends.
8. **Probe-Irradiance-Feld + temporale Reuse + Dirty-Region-Invalidierung** für Dynamik.
9. **Analytischer Himmel** mit dem Sky/Atmosphere-Projekt zusammenführen (nicht doppeln).

Schritte 1–5 liefern ein vollständiges, ausgeliefertes Feature auf Tier B/C über *alle*
Backends — ohne je den vio-Core anzufassen. Erst 6–8 holen die Compute-Qualität nach.

---

## 10. Performance & Tests

- **Die Decke ist nicht Parallelität, sondern Divergenz + Bandbreite.** Streifende Rays
  marschieren viele kleine Schritte (Warp-Divergenz), Volume-Fetches sind bandbreiten-
  gebunden. Mehr Lanes helfen jenseits dessen nicht. Optimierungshebel: Rays/Cones
  bündeln (Kohärenz), Mip-abhängige Fetches (Bandbreite). Das ist dieselbe Wand wie bei
  RT-Cores — keine Überraschung, nur kein Wunder erwarten.
- **Perf-Contract:** Trace-Pässe und SDF-Bake sind Hot-Path → unter den
  CI-Benchmark-Gate (`perf-bench.yml`, > 15% p95 bricht den Build). Neue Hot-Path-Dateien
  brauchen einen `*Bench.php` unter `benchmarks/micro/`. Kein „sollte schneller sein"
  ohne Bench — die SoA-Particle-Geschichte im Repo-Verlauf war 5× *langsamer* trotz
  Papierform.
- **Tests:** Headless-Integration inspiziert die `RenderCommandList` aus
  `NullRenderer3D` (kein GPU in CI). Für den Trace-Output selbst: headless vio-Context
  (`vio_create("auto", [..., "headless" => true])`) + `vio_read_pixels()` /
  `vio_compare_images()` gegen committete Referenz. SDF-Primitive + Baker werden als
  reine Unit-Tests gegen bekannte Distanzwerte geprüft (Punkt X hat Distanz d zur
  Box) — der wertvollste, GPU-freie Testlayer.

---

## 11. Timing

- **Bis 22. Juni 2026:** nichts. Next Fest ist Blocker für jede Architekturarbeit
  (identisch zur v2-Regel).
- **Nach Next Fest + iPad-Port:** Schritte 1–5 (Tier B, kein vio-Core-Eingriff) können
  parallel zur v2-Zend-Trennung laufen, da sie rein engine-seitig sind.
- **Compute-Surfacing (Schritt 6):** sauber als Teil von / nach libvio v2 Phase 1–2
  einplanen, nicht in den 1.x-Monolithen quetschen.
- **Analytischer Himmel:** mit dem laufenden Sky/Atmosphere-Projekt synchronisieren.

Der Zeitrahmen ist lose. Das ist ein Qualitäts-Feature ohne Zeitdruck — Tier B liefert
früh sichtbaren Mehrwert, der Rest folgt der v2-Reife.

---

## 12. Wichtige Hinweise für Claude Code

1. **Gegen Capabilities gaten, nie gegen Backend-Namen.** `vio_supports_feature()` +
   `BackendConventions`. Ein `is-opengl`-Switch im GI-Code ist ein Bug.
2. **Drei Shader-Kopien synchron patchen** (`mesh3d.frag.glsl`, `vio/mesh3d.frag.glsl`,
   `mesh3d.metal`) — in einem Commit. Nie nur eine.
3. **SDF-Build ist CPU-Subsystem, Trace ist GPU.** PHP orchestriert, rechnet nicht im
   Hot Path. `SdfBakeSystem` nach `SubsystemInterface`-Muster, gibt Deltas zurück.
4. **Fragment-Fallback (Tier B) ist erstklassig, kein Nachgedanke.** Er ist der erste
   Implementierungsschritt und der einzige Pfad auf macOS-GL und iPad.
5. **Kein Hardware-RT annehmen.** `VIO_FEATURE_RAYTRACING == 0` überall. Floor-only
   designen; HW-RT-Decke ist spätere Iteration.
6. **Den bestehenden Forward-Stack nicht ersetzen** — ergänzen. Schattenkarten,
   Volumetric Fog, SSAO, SSR, ACES bleiben. GI-Result wird vor `finalize()`/
   `outputColor()`/`finalizeColor()` addiert.
7. **Headless = No-Op, aber Command-List lesbar.** `NullRenderer3D` akzeptiert die
   GI-Kommandos und führt nichts aus.
8. **GI-Tier NICHT in den Adaptive-Hot-Swap-Stack.** Rebuild-Kosten dominieren — wie
   `TextureQuality`.
9. **Analytischer Himmel gehört ins Sky/Atmosphere-Projekt**, nicht in eine zweite
   Implementierung. `SetSky`/`SetSkyColors`/`ProceduralSky` sind der Anker.
10. **vio-Core-Eingriffe (Compute, 3D-Textur) sind v2-verzahnt.** Erst Tier B ohne
    vio-Änderung liefern, dann den Core-Teil im Rahmen der v2-Phasen.

---

## 13. Anti-Patterns — niemals

- **Nicht** den SDF-Trace im Mesh-Shader selbst ausführen — er läuft im separaten
  Compute-/Fragment-Pass, der Mesh-Shader samplet nur das Result.
- **Nicht** eine Shader-Kopie ändern und die anderen zwei vergessen.
- **Nicht** das ganze SDF-Volume neu backen, wenn sich ein dynamisches Objekt bewegt —
  nur die von der AABB berührten Dirty-Regionen.
- **Nicht** GPU-APIs aus Systemen/Komponenten aufrufen — nur Backends fassen die GPU an
  (bestehendes Engine-Anti-Pattern, gilt auch hier).
- **Nicht** vio um eine 3D-Textur-API erweitern, *bevor* Tier B mit dem 2D-Atlas-Interim
  validiert ist — sonst baust du Core-Surface für ein noch unbewiesenes Feature.
- **Nicht** „RT-Qualität" oder „raytraced lighting" in user-facing Strings/Settings-
  Labels schreiben. Es ist approximierte GI; ehrliche Labels („Global Illumination").
- **Nicht** einen Perf-Win ohne Benchmark behaupten.

---

*Ende des Briefings. Änderungen via PR, mit Begründung im Commit. Dieses Doc ist das
Artefakt der Konzeptphase — die Implementierung beginnt frühestens nach Next Fest.*
