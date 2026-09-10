# RFC: G-Buffer als MRT-Attachment des Opaque-Passes

**Status**: implemented (`feat/mrt-gbuffer`, 2026-09-10) — see „Umsetzung" am Ende
**Author**: engineering
**Last update**: 2026-09-10
**Related**: php-vio ≥ 2.10 (`vio_render_target(['attachments' => …])`, `vio_pipeline(['attachments' => …])`, Test 097), php-vio PR #24 (Bind-Tabellen-Fix, Voraussetzung auf D3D11/D3D12), `docs/rfcs/compute-pipeline.md`

## Summary

`VioRenderer3D` rasterisiert die Geometrie heute fünfmal pro Frame: drei Schatten-Kaskaden,
einmal den G-Buffer (`renderSsaoPass`: View-Normale, Reflektivität, lineare Tiefe) und
einmal den Opaque-Pass. Der G-Buffer-Pass ist eine vollständige zweite CPU-Draw-Submission
derselben Draw-Liste (Sortierung, Material-Uniforms, Instanz-Buffer) – auf dem CPU-gebundenen
Frame der Spiele der grösste einzelne Posten nach Schatten und Opaque (`render3d.submit.ssao`
~3 ms von ~13 ms `vio_submit`, Messung 2026-06-22).

Mit Multiple Render Targets schreibt der Opaque-Pass den G-Buffer als **zweites Attachment**
nebenbei; der separate Geometrie-Pass entfällt. Weil der Mesh-Shader die AO-Karten heute
IN der Beleuchtung liest (SSAO/SDF-AO auf Ambient und Probe-GI, SDF-Sonnenschatten auf das
Direktlicht), kann AO nicht einfach nachmultipliziert werden – das würde Sonne und Emission
mit abdunkeln. Der Shader gibt deshalb **Direkt- und Ambient-Anteil getrennt** aus, und ein
Composite-Pass legt die AO-Terme exakt dort an, wo sie heute wirken.

## Ziel und Nicht-Ziel

- Ziel: identisches Bild (bis auf FP16-Rundung) bei einer Geometrie-Submission weniger. Kein
  Qualitätsumbau, kein neues Feature.
- Nicht-Ziel: Deferred Lighting, Light-Culling, MSAA+MRT, Änderung der AO-Algorithmen.

## Ist-Zustand (verifiziert im Code, Stand v0.41.0)

Frame-Ablauf in `VioRenderer3D::render()`:

1. `renderShadowPass` – 3 Kaskaden, depth-only RTs.
2. `renderSsaoPass` – **eigener Geometrie-Pass** (`gbuffer.vert/frag`, alle opaken Draws ohne
   `excludeFromGbuffer`, RGBA16F: `rg` = oktaedrisch kodierte View-Normale, `b` =
   Reflektivität, `a` = lineare View-Tiefe, Clear 0 = Himmel) → SSAO-Occlusion (halbe
   Auflösung) → Blur.
3. `renderSdfAoPass` – liest G-Buffer + SDF-Volumen, schreibt `R` = AO, `G` = Sonnenschatten,
   Blur.
4. Env-Cubemap (nur bei geändertem Himmel).
5. Szenen-Target binden (`hdrTarget` im Bloom-Pfad, sonst `VioOffscreenTarget`; FP16 nur auf
   Direct3D, siehe `offscreenIsHdr()`), Himmel, **Opaque** (liest `u_ssao_map`, `u_sdf_ao_map`
   in `mesh3d.frag`), **Transparent** (gleiches Target, gleicher Depth-Buffer).
6. `renderSsrPass` – liest G-Buffer + Szene, komponiert zurück ins Szenen-Target.
7. Post: Bloom → Tonemap → FXAA → Swapchain.

Zusammensetzung in `mesh3d.frag` (Zeilen ~750–970): `color = ambient·kD·ambientShadow·ao`
(+ Probe-GI · ao) + Direktlicht (Sonne · shadow · ftSunShadow, Punkt-/Spotlichter) + IBL
(`· shadow`) + Clearcoat + Emission; dann Nebel (`mix(color, fog, f)`), Volumetrik (additiv),
Post-Color-Hook (Spiel-Snippet, z.B. Unterwasser-Tint: `mix(color, T, k) · d` – affin),
`outputColor` (Tonemap, wenn `u_linear_output == 0`).

Fünf Spiel-Snippets (Wasser, Pool, Wolke, Mond, Hologramm) schreiben `frag_color` direkt und
`return`en – sie umgehen die Beleuchtung komplett.

## Design

### Attachments des Opaque-Passes (RGBA16F ×3)

| # | Inhalt | Warum getrennt |
|---|---|---|
| 0 `o_direct` | Sonne + Punkt-/Spotlichter + Clearcoat-Specular + Emission + Volumetrik; Nebelfarbe · f | AO darf hier nicht wirken; nur der SDF-Sonnenschatten skaliert den Sonnenanteil |
| 1 `o_ambient` | Ambient · kD · ambientShadow + Probe-GI + IBL, skaliert mit (1 − f) Nebel | wird im Composite mit `ssao · sdfAo` multipliziert |
| 2 `o_gbuffer` | exakt der heutige `gbuffer.frag`-Inhalt (Oktaeder-Normale, Reflektivität, lineare Tiefe) | Eingang für SSAO, SDF-AO, SSR |

Nebel ist affin, also exakt aufteilbar: `mix(a + b, F, f) = [(1−f)·a + f·F] + (1−f)·b`. Der
Post-Color-Hook ist im Spiel affin (`mix` + Skalar), also ebenso: Skalierung auf beide Anteile,
der additive Term auf `o_direct`. **Vertrag für Snippets**: der Hook erhält weiterhin
`color`, wird aber zweimal aufgerufen (direct, ambient) mit einem Flag, ob der additive Anteil
gilt – oder einfacher: der Hook wandert in den Composite-Pass (dort liegen World-Position via
`inv(VP)` und Tiefe vor). Empfehlung: **Composite**, dann bleibt der Snippet-Vertrag eine
Funktion `vec3 → vec3`.

Der SDF-Sonnenschatten (`ftSunShadow`) muss im Shader weiter das Direktlicht skalieren – er
kommt aber erst aus dem SDF-Pass, der jetzt NACH dem Opaque-Pass läuft. Lösung: `o_direct`
zusätzlich in `o_sun` (Sonnenanteil) und Rest teilen? Das kostet ein viertes Attachment
(Limit 4, `VIO_MAX_COLOR_ATTACHMENTS`). Alternative ohne viertes Attachment: der
Sonnen-Direktanteil wird in `o_direct.rgb`, der restliche Direktanteil (Punktlichter,
Emission, Volumetrik) in `o_ambient.a`? Nein – Farbe. **Entscheidung**: vier Attachments
`o_sun`, `o_local` (Punkt/Spot/Emission/Volumetrik/Clearcoat), `o_ambient`, `o_gbuffer`; das
Composite rechnet `hdr = o_sun · sdfShadow + o_local + o_ambient · ssao · sdfAo`. Vier
Attachments sind das vio-Limit – ausgeschöpft, aber ausreichend. Alpha-Kanäle: `o_sun.a` =
Material-Alpha (für den Transparent-Pass), `o_local.a`/`o_ambient.a` frei (0).

### Neue Pass-Reihenfolge

1. Schatten (unverändert).
2. Env-Cubemap (unverändert).
3. **Opaque → MRT-Target** (4 Attachments + Depth). Himmel zeichnet nur `o_sun`/`o_local`?
   Der Himmel ist unbeleuchtet: `o_local = sky`, übrige 0, `o_gbuffer` bleibt 0 (Clear) →
   AO/SSR sehen Himmel wie heute.
4. MRT-Target unbinden. **SSAO** (Occlusion + Blur) und **SDF-AO** lesen Attachment 2 – die
   Passes bleiben bis auf die Quelle unverändert; `renderSsaoPass` verliert den
   Geometrie-Teil, `bindGbufferPipeline`/`drawGbufferMesh*`/`gbuffer.vert|frag` entfallen.
5. MRT-Target wieder binden (Depth bleibt), **Transparent** zeichnet mit Alpha-Blend in
   `o_sun`/`o_local`/`o_ambient` und schreibt `o_gbuffer` mit (Wasseroberfläche wird damit
   für SSR sichtbar – heute wird sie bewusst über `excludeFromGbuffer`/Alpha ausgeschlossen;
   Verhalten gleich halten heisst: Transparent-Pipeline mit `color_mask` nur für Attachment 0–2.
   php-vio `color_mask` gilt heute nur für RT0 → **vio-Follow-up**: per-Attachment-Write-Mask
   oder `depth_write=false`-Variante mit `attachments` ohne #3).
6. MRT unbinden. **Composite** (Fullscreen, neuer `composite.frag`): liest 3 Farb-Attachments +
   SSAO-Blur + SDF-Blur, schreibt das bisherige Szenen-Target (FP16). Hier auch der
   Post-Color-Hook (mit rekonstruierter World-Position aus `o_gbuffer.a` und `inv(VP)`).
7. SSR (liest Attachment 2 + Composite-Ziel), Bloom, Tonemap, FXAA – unverändert.

Die AO-Approximation für Transparente (sie erhalten die AO der dahinterliegenden opaken
Fläche) ist die einzige bewusste Abweichung; heute lesen sie dieselben AO-Karten mit dem
Screen-UV, also identisch.

### Was am Shader passiert

- `mesh3d.frag` (VIO-Familie): vier `layout(location = n) out vec4`. Die Additionen der
  Zeilen 798–960 werden auf drei Akkumulatoren verteilt; `outputColor()` (Tonemap) entfällt
  auf diesem Pfad (Composite tonemappt, wie heute der HDR-Pfad). Sentinel-Blöcke bleiben, der
  Hook `PHPOLYGON:POSTCOLOR` wird auf dem MRT-Pfad NICHT expandiert (Composite übernimmt).
- Snippets mit `frag_color = …; return;` bekommen einen Makro-Vertrag `EMIT_UNLIT(color, alpha)`
  → schreibt `o_local = color`, Rest 0, `o_gbuffer` regulär (Normale/Tiefe der Fläche). Das
  sind im Spiel 5 Branches × 2 Familien; der Byte-Identitäts-Test des Spiels
  (`ProcModeShaderPilotTest`) muss dafür einmal neu aufgesetzt werden.
- `gbuffer.frag`-Logik (Oktaeder-Encoding, Reflektivität inkl. Shoreline-Fade) wandert als
  Funktion in `mesh3d.frag`.
- GL-Familie (`mesh3d.frag.glsl` für den nativen OpenGL-Renderer): unverändert, kein MRT-Pfad.

### Backends und Fallback

- MRT-Pfad nur, wenn `vio_supports_feature(VIO_FEATURE_MRT)` UND FP16-Szene aktiv
  (`sceneTargetIsHdr()`) UND `samples == 1`. Sonst der heutige Pfad (bleibt vollständig
  erhalten, Schalter `PHPOLYGON_VIO_MRT=0` als Escape-Hatch).
- D3D12: Pipeline braucht `attachments` (PSO-RTV-Formate) → `bindPipeline('opaque')` und
  `('transparent')` bekommen eine MRT-Variante im Cache-Key. D3D11/OpenGL/Metal binden zur
  Laufzeit.
- Vulkan (vio 2D-only) ohnehin nicht betroffen.
- Voraussetzung php-vio ≥ 2.10.2 (Bind-Tabellen-Fix): ohne ihn sampeln die Attachments auf
  D3D11/D3D12 recycelte Texturobjekte (genau die Phantom-Schatten vom 2026-09-10).

### Kosten/Nutzen

- Gewinn: eine Geometrie-Submission weniger (~3 ms bei ~13 ms `vio_submit` in CodeCity, also
  rund +25 % Bildrate auf dem CPU-gebundenen Pfad); der Composite-Pass ist ein Fullscreen-Quad.
- GPU: 4× FP16-Attachments statt 1× FP16 + 1× FP16-G-Buffer → +2 Attachments Bandbreite; die
  GPU ist in beiden Spielen unbeschäftigt (Auflösung wirkt nicht auf die Bildrate).
- Speicher: 2 zusätzliche FP16-Targets in Szenenauflösung (~16 MB bei 1080p).

## Phasen

| Phase | Inhalt | Beleg |
|---|---|---|
| 0 | `enableHdr`/`hdrTarget` (Bloom-Pfad) und `VioOffscreenTarget` (Render-Scale/AA) auf EIN Szenen-Target zusammenführen; Composite-Ziel = dieses Target | bestehende Bildtests unverändert grün |
| 1 | `composite.frag` + Composite-Pass mit heutigem Input (AO-Karten, aber Direct/Ambient aus einem Debug-Split des alten Pfads) – reines Plumbing, Bild unverändert | `MeshShaderHeadlessTest`-Variante: Composite(direct, ambient, ao=1) == alter Output |
| 2 | `mesh3d.frag` MRT-Ausgänge + `EMIT_UNLIT`-Vertrag, Opaque schreibt 4 Attachments, G-Buffer-Pass entfällt | Headless: Attachment 3 == alter `gbuffer.frag` pixelgleich; Attachment-Summe == alter Farbwert |
| 3 | Pass-Reihenfolge (AO-Passes nach Opaque, Transparent in MRT, Composite, SSR) | Spiel-VRT (`WorldTourGpuTest`, `BriefingRoomGpuTest`) innerhalb der Toleranz; Profil `render3d.submit.ssao` ≈ 0 |
| 4 | Post-Color-Hook in den Composite (World-Pos aus Tiefe), Snippet-Vertrag im Spiel umziehen | `ProcModeShaderPilotTest` neu aufgesetzt |
| 5 | Feature-Gate + Fallback-Pfad, Escape-Hatch, Doku (`skills/PHPOLYGON_RENDERING_SHADERS.md`, Engine-CLAUDE.md) | beide Pfade per Env schaltbar, Bildtests je Pfad |

Aufwand: 1–2 Tage plus Cross-Backend-Prüfung (D3D12 hier, Metal/GL über CI-Bildtests).

## Offene Fragen

1. Vier Attachments sind das vio-Limit. Falls später ein weiterer Kanal nötig wird (z.B.
   Motion-Vektoren für TAA): `o_local` und `o_ambient` in RGBA16F packen (Ambient ist
   niederfrequent; `o_local.a` könnte Luminanz-Skalierung tragen) – oder das Limit in vio auf 8
   (D3D12/Metal erlauben 8).
2. Per-Attachment-Write-Mask in php-vio (für den Transparent-Pass ohne G-Buffer-Schreiben) –
   kleiner vio-Follow-up, sonst Transparente in den G-Buffer aufnehmen (SSR-Verhalten ändert
   sich dann für Wasser: es würde erstmals selbst gespiegelt; könnte gewollt sein).
3. Der native OpenGL-Renderer (`OpenGLRenderer3D`, GL-Familie) bleibt beim alten Pfad; ist er
   noch ein Auslieferungsziel oder kann er auf den vio-OpenGL-Pfad folgen?

## Umsetzung (Stand 2026-09-10)

Umgesetzt wie oben entworfen, mit diesen Abweichungen gegenüber dem Entwurf:

- **Post-Color-Hook bleibt im Mesh-Shader** (Phase 4 entfällt). Der Hook ist als Funktion
  `applyPostColor(vec3)` gekapselt; der MRT-Pfad wertet ihn an 0 und 1 aus (`A = hook(0)`,
  `S = hook(1) − A`) und verteilt `S` auf alle drei Anteile, `A` auf den lokalen Anteil.
  Jeder in der Farbe affine Hook (Tint, Fade, Absorption) ist damit exakt; der
  Snippet-Vertrag im Spiel bleibt unverändert.
- **Kein `EMIT_UNLIT`-Makro**: unter `PHPOLYGON_MRT` ist `frag_color` ein Alias für
  `o_local`; alle Attachments werden am Anfang von `main()` initialisiert (Sonne/Ambient 0,
  G-Buffer regulär). Snippets mit `frag_color = …; return;` laufen unverändert.
- **Transparent-Pass**: php-vio 2.11 liefert `attachment_blend` / `attachment_color_mask`
  (PR #25). Transparente blenden in Attachment 0–2 und lassen den G-Buffer unberührt;
  Wasser (proc_mode 2/11) wechselt bei aktivem SSR auf die Variante `transparent_water`,
  die Attachment 3 mitschreibt – das ersetzt `appendReflectiveTransparentToGbuffer()`.
- **Himmel**: die Sky-Layer- und Skybox-Pipelines schreiben unter MRT nur Attachment 1
  (Maske `[0, RGBA, 0, 0]`), der G-Buffer behält dort die Clear-0 = Himmel. Die Skybox
  linearisiert jetzt wie die Sky-Layer (`u_linear_output`).
- **Composite ist alpha-geblendet** über das Szenen-Target, damit unberührte Pixel die
  Clear-Farbe des Aufrufers behalten (`renderToImage`); premultipliziertes Alpha
  transparenter Flächen über Nichts wird vorher herausdividiert.
- **Gate**: MRT nur, wenn der Frame den G-Buffer braucht (`gbufferNeededThisFrame()`),
  der Built-in-Shader aktiv ist, die MRT-Shader kompiliert sind und das Szenen-Target
  einfach gesampelt ist; `hdrTarget`-Altpfad ausgenommen. `PHPOLYGON_VIO_MRT=0|1`.
- **Alter Pfad bleibt vollständig** (Fallback für Backends ohne MRT / MSAA / Shader-Override).

Belege: `tests/Rendering/Shader/MrtMeshShaderHeadlessTest.php` (Attachment-Summe ==
Forward-Linearfarbe mit/ohne Nebel; Attachment 3 == `gbuffer.frag`; `u_gbuffer_write`),
`tests/Rendering/VioRendererMrtTest.php` (Forward- vs. MRT-Bild auf D3D, Clear-Farbe,
Transparenz).
