# iOS / iPadOS builds

PHPolygon can package a game as a native iOS/iPadOS `.app`. Unlike the desktop
targets - which combine a phpmicro self-extracting binary with a PHAR - iOS
links the PHP runtime as a **static library** (`libphp.a`) into a thin
UIKit/Metal wrapper and builds the bundle with Xcode.

| | Desktop (macos/linux/windows) | iOS |
|---|---|---|
| PHP runtime | phpmicro `micro.sfx` + PHAR | `libphp.a` embed, statically linked |
| Renderer surface | GLFW window | `CAMetalLayer` in a `UIView` |
| Input | mouse/keyboard | `UITouch` → mouse emulation |
| Resources | sidecar next to binary | folded into the read-only app bundle |
| Toolchain | `phpolygon build` only | `phpolygon build` + Xcode + xcodegen |

The flag `PHPOLYGON_PLATFORM_IOS` is defined at runtime so game code can hide
desktop-only affordances (e.g. a quit button - Apple's HIG forbids
app-initiated termination). `PHP_OS_FAMILY` is `"Darwin"` on iOS just like
macOS, so this explicit flag is the only reliable signal.

## Prerequisites

```bash
xcode-select --install        # or full Xcode from the App Store
brew install xcodegen         # project generation
```

You also need a checkout of [static-php-cli](https://github.com/crazywhalecc/static-php-cli)
with the iOS target (the engine's fork adds `SPC_TARGET=ios-*` support).

## Step 1 - build libphp.a for iOS

From the static-php-cli checkout, build the **embed** SAPI for the slice you
target. The extension list must match what your game's `build.json` declares:

```bash
# device (arm64)
SPC_TARGET=ios-arm64 bin/spc build vio,mbstring,zip --build-embed

# simulator on Apple Silicon
SPC_TARGET=ios-simulator-arm64 bin/spc build vio,mbstring,zip --build-embed

# simulator on Intel
SPC_TARGET=ios-simulator-x86_64 bin/spc build vio,mbstring,zip --build-embed
```

This produces `buildroot/lib/libphp.a` plus the PHP headers under
`buildroot/include`. **The slice matters:** a device `libphp.a` cannot link
into a simulator binary and vice versa - rebuild for the slice you intend to
ship. If you switch slices, rebuild rather than reusing a stale archive.

## Step 2 - configure build.json

Add a `platforms.ios` block:

```jsonc
{
  "platforms": {
    "ios": {
      "bundleId": "com.example.mygame",   // defaults to top-level "identifier"
      "team": "ABCDE12345",                // Apple Developer Team ID (device only)
      "deploymentTarget": "14.0",
      "orientations": ["LandscapeLeft", "LandscapeRight"],
      "buildroot": "../static-php-cli/buildroot", // optional, see resolution below
      "libs": ["-lphp", "-lzip", "..."]    // optional link-line override
    }
  }
}
```

| Key | Required | Default |
|---|---|---|
| `bundleId` | no | top-level `identifier` |
| `team` | device only | unsigned/ad-hoc (simulator-only) |
| `deploymentTarget` | no | `14.0` |
| `orientations` | no | `LandscapeLeft`, `LandscapeRight` |
| `buildroot` | no | resolved (see below) |
| `libs` | no | a default vio+mbstring+zip link order |

`externalResources` (e.g. `resources/audio`) are **folded into the bundle**
automatically. On desktop those sit next to the binary; the iOS bundle has no
such sidecar, so `App/resources` is the resource root.

### Buildroot resolution order

`libphp.a` is located by trying, in order:

1. `PHPOLYGON_IOS_BUILDROOT` environment variable
2. `platforms.ios.buildroot` in build.json
3. `../static-php-cli/buildroot` relative to the project root

## Step 3 - build the app

```bash
phpolygon build ios-arm64               # device .app
phpolygon build ios-simulator-arm64     # simulator .app (Apple Silicon)
phpolygon build ios                     # shorthand for ios-arm64
phpolygon build ios-arm64 --dry-run     # show config + verify libphp.a, no Xcode
```

iOS is **not** part of `phpolygon build all` (it is Apple-only and needs Xcode
+ signing). The output `.app` lands in `build/ios-arm64-arm64/`.

The pipeline: stage sources → fold in external resources → generate
`ios_main.php` (entry shim) + `project.yml` → `xcodegen generate` →
`xcodebuild` → copy out the `.app`.

## Step 4 - signing and device install

- **Simulator** needs no team; the build ad-hoc signs and runs in any
  simulator of the matching slice.
- **Device** needs a `team` (Apple Developer Team ID). The build passes
  `-allowProvisioningUpdates`, so Xcode fetches/creates the provisioning
  profile. The device's UDID must already be registered with that team
  (connect it once in Xcode so it gets added). A **Personal Team** profile
  expires after 7 days and only covers registered devices - rebuild to refresh.

Install the built `.app` on a connected device:

```bash
xcrun devicectl device install app --device <UDID> build/ios-arm64-arm64/MyGameIOS.app
```

## Troubleshooting

| Symptom | Cause / fix |
|---|---|
| `has entitlements that require signing with a development certificate` | No `team` set for a device build → add `platforms.ios.team`. |
| `building for iOS Simulator, but linking ... built for iOS` | `libphp.a` slice mismatch → rebuild for the slice you're building. |
| `libphp.a not found` | Build it (Step 1) or point `buildroot` at the right place. |
| Undefined symbols for an extension | The slice's `libphp.a` was built without that extension, or its lib deps are missing from `platforms.ios.libs`. |
| No audio / missing assets on device | Ensure the asset dir is listed in `resources.external` so it gets folded into the bundle. |
| `xcodegen: command not found` | `brew install xcodegen`. |
