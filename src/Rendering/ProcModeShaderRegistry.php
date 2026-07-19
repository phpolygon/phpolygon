<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

/**
 * Holds the GLSL/MSL shader SNIPPETS a game injects to give {@see ProcModeRegistry}
 * modes their own looks, and splices them into the engine's base mesh3d shaders at
 * compile time.
 *
 * The engine base shaders ship only the `u_proc_mode == 0` standard-PBR arm plus
 * sentinel comments marking where the game's proc_mode code goes:
 *  - {@see HELPERS_SENTINEL} / {@see MSL_HELPERS_SENTINEL}  — file scope, the
 *    game's per-mode helper functions.
 *  - {@see BRANCHES_SENTINEL} / {@see MSL_BRANCHES_SENTINEL} — inside `main()`, the
 *    `u_proc_mode == N` branch ladder (everything except the mode-0/else arm).
 *
 * A game registers, per mode, its helper text and branch text (per GLSL family and
 * for MSL). At compile time the renderer calls {@see spliceGlsl()}/{@see spliceMsl()}
 * to replace each sentinel with the assembled snippet region, then hands the result
 * to the normal pipeline (glslang → SPIR-V → SPIRV-Cross on transpiling backends,
 * or straight to the driver). No new pipeline — the splice is pure string assembly.
 *
 * BYTE-IDENTITY: the assembled region reproduces the pre-migration shader exactly.
 * That needs the snippets concatenated in ORIGINAL FILE ORDER, which is NOT the same
 * for every region (a file's helper functions and its branch ladder are ordered
 * differently, and VIO/GL/MSL diverge). So emission order is an explicit per-region
 * list set via {@see setOrder()}; concatenation is separator-free (each snippet is a
 * contiguous slice of the original region, carrying its own surrounding whitespace).
 *
 * Per-dialect divergence: the two GLSL families (VIO `source/vio` and native-GL
 * `source`) can need different helper text for the same mode (their noise/helper
 * APIs differ) — see the `$glHelpers`/`$glBranch` params. Native Metal (hand-written
 * MSL) has its own helper + branch text and its own mode coverage (a mode implemented
 * in one dialect may be absent in another). A mode absent from a dialect simply
 * registers no snippet there.
 */
final class ProcModeShaderRegistry
{
    // Helper sentinels are line comments: they replace a run of file-scope helper
    // functions and sit alone on a line, so nothing follows them on the line.
    public const HELPERS_SENTINEL = '// PHPOLYGON:PROCMODE_HELPERS';
    public const MSL_HELPERS_SENTINEL = '// PHPOLYGON:PROCMODE_HELPERS_MSL';

    // Branch sentinels are BLOCK comments on purpose. The branch region ends at the
    // mode-0 `else` keyword, so the retained ` {<mode-0 body>}` follows the sentinel
    // ON THE SAME LINE. A line comment would swallow that `{`; a block comment does
    // not — leaving `/* … */ { … }`, a valid bare block. That keeps the engine base
    // a COMPILABLE mode-0-only shader when no game has registered (or the sentinel is
    // replaced by empty), while a full splice still reproduces the original exactly.
    public const BRANCHES_SENTINEL = '/* PHPOLYGON:PROCMODE_BRANCHES */';
    public const MSL_BRANCHES_SENTINEL = '/* PHPOLYGON:PROCMODE_BRANCHES_MSL */';

    // Post-color hook. A game-specific colour transform applied to the final lit
    // colour (e.g. an underwater absorption tint keyed off world position). Unlike
    // the proc_mode regions this is NOT per-mode and NOT tied to u_proc_mode — it is
    // a single, whole-scene post-lighting pass. Two GLSL sentinels: one at file scope
    // for the helper function definition, one in main() for the call. Both are LINE
    // comments (each sits alone / at the end of the base's leading indent), so when no
    // game registers a transform they replace with '' and the base stays a valid,
    // untinted shader. No MSL equivalent — native Metal ships no post-color transform.
    public const POSTCOLOR_HELPERS_SENTINEL = '// PHPOLYGON:POSTCOLOR_HELPERS';
    public const POSTCOLOR_SENTINEL = '// PHPOLYGON:POSTCOLOR';

    public const FAMILY_VIO = 'vio';
    public const FAMILY_GL = 'gl';

    public const REGION_GLSL_HELPERS = 'glsl_helpers';
    public const REGION_GLSL_BRANCHES = 'glsl_branches';
    public const REGION_MSL_HELPERS = 'msl_helpers';
    public const REGION_MSL_BRANCHES = 'msl_branches';

    /** @var array<int, array{vio: string, gl: string}> */
    private static array $glslHelpers = [];
    /** @var array<int, array{vio: string, gl: string}> */
    private static array $glslBranch = [];
    /** @var array<int, string> */
    private static array $mslHelpers = [];
    /** @var array<int, string> */
    private static array $mslBranch = [];
    /** @var array{vio: string, gl: string}|null helper def for the post-color transform */
    private static ?array $postColorHelpers = null;
    /** @var array{vio: string, gl: string}|null call site for the post-color transform */
    private static ?array $postColorCall = null;
    /** @var array<string, list<int>> region → explicit mode emission order */
    private static array $order = [];

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
     * Register the native-Metal (MSL) snippets for one proc_mode.
     *
     * @param string $branch  the mode's branch arm(s) inside the fragment function
     * @param string $helpers file-scope MSL helper function(s); '' if the mode has none
     */
    public static function registerMsl(int $mode, string $branch, string $helpers = ''): void
    {
        self::$mslBranch[$mode] = $branch;
        self::$mslHelpers[$mode] = $helpers;
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

    public static function glslHelpers(string $family = self::FAMILY_VIO): string
    {
        return self::assemble(
            self::$glslHelpers,
            self::REGION_GLSL_HELPERS,
            static fn (mixed $entry): string => self::pickFamily($entry, $family),
        );
    }

    public static function glslBranches(string $family = self::FAMILY_VIO): string
    {
        return self::assemble(
            self::$glslBranch,
            self::REGION_GLSL_BRANCHES,
            static fn (mixed $entry): string => self::pickFamily($entry, $family),
        );
    }

    public static function mslHelpers(): string
    {
        return self::assemble(self::$mslHelpers, self::REGION_MSL_HELPERS, static fn (string $s): string => $s);
    }

    public static function mslBranches(): string
    {
        return self::assemble(self::$mslBranch, self::REGION_MSL_BRANCHES, static fn (string $s): string => $s);
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
     * Concatenate the registered snippets for a region in the declared mode order
     * (falling back to registration order), separator-free. Modes absent from $store
     * or contributing an empty string are skipped.
     *
     * @param array<int, mixed> $store
     */
    private static function assemble(array $store, string $region, callable $pick): string
    {
        $order = self::$order[$region] ?? array_keys($store);
        $out = '';
        foreach ($order as $mode) {
            if (isset($store[$mode])) {
                $piece = $pick($store[$mode]);
                if (is_string($piece)) {
                    $out .= $piece;
                }
            }
        }

        return $out;
    }

    /** Splice the assembled helpers + branches (+ post-color hook) into a base GLSL fragment shader. */
    public static function spliceGlsl(string $source, string $family = self::FAMILY_VIO): string
    {
        $source = str_replace(self::HELPERS_SENTINEL, self::glslHelpers($family), $source);
        $source = str_replace(self::BRANCHES_SENTINEL, self::glslBranches($family), $source);
        $source = str_replace(self::POSTCOLOR_HELPERS_SENTINEL, self::postColorHelpers($family), $source);
        $source = str_replace(self::POSTCOLOR_SENTINEL, self::postColorCall($family), $source);

        return $source;
    }

    /** Splice the assembled MSL helpers + branches into a base MSL shader. */
    public static function spliceMsl(string $source): string
    {
        $source = str_replace(self::MSL_HELPERS_SENTINEL, self::mslHelpers(), $source);
        $source = str_replace(self::MSL_BRANCHES_SENTINEL, self::mslBranches(), $source);

        return $source;
    }

    public static function clear(): void
    {
        self::$glslHelpers = [];
        self::$glslBranch = [];
        self::$mslHelpers = [];
        self::$mslBranch = [];
        self::$postColorHelpers = null;
        self::$postColorCall = null;
        self::$order = [];
    }
}
