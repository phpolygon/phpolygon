# VIO-UPGRADE-PLAN – Engine auf das nächste php-vio-Release heben

Stand 2026-10-30. Grundlage ist der php-vio-Branch `feat/agility-1620` (Plan dort: `AGILITY-1620-PLAN.md`): Agility SDK 1.620, Micromap-Baker
`vio_opacity_micromap_bake`, neue Caps in `vio_backend_info()`. Dieses Dokument legt fest, was die Engine davon übernimmt, in welcher Reihenfolge
und was sie bewusst nicht tut. Es bleibt spielagnostisch: Alles Spielspezifische kommt als Konfiguration herein.

## Ausgangslage

| Thema | Stand in der Engine |
|---|---|
| Agility SDK | nicht genutzt, D3D12 läuft auf der Runtime des Betriebssystems. `EngineConfig` hat keine Option, `StaticPhpResolver` liefert nur `d3dcompiler_47`, DXC und `vulkan-1.dll` |
| GPU-Zeiten | `VioRenderer3D::endFrame` liest nur `vio_gpu_frame_time`, also die Summe je Frame. `vio_gpu_timestamp` wird nirgends aufgerufen |
| Shader-Cache | `EngineConfig::$shaderCachePath` wird an `vio_create` gereicht, der Cache beginnt beim Spieler leer |
| Hardware-Raytracing | nicht genutzt: keine `vio_acceleration_structure`, kein `vio_rt_pipeline`, kein `rayQueryEXT` |
| Alpha-Cutout | gibt es nicht: Blätter sind echte Meshes (`Material::$twoSided`, `$foliageWind`), kein Alpha-Schnitt im Material, kein `discard` in den Shadern |
| Device Lost | kein Weg von den php-vio-Meldungen (stderr) ins Engine-Log |

Gemessen (größter Shader `mesh3d`, 61 KB Fragment-Shader, RTX 2080, Treiber 617.42, SM 6.9, vier Runden):

| Schritt | kalt | warm |
|---|---|---|
| `vio_shader` | 503–510 ms | 103–119 ms |
| `vio_pipeline` (PSO, Treiber) | 325–337 ms | 2 ms |

Die Summe über alle Shader ist nicht gemessen. Keine der Neuerungen aus Agility 1.620 hebt die Framerate.

## Grundsätze

- Wie in der Engine üblich: Gates je Feature, nie je php-vio-Version. Fehlt eine Funktion oder Cap, läuft alles wie bisher.
- Reihenfolge nach Nutzen, nicht nach SDK: Was ohne Agility geht, kommt zuerst.
- Jede Phase ist ein eigener Branch mit Tests und Release (Tag), danach heben die Spiele die Version.
- Was in php-vio gehört, kommt dort hinein und nicht als Umweg in die Engine.

## Phase 0 – php-vio-Release übernehmen

Voraussetzung: `feat/agility-1620` ist gemergt und released.

- Lokale Entwicklungs- und CI-Runtime auf das neue Release heben.
- `VioNativeUpscalerTest`, Screenshot- und Shader-Tests laufen unverändert. Erwartung: keine sichtbare Änderung, der Build-Header-Wechsel in php-vio betrifft nur D3D12.
- Keine neue Engine-Funktion in dieser Phase.

**Fertig, wenn** die Engine-Suite auf Windows, Linux und macOS gegen das neue Release grün ist.

## Phase 1 – GPU-Zeit je Pass

Der größte Hebel dieses Plans: Heute sieht man nur die Frame-Summe, nicht welcher Pass sie kostet. Braucht kein Agility SDK.

- `VioRenderer3D` setzt `vio_gpu_timestamp($ctx, 'name')` an den Passgrenzen: Schatten, G-Buffer bzw. Forward, SDF-AO, SSAO, SSR, Himmel, Bloom, TAA bzw. Upscaler, Post, Outline.
- `endFrame` liest `vio_gpu_timings($ctx)` und meldet jeden Eintrag über `PerfProfiler::record()`, genau wie heute `vio_gpu_frame_time`.
- **Nur bei aktivem Profiler** (`PerfProfiler::isActive()`): Auf Metal schließt jede Marke den Command-Buffer des Abschnitts, das hat Kosten im Spielbetrieb.
- Gate: `VIO_FEATURE_GPU_TIMESTAMP`. Höchstens 32 Marken je Frame.
- Zeiten folgen dem letzten fertigen Frame, nicht dem aktuellen; wie bei `vio_gpu_frame_time`.

**Tests:** Headless-Test mit aktivem Profiler: Jeder gelaufene Pass taucht auf, die Summe passt in die Frame-Zeit, ohne Profiler entsteht keine Marke. Backends ohne Feature liefern keine Einträge und keinen Fehler.

**Später:** php-vio-Issue #94 macht aus denselben Marken PIX-Marker. Die Engine muss dafür nichts weiter tun, deshalb lohnt sich die Benennung jetzt.

## Phase 2 – Start beschleunigen

Zwei getrennte Punkte, beide ohne Agility SDK.

1. **Warmer Cache (php-vio).** Warm kostet `mesh3d` weiter rund 100 ms bei jedem Start, obwohl der Cache beide Stages trifft. Vermutlich ist das die Übersetzung GLSL → HLSL vor der Cache-Abfrage. Umsetzung in php-vio (Issue #92, Abschnitt „Vorarbeit“), die Engine profitiert ohne Änderung. Dafür hier nur nachmessen: Startzeit vorher und nachher über den ganzen Shader-Satz.
2. **Vorbefüllter Cache (Engine, Build).** Spike vor jeder Umsetzung. Der Build erzeugt Shader-Blobs, das Spiel kopiert sie beim ersten Start nach `$shaderCachePath`. Das spart den DXC-Anteil des Kaltstarts, nicht den PSO-Anteil im Treiber.
   - Offen: Der Cache-Schlüssel hängt von Profil und HLSL ab, das Shader-Model-Profil aber vom Gerät. Es bräuchte einen Satz je Profil.
   - Offen: Passt die Größe, und wie viel spart es über alle Shader?
   - Abbruchkriterium: Bleiben unter einer Sekunde Ersparnis im Kaltstart, entfällt der Punkt.

**Tests:** Der Spike liefert Messwerte. Bei Umsetzung ein Test, der einen vorbefüllten Cache trifft (`vio_shader_cache_stats()['hits']`) und bei fremdem Profil sauber neu kompiliert.

## Phase 3 – Agility SDK optional ausliefern

Bewusst opt-in und standardmäßig aus. Ein Spiel schaltet es nur ein, wenn es ein Feature braucht, das nur so geht, zum Beispiel Dump-Dateien aus Phase 4 oder später SM 6.10.

- `EngineConfig`: neue Option `$vioAgilitySdk = ''`, Verzeichnis mit `D3D12Core.dll` relativ zur Executable. `VioWindow` reicht sie als `'agility_sdk'` weiter, wie heute `$vioDxcDir`.
- `StaticPhpResolver`: lädt `D3D12Core.dll` aus dem NuGet-Paket `Microsoft.Direct3D.D3D12` in fester Version, gecacht wie DXC, und nur wenn das Spiel es verlangt. `d3d12SDKLayers.dll` gehört nicht in ein Release-Build.
- `PlatformPackager` kopiert Laufzeit-Bibliotheken heute nach Basisnamen neben die Executable. Die Agility-DLL gehört in ein Unterverzeichnis (`D3D12\`), der Packager muss das lernen.
- `VioWindow` loggt `vio_swapchain_info()['agility_sdk']`, damit eine stille Rückkehr auf die OS-Runtime im Log sichtbar ist.

Vor dem Einschalten in einem Spiel zu prüfen:

- Funktioniert der relative Pfad mit der `micro.sfx`-Runtime? php-vio sucht relativ zum Verzeichnis von `php.exe`.
- Läuft es auf Rechnern ohne Entwicklermodus und auf einem sauberen Windows?
- Verhalten auf AMD und Intel. Getestet ist bisher nur die RTX 2080.
- Das Paket erlaubt die Weitergabe von `D3D12Core.dll`, `d3d12SDKLayers.dll` und `d3dconfig.exe`; der Lizenztext ist vor dem ersten Release zu prüfen.

**Tests:** Packaging-Test: Mit Option liegt die DLL im Unterverzeichnis, ohne Option nirgends. Laufzeit-Test mit gesetzter Option meldet die SDK-Version, ein falscher Pfad führt zu Warnung plus Betrieb auf der OS-Runtime.

## Phase 4 – Diagnose bei Geräteverlust

Abhängig von php-vio: Die Daten gehen dort heute nur nach stderr.

- **php-vio zuerst:** Eine PHP-lesbare Quelle für den letzten Geräteverlust (Grund, `DEVICE_HUNG`/`PAGE_FAULT`, Pfad einer Dump-Datei). Dump-Dateien selbst sind in php-vio noch offen.
- **Engine:** beim Start `vio_backend_info()['caps']` (`async_commands`, `fence_barriers`, `dump_file`) ins Log, bei Geräteverlust Grund und Dump-Pfad ins Log und an einen Hook, über den ein Spiel den Bericht in seine Fehlermeldung übernimmt.
- Der Fehlercode kommt ohne Agility nicht; ohne Agility bleibt es bei `GetDeviceRemovedReason` und DRED.

**Tests:** Simulierter Geräteverlust ist schwer zuverlässig herzustellen; getestet wird der Hook mit eingespeisten Daten.

## Phase 5 – Hardware-Raytracing: Sonnenschatten per Ray Query

Der erste echte Nutzer von Hardware-RT in der Engine. Er braucht **keine** Micromaps: Die Engine-Geometrie ist undurchsichtig, ein Strahl trifft sie ohne Any-Hit-Shader.

Entscheidung: **Sonnenschatten als Maskenpass.** Ein Compute-Kernel rekonstruiert je Pixel die Weltposition aus der Tiefe, schießt einen Strahl zur Sonne und schreibt eine Schattenmaske. Der Forward-Pfad und `composite.frag` lesen die Maske statt der Kaskaden.

Warum kein Ray Query direkt in `mesh3d.frag`: Das wäre eine zweite Variante des größten Shaders (61 KB, kalt rund 840 ms inkl. PSO) und nur für einen Teil der Backends übersetzbar. Der Maskenpass bleibt ein eigener kleiner Kernel, die Materialien bleiben unberührt.

Bausteine:

- **Beschleunigungsstruktur** als eigene Klasse (`Rendering\Raytracing\…`): sammelt aus `RenderCommandList` die schattenwerfenden Draws (`DrawMesh::$castShadows`, `DrawMeshInstanced`), je Mesh ein BLAS, je Instanz ein TLAS-Eintrag.
- **Aktualisierung** über `vio_acceleration_structure_update` vor `vio_begin`, denn innerhalb eines Frames lehnt php-vio das ab: `refit` für bewegte Objekte, `rebuild` bei geänderter Liste, `full` bei neuem Mesh.
- **Fallback:** Kaskaden bleiben vollständig erhalten. Gate `VIO_FEATURE_RAY_QUERY` und Compute, nur dann die Option. OpenGL und D3D11 können es nicht.
- **Option:** neues Grafik-Setting, Standard aus, dazu `PHPOLYGON_VIO_RT_SHADOWS=0|1` wie bei den übrigen Escape-Hatches.

Bekannte Grenzen, die zu klären sind:

| Thema | Problem | Vorgehen |
|---|---|---|
| Vertex-Animation | `foliageWind`, Gras und `cloth` verformen im Vertex-Shader, der BLAS kennt das nicht. Schatten und Bild laufen auseinander | zuerst statische Pose, Abweichung bewerten; Alternative: diese Materialien behalten Kaskaden |
| Instanzmenge | Vegetation kann sehr viele Instanzen haben, jede wird ein TLAS-Eintrag | Spike misst Bauzeit und Speicher an einer echten Szene, ggf. Distanzgrenze |
| GPU-Instanzen | `DrawMeshInstanced::fromStorageBuffer` hält Matrizen nur auf der GPU, TLAS-Einträge brauchen sie auf der CPU | bleiben wie heute ohne Schatten |
| Transparenz | Glas und Wasser sollen keinen harten Schatten werfen | über `$castShadows` bzw. Instanz-`mask` ausschließen |

**Spike vor der Umsetzung:** auf der RTX 2080 an einer großen Szene Bauzeit, Refit-Zeit, Speicher und Kosten der Maske gegenüber den Kaskaden messen. Dieser Plan enthält dazu noch keine Zahlen. Abbruchkriterium: teurer als die Kaskaden ohne sichtbaren Gewinn.

**Tests:** Headless-Test gegen eine Szene mit bekanntem Schattenwurf: Maske und Kaskaden stimmen bis auf eine Kantentoleranz überein. Fallback auf Backends ohne Feature ohne Bildänderung. Bewegtes Objekt: Schatten folgt nach dem Refit.

## Phase 6 – Alpha-Cutout und Opacity Micromaps

**Micromaps sind keine Voraussetzung für Hardware-RT, sondern eine spätere Beschleunigung.** Sie sparen Any-Hit-Aufrufe an Alpha-Cutout-Geometrie, und die gibt es in der Engine bisher nicht: Blätter sind echte undurchsichtige Meshes, `Material` hat keinen Alpha-Schnitt, in den Shadern steht kein `discard`. Ohne Teil 1 hätte eine Micromap nichts zu tun.

Teil 1 – **Alpha-Cutout im Material** (eigener Nutzen, auch ohne RT):

- `Material` bekommt einen Alpha-Schnitt für `$albedoTexture`. `mesh3d.frag`, `shadow` und `gbuffer` verwerfen danach; die MRT-Regeln aus `VioMrtEarlyReturnAlphaTest` sind zu beachten.
- Nutzen: Blatt-Karten statt einzelner Blatt-Geometrie, also weniger Dreiecke in Vegetation. Ob das optisch zum Bildstil passt, entscheidet das jeweilige Spiel.

Teil 2 – **Micromaps im BLAS:**

- Beim Aufbau für Cutout-Meshes `vio_opacity_micromap_bake` aufrufen und die Map als `'opacity_micromap'` mitgeben.
- Ray Queries haben keinen Any-Hit-Shader: Unbekannte Zustände müsste der Kernel selbst auflösen, dafür braucht er Textur und UV je Treffer. Einfachster Einstieg ist deshalb **Format 2**, das keine Any-Hit-Entscheidung braucht und nur an den Kanten gröber ist. Für Schatten reicht das meist.
- Vorgabe Subdivision 6. Gemessen an 80 Blatt-Karten, 40 je Strahl, mit trivialem Any-Hit auf der RTX 2080 (Format 4):

| Subdivision | Any-Hit gespart | Backen | Gepackt |
|---|---|---|---|
| 2 | 0 % | 8 ms | unter 1 KiB |
| 4 | 67 % | 52 ms | 5 KiB |
| 6 | 90 % | 87 ms | 80 KiB |
| 8 | 96 % | 370 ms | 1280 KiB |

- Backen dauert spürbar: Maps je Textur und UV-Satz cachen und im Ladeschritt erzeugen, nicht im Frame.
- Der Baker liest nur Mip 0.
- Eine Map je Mesh: Die php-vio-API erlaubt keine zwei verschiedenen Maps für dasselbe Mesh.
- Gate `VIO_FEATURE_OPACITY_MICROMAP`. **Auf D3D12 braucht das DXR 1.2**, belegt ist das bisher nur mit Agility SDK, also Phase 3. Auf Vulkan genügt `VK_EXT_opacity_micromap`. Metal, OpenGL und D3D11 haben keine Micromaps.

**Tests:** Cutout: Pixel unter dem Schnitt schreiben weder Farbe noch Tiefe, auf beiden Pfaden und im Schatten-Pass. Micromaps: Der Maskenpass liefert mit und ohne Map dasselbe Bild.

## Nicht in diesem Plan

- **Advanced Shader Delivery** (php-vio #92): Für Steam ist kein Verteilweg bestätigt.
- **Serialisierte Root Signatures** (php-vio #93): allein ohne Nutzen.
- **Batched Async Commands, Fence Barriers:** liegen vollständig in php-vio; die Engine merkt davon nichts.
- **Shader Model 6.10:** Preview, keine Herstellerbasis, keine Anwendung in der Engine.

## Reihenfolge und Aufwand

| Phase | Hängt ab von | Aufwand | Nutzen |
|---|---|---|---|
| 0 Release übernehmen | php-vio-Release | klein | Grundlage |
| 1 GPU-Zeit je Pass | 0 | klein | hoch für Optimierung |
| 2 Start | php-vio #92 für Teil 1, Spike für Teil 2 | klein bis mittel | mittel, bei jedem Start |
| 3 Agility opt-in | 0 | mittel | allein keiner, Grundlage für 4 |
| 4 Diagnose | php-vio, 3 für den Fehlercode | mittel | hoch, sobald Spieler Geräteverluste melden |
| 5 Hardware-RT-Schatten | 0, Spike | groß | noch nicht gemessen, entscheidet der Spike |
| 6 Alpha-Cutout + Micromaps | 5; auf D3D12 auch 3 | mittel | Cutout: weniger Dreiecke; Micromaps: nur an Cutout-Geometrie |
