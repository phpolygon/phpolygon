# Shipping the native upscalers (FSR 3, DLSS)

`Upscaler::Fsr3` and `Upscaler::Dlss` run in php-vio's native module, which
loads its runtimes when a game first asks for them: AMD's FidelityFX
libraries for FSR 3 and php-vio's DLSS plugin plus NVIDIA's runtime for DLSS.
None of them is part of php-vio or the static PHP runtime. A build puts them
next to the executable when `build.json` asks for them:

```json
"upscalers": {
  "fsr": true,
  "dlss": {
    "plugin": "github:<owner>/<private-repo>@<tag>",
    "projectId": "3f2a9c1e-7b4d-4e8a-9f60-1c2d3e4f5a6b"
  }
}
```

| Key | Values |
|---|---|
| `fsr` | `true`: AMD's signed runtimes from the FidelityFX SDK tag the engine pins (`v1.1.4`). A folder (string or `{"path": "..."}`, relative to the project root): the files from there. `false` / absent: no FSR 3. |
| `dlss` | `{"plugin": ..., "projectId": ...}` or just the plugin source. `false` / absent: no DLSS. |
| `dlss.plugin` | A folder with `vio_dlss.dll` + `nvngx_dlss.dll` (for example the plugin repository's `dist/`), or `github:<owner>/<repo>@<tag>`: the release of a **private** repository with the assets `vio_dlss.dll` and `nvngx_dlss.dll`. |
| `dlss.projectId` | The game's NGX project id, a random GUID generated once and kept (braces and case do not matter). Optional; without it the plugin's own id is used and the build warns. |

`BuildConfig` rejects unknown keys, a malformed `github:` source, a project id
that is no GUID and a project id that contradicts `php.ini`'s
`vio.dlss_project_id`. `phpolygon build --dry-run` shows the parsed section.

## Where the files come from

**FSR 3, `"fsr": true`.** AMD's FidelityFX SDK release asset is the whole SDK
(`FidelityFX-SDK-v1.1.4.zip`, about 470 MB). The two signed runtimes are also
in the repository at the tag (`PrebuiltSignedDLL/`), so the build downloads
just those from
`raw.githubusercontent.com/GPUOpen-LibrariesAndSDKs/FidelityFX-SDK/v1.1.4/PrebuiltSignedDLL/`,
checks them against the SHA-256 sums pinned in `NativeUpscalerResolver::FFX_SHA256`
and keeps them in `~/.phpolygon/build-cache/upscalers/fidelityfx-v1.1.4/`.
Every later build reads the cache. A file with another checksum is refused.

**DLSS from a private release.** The token comes from `GITHUB_TOKEN`, then
`GH_TOKEN`, then `gh auth token`. The build first checks that the repository
is private: NVIDIA's runtime may be passed on only inside an application, so
a public repository is refused. It then downloads the two assets through the
GitHub asset API. The token goes to `api.github.com` only, never to the
storage host the download redirects to. The files are cached per tag in
`~/.phpolygon/build-cache/upscalers/dlss/<owner>/<repo>/<tag>/<platform>/`.
Release tags are never moved, so a tag is fetched only once.

**Local folders** are read in place on every build.

## Platforms

| Target | FSR 3 | DLSS |
|---|---|---|
| Windows | `amd_fidelityfx_dx12.dll`, `amd_fidelityfx_vk.dll` | `vio_dlss.dll`, `nvngx_dlss.dll` |
| Linux | only from a folder with `libamd_fidelityfx_vk.so` (AMD publishes none) | only when the source has `libvio_dlss.so` + `libnvidia-ngx-dlss.so.*`, otherwise "not supported" (the Linux plugin is untested) |
| macOS | no | no |

Nothing here fails a build. If something cannot be resolved (offline, no
token, a missing file, a wrong checksum, a public repository, an unsupported
platform), the build logs a warning and builds the game without that
upscaler. php-vio then reports it as unsupported, and the graphics options
fall back along `Upscaler::fallbackChain()`.

## At runtime

php-vio looks for each library in this order: the place named in its ini
(`vio.ffx_path`, `vio.dlss_plugin_path`, `vio.dlss_path`) or environment
(`VIO_FFX_PATH`, `VIO_DLSS_PLUGIN`), else **next to the executable**
(`GetModuleFileName(NULL)`), else next to `php_vio`, else `PATH`. In a built
game the executable is the game's own `.exe` (micro.sfx + PHAR), and php-vio
is linked into it. Files next to the exe are therefore found without any ini
and without `EngineConfig::$upscalerRuntimePath`. A built game leaves that
setting `''`. It is for games that keep the runtimes in a subfolder; it sets
all three ini keys.

`upscalers.dlss.projectId` goes into the executable's embedded php.ini
(`vio.dlss_project_id`, see `MicroIni`), so the shipped game identifies itself
to NGX without code. A game can still set `EngineConfig(dlssProjectId: ...)`;
when that is non-empty, it overrides the baked value before the vio context is
created. During development (`php game.php`) the executable is `php.exe`: put
the files next to it or `php_vio.dll`, or set `upscalerRuntimePath`.

## Notices and the game's duties

The build writes `THIRD-PARTY-NOTICES.txt` next to the executable (CRLF line
endings on Windows; on macOS it goes in `Contents/Resources`). It covers
whichever upscalers were actually shipped (`ThirdPartyNotices`):

- **AMD FidelityFX SDK**: the full MIT text with AMD's copyright, which must
  accompany every copy, plus AMD's trademark line.
- **NVIDIA DLSS**: "This software contains source code provided by NVIDIA
  Corporation." (RTX SDK licence §2b; strictly required for distributed
  source, included because the plugin is built from NVIDIA's SDK headers),
  NVIDIA's copyright and trademark notices, and the restrictions an
  application must pass on to its users (§2c): object code only, part of the
  application only, no reverse engineering, notices kept, no critical-system
  use, provided "AS IS".

The game is responsible for the parts a file cannot cover:

- **NVIDIA attribution** in the splash screen or credits, as described in
  NVIDIA's RTX UI Developer Guidelines (the DLSS SDK's `doc/` folder), and the
  NVIDIA trademark line in the credits.
- **Notify NVIDIA before the commercial release** (RTX SDK supplement §4):
  https://developer.nvidia.com/sw-notification with company, publisher and
  developer name, SDK used, application name, platform, ship date and a link.
- **EULA**: the game's end-user terms must protect NVIDIA's components at
  least as much as the RTX SDK licence does (§2c). The notices file states
  the restrictions; the EULA should refer to it.
- **Its own NGX project id** (`upscalers.dlss.projectId`).
- **NVIDIA's files travel only inside the game.** Never put them in php-vio,
  the engine, a public release or a separate download. Keep the plugin
  repository and its releases private.
- **AMD**: keep the notice. If the game names FSR in its store page or
  credits, it should follow AMD's trademark wording ("AMD FidelityFX Super
  Resolution").
