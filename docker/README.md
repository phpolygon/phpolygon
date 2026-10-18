# Cross-platform build in Docker

Build a PHPolygon game's bundles for **windows-x86_64, linux-x86_64, macos-arm64
and macos-x86_64** inside a single Linux container - no macOS host needed.

This harness is **game-agnostic**: the game project is bind-mounted at `/app` and
the app name, version and build types are read from its `build.json` at runtime.
The same image builds any PHPolygon game.

## Why this works (and what it can't do)

The `phpolygon build` step is **pure packaging**: it stages the PHAR, downloads
the pre-built `micro.sfx` PHP runtime from the static-php-cli releases, and
assembles the platform bundle. None of that needs a Mac.

The only genuinely macOS-bound step is **code signing**. We sidestep it:

- **Ad-hoc signing** (mandatory so the arm64 binary even launches on Apple
  Silicon) is done in-container with [`rcodesign`](https://github.com/indygreg/apple-platform-rs),
  which runs on Linux. No Apple ID, no certificate. Apple's own `codesign -dvvv`
  reads the resulting signature as `flags=0x2(adhoc)`.
- **Notarization** is *skipped on purpose*. In the Steamworks depot settings you
  mark the macOS build as **not notarized**; the Steam client strips the
  `com.apple.quarantine` attribute on launch, so Gatekeeper never runs the
  notarization check.

### How the macOS signing actually works (important)

phpmicro produces the final binary by **appending the PHAR after the Mach-O**.
Any Mach-O signer - rcodesign *and* Apple's `codesign` - rewrites the Mach-O and
**drops trailing data**, which would strip the PHAR and break the game. So the
order matters: the image signs the **bare `micro.sfx` runtime first**, then
`phpolygon build` appends the PHAR. The final layout is `[signed Mach-O][PHAR]` -
the signature covers the runtime, the PHAR is trailing data that phpmicro reads
from the end. This is the only order that yields a binary that is both signed and
runnable. The build verifies it and prints the trailing payload size.

Since the shared-PHAR layout the trailing payload of a macOS binary is only a
small launcher; the PHAR itself is `Contents/Resources/<name>.phar`, one file for
the arm64 and the x86_64 binary of the universal bundle, with its assets already
extracted next to it - the game never writes into the bundle (logs:
`~/Library/Logs/<name>/`, saves: `~/Library/Application Support/<identifier>/`).
The runtime is signed with JIT/library entitlements (`allow-jit`,
`allow-unsigned-executable-memory`, `disable-library-validation`);
`MAC_HARDENED=1` also sets the hardened-runtime flag. The universal bundle's shell
launcher (`macos-launcher.sh`, installed in the image; a game overrides it with
build.json `platforms.macos.launcher`) picks arm64 Rosetta-safe, forwards
SIGTERM and writes `~/Library/Logs/<name>/launch.log` (chosen binary, stderr,
exit status - 137 means the system killed it, e.g. over its signature). The
upload copies all depots to `STEAM_STAGE` (container filesystem, default
`/tmp/phpolygon-steam-content`), sets and verifies the executable bits there,
and aborts when a binary or `.dylib` lacks its arm64/x86_64 slice. Rebuild the
image after updating the engine (the launcher template is baked in).


> Do **not** "improve" this into post-build bundle/binary signing. Signing the
> final binary rewrites the Mach-O and truncates the appended PHAR (observed
> ~98 MB -> ~60 MB), producing a binary that is signed but no longer runnable.

> **Verify launch on a real Apple Silicon Mac.** The signature is structurally
> valid (confirmed with Apple `codesign`), but this container can't *launch* an
> arm64 binary. Before shipping, run the `.app` once on an M-series Mac to
> confirm the window opens and phpmicro loads the PHAR.

## A GitHub token is required

`phpolygon build` re-resolves the (SSH-URL) `phpolygon/phpolygon` repo via
Composer, and the runtime prefetch hits the GitHub API. Both fail on the
container's shared egress IP without auth. Pass **any** valid token (the repos
are public, so no special scopes are needed):

- Easiest: `gh auth token` (if you have `gh` set up).
- Or a classic/fine-grained PAT with default read access.

The token is also used to prefetch `micro.sfx` with an authenticated request
into the resolver's cache dir (`~/.phpolygon/build-cache/<key>/`). The engine's
`StaticPhpResolver` then finds it in cache and skips the rate-limited anonymous
download.

## 1. Build the image

Run from the **engine** repo root. The build context is just the `docker/` dir,
so the engine tree is **not** shipped to the daemon - the game source is
bind-mounted at runtime.

```bash
docker build -f docker/Dockerfile.build -t phpolygon-build docker
```

## 2. Run a build

The **game** repo is bind-mounted at `/app`, so artifacts land in its local
`build/` directory. A named volume keeps the `micro.sfx` download cache warm
between runs.

### Windows (PowerShell)

```powershell
docker run --rm `
  --platform linux/amd64 `
  -e GITHUB_TOKEN=$(gh auth token) `
  -v "${PWD}:/app" `
  -v phpolygon-buildcache:/root/.phpolygon `
  phpolygon-build
```

### Windows (cmd.exe)

```bat
for /f %i in ('gh auth token') do set GH=%i
docker run --rm --platform linux/amd64 -e GITHUB_TOKEN=%GH% -v "%cd%:/app" -v phpolygon-buildcache:/root/.phpolygon phpolygon-build
```

### macOS / Linux (bash)

```bash
docker run --rm \
  --platform linux/amd64 \
  -e GITHUB_TOKEN="$(gh auth token)" \
  -v "$PWD:/app" \
  -v phpolygon-buildcache:/root/.phpolygon \
  phpolygon-build
```

Outputs (`<App>` = the `name` from the game's `build.json`):

```
build/windows-x86_64-steam/<App>/        # .exe + dlls
build/linux-x86_64-steam/<App>/           # ELF + .so
build/macos-arm64-steam/<App>.app         # ad-hoc signed
build/macos-x86_64-steam/<App>.app        # ad-hoc signed
```

### Interactive mode

Add `-it` (allocate a TTY) and the build asks you, up front, for the **target
version**, **platforms**, **build type** and **signing**, then shows a confirm
prompt. The chosen version is written into the game's `build.json` (`version`)
and its version constant file (auto-detected `bootstrap_version.php` →
`GAME_VERSION`) before building - one flow for every game.

```bash
docker run --rm -it \
  --platform linux/amd64 \
  -e GITHUB_TOKEN="$(gh auth token)" \
  -v "$PWD:/app" -v phpolygon-buildcache:/root/.phpolygon \
  phpolygon-build
```

PowerShell: `docker run --rm -it --platform linux/amd64 -e GITHUB_TOKEN=$(gh auth token) -v "${PWD}:/app" -v phpolygon-buildcache:/root/.phpolygon phpolygon-build`

Without a TTY the build runs non-interactively from env vars (CI-friendly). If a
terminal mis-reports its TTY (some Windows shells), force the menu with
`-e INTERACTIVE=1`; force env-driven mode with `-e NONINTERACTIVE=1`.

### Speed: the PHAR is built once

The staged PHP code is identical for every desktop target of the same
variant/build-type, so the harness builds the `game.phar` **once** (first target)
and the rest reuse it via `phpolygon build --phar` - only micro.sfx + combine +
package run per reuse target. In practice the first target takes ~50-90s
(composer + staging + PHAR) and each remaining target ~1-4s, run in parallel.
All four desktop targets land in roughly the time of one.

Further build types do not repeat any of it: the first type builds the PHAR, every
later one derives its PHAR from it in about two seconds (`phpolygon build
--derive-phar`, only the type's constants differ). A type whose hooks produce
type-specific files sets `"sharedPhar": false` in `build.json`.

### Speed: build in a volume, not on the bind mount

On Docker Desktop (Windows, macOS) the `/app` bind mount is the bottleneck - for a
594 MB package of 18k files: **98 s** to write it there, **1 s** into a volume,
64 s to delete the previous one, 186 s to read it. Mount a named volume and point
`PHPOLYGON_WORKDIR` at it: the harness mirrors `/app` into it with rsync (first run
copies, later runs only what changed; `build/` and `.git` stay out, so do the
rsync patterns in `PHPOLYGON_SYNC_EXCLUDE` - not `resources.exclude`: those files
never ship, but hooks read them) and builds there. `build/` then lives in that volume; mount it
again for an upload step, or set `PHPOLYGON_COPY_OUT` to get packages back.

The copy logs its progress line by line (rsync `--info=progress2`, one total percentage; a
line every 5 % or 10 s - `PHPOLYGON_SYNC_PROGRESS_STEP` / `_SECS` - then a summary). Nothing
changed = one line:

```text
  mirroring /app -> /work (rsync, 18 excludes)...
  sync: 43 % – 1002.7 MB – 12.7 MB/s – 1:44 left
  sync: done – 27,997 of 27,997 files, 2.3 GB of 2.3 GB – 298 s
  sync: up to date – 27,595 files checked (2.2 GB) – 22 s
```

**Seeding hook outputs.** Prebake outputs (`hooks.beforeBuild`) usually belong in
`PHPOLYGON_SYNC_EXCLUDE`, so a stale host copy never overwrites what the container rendered.
When the host copy is the newer one (re-rendered on the host), list the paths in
`PHPOLYGON_SYNC_SEED` too: after the mirror each one is copied with `rsync -a --update` - a
host file only when it is newer than the volume's, nothing in the volume deleted - so the hook
finds it up to date instead of rendering everything again. A path missing on the host is
reported and skipped.

```text
  seed assets/audio/voice/: 50 % – 173.1 MB – 9.6 MB/s – 0:29 left
  seed assets/audio/voice/: done – 15,412 of 15,412 files, 346.3 MB of 346.3 MB – 46 s
  seed assets/holograms/: up to date – 330 files checked (17.2 MB) – 0 s
```

The sync functions live in `sync-workdir.sh`; `tests/sync-workdir-test.sh` checks them in the
image: `docker run --rm --entrypoint bash -v "$PWD/docker:/harness" phpolygon-build /harness/tests/sync-workdir-test.sh`.

```bash
docker run --rm --platform linux/amd64 \
  -e GITHUB_TOKEN="$(gh auth token)" -e PHPOLYGON_WORKDIR=/work \
  -v "$PWD:/app" -v mygame-work:/work -v phpolygon-buildcache:/root/.phpolygon \
  phpolygon-build
```

### Build hooks

`build.json` `hooks.beforeBuild` run inside the container before the first PHAR is built. `@php` falls back to the game's static runtime when the image PHP lacks a function the hook `requires` (e.g. GD with FreeType or php-vio), and the image carries `build-essential`/`cmake` for hooks that compile tools. Set `-e PHPOLYGON_SKIP_HOOKS=1` to skip them.

## 3. Options (env vars)

| Var | Default | Values |
|-----|---------|--------|
| `VERSION` | _(keep)_ | version to stamp into `build.json` + the version const file before building |
| `PLATFORMS` | `all` | `all`, or any subset of `windows-x86_64 linux-x86_64 macos-arm64 macos-x86_64` |
| `TYPE` | `full` | `full`, any build type from the game's `build.json`, or `all-types` |
| `VARIANT` | `steam` | any variant the engine supports (e.g. `base`, `steam`) |
| `SIGN_MAC` | `1` | `1`, `0` |
| `PHP_VERSION` | `8.5` | micro.sfx runtime PHP version (matches `StaticPhpResolver`) |
| `SPC_REPO` | `hmennen90/static-php-cli` | static-php-cli releases repo (matches `StaticPhpResolver::GITHUB_REPO`) |
| `INTERACTIVE` | _(auto)_ | `1` forces the menu even without a detected TTY |
| `NONINTERACTIVE` | `0` | `1` forces env-driven mode even with a TTY |
| `VERSION_CONST_FILE` / `VERSION_CONST` | _(auto)_ | override the in-game version constant file / name (default `bootstrap_version.php` / `GAME_VERSION`); `phpolygon.project.json` `version` follows too |
| `PHPOLYGON_WORKDIR` | _(off)_ | path of a mounted volume to build in (rsync mirror of `/app`, see above) |
| `PHPOLYGON_SYNC_EXCLUDE` | _(none)_ | rsync patterns the `PHPOLYGON_WORKDIR` mirror skips (`"artwork/ docs/"`) - only what no build step reads |
| `PHPOLYGON_SYNC_SEED` | _(none)_ | paths (relative to `/app`) copied into the mirror afterwards where the host file is newer (`rsync --update`, no delete) - hook outputs, may also be excluded |
| `PHPOLYGON_SYNC_PROGRESS_STEP` / `_SECS` | `5` / `10` | sync progress line every N percent / N seconds |
| `PHPOLYGON_COPY_OUT` | _(none)_ | with `PHPOLYGON_WORKDIR`: `all` or package dir names (`windows-x86_64-steam ...`) copied back to `/app/build` |

`TYPE` and `VARIANT` are passed straight through to `phpolygon build`; the engine
reads the build type's constant overrides from the game's `build.json`. The
harness adds no game-specific build mechanism of its own.

Example - only the two macOS targets:

```bash
docker run --rm --platform linux/amd64 \
  -e GITHUB_TOKEN="$(gh auth token)" \
  -e PLATFORMS="macos-arm64 macos-x86_64" \
  -v "$PWD:/app" -v phpolygon-buildcache:/root/.phpolygon \
  phpolygon-build
```

## 4. Steam upload

The harness can authenticate, build, and push to Steam in one run. Set `UPLOAD`
to one or more **upload target** names (in interactive mode you're asked after the
build prompts). The flow is **auth → build → upload**: it logs in first (so you
enter Steam Guard up front), builds all four desktop targets, assembles the macOS
**universal** bundle (one `.app`, launcher picks arm64/x86_64), generates one
`app_build` VDF per target and uploads all of them in **one** steamcmd session
(`steamcmd +login <user> +run_app_build <vdf1> +run_app_build <vdf2> … +quit`).

```bash
docker run --rm -it \
  --platform linux/amd64 \
  -e GITHUB_TOKEN="$(gh auth token)" \
  -e UPLOAD="full" \
  -v "$PWD:/app" \
  -v phpolygon-buildcache:/root/.phpolygon \
  -v steam-session:/root/Steam \
  phpolygon-build
```

- **`-it` is required the first time** (Steam Guard 2FA prompt). The
  `steam-session` volume caches the login so later runs need no 2FA.
- **`-e UPLOAD="full demo"`** uploads several targets; their build types are added
  to the build set automatically, and `UPLOAD` forces all four desktop targets.
- **`-e STEAM_DRY_RUN=1`** does everything except the actual upload (and skips
  login) - it prints the steamcmd session it would run and every generated VDF.
  Use it to check config before going live.

### One session, status table

`steam_upload_many <target>...` (what the harness runs; `steam_upload <target>`
is the one-target case of it) first prepares **every** target - builds present,
content staged under `STEAM_STAGE` (each build type once, side by side),
`STEAM_STAGE_HOOK` (optional shell function, called once with the staged
suffixes, for game-side checks), required files verified, VDFs written. If any
of that fails, nothing is uploaded. Then one steamcmd session uploads all apps.

steamcmd's own output goes, complete, to `steam/output/steamcmd-<date>.log`.
The terminal gets a compact block instead - plain lines, no `\r` or cursor
control, so it reads the same in a pipe or a CI log. It is checked every
`STEAM_STATUS_INTERVAL` s (5) and printed when a depot changes phase, otherwise
every `STEAM_STATUS_HEARTBEAT` s (15):

```
[1:06] steam upload
  Main game windows ✓ 62 s · linux ✓ 41 s · macos ✓ 62 s
  demo      windows ✓ 38 s · linux ✓ 39 s · macos ⠿ upload 312/~648 MB · 13.0 MB/s
```

The states come from the `depot_build_<depot>.log` / `app_build_<app>.log` files
steamcmd writes into the VDF `BuildOutput` - only those written by this run
(modified after it started); old logs of earlier uploads are ignored. Phases:
`waiting`, `⠿ scan`, `⠿ upload <sent>/~<added+changed> MB · <MB/s>`, `✓ <duration>`,
`✗ failed`, `✗ not built` (an earlier depot of that app failed). The logs carry no
byte progress, so sent MB and MB/s are the container's TX counters
(`/proc/net/dev`, all interfaces but `lo`) - only one depot uploads at a time.
The line label is the target's `label` in `steam-build.json`, else the target
name. At the end a summary per depot: manifest id, new chunks, files
added/changed/removed with MB, duration (from the log timestamps), BuildID per
app, total time. On a failure: the relevant last lines of the steamcmd log, its
path, and a non-zero exit.

| Variable | Default | Meaning |
|---|---|---|
| `STEAM_STATUS` | `1` | `0` streams steamcmd's raw output instead (still logged) |
| `STEAM_STATUS_INTERVAL` / `STEAM_STATUS_HEARTBEAT` | `5` / `15` | seconds between checks / re-prints of an unchanged block |
| `STEAM_BUILD_OUTPUT` | `/app/steam/output` | VDF `BuildOutput` (steamcmd logs) |
| `STEAM_VDF_DIR` | `/tmp` | where the generated VDFs go |
| `STEAMCMD` | `steamcmd` | the binary (the test points it at a fake) |

steamcmd runs with stdin from `/dev/null`: if the cached login has expired it
fails (visible in the log tail) instead of waiting for a Steam Guard code no one
sees. Log in again with `-it` (`steam_auth` runs before the build).

Test without Steam: `docker/tests/steam-upload-test.sh` drives the whole flow
against `docker/tests/fake-steamcmd.sh`, which plays depot/app logs into
`BuildOutput` over time (synthetic, or real ones via `FAKE_STEAM_SAMPLES=<dir>`):

```bash
docker run --rm --entrypoint bash -v "$PWD/docker:/harness" phpolygon-build \
    /harness/tests/steam-upload-test.sh
```

### `steam-build.json` (in the game dir)

Upload targets live in `steam-build.json` next to `build.json`. If a requested
target isn't configured, the harness **prompts for it and saves it** (App ID,
per-platform depot IDs, branch to set live, Steam login). Example:

```json
{
  "steamUser": "your-login",
  "uploads": {
    "full": {
      "appId": "1234560",
      "buildType": "full",
      "setLive": "",
      "depots": { "windows-x86_64": "1234561", "linux-x86_64": "1234562", "macos-universal": "1234563" }
    },
    "beta": { "appId": "1234570", "buildType": "full", "setLive": "beta",
      "depots": { "windows-x86_64": "1234571", "linux-x86_64": "1234572", "macos-universal": "1234573" } }
  }
}
```

`buildType` picks which `build/<plat>-<suffix>` dirs ship; `setLive` (a branch)
is set live after upload, or empty to upload only. The macOS depot always takes
the universal bundle. An optional `label` names the target in the upload status
(default: the target name).

## Benchmarking across machines

On two Intel x86_64 machines, `--platform linux/amd64` runs **natively** on both
(no QEMU emulation) - the comparison is apples-to-apples. The script prints a
per-target + total timing table.

For a fair number, watch out for two things:

1. **Warm the cache first.** The first target builds the shared PHAR (composer +
   staging) and downloads `micro.sfx`; the rest reuse the PHAR in seconds. Run the
   build **twice** and compare the *second* run - the named volume
   `phpolygon-buildcache` persists the runtime download, so the second run's time
   is essentially the one PHAR build plus a few seconds of packaging.

2. **Bind-mount I/O differs per host.** Docker Desktop's bind mounts are slower
   on macOS (virtiofs/gRPC-FUSE) than on Windows (WSL2). This build is I/O-heavy
   (staging files, writing 100-150 MB binaries), so a chunk of any gap can be
   filesystem overhead, not CPU.

The clean single number to compare is the **TOTAL** line from a warm second run.
