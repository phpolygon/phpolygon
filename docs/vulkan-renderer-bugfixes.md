# Vulkan Renderer — Bugfix Anleitung

Das Bild ist im Vulkan-Backend gestreckt/verzerrt, obwohl der OpenGL-Backend korrekt rendert.
Zwei Bugs sind gleichzeitig verantwortlich.

---

## Bug 1 — Fehlende Vulkan Clip-Space Correction

### Datei
`src/Rendering/VulkanRenderer3D.php` → Methode `uploadFrameUbo()`

### Ursache
`Mat4::perspective()` erzeugt eine **OpenGL-konforme Projektionsmatrix**:
- Y-Achse zeigt **nach oben** (Y = +1 oben im Bild)
- Z-Bereich: **−1 … 1**

Vulkan erwartet dagegen:
- Y-Achse zeigt **nach unten** (Y = +1 unten)
- Z-Bereich: **0 … 1**

Der Docblock in `Mat4::perspective()` dokumentiert das explizit:
> *"For Vulkan, compose with a clip-space correction matrix in the backend."*

Diese Korrektur wurde nie implementiert. Die Projektionsmatrix wird ungefiltert in den UBO
geschrieben und Vulkan interpretiert sie falsch → vertikale Verzerrung und fehlerhafte Tiefenwerte.

### Fix

Die aktuelle `uploadFrameUbo()` sieht so aus:

```php
private function uploadFrameUbo(): void
{
    $data = pack('f16', ...$this->viewMatrix) . pack('f16', ...$this->projMatrix);
    $this->frameUboMem->write($data, 0);
}
```

Ersetze sie durch:

```php
private function uploadFrameUbo(): void
{
    // Vulkan clip correction (column-major):
    //   Row 1 negiert  → Y-Achse flippen (OpenGL Y-up → Vulkan Y-down)
    //   Z-Spalte ×0.5, Offset +0.5 → Z-Bereich [-1,1] → [0,1]
    // Anwendung links der Projektionsmatrix: correctedProj = clipMatrix * proj
    $vulkanClip = new Mat4([
         1.0,  0.0,  0.0,  0.0,
         0.0, -1.0,  0.0,  0.0,
         0.0,  0.0,  0.5,  0.0,
         0.0,  0.0,  0.5,  1.0,
    ]);
    $correctedProj = $vulkanClip->multiply(new Mat4($this->projMatrix));
    $data = pack('f16', ...$this->viewMatrix)
          . pack('f16', ...$correctedProj->toArray());
    $this->frameUboMem->write($data, 0);
}
```

`Mat4::multiply()` ist bereits vollständig in `src/Math/Mat4.php` implementiert —
kein Hilfscode nötig.

---

## Bug 2 — Logische Pixel statt Framebuffer-Pixel (Retina / HiDPI)

### Datei
`src/Engine.php` → Methode `run()` → Vulkan-Branch im `match`-Ausdruck

### Ursache
Der `VulkanRenderer3D` wird aktuell so erstellt:

```php
'vulkan' => new VulkanRenderer3D(
    $this->config->width,     // ← logische Fensterkoordinaten (z. B. 1440)
    $this->config->height,    // ← logische Fensterkoordinaten (z. B. 900)
    $this->window->getHandle(),
),
```

Auf einem Retina-Mac entsprechen `config->width/height` den **logischen Punkten** des Fensters.
Der echte Framebuffer hat dabei die doppelte Pixelauflösung (z. B. 2880 × 1800).

Der Swapchain wird mit der halben Auflösung erstellt und von MoltenVK auf die physische Surface
hochskaliert → unscharfes, gestrecktes Bild.

`Window` verwaltet bereits beide Werte korrekt:
- `getWidth()` / `getHeight()` → logische Punkte
- `getFramebufferWidth()` / `getFramebufferHeight()` → physische Pixel (per `glfwGetFramebufferSize`)

### Fix

```php
'vulkan' => new VulkanRenderer3D(
    $this->window->getFramebufferWidth(),    // physische Pixel
    $this->window->getFramebufferHeight(),   // physische Pixel
    $this->window->getHandle(),
),
```

`window->initialize()` wird vor diesem Block aufgerufen, daher sind
`getFramebufferWidth()` / `getFramebufferHeight()` beim Erstellen des Renderers bereits befüllt.

---

## Zusammenfassung der Änderungen

| Datei | Methode | Änderung |
|---|---|---|
| `src/Rendering/VulkanRenderer3D.php` | `uploadFrameUbo()` | Clip-Correction-Matrix vor Upload anwenden |
| `src/Engine.php` | `run()` | `config->width/height` → `window->getFramebufferWidth/Height()` |

---

## Verifikation

Nach beiden Fixes:

1. `renderBackend3D = 'vulkan'` in `engine.json` setzen
2. Beach-Szene starten
3. Palme und Terrain müssen **identisch zum OpenGL-Backend** aussehen
4. Auf Retina-Mac: Bild **scharf** (kein Unschärfe-Scaling)
5. Kein vertikaler Flip, keine Depth-Artefakte, korrektes Seitenverhältnis
