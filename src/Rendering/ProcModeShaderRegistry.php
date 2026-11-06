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
 *
 * SNIPPET UNIFORMS: {@see declareUniform()} and {@see declareSampler()} add file-scope
 * declarations to every mesh program (forward, MRT, motion, storage, each variant).
 * Values travel as {@see Command\SetShaderParameter} / {@see Command\SetShaderTexture}.
 * Uniforms live in the fragment stage, where snippets run, and a name must not exist
 * in the engine's stages: a uniform declared in two stages is not set in both on D3D12.
 *
 * SCENE DEPTH: {@see requireSceneDepth()} gives programs the opaque scene's depth for
 * transparent surfaces and particles. It costs a copy per frame, only with a consumer.
 *
 * PARAMETERS WITHOUT NEW MATERIAL IDS: {@see declareMaterialParameter()} adds a uniform
 * filled from {@see Material::$params}, {@see enableInstanceParams()} three floats per
 * draw or instance.
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

    // Surface hook. A game-specific change of the SURFACE before lighting, for every
    // fragment that reaches the lit path (proc_mode arms that return early never get
    // there): it has the last word on `albedo`, `roughness` and `metallic`, may
    // `discard`, and adds self-lit colour through `surfaceEmission` (local light,
    // fogged like the material emission). Reads `v_worldPos`, `N`, `V` and, with
    // instance params, `v_instance_params`. Helpers at file scope after the proc_mode
    // helpers, so they can use those and the engine noise; {@see SurfaceEffects}
    // is an engine library of building blocks for it. Both sentinels are line
    // comments and replace with '' when no game registers a hook.
    public const SURFACE_HELPERS_SENTINEL = '// PHPOLYGON:SURFACE_HELPERS';
    public const SURFACE_SENTINEL = '// PHPOLYGON:SURFACE';

    /** File scope of the fragment stage, where the generated declarations land. */
    public const UNIFORMS_SENTINEL = '// PHPOLYGON:SNIPPET_UNIFORMS';

    /** First texture slot of snippet samplers; the engine's mesh programs use 0-10. */
    public const FIRST_SAMPLER_SLOT = 12;

    /** Slots above FIRST_SAMPLER_SLOT for declared samplers. */
    public const MAX_SAMPLERS = 4;

    /**
     * Ordinary samplers one program can hold: php-vio's D3D12 path numbers them t0..t7
     * in declaration order, comparison samplers start at t8.
     */
    public const SAMPLER_REGISTERS = 8;

    /** Ordinary samplers of mesh3d.frag: environment, 3 probe fields, albedo, SSAO, SDF AO. */
    private const ENGINE_SAMPLERS = 7;

    /** Of those, the ones {@see ShaderPath::ProbeGi} takes out of a program. */
    private const PROBE_SAMPLERS = 3;

    private const UNIFORM_TYPES = ['float', 'int', 'vec2', 'vec3', 'vec4', 'mat4'];

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
    /** @var array{vio: string, gl: string}|null file-scope helpers of the surface hook */
    private static ?array $surfaceHelpers = null;
    /** @var array{vio: string, gl: string}|null statement(s) of the surface hook in main() */
    private static ?array $surfaceCall = null;
    /** @var array<string, list<int>> region → explicit mode emission order */
    private static array $order = [];
    /** @var array<string, array{modes: list<int>, omit: list<ShaderPath>, lazy: bool, keepOrder: bool}> name → variant */
    private static array $variants = [];
    /** @var array<int, string> mode → variant name */
    private static array $modeVariant = [];
    /** @var list<ShaderPath> paths the base program leaves out */
    private static array $baseOmissions = [];
    /** @var array<string, string> uniform name → GLSL type */
    private static array $uniforms = [];
    /** @var array<string, int> sampler name → texture slot */
    private static array $samplers = [];
    /** @var array<string, list<string>|null> sampler name → the programs that declare it, null = all */
    private static array $samplerPrograms = [];

    /** @var array<string, true> samplers declared as samplerCube */
    private static array $cubeSamplers = [];
    /** @var array<string, int|float|list<float>> material parameter uniform → default */
    private static array $materialParameters = [];
    private static bool $instanceParams = false;
    /** @var list<string>|null programs that read the scene depth, null = all of them */
    private static ?array $sceneDepthPrograms = [];
    /** @var array<string, array{vio: string, gl: ?string, programs: list<string>|null}> name → shared helper library */
    private static array $libraries = [];

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

    /**
     * Register a helper library: file-scope GLSL that several modes call, emitted once per
     * program at the helpers sentinel, before the modes' own helpers (a function in two
     * modes' helpers would be defined twice in one program). Libraries keep their
     * registration order; registering a name again replaces it. $gl is the native-GL
     * text; null leaves the library out of that family (it may sample snippet textures,
     * which the GL renderer does not bind). $programs limits it like {@see declareSampler()}.
     *
     * @param list<string>|null $programs null = every program
     */
    public static function registerLibrary(string $name, string $vio, ?string $gl = null, ?array $programs = null): void
    {
        self::$libraries[$name] = ['vio' => $vio, 'gl' => $gl, 'programs' => $programs];
    }

    /** The libraries a program holds, concatenated. */
    public static function libraries(string $family = self::FAMILY_VIO, ?string $variant = null): string
    {
        $out = '';
        foreach (self::$libraries as $library) {
            $programs = $library['programs'];
            if ($programs !== null && $variant !== null && !in_array($variant, $programs, true)) {
                continue;
            }
            $out .= $family === self::FAMILY_VIO ? $library['vio'] : ($library['gl'] ?? '');
        }

        return $out;
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
     * Register the game's surface hook (see {@see SURFACE_SENTINEL}): file-scope
     * helpers and the statement(s) inside main(), spliced into every mesh program
     * (forward, MRT, motion, storage, each variant; the native-GL program with the GL
     * text). Pass '' as $glCall to leave the native-GL renderer untouched, e.g. for a
     * hook that needs instance params (vio family only).
     *
     * @param string      $helpers   file-scope helper text (VIO family / default)
     * @param string      $call      statement(s) inside main() (VIO family / default)
     * @param string|null $glHelpers native-GL helper variant; falls back to $helpers
     * @param string|null $glCall    native-GL call variant; falls back to $call
     */
    public static function registerSurfaceGlsl(string $helpers, string $call, ?string $glHelpers = null, ?string $glCall = null): void
    {
        self::$surfaceHelpers = [self::FAMILY_VIO => $helpers, self::FAMILY_GL => $glHelpers ?? $helpers];
        self::$surfaceCall = [self::FAMILY_VIO => $call, self::FAMILY_GL => $glCall ?? $call];
    }

    /**
     * The base program compiled with PHPOLYGON_SURFACE_CUT defined. A surface hook that
     * may `discard` (dissolve, holes) wraps the discard in `#ifdef PHPOLYGON_SURFACE_CUT`:
     * `discard` in a program costs early depth testing for every draw it renders, also
     * where it is never taken (measured: about 1.3 ms of opaque time at 1080p on a busy
     * view). The renderer draws with this program only while the game says a cut runs.
     */
    public const string SURFACE_CUT_VARIANT = 'surface_cut';

    private static bool $surfaceCut = false;

    /** Whether surface hooks may discard this frame (see SURFACE_CUT_VARIANT). */
    public static function setSurfaceCut(bool $on): void
    {
        self::$surfaceCut = $on;
    }

    public static function surfaceCutActive(): bool
    {
        return self::$surfaceCut && (self::$surfaceCall[self::FAMILY_VIO] ?? '') !== '';
    }

    public static function surfaceHelpers(string $family = self::FAMILY_VIO): string
    {
        return self::$surfaceHelpers[$family] ?? self::$surfaceHelpers[self::FAMILY_VIO] ?? '';
    }

    public static function surfaceCall(string $family = self::FAMILY_VIO): string
    {
        return self::$surfaceCall[$family] ?? self::$surfaceCall[self::FAMILY_VIO] ?? '';
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
     * The opaque pass groups draws by program, so a variant's draws normally move to
     * their own run. Where two opaque surfaces meet at the same depth (a sea plane
     * along a shore, a decal on a wall) the earlier draw keeps the pixel, and that
     * move changes which one it is. $keepOrder leaves this variant's draws at their
     * material position among the base program's draws: the image is that of the
     * base program, for a pipeline switch per change of program in the run.
     *
     * @param list<int>        $modes
     * @param list<ShaderPath> $omit
     */
    public static function defineVariant(string $name, array $modes, array $omit = [], bool $lazy = false, bool $keepOrder = false): void
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
        self::$variants[$name] = ['modes' => $modes, 'omit' => $omit, 'lazy' => $lazy, 'keepOrder' => $keepOrder];
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

    /**
     * Declare a fragment-stage uniform for snippets, set per frame with
     * {@see Command\SetShaderParameter}. Until a command names it, it holds zero.
     */
    public static function declareUniform(string $name, string $type): void
    {
        self::assertName($name);
        if (isset(self::$samplers[$name])) {
            throw new \InvalidArgumentException("Snippet uniform '{$name}' is already a sampler");
        }
        if (!in_array($type, self::UNIFORM_TYPES, true)) {
            throw new \InvalidArgumentException("Snippet uniform '{$name}': type '{$type}' is not one of " . implode(', ', self::UNIFORM_TYPES));
        }
        self::$uniforms[$name] = $type;
    }

    /**
     * Declare a sampler2D for snippets, bound with {@see Command\SetShaderTexture};
     * until then it reads white. Vio family only: the native-GL renderer binds no
     * snippet textures, so a look that samples one registers a GL branch without it.
     *
     * A program holds few samplers ({@see samplerBudget()}): the base program has room
     * for one. $programs limits the declaration to some programs (variant names,
     * BASE_VARIANT for the base one); a variant without {@see ShaderPath::ProbeGi} has
     * room for four.
     *
     * $cube declares a samplerCube instead: its {@see Command\SetShaderTexture} id names a
     * {@see CubemapRegistry} entry, and until one is set it reads the sky environment.
     *
     * @param list<string>|null $programs null = every program
     * @return int the texture slot
     */
    public static function declareSampler(string $name, ?array $programs = null, bool $cube = false): int
    {
        self::assertName($name);
        if (isset(self::$uniforms[$name])) {
            throw new \InvalidArgumentException("Snippet sampler '{$name}' is already a uniform");
        }
        if (isset(self::$samplers[$name])) {
            return self::$samplers[$name];
        }
        if (count(self::$samplers) >= self::MAX_SAMPLERS) {
            throw new \InvalidArgumentException('Snippets can declare at most ' . self::MAX_SAMPLERS . ' samplers');
        }
        self::$samplerPrograms[$name] = $programs;
        if ($cube) {
            self::$cubeSamplers[$name] = true;
        }

        return self::$samplers[$name] = self::FIRST_SAMPLER_SLOT + count(self::$samplers);
    }

    /**
     * Give programs `sceneLinearDepth()` and `sceneDepthDelta()`: the depth of the opaque
     * scene behind a fragment, for what the transparent pass draws (soft particles,
     * water edges, decals). Takes a sampler of each program's budget. The renderer
     * copies the depth per frame while this is set, and a frame without a readable depth (MSAA,
     * a backend without VIO_FEATURE_RENDER_TARGET_DEPTH_SAMPLE) answers "far away". Vio family
     * only: a look that reads it registers a GL branch without.
     *
     * @param list<string>|null $programs null = every program
     */
    public static function requireSceneDepth(?array $programs = null): void
    {
        self::$sceneDepthPrograms = $programs;
    }

    public static function sceneDepthRequired(): bool
    {
        return self::$sceneDepthPrograms !== [];
    }

    private static function readsSceneDepth(string $variant): bool
    {
        return self::$sceneDepthPrograms === null || in_array($variant, self::$sceneDepthPrograms, true);
    }

    /** Ordinary samplers a program has left for snippets. */
    public static function samplerBudget(string $variant = self::BASE_VARIANT): int
    {
        $omit = $variant === self::BASE_VARIANT ? self::$baseOmissions : (self::$variants[$variant]['omit'] ?? []);
        $engine = self::ENGINE_SAMPLERS - (in_array(ShaderPath::ProbeGi, $omit, true) ? self::PROBE_SAMPLERS : 0);

        return self::SAMPLER_REGISTERS - $engine - (self::readsSceneDepth($variant) ? 1 : 0);
    }

    /**
     * Whether a sampler is declared in a program.
     */
    private static function samplerIn(string $name, ?string $variant): bool
    {
        $programs = self::$samplerPrograms[$name] ?? null;

        return $programs === null || $variant === null || in_array($variant, $programs, true);
    }

    /** Refuse a declaration no D3D12 compile would take (error X4610, overlapping SRV ranges). */
    public static function assertSamplerBudget(): void
    {
        foreach ([self::BASE_VARIANT, ...self::variants()] as $variant) {
            $declared = count(array_filter(array_keys(self::$samplers), static fn (string $n): bool => self::samplerIn($n, $variant)));
            $budget = self::samplerBudget($variant);
            if ($declared > $budget) {
                $program = $variant === self::BASE_VARIANT ? 'the base program' : "variant '{$variant}'";
                throw new \InvalidArgumentException("Snippets declare {$declared} samplers in {$program}, it has room for {$budget}");
            }
        }
    }

    /** @return array<string, int> sampler name → texture slot */
    public static function samplers(): array
    {
        return self::$samplers;
    }

    /** Whether $name was declared as a samplerCube (its SetShaderTexture id names a cubemap). */
    public static function isCubeSampler(string $name): bool
    {
        return isset(self::$cubeSamplers[$name]);
    }

    /** The generated declarations of a family. */
    public static function uniformDeclarations(string $family = self::FAMILY_VIO, ?string $variant = null): string
    {
        $out = '';
        foreach (self::$uniforms as $name => $type) {
            $out .= "uniform {$type} {$name};\n";
        }
        if ($family === self::FAMILY_VIO) {
            foreach (self::$samplers as $name => $_) {
                if (self::samplerIn($name, $variant)) {
                    $type = isset(self::$cubeSamplers[$name]) ? 'samplerCube' : 'sampler2D';
                    $out .= "uniform {$type} {$name};\n";
                }
            }
        }

        return $out;
    }

    /**
     * Declare a uniform that each material fills from {@see Material::$params}; a
     * material without a value gets $default, so a draw never sees another
     * material's value. Uploaded with the material's other uniforms.
     *
     * @param int|float|list<float> $default
     */
    public static function declareMaterialParameter(string $name, string $type, int|float|array $default): void
    {
        self::declareUniform($name, $type);
        self::$materialParameters[$name] = $default;
    }

    /** @return array<string, int|float|list<float>> uniform → default */
    public static function materialParameters(): array
    {
        return self::$materialParameters;
    }

    /**
     * Give every mesh program `v_instance_params`, a flat vec3 of the fragment stage,
     * from {@see Command\DrawMesh::$params} or {@see InstanceParams}. Grass keeps
     * m[0].w for its hash: its x reads 0. Vio family only.
     */
    public static function enableInstanceParams(): void
    {
        self::$instanceParams = true;
    }

    public static function instanceParamsEnabled(): bool
    {
        return self::$instanceParams;
    }

    /**
     * Refuse a snippet name the engine's stages already use: on D3D12 it would be set
     * in one stage only, or in the wrong cbuffer slot, without any error.
     */
    public static function assertUniqueUniforms(string $vertexSource, string $fragmentSource): void
    {
        foreach ([...array_keys(self::$uniforms), ...array_keys(self::$samplers)] as $name) {
            $pattern = '/^\s*uniform\s+\w+\s+' . preg_quote($name, '/') . '\b/m';
            if (preg_match($pattern, $vertexSource) === 1 || preg_match($pattern, $fragmentSource) === 1) {
                throw new \InvalidArgumentException("Snippet uniform '{$name}' is already a uniform of the engine's mesh shader");
            }
        }
    }

    private static function assertName(string $name): void
    {
        if (preg_match('/^u_[a-z0-9_]+$/', $name) !== 1) {
            throw new \InvalidArgumentException("Snippet uniform name '{$name}' must look like u_snake_case");
        }
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
     * The program run a mode's opaque draws are sorted into: its variant, or the base
     * program's for a variant defined with `keepOrder`.
     */
    public static function sortVariantOf(int $mode): string
    {
        $variant = self::$modeVariant[$mode] ?? self::BASE_VARIANT;
        return $variant !== self::BASE_VARIANT && self::$variants[$variant]['keepOrder'] ? self::BASE_VARIANT : $variant;
    }

    /**
     * The `#define`s of a program's fragment stage.
     *
     * @return list<string>
     */
    public static function variantDefines(string $variant = self::BASE_VARIANT): array
    {
        $omit = $variant === self::BASE_VARIANT ? self::$baseOmissions : (self::$variants[$variant]['omit'] ?? []);

        $defines = array_map(static fn (ShaderPath $path): string => $path->define(), $omit);
        if (self::readsSceneDepth($variant)) {
            $defines[] = 'PHPOLYGON_SCENE_DEPTH';
        }
        if (self::$instanceParams) {
            $defines[] = 'PHPOLYGON_INSTANCE_PARAMS';
        }

        return $defines;
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
        $source = str_replace(self::UNIFORMS_SENTINEL, self::uniformDeclarations($family, $variant), $source);
        $source = str_replace(self::HELPERS_SENTINEL, self::libraries($family, $variant) . self::glslHelpers($family, $variant), $source);
        $source = str_replace(self::BRANCHES_SENTINEL, self::glslBranches($family, $variant), $source);
        $source = str_replace(self::POSTCOLOR_HELPERS_SENTINEL, self::postColorHelpers($family), $source);
        $source = str_replace(self::POSTCOLOR_SENTINEL, self::postColorCall($family), $source);
        // SURFACE is a prefix of SURFACE_HELPERS: the helpers go first.
        $source = str_replace(self::SURFACE_HELPERS_SENTINEL, self::surfaceHelpers($family), $source);
        $source = str_replace(self::SURFACE_SENTINEL, self::surfaceCall($family), $source);

        return $source;
    }


    public static function clear(): void
    {
        self::$glslHelpers = [];
        self::$glslBranch = [];
        self::$postColorHelpers = null;
        self::$postColorCall = null;
        self::$surfaceHelpers = null;
        self::$surfaceCall = null;
        self::$surfaceCut = false;
        self::$order = [];
        self::$variants = [];
        self::$modeVariant = [];
        self::$baseOmissions = [];
        self::$uniforms = [];
        self::$samplers = [];
        self::$samplerPrograms = [];
        self::$cubeSamplers = [];
        self::$sceneDepthPrograms = [];
        self::$libraries = [];
        self::$materialParameters = [];
        self::$instanceParams = false;
    }
}
