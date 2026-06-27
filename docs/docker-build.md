# Cross-platform Docker builds

PHPolygon can build a game's desktop bundles for **all four Steam targets**
(`windows-x86_64`, `linux-x86_64`, `macos-arm64`, `macos-x86_64`) from a **single
Linux container** - no macOS host required. The harness lives in the engine repo
under [`docker/`](../docker/) and is game-agnostic: the consuming game is
bind-mounted at `/app` and everything game-specific (app name, version, build
types) is read from its `build.json` at runtime.

| | Native `phpolygon build` | Docker harness |
|---|---|---|
| Host needed for macOS targets | an Apple Silicon Mac | any Linux/amd64 Docker host |
| macOS code signing | Apple `codesign` | `rcodesign` (ad-hoc, runs on Linux) |
| Runtime (`micro.sfx`) | resolved by `StaticPhpResolver` | prefetched with a token, same resolver |
| Output layout | `build/<target>-<variant>[-<type>]/` | identical |

## Why no Mac is needed

A desktop `phpolygon build` is **pure packaging**: stage the PHAR, fetch the
pre-built `micro.sfx` PHP runtime from the static-php-cli releases, and assemble
the platform bundle. None of that is macOS-bound. The one genuinely Apple step is
**code signing the macOS binary** - and that is done in-container with
[`rcodesign`](https://github.com/indygreg/apple-platform-rs), which produces a
valid **ad-hoc** Mach-O signature on Linux with no Apple ID or certificate.
Apple's own `codesign -dvvv` reads it back as `flags=0x2(adhoc)`.

Notarization is intentionally skipped. The Steamworks depot is marked
**not notarized**; the Steam client strips `com.apple.quarantine` on launch, so
Gatekeeper never runs the notarization check.

## The phpmicro signing model (the critical part)

phpmicro builds the final macOS binary by **appending the PHAR after the
Mach-O**:

```
[ Mach-O executable (micro.sfx) ][ PHAR (game code) ]
```

Any Mach-O signer - `rcodesign` **and** Apple's `codesign` - rewrites the Mach-O
and **drops everything after it**. Signing the *finished* binary therefore
truncates the appended PHAR (observed ~98 MB -> ~60 MB) and produces a binary
that is signed but no longer runnable.

The only correct order is to **ad-hoc sign the bare `micro.sfx` runtime *before*
`phpolygon build` appends the PHAR.** The harness does this in its prefetch step,
on the cached runtime, so:

- the signature covers the runtime,
- the PHAR is trailing data that phpmicro reads from the end of the file,
- the build never re-signs the finished bundle.

The build then **verifies** (it does not re-sign) each `.app`, printing e.g.
`signed (ad-hoc), PHAR trailing data: 36 MB after signature`.

> Do not refactor this into post-build bundle signing. That was tried and
> truncates the PHAR. Sign the runtime, then append - never the other way round.

### Launch-verification caveat

The signature is structurally valid (confirmed against Apple `codesign`), but a
Linux container **cannot launch an arm64 binary**. Before shipping, run the
`.app` once on a real Apple Silicon Mac to confirm the window opens and phpmicro
loads the PHAR. The container proves the bundle is *well-formed and signed*, not
that it *runs*.

## The GITHUB_TOKEN requirement

A GitHub token is **required** (any valid token; the repos are public, so no
special scopes):

1. `phpolygon build` internally runs `composer update --no-dev`, which
   re-resolves the `phpolygon/phpolygon` repo. It is declared with an SSH URL
   (`git@github.com:...`); the container has no SSH key, and the anonymous GitHub
   API is rate-limited on shared Docker egress IPs. The token makes Composer use
   the authenticated HTTPS API.
2. The `micro.sfx` prefetch hits the GitHub releases API, which is likewise
   rate-limited anonymously on shared IPs.

The harness configures `composer config --global github-oauth.github.com $TOKEN`
and a `url.https://github.com/.insteadOf git@github.com:` git rewrite, then
prefetches each runtime with `Authorization: Bearer $TOKEN` into the resolver's
cache dir. `StaticPhpResolver` finds the cached runtime (cache is its fallback
when the API fails) and skips the network entirely.

The prefetch cache key and OS-name mapping mirror `StaticPhpResolver` exactly:

- key: `<platform>-<arch>-<variant>-php<ver>` (the `-<variant>` segment is
  omitted when `variant=base`),
- OS name: `macos-arm64 -> macos-aarch64`, `macos-x86_64 -> macos-x86_64`,
  `linux-x86_64 -> linux-x86_64`, `windows-x86_64 -> windows-x86_64`.

`PHP_VERSION` (default `8.5`) and `SPC_REPO` (default `hmennen90/static-php-cli`,
matching `StaticPhpResolver::GITHUB_REPO`) are env vars so the prefetch always
agrees with whatever the engine's resolver would fetch.

## Usage

Build the image from the **engine** repo root (context is just `docker/`):

```bash
docker build -f docker/Dockerfile.build -t phpolygon-build docker
```

Run it against a **game** checkout (bind-mounted at `/app`):

```bash
docker run --rm \
  --platform linux/amd64 \
  -e GITHUB_TOKEN="$(gh auth token)" \
  -v "$PWD:/app" \
  -v phpolygon-buildcache:/root/.phpolygon \
  phpolygon-build
```

Env knobs: `VERSION`, `PLATFORMS` (default `all`), `TYPE` (default `full`, or any
build type from the game's `build.json`, or `all-types`), `VARIANT` (default
`steam`), `SIGN_MAC` (default `1`), `PHP_VERSION`, `SPC_REPO`. `TYPE` and
`VARIANT` pass straight through to `phpolygon build`; the engine applies
build-type constant overrides from the game's `build.json`, so the harness adds
no game-specific build mechanism of its own.

### Interactive mode + version stamping

Run with `-it` and the harness prompts up front for the target version,
platforms, build type and signing, then confirms. The chosen version is written
into the game's `build.json` (`version`) and its version constant file
(auto-detected `bootstrap_version.php` → `GAME_VERSION`; override with
`VERSION_CONST_FILE` / `VERSION_CONST`) - one unified release flow for every
game. Without a TTY it runs from env vars (CI); `INTERACTIVE=1` forces the menu
(for Windows shells that mis-report a TTY), `NONINTERACTIVE=1` forces env mode.

### Build-once PHAR (`phpolygon build --phar`)

The staged PHP code is identical for every desktop target of the same
variant/build-type, so the harness passes `--phar <cache>` to every target: the
first call builds the `game.phar` and persists it there; later calls reuse it and
skip vendor-prep + staging + PHAR creation entirely (only micro.sfx + combine +
package run). The harness builds the first target sequentially, then the rest in
parallel - all four desktop targets in roughly the time of one. The `--phar` flag
is a general `GameBuilder::build()` / `bin/phpolygon` feature, usable outside Docker.

### Steam upload (auth → build → upload)

Set `UPLOAD=<target>` and the harness logs in to Steam first (Steam Guard up
front, cached in the `/root/Steam` volume), builds all four desktop targets,
assembles the macOS universal bundle, generates the `app_build` VDF and runs
`steamcmd +run_app_build`. Upload targets (App ID, per-platform depot IDs,
`setLive` branch, login) live in `steam-build.json` in the game dir; a missing
target is prompted for and saved. `STEAM_DRY_RUN=1` does everything except the
actual upload. steamcmd ships in the image (32-bit runtime + bootstrap tarball).

See [`docker/README.md`](../docker/README.md) for PowerShell/cmd invocations,
benchmarking notes, and the full options table.
