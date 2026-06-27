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
runnable. The build verifies it and prints e.g. `PHAR trailing data: 36 MB`.

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
| `VERSION_CONST_FILE` / `VERSION_CONST` | _(auto)_ | override the in-game version constant file / name (default `bootstrap_version.php` / `GAME_VERSION`) |

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
**universal** bundle (one `.app`, launcher picks arm64/x86_64), generates the
`app_build` VDF and runs `steamcmd +run_app_build`.

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
  login) - it prints the generated VDF. Use it to check config before going live.

### `steam-build.json` (in the game dir)

Upload targets live in `steam-build.json` next to `build.json`. If a requested
target isn't configured, the harness **prompts for it and saves it** (App ID,
per-platform depot IDs, branch to set live, Steam login). Example:

```json
{
  "steamUser": "your-login",
  "uploads": {
    "full": {
      "appId": "4347780",
      "buildType": "full",
      "setLive": "",
      "depots": { "windows-x86_64": "4347782", "linux-x86_64": "4347783", "macos-universal": "4347784" }
    },
    "beta": { "appId": "4417500", "buildType": "full", "setLive": "beta",
      "depots": { "windows-x86_64": "4417501", "linux-x86_64": "4417505", "macos-universal": "4417506" } }
  }
}
```

`buildType` picks which `build/<plat>-<suffix>` dirs ship; `setLive` (a branch)
is set live after upload, or empty to upload only. The macOS depot always takes
the universal bundle.

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
