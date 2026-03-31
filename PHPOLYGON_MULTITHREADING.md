# PHPolygon — Multithreading-Architektur

> **Claude Code Implementierungs-Briefing**
> Dieses Dokument beschreibt die vollständige Thread-Architektur für PHPolygon.
> Basis: PHP `parallel` Extension (ZTS), static-php-cli Build mit ZTS + parallel eingebaut.

---

## 1. Voraussetzungen

### PHP Extension

```bash
# static-php-cli Build mit ZTS + parallel
SPC_PHP_ENABLE_ZTS=yes ./bin/spc build parallel \
  --build-embed \
  --with-extensions=parallel,...
```

`parallel` erfordert **PHP ZTS (Zend Thread Safety)**. static-php-cli baut den Binary mit ZTS + parallel eingebaut — kein manuelles Setup für Endnutzer.

### Grundprinzip

```
parallel\Runtime   = ein Thread
parallel\Channel   = bidirektionale Kommunikation zwischen Threads
parallel\Future    = Rückgabewert eines einmaligen Tasks
parallel\Events    = non-blocking Channel-Polling
```

**Wichtig:** Closures in `parallel\Runtime::run()` dürfen **keine Objekte oder Ressourcen** aus dem Eltern-Scope capturen — nur serialisierbare Werte (Arrays, Primitives).

---

## 2. Kernprinzipien

### Main Thread ist autoritativer Writer

```
Subsystem → (Delta via Channel) → Main Thread → $worldState → Renderer
```

- Kein Subsystem schreibt direkt in den `$worldState`
- Subsysteme liefern nur **Deltas** zurück
- Main Thread ist der einzige Writer — kein Locking, keine Race Conditions
- `$worldState` ist der einzige echte Shared State

### Frame-Synchron vs. Non-Blocking

```
Frame N:
  Main  ──send(input)──► Physics    (Physics rechnet parallel)
  Main  ──send(input)──► Audio      (Audio rechnet parallel)
         │
         └──► renderer->frame()     (Render mit State von Frame N-1)
         │
  Main  ◄──recv()──────  Physics    (blockiert bis Physics fertig)
  Main  ◄──recv()──────  Audio      (blockiert bis Audio fertig)
```

Der Render-Call läuft **während** die Threads rechnen — das ist der eigentliche Parallelitäts-Gewinn.

---

## 3. Thread-Prioritäten & Kern-Skalierung

### Priorität 1 — immer eigener Thread (Minimum: 3 Kerne)

| Thread | Inhalt |
|---|---|
| `Main Thread` | Game Loop, Rendering, Player Input, lokale Simulation |
| `AI + Audio` | Audio hat CPU-Priorität, AI rechnet in den Lücken. Ab 8 Kernen: Audio bekommt eigenen Thread |

### Priorität 2 — eigener Thread ab 4 Kernen

| Thread | Inhalt |
|---|---|
| `NEXUS Runtime` | Globaler Koordinator (nur Netrunner — siehe Abschnitt 7) |
| `Physics` | Ab 5 Kernen eigener Thread |

### Priorität 3 — Worker Pool (restliche Kerne)

| Thread | Inhalt |
|---|---|
| `City Worker Pool` | Inaktive Städte, sequentiell bei 1 Worker, parallel bei mehreren |

### Kern-Szenarien

| Kerne | Konfiguration |
|---|---|
| 4 | Main · AI+Audio · NEXUS · Pool(1) |
| 6 | Main · AI+Audio · NEXUS · Physics · Pool(2) |
| 8 | Main · AI · Audio · NEXUS · Physics · Pool(3) |
| 12+ | alle Threads · Pool(6) |

---

## 4. ThreadScheduler — Bootstrap

```php
<?php

namespace PHPolygon\Thread;

use parallel\Runtime;
use parallel\Channel;

class ThreadScheduler
{
    private array $runtimes  = [];
    private array $channels  = [];

    public function boot(): void
    {
        $cores     = $this->getCpuCount();
        $available = $cores;

        // Priorität 1 — immer fix
        $this->spawnMain();    $available--;
        $this->spawnAiAudio(); $available--;  // kombiniert bis 8 Kerne

        // Ab 8 Kernen: Audio bekommt eigenen Thread
        if ($cores >= 8) {
            $this->spawnAudio(); // Audio von AI trennen
        }

        // Priorität 2 — ab 4 Kernen
        if ($available >= 1) { $this->spawnNexus();   $available--; }
        if ($available >= 1) { $this->spawnPhysics();  $available--; }

        // Priorität 3 — restliche Kerne als Worker Pool
        $workerCount = max(1, $available);
        $this->spawnCityPool($workerCount);
    }

    private function spawnCityPool(int $workers): void
    {
        $queue = new Channel(Channel::Infinite);

        for ($i = 0; $i < $workers; $i++) {
            $runtime = new Runtime();
            $runtime->run(function(Channel $queue) {
                while (true) {
                    $job = $queue->recv();
                    if ($job === null) break;
                    $this->tickCity($job);
                }
            }, [$queue]);
        }

        $this->channels['city_queue'] = $queue;
    }

    private function getCpuCount(): int
    {
        // Linux
        if (is_file('/proc/cpuinfo')) {
            $count = substr_count(file_get_contents('/proc/cpuinfo'), 'processor');
            if ($count > 0) return $count;
        }
        // macOS / BSD
        if (PHP_OS_FAMILY === 'Darwin') {
            return (int) shell_exec('sysctl -n hw.ncpu');
        }
        // Windows
        return (int) ($_SERVER['NUMBER_OF_PROCESSORS'] ?? 4);
    }
}
```

---

## 5. Subsystem-Interface

Jedes Subsystem implementiert dieses Interface:

```php
<?php

namespace PHPolygon\Thread;

use parallel\Channel;

interface SubsystemInterface
{
    /**
     * Läuft in eigenem Runtime-Thread.
     * Blockiert auf $in->recv() bis nächster Tick.
     * Sendet Delta via $out->send().
     */
    public function run(Channel $in, Channel $out): void;
}
```

### Beispiel: PhysicsSystem

```php
<?php

namespace PHPolygon\System;

use PHPolygon\Thread\SubsystemInterface;
use parallel\Channel;

class PhysicsSystem implements SubsystemInterface
{
    public function run(Channel $in, Channel $out): void
    {
        while (true) {
            $input = $in->recv();

            if ($input === null) break; // Shutdown-Signal

            $delta = $this->simulate($input);

            $out->send($delta); // nur Delta zurück
        }
    }

    private function simulate(array $input): array
    {
        return [
            'bodies'     => [],   // aktualisierte Körper-Positionen
            'collisions' => [],   // erkannte Kollisionen
        ];
    }
}
```

### Beispiel: AI + Audio kombiniert (schwache Hardware)

```php
<?php

namespace PHPolygon\System;

use parallel\Channel;
use parallel\Events;

class AiAudioSystem
{
    private const AUDIO_BUFFER_SIZE = 735; // Samples bei 60fps / 44100Hz

    public function run(Channel $aiIn, Channel $audioOut): void
    {
        while (true) {
            // Audio zuerst — immer, hat CPU-Priorität
            $audioOut->send($this->synthesizer->nextBuffer(self::AUDIO_BUFFER_SIZE));

            // AI in der verbleibenden Zeit — non-blocking
            try {
                $task = Events::poll($aiIn);
                if ($task) {
                    $this->ai->process($task->value);
                }
            } catch (\parallel\Events\Error\Timeout $e) {
                // Kein Task — normal, weiter
            }
        }
    }
}
```

---

## 6. Engine Bootstrap mit persistenten Runtimes

```php
<?php

namespace PHPolygon;

use parallel\Runtime;
use parallel\Channel;

class Engine
{
    private array $runtimes  = [];
    private array $channels  = [];
    private array $worldState = [
        'physics' => [],
        'audio'   => [],
        'ai'      => [],
        'assets'  => [],
    ];

    public function boot(): void
    {
        $subsystems = [
            'physics' => PhysicsSystem::class,
            'audio'   => AudioSystem::class,
            'ai'      => AISystem::class,
        ];

        foreach ($subsystems as $name => $class) {
            $in  = new Channel(Channel::Infinite);
            $out = new Channel(Channel::Infinite);

            $runtime = new Runtime();
            $runtime->run(function(string $class, Channel $in, Channel $out) {
                $system = new $class();
                $system->run($in, $out);
            }, [$class, $in, $out]);

            $this->runtimes[$name] = $runtime;
            $this->channels[$name] = ['in' => $in, 'out' => $out];
        }
    }

    public function shutdown(): void
    {
        // Shutdown-Signal an alle Threads
        foreach ($this->channels as $name => $channel) {
            $channel['in']->send(null);
        }
    }
}
```

---

## 7. Main Game Loop

```php
<?php

public function run(): void
{
    while (true) {
        $dt    = $this->timer->delta();
        $input = $this->input->poll(); // sofort, kein Thread

        // Player: direkt im Main Thread — zero latency
        $this->player->move($input, $dt);
        $this->player->resolveCollision($this->collisionMap);
        $this->camera->update($this->player);

        // Subsysteme mit aktuellem State füttern
        $this->channels['physics']['in']->send([
            'dt'     => $dt,
            'bodies' => $this->worldState['physics']['bodies'] ?? [],
        ]);
        $this->channels['ai']['in']->send([
            'playerPosition' => $this->player->getPosition(),
            'playerVelocity' => $this->player->getVelocity(),
            'navMesh'        => $this->navMesh,
            'dt'             => $dt,
        ]);

        // Render mit aktuellem Player-State + World State von Frame N-1
        $this->renderer->frame(
            $this->player,    // aktuell — zero latency
            $this->worldState // 1 Frame alt — akzeptabel für Welt-Objekte
        );

        // Frame-Ende: Ergebnisse einsammeln (blockiert auf langsamstem Thread)
        $this->worldState['physics'] = $this->channels['physics']['out']->recv();
        $this->worldState['ai']      = $this->channels['ai']['out']->recv();

        // Assets non-blocking pollen
        try {
            $asset = \parallel\Events::poll($this->channels['assets']['out']);
            if ($asset) {
                $this->worldState['assets'][$asset->value['id']] = $asset->value;
            }
        } catch (\parallel\Events\Error\Timeout $e) {}
    }
}
```

---

## 8. Latenz-Klassen — Was gehört wohin

| System | Thread | Warum |
|---|---|---|
| Player Input | Main Thread | Zero Latency — sofort |
| Player Movement | Main Thread | Zero Latency — sofort |
| Camera Update | Main Thread | Zero Latency — sofort |
| Hitscan Collision | Main Thread | Zero Latency — sofort |
| Renderer | Main Thread | OpenGL/Vulkan Context-Binding |
| World Physics | Physics Thread | 1 Frame Versatz akzeptabel |
| Ragdoll / Debris | Physics Thread | 1 Frame Versatz völlig egal |
| NPC-Bewegung (Interpolation) | AI Thread | 60fps, smooth |
| NPC-Perception | AI Thread | 60fps, kontinuierlich |
| A* Pathfinding | AI Thread | 10–20fps, teuer aber reicht |
| Audio-Synthese | Audio Thread | Eigener Takt, Buffer-kritisch |
| Asset Streaming | Asset Thread | Non-blocking, async |

---

## 9. AI-System — eigener Takt intern

AI läuft **kontinuierlich** mit dem Main Loop — nicht event-getriggert. Intern priorisiert es selbst was wie oft ausgeführt wird:

```php
<?php

namespace PHPolygon\System;

use parallel\Channel;

class AISystem
{
    private const PATHFIND_RATE = 10; // Hz — reicht für glaubwürdige Reaktion

    private float $lastPathfindTick = 0.0;
    private float $pathfindInterval;

    public function __construct()
    {
        $this->pathfindInterval = 1_000_000_000 / self::PATHFIND_RATE;
    }

    public function run(Channel $in, Channel $out): void
    {
        while (true) {
            $state = $in->recv(); // blockiert auf nächsten Frame
            if ($state === null) break;

            $now = hrtime(true);

            // Perception: jeden Frame — Spieler sehen
            foreach ($this->agents as $agent) {
                $agent->perceive($state['playerPosition']);
            }

            // Pathfinding: nur alle 100ms
            if (($now - $this->lastPathfindTick) >= $this->pathfindInterval) {
                foreach ($this->agents as $agent) {
                    $agent->updatePath($state['navMesh']);
                    $agent->think(); // FSM / BehaviorTree
                }
                $this->lastPathfindTick = $now;
            }

            // Bewegung interpolieren: jeden Frame smooth
            foreach ($this->agents as $agent) {
                $agent->interpolate($state['dt']);
            }

            $out->send([
                'agents' => array_map(fn($a) => $a->getState(), $this->agents)
            ]);
        }
    }
}
```

---

## 10. World State & CityState (für Netrunner)

```php
<?php

namespace PHPolygon\World;

/**
 * Schlankes State-Objekt pro inaktiver Stadt.
 * Wird von City Worker Threads geticktet.
 * Immer aktuell — kein Catch-up beim Stadtladen.
 */
class CityState
{
    public float $threatLevel    = 0.0;   // 0–5
    public float $economyIndex   = 1.0;
    public array $factionControl = [];
    public array $activeEvents   = [];
    public bool  $isPlayerKnown  = false;
    public int   $alertCooldown  = 0;
    public float $lastVisited    = 0.0;
}
```

### Stunden-Tick (Zweiphasen — kein Update-Order-Problem)

```php
<?php

public function onHourTick(float $gameHour): void
{
    // UNVERÄNDERLICHER Snapshot — alle Städte lesen dieselbe Version
    $snapshot = $this->worldState['cities'];

    // Phase 1: alle Cities gleichzeitig anstoßen
    foreach ($this->channels as $cityId => $channel) {
        $channel['in']->send([
            'gameHour'  => $gameHour,
            'ownState'  => $snapshot[$cityId],
            'allCities' => $snapshot, // kompletter Snapshot für Kontext
        ]);
    }

    // Phase 2: Deltas einsammeln
    foreach ($this->channels as $cityId => $channel) {
        $delta = $channel['out']->recv();
        $this->worldState['cities'][$cityId] = array_merge(
            $this->worldState['cities'][$cityId],
            $delta
        );
    }
}
```

---

## 11. NEXUS Runtime (Netrunner-spezifisch)

NEXUS ist kein Spieler, keine Stadt — NEXUS ist das globale Netzwerk. Alle Stadt-zu-Stadt-Kommunikation läuft **ausschließlich** über NEXUS. Das eliminiert das Update-Order-Problem vollständig.

```php
<?php

namespace Netrunner\Thread;

use parallel\Runtime;
use parallel\Channel;

class NexusRuntime
{
    private Channel $eventIn;   // Events von aktiver Stadt → NEXUS
    private Channel $directOut; // NEXUS-Direktiven → alle Städte

    public function boot(): void
    {
        $this->eventIn   = new Channel(Channel::Infinite);
        $this->directOut = new Channel(Channel::Infinite);

        $runtime = new Runtime();
        $runtime->run(function(Channel $in, Channel $out) {
            $nexus = new NexusIntelligence();

            while (true) {
                $event = $in->recv(); // irgendeine Stadt meldet ein Ereignis
                if ($event === null) break;

                // NEXUS entscheidet sofort und global
                $directives = $nexus->respond($event);

                // Broadcast an alle betroffenen Städte — simultan
                foreach ($directives as $directive) {
                    $out->send($directive);
                }
            }
        }, [$this->eventIn, $this->directOut]);
    }
}
```

### Event-Flow

```
Aktive Stadt:
  $nexusIn->send([
      'type'     => 'player_detected',
      'origin'   => 'volkhaven',
      'severity' => 4,
      'gameHour' => $currentHour,
  ]);

NEXUS antwortet simultan:
  → Ironspire: threatLevel + 2, Grenze schließen
  → Neonveil:  Bounty erhöhen, Desinformation
  → Ashgate:   Militäreinheiten aktivieren
  → alle:      Beobachtungsmodus ++
```

---

## 12. Double Buffering (optional — wenn zero Frame-Delay nötig)

Für Systeme die nie blockieren dürfen:

```php
<?php

// Physics bekommt State von Frame N
$physicsIn->send($stateN);

// Main rendert mit Physics-Ergebnis von Frame N-1 (sofort verfügbar)
$renderer->frame($worldState); // kein Warten

// Erst dann: Ergebnis von Frame N abholen
$worldState['physics'] = $physicsOut->recv();
```

**Konsequenz:** Physics-Ergebnis ist immer 1 Frame alt (~16ms bei 60fps). Für Welt-Physik akzeptabel. Für direkten Player-Input nie verwenden — der bleibt immer im Main Thread.

---

## 13. Shutdown-Pattern

```php
<?php

// Sauberes Herunterfahren aller Threads
public function shutdown(): void
{
    foreach ($this->channels as $name => $channel) {
        $channel['in']->send(null); // null = Shutdown-Signal
    }

    // Optional: warten bis alle Threads fertig
    foreach ($this->runtimes as $runtime) {
        $runtime->close();
    }
}
```

---

## 14. Implementierungs-Reihenfolge für PHPolygon

1. `ThreadScheduler` — Boot-Logik mit CPU-Erkennung
2. `SubsystemInterface` — gemeinsames Interface
3. `PhysicsSystem` — einfachster Thread zum Testen
4. Main Loop anpassen — send/recv Muster einbauen
5. `AISystem` mit internem Takt-Splitting
6. `AudioSystem` — Buffer-basiert, höchste Priorität
7. `AssetSystem` — non-blocking mit `Events::poll()`
8. `CityWorkerPool` — für Netrunner
9. `NexusRuntime` — für Netrunner

---

## 15. Wichtige Hinweise für Claude Code

1. **Kein `pcntl_fork`** — nur `parallel\Runtime` + `parallel\Channel`
2. **Keine Objekte in Closures capturen** — nur Arrays und Primitives
3. **Main Thread ist der einzige Writer** auf `$worldState`
4. **Player Input, Movement, Hitscan** — immer Main Thread, nie auslagern
5. **Audio hat immer höchste Priorität** — Buffer-Riss ist sofort hörbar
6. **AI läuft kontinuierlich** — nicht event-getriggert
7. **Pathfinding intern auf 10–20fps drosseln** — Perception bleibt 60fps
8. **Shutdown via `null`-Signal** auf den `in`-Channel
9. **Zweiphasen-Tick für Stunden-Events** — unveränderlicher Snapshot verhindert Update-Order-Probleme
10. **NEXUS ist der einzige globale Koordinator** — keine direkte Stadt-zu-Stadt-Kommunikation
