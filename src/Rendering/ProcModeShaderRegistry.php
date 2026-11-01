<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

/**
 * Holds the GLSL shader SNIPPETS a game injects to give {@see ProcModeRegistry}
 * modes their own looks, and splices them into the engine's base mesh3d shaders at
 * compile time.
 *
 * The engine base shaders ship only the `u_proc_mode == 0` standard-PBR arm plus
 * sentinel comments marking where the game's proc_mode code goes:
 *  - {@see HELPERS_SENTINEL}  — file scope, the
 *    game's per-mode helper functions.
 *  - {@see BRANCHES_SENTINEL} — inside `main()`, the
 *    `u_proc_mode == N` branch ladder (everything except the mode-0/else arm).
 *
 * A game registers, per mode, its helper text and branch text (per GLSL family and
 *. At compile time the renderer calls {@see spliceGlsl()}
 * to replace each sentinel with the assembled snippet region, then hands the result
 * to the normal pipeline (glslang → SPIR-V → SPIRV-Cross on transpiling backends,
 * or straight to the driver). No new pipeline — the splice is pure string assembly.
 *
 * BYTE-IDENTITY: the assembled region reproduces the pre-migration shader exactly.
 * That needs the snippets concatenated in ORIGINAL FILE ORDER, which is NOT the same
 * for every region (a file's helper functions and its branch ladder are ordered
 * differently, and VIO/GL diverge). So emission order is an explicit per-region
 * list set via {@see setOrder()}; concatenation is separator-free (each snippet is a
 * contiguous slice of the original region, carrying its own surrounding whitespace).
 *
 * Per-dialect divergence: the two GLSL families (VIO `source/vio` and native-GL
 * `source`) can need different helper text for the same mode (their noise/helper
 * APIs differ) — see the `$glHelpers`/`$glBranch` params. (A mode implemented
 * in one dialect may be absent in another). A mode absent from a dialect simply
 * registers no snippet there.
 *
 * PROGRAM VARIANTS (vio family only; the native-GL renderer has one program and
 * always takes every mode): the übershader must not grow with every look. A game
 * moves modes out of the base program with {@see defineVariant()}: those modes
 * compile into a program of their own, which can also leave out engine paths it
 * never reaches ({@see ShaderPath}), and the renderer switches program per material.
 * {@see omitFromBase()} leaves paths out of the base program. Without any of this
 * the assembled source is the same as before, and so is the frame.
 *
 * The branch ladder of a program is assembled from whatever modes it holds, so a
 * mode can be left out or moved: the first arm opens with `if`, the others with
 * `} else if`, and the ladder closes with the `} else` of the mode-0 arm wherever
 * the registered snippets put them.
 */
final class ProcModeShaderRegistry
{
    // Helper sentinels are line comments: they replace a run of file-scope helper
    // functions and sit alone on a line, so nothing follows them on the line.
    public const HELPERS_SENTINEL = '// PHPOLYGON:PROCMODE_HELPERS';

    // Branch sentinels are BLOCK comments on purpose. The branch region ends at the
    // mode-0 `else` keyword, so the retained ` {<mode-0 body>}` follows the sentinel
    // ON THE SAME LINE. A line comment would swallow that `{`; a block comment does
    // not — leaving `/* … */ { … }`, a valid bare block. That keeps the engine base
    // a COMPILABLE mode-0-only shader when no game has registered (or the sentinel is
    // replaced by empty), while a full splice still reproduces the original exactly.
    public const BRANCHES_SENTINEL = '/* PHPOLYGON:PROCMODE_BRANCHES */';

    // Post-color hook. A game-specific colour transform applied to the final lit
    // colour (e.g. an underwater absorption tint keyed off world position). Unlike
    // the proc_mode regions this is NOT per-mode and NOT tied to u_proc_mode — it is
    // a single, whole-scene post-lighting pass. Two GLSL sentinels: one at file scope
    // for the helper function definition, one in main() for the call. Both are LINE
    // comments (each sits alone / at the end of the base's leading indent), so when no
    // game registers a transform they replace with '' and the base stays a valid,
    // untinted shader.
    public const POSTCOLOR_HELPERS_SENTINEL = '// PHPOLYGON:POSTCOLOR_HELPERS';
    public const POSTCOLOR_SENTINEL = '// PHPOLYGON:POSTCOLOR';

    public const FAMILY_VIO = 'vio';
    public const FAMILY_GL = 'gl';

    public const REGION_GLSL_HELPERS = 'glsl_helpers';
    public const REGION_GLSL_BRANCHES = 'glsl_branches';

    /** The base program: every mode no variant claims. */
    public const BASE_VARIANT = '';

    /** @var array<int, array{vio: string, gl: string}> */
    private static array $glslHelpers = [];
    /** @var array<int, array{vio: string, gl: string}> */
    private static array $glslBranch = [];
    /** @var array{vio: string, gl: string}|null helper def for the post-color transform */
    private static ?array $postColorHelpers = null;
    /** @var array{vio: string, gl: string}|null call site for the post-color transform */
    private static ?array $postColorCall = null;
    /** @var array<string, list<int>> region → explicit mode emission order */
    private static array $order = [];
    /** @var array<string, array{modes: list<int>, omit: list<ShaderPath>, lazy: bool}> name → variant */
    private static array $variants = [];
    /** @var array<int, string> mode → variant name */
    private static array $modeVariant = [];
    /** @var list<ShaderPath> paths the base program leaves out */
    private static array $baseOmissions = [];

    /**
     * Register the GLSL for one proc_mode.
     *
     * @param string      $helpers   file-scope helper functions (VIO family / default)
     * @param string      $branch    the mode's branch arm(s) inside `main()` (VIO / default)
     * @param string|null $glHelpers native-GL helper variant; falls back to $helpers
     * @param string|null $glBranch  native-GL branch variant; falls back to $branch
     */
    public static function registerGlsl(int $mode, string $helpers, string $branch, ?string $glHelpers = null, ?string $glBranch = null): void
    {
        self::$glslHelpers[$mode] = [
            self::FAMILY_VIO => $helpers,
            self::FAMILY_GL => $glHelpers ?? $helpers,
        ];
        self::$glslBranch[$mode] = [
            self::FAMILY_VIO => $branch,
            self::FAMILY_GL => $glBranch ?? $branch,
        ];
    }


    /**
     * Register the game's post-color transform: a helper function definition (file
     * scope) and its call statement (inside main()), spliced at the POSTCOLOR
     * sentinels. Applies to the final lit colour for the whole scene, independent of
     * u_proc_mode. The two GLSL families can diverge (comment wording / helper API),
     * so pass native-GL variants when they differ; each falls back to the VIO text.
     *
     * @param string      $helpers file-scope helper function text (VIO family / default)
     * @param string      $call    the call statement inside main() (VIO family / default)
     * @param string|null $glHelpers native-GL helper variant; falls back to $helpers
     * @param string|null $glCall    native-GL call variant; falls back to $call
     */
    public static function registerPostColorGlsl(string $helpers, string $call, ?string $glHelpers = null, ?string $glCall = null): void
    {
        self::$postColorHelpers = [self::FAMILY_VIO => $helpers, self::FAMILY_GL => $glHelpers ?? $helpers];
        self::$postColorCall = [self::FAMILY_VIO => $call, self::FAMILY_GL => $glCall ?? $call];
    }

    public static function postColorHelpers(string $family = self::FAMILY_VIO): string
    {
        return self::$postColorHelpers[$family] ?? self::$postColorHelpers[self::FAMILY_VIO] ?? '';
    }

    public static function postColorCall(string $family = self::FAMILY_VIO): string
    {
        return self::$postColorCall[$family] ?? self::$postColorCall[self::FAMILY_VIO] ?? '';
    }

    /**
     * Declare the emission order (list of modes, in original file order) for a region.
     * Required for byte-identity when more than one mode is registered.
     *
     * @param list<int> $modeOrder
     */
    public static function setOrder(string $region, array $modeOrder): void
    {
        self::$order[$region] = $modeOrder;
    }

    /**
     * Give $modes a program of their own. $omit lists the engine paths that program
     * is compiled without: modes that return early (water, unlit panels) never reach
     * the lighting patterns, and the variant is then far smaller than the base.
     * Helpers must be self-contained within the variant: a helper of a mode outside
     * it is not there. $lazy compiles it at first use instead of behind the splash,
     * for looks only some scenes need.
     *
     * @param list<int>        $modes
     * @param list<ShaderPath> $omit
     */
    public static function defineVariant(string $name, array $modes, array $omit = [], bool $lazy = false): void
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1) {
            throw new \InvalidArgumentException("Shader variant name '{$name}' must be lower snake case");
        }
        foreach ($modes as $mode) {
            $owner = self::$modeVariant[$mode] ?? $name;
            if ($owner !== $name) {
                throw new \InvalidArgumentException("proc_mode {$mode} already belongs to shader variant '{$owner}'");
            }
            self::$modeVariant[$mode] = $name;
        }
        self::$variants[$name] = ['modes' => $modes, 'omit' => $omit, 'lazy' => $lazy];
    }

    /**
     * Leave paths out of the base program, for a game none of whose base-program
     * materials uses them. {@see omissionViolations()} is the test for that promise.
     *
     * @param list<ShaderPath> $paths
     */
    public static function omitFromBase(array $paths): void
    {
        self::$baseOmissions = $paths;
    }

    public static function hasVariants(): bool
    {
        return self::$variants !== [];
    }

    /** @return list<string> variant names, without the base program */
    public static function variants(): array
    {
        return array_keys(self::$variants);
    }

    public static function isLazyVariant(string $variant): bool
    {
        return self::$variants[$variant]['lazy'] ?? false;
    }

    /** The program a mode's fragments are drawn with. */
    public static function variantOf(int $mode): string
    {
        return self::$modeVariant[$mode] ?? self::BASE_VARIANT;
    }

    /**
     * The `#define`s of a program's fragment stage.
     *
     * @return list<string>
     */
    public static function variantDefines(string $variant = self::BASE_VARIANT): array
    {
        $omit = $variant === self::BASE_VARIANT ? self::$baseOmissions : (self::$variants[$variant]['omit'] ?? []);

        return array_map(static fn (ShaderPath $path): string => $path->define(), $omit);
    }

    /**
     * Materials drawn with a program that leaves out a path they need, as
     * `material id => [path, ...]`. Meant for a game's test suite.
     *
     * @return array<string, list<ShaderPath>>
     */
    public static function omissionViolations(): array
    {
        $violations = [];
        foreach (MaterialRegistry::ids() as $id) {
            $material = MaterialRegistry::get($id);
            if ($material === null) {
                continue;
            }
            $variant = $material->grass ? self::BASE_VARIANT : self::variantOf(ProcModeRegistry::resolve($id));
            $omit = $variant === self::BASE_VARIANT ? self::$baseOmissions : (self::$variants[$variant]['omit'] ?? []);
            foreach ($omit as $path) {
                if ($path->neededBy($material)) {
                    $violations[$id][] = $path;
                }
            }
        }

        return $violations;
    }

    public static function glslHelpers(string $family = self::FAMILY_VIO, ?string $variant = null): string
    {
        return self::assemble(
            self::$glslHelpers,
            self::REGION_GLSL_HELPERS,
            static fn (mixed $entry): string => self::pickFamily($entry, $family),
            $variant,
        );
    }

    public static function glslBranches(string $family = self::FAMILY_VIO, ?string $variant = null): string
    {
        $arms = [];
        foreach (self::modesIn(self::$glslBranch, self::REGION_GLSL_BRANCHES, $variant) as $mode) {
            $arm = self::pickFamily(self::$glslBranch[$mode], $family);
            if ($arm !== '') {
                $arms[] = $arm;
            }
        }

        return self::ladder($arms);
    }

    /**
     * Join branch arms into a ladder that opens with `if` and leaves the `} else` of
     * the mode-0 arm at its end, wherever the arms carried them. Arms that already
     * form such a ladder come back unchanged, byte for byte.
     *
     * @param list<string> $arms
     */
    private static function ladder(array $arms): string
    {
        if ($arms === []) {
            return '';
        }
        $close = "\n    } else\n";
        $out = '';
        foreach ($arms as $i => $arm) {
            if (preg_match('/\}\s*else\s*$/', $arm, $tail) === 1) {
                $close = $tail[0];
                $arm = substr($arm, 0, -strlen($tail[0]));
            }
            $arm = $i === 0
                ? preg_replace('/^(\s*)\}\s*else\s+if\b/', '$1if', $arm)
                : preg_replace('/^(\s*)if\b/', '$1} else if', $arm);
            $out .= $arm ?? '';
        }

        return $out . $close;
    }


    /**
     * Pick the per-family snippet for a GLSL entry, falling back to the VIO text
     * when the requested family has none. Accepts the raw store value ({@see assemble}
     * erases the entry shape to mixed) and returns '' for anything malformed.
     */
    private static function pickFamily(mixed $entry, string $family): string
    {
        if (!is_array($entry)) {
            return '';
        }
        $value = $entry[$family] ?? $entry[self::FAMILY_VIO] ?? '';

        return is_string($value) ? $value : '';
    }

    /**
     * The registered modes of a region in the declared order (falling back to
     * registration order). $variant selects one program's modes, null all of them.
     *
     * @param array<int, mixed> $store
     * @return list<int>
     */
    private static function modesIn(array $store, string $region, ?string $variant): array
    {
        $modes = [];
        foreach (self::$order[$region] ?? array_keys($store) as $mode) {
            if (isset($store[$mode]) && ($variant === null || self::variantOf($mode) === $variant)) {
                $modes[] = $mode;
            }
        }

        return $modes;
    }

    /**
     * Concatenate the registered snippets for a region, separator-free. Modes absent
     * from $store or contributing an empty string are skipped.
     *
     * @param array<int, mixed> $store
     */
    private static function assemble(array $store, string $region, callable $pick, ?string $variant): string
    {
        $out = '';
        foreach (self::modesIn($store, $region, $variant) as $mode) {
            $piece = $pick($store[$mode]);
            if (is_string($piece)) {
                $out .= $piece;
            }
        }

        return $out;
    }

    /**
     * Splice the assembled helpers + branches (+ post-color hook) into a base GLSL
     * fragment shader. $variant selects one program's modes (BASE_VARIANT = those no
     * variant claims); null takes every mode, which is what the one program of the
     * native-GL renderer needs.
     */
    public static function spliceGlsl(string $source, string $family = self::FAMILY_VIO, ?string $variant = null): string
    {
        $source = str_replace(self::HELPERS_SENTINEL, self::glslHelpers($family, $variant), $source);
        $source = str_replace(self::BRANCHES_SENTINEL, self::glslBranches($family, $variant), $source);
        $source = str_replace(self::POSTCOLOR_HELPERS_SENTINEL, self::postColorHelpers($family), $source);
        $source = str_replace(self::POSTCOLOR_SENTINEL, self::postColorCall($family), $source);

        return $source;
    }


    public static function clear(): void
    {
        self::$glslHelpers = [];
        self::$glslBranch = [];
        self::$postColorHelpers = null;
        self::$postColorCall = null;
        self::$order = [];
        self::$variants = [];
        self::$modeVariant = [];
        self::$baseOmissions = [];
    }
}
