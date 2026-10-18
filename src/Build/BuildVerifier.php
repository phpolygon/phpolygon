<?php

declare(strict_types=1);

namespace PHPolygon\Build;

/**
 * Checks a built game against its build.json - the artifact itself, not the
 * sources it was made from:
 *
 * - every constant any build type overrides (`buildTypes.<type>.constants`)
 *   resolves, inside the PHAR, to the value of the requested type; constants the
 *   type does not set keep the project's own value (a full build never carries a
 *   demo flag);
 * - `verify.versionConstant` equals build.json `version`;
 * - every path of `verify.files` (`*` and the type) ships non-empty, in the PHAR
 *   or among the external resources.
 *
 * {@see GameBuilder} runs it on every build; `phpolygon verify-build` runs it on a
 * finished one (a `.phar`, a macOS `.app`, a flat package directory or binary).
 */
final class BuildVerifier
{
    public function __construct(private readonly BuildConfig $config) {}

    /**
     * @param ?string $resourcesRoot where the package's external resources live
     *   (macOS `Contents/Resources`, else the binary's directory); null = only the PHAR
     */
    public function verifyPhar(string $pharPath, string $buildType, ?string $resourcesRoot = null): BuildVerification
    {
        if ($buildType !== 'full' && !isset($this->config->buildTypes[$buildType])) {
            return new BuildVerification($buildType, [], ["unknown build type '{$buildType}'"]);
        }
        $passed = [];
        $problems = [];
        $base = 'phar://' . str_replace('\\', '/', $pharPath);

        // Constants: what the PHAR's bootstrap chain resolves to vs. what the type asks for.
        $expected = $this->expectedConstants($buildType);
        if ($expected !== []) {
            $sources = [];
            foreach ([BuildConstants::BOOTSTRAP, ...$this->config->additionalRequires] as $file) {
                $content = @file_get_contents($base . '/' . ltrim(str_replace('\\', '/', $file), '/'));
                if (is_string($content)) {
                    $sources[] = $content;
                }
            }
            [$values, $unresolved] = BuildConstants::resolve($sources, array_keys($expected));
            foreach ($expected as $name => $value) {
                if (in_array($name, $unresolved, true)) {
                    $problems[] = "{$name}: not resolvable from the PHAR's bootstrap files";
                } elseif ($values[$name] !== $value) {
                    $problems[] = sprintf('%s = %s, but build type %s expects %s', $name, self::show($values[$name]), $buildType, self::show($value));
                } else {
                    $passed[] = $name . ' = ' . self::show($value);
                }
            }
        }

        // Files that must ship (git-ignored tokens and the like).
        foreach ([...($this->config->verifyFiles['*'] ?? []), ...($this->config->verifyFiles[$buildType] ?? [])] as $path) {
            $inPhar = @file_get_contents($base . '/' . $path);
            $outside = $resourcesRoot !== null ? @file_get_contents(rtrim($resourcesRoot, '/\\') . '/' . $path) : false;
            if ((is_string($inPhar) && trim($inPhar) !== '') || (is_string($outside) && trim($outside) !== '')) {
                $passed[] = "{$path} present";
            } else {
                $problems[] = "{$path}: missing or empty in the build";
            }
        }

        return new BuildVerification($buildType, $passed, $problems);
    }

    /**
     * Verify a finished build: a `.phar`, a macOS `.app`, a package directory
     * (`build/<platform>-<arch>...`) or a combined binary.
     */
    public function verifyPackage(string $path, string $buildType): BuildVerification
    {
        [$phar, $resourcesRoot, $temporary] = $this->locate($path);
        try {
            return $this->verifyPhar($phar, $buildType, $resourcesRoot);
        } finally {
            if ($temporary) {
                @unlink($phar);
            }
        }
    }

    /**
     * The build type a package directory was built as, from its name
     * (`<platform>-<arch>[-<variant>][-<type>]`); `full` when no known type ends it.
     */
    public function typeFromPath(string $path): string
    {
        $name = basename(rtrim(str_replace('\\', '/', $path), '/'));
        foreach (array_keys($this->config->buildTypes) as $type) {
            if (str_ends_with($name, '-' . $type)) {
                return $type;
            }
        }

        return 'full';
    }

    /**
     * Value each checked constant must have in a build of $buildType.
     *
     * @return array<string, mixed>
     */
    public function expectedConstants(string $buildType): array
    {
        $expected = $this->typeConstants($buildType);
        if ($this->config->verifyVersionConstant !== null) {
            $expected[$this->config->verifyVersionConstant] = $this->config->version;
        }

        return $expected;
    }

    /**
     * Value of every constant any build type sets, for a build of $buildType:
     * the type's own value, else the project's.
     *
     * @return array<string, mixed>
     */
    public function typeConstants(string $buildType): array
    {
        $names = [];
        foreach ($this->config->buildTypes as $definition) {
            foreach (is_array($definition['constants'] ?? null) ? $definition['constants'] : [] as $name => $_) {
                $names[(string) $name] = true;
            }
        }
        $expected = [];
        if ($names !== []) {
            // Constants the type leaves alone keep the project's own value.
            $root = $this->config->projectRoot;
            $sources = array_map(
                static fn (string $file): string => (string) file_get_contents($root . '/' . $file),
                BuildConstants::files($this->config, $root),
            );
            [$values] = BuildConstants::resolve($sources, array_keys($names));
            $overrides = $buildType === 'full' ? [] : ($this->config->buildTypes[$buildType]['constants'] ?? []);
            foreach (array_keys($names) as $name) {
                if (is_array($overrides) && array_key_exists($name, $overrides)) {
                    $expected[$name] = $overrides[$name];
                } elseif (array_key_exists($name, $values)) {
                    $expected[$name] = $values[$name];
                }
            }
        }

        return $expected;
    }

    /**
     * @return array{0: string, 1: ?string, 2: bool} [PHAR path, external resources root, PHAR is a temp copy]
     */
    private function locate(string $path): array
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        if (is_file($path) && str_ends_with(strtolower($path), '.phar')) {
            return [$path, null, false];
        }
        $name = $this->config->name;
        if (is_dir($path)) {
            $candidates = str_ends_with($path, '.app')
                ? [$path]
                : [$path . '/' . $name . '.app', $path . '/' . $name, $path . '/' . $name . '/' . $name, $path . '/' . $name . '.exe', $path . '/' . $name . '/' . $name . '.exe'];
            foreach ($candidates as $candidate) {
                if (str_ends_with($candidate, '.app') && is_dir($candidate)) {
                    $resources = $candidate . '/Contents/Resources';
                    $phar = $resources . '/' . (new PlatformPackager($this->config))->macPharFile();
                    if (!is_file($phar)) {
                        throw new \RuntimeException("no PHAR in {$resources}");
                    }

                    return [$phar, $resources, false];
                }
                if (is_file($candidate)) {
                    return [self::extractEmbeddedPhar($candidate), dirname($candidate), true];
                }
            }
            throw new \RuntimeException("no {$name} binary, .app or .phar in {$path}");
        }
        if (is_file($path)) {
            return [self::extractEmbeddedPhar($path), dirname($path), true];
        }
        throw new \RuntimeException("not found: {$path}");
    }

    /**
     * Copy the PHAR a combined binary (runtime + embedded php.ini + PHAR) carries
     * into a temporary `.phar` file. The runtime itself contains the halt-compiler
     * token, so the archive is found by its stub, not by PHP's own scan.
     */
    public static function extractEmbeddedPhar(string $binary): string
    {
        $in = @fopen($binary, 'rb');
        if ($in === false) {
            throw new \RuntimeException("cannot read {$binary}");
        }
        try {
            // Last `<?php` + marker in the file, read in blocks with an overlap so
            // a match across a block border is still seen.
            $chunk = 8 * 1024 * 1024;
            $overlap = strlen(PharBuilder::STUB_MARKER) + 16;
            $offset = null;
            $position = 0;
            $carry = '';
            while (!feof($in)) {
                $block = $carry . (string) fread($in, $chunk);
                $start = $position - strlen($carry);
                $search = 0;
                while (($hit = strpos($block, PharBuilder::STUB_MARKER, $search)) !== false) {
                    $open = strrpos(substr($block, max(0, $hit - 16), min(16, $hit)), '<?php');
                    if ($open !== false) {
                        $offset = $start + max(0, $hit - 16) + $open;
                    }
                    $search = $hit + 1;
                }
                $position = $start + strlen($block);
                $carry = substr($block, -$overlap);
            }
            if ($offset === null) {
                throw new \RuntimeException("{$binary} carries no PHPolygon PHAR");
            }
            $target = sys_get_temp_dir() . '/phpolygon-verify-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.phar';
            $out = fopen($target, 'wb');
            if ($out === false) {
                throw new \RuntimeException("cannot write {$target}");
            }
            fseek($in, $offset);
            stream_copy_to_stream($in, $out);
            fclose($out);

            return $target;
        } finally {
            fclose($in);
        }
    }

    private static function show(mixed $value): string
    {
        return var_export($value, true);
    }
}
