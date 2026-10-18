<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Build;

use PHPolygon\Build\BuildConfig;
use PHPolygon\Build\BuildHookRunner;
use PHPolygon\Build\MicroIni;
use PHPUnit\Framework\TestCase;

final class BuildHookRunnerTest extends TestCase
{
    private string $dir;

    /** @var array{platform: string, arch: string, variant: string, type: string, phpVersion: string} */
    private array $context = ['platform' => 'linux', 'arch' => 'x86_64', 'variant' => 'steam', 'type' => 'demo', 'phpVersion' => '8.5'];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/phpolygon-hooks-' . getmypid() . '-' . bin2hex(random_bytes(3));
        mkdir($this->dir, 0755, true);
        putenv('PHPOLYGON_SKIP_HOOKS');
        putenv('PHPOLYGON_HOOK_PHP');
    }

    protected function tearDown(): void
    {
        putenv('PHPOLYGON_SKIP_HOOKS');
        putenv('PHPOLYGON_HOOK_PHP');
        $this->removeTree($this->dir);
    }

    public function testBuildJsonHooksParseFullAndShortForms(): void
    {
        $config = $this->config([
            ['run' => ['@php', 'tools/a.php'], 'requires' => ['gd_info', 7], 'runtimeVariant' => 'steam'],
            ['@php', 'tools/b.php', '--fast'],
            ['run' => []],
            ['run' => ['@php', 42]],
            'not a hook',
        ]);

        self::assertSame([
            ['run' => ['@php', 'tools/a.php'], 'requires' => ['gd_info'], 'runtimeVariant' => 'steam'],
            ['run' => ['@php', 'tools/b.php', '--fast'], 'requires' => [], 'runtimeVariant' => null],
        ], $config->hooksBeforeBuild);
    }

    public function testHookRunsInTheProjectRootWithArgumentsAndBuildEnvironment(): void
    {
        file_put_contents($this->dir . '/hook.php', '<?php file_put_contents("out.json", json_encode(["argv" => array_slice($argv, 1), "platform" => getenv("PHPOLYGON_BUILD_PLATFORM"), "type" => getenv("PHPOLYGON_BUILD_TYPE")])); echo "prepared\n";');
        $runner = new BuildHookRunner($this->config([['@php', 'hook.php', 'one', 'two words']]), $this->neverResolve());
        $lines = [];
        $runner->setLogger(function (string $level, string $message) use (&$lines): void {
            $lines[] = "{$level}: {$message}";
        });

        $runner->runBeforeBuild($this->context);

        self::assertFileExists($this->dir . '/out.json');
        self::assertSame(
            ['argv' => ['one', 'two words'], 'platform' => 'linux', 'type' => 'demo'],
            json_decode((string) file_get_contents($this->dir . '/out.json'), true),
        );
        self::assertContains('hook: prepared', $lines, 'hook output is relayed');
    }

    public function testAfterPackageHooksSeeTheBundleOfTheirTarget(): void
    {
        file_put_contents($this->dir . '/place.php', '<?php file_put_contents("placed.json", json_encode([getenv("PHPOLYGON_BUILD_PLATFORM"), getenv("PHPOLYGON_BUILD_ARCH"), getenv("PHPOLYGON_BUILD_OUTPUT"), getenv("PHPOLYGON_BUILD_RESOURCES")]));');
        file_put_contents($this->dir . '/build.json', json_encode([
            'hooks' => [
                'beforeBuild' => [['@php', 'not-run.php']],
                'afterPackage' => [['@php', 'place.php']],
            ],
        ]));
        $config = BuildConfig::load($this->dir);
        self::assertSame([['run' => ['@php', 'place.php'], 'requires' => [], 'runtimeVariant' => null]], $config->hooksAfterPackage);

        (new BuildHookRunner($config, $this->neverResolve()))->runAfterPackage(
            ['platform' => 'macos', 'arch' => 'arm64', 'variant' => 'steam', 'type' => 'full', 'phpVersion' => '8.5'],
            '/out/Game.app',
            '/out/Game.app/Contents/Resources',
        );

        self::assertSame(
            ['macos', 'arm64', '/out/Game.app', '/out/Game.app/Contents/Resources'],
            json_decode((string) file_get_contents($this->dir . '/placed.json'), true),
        );
    }

    public function testOutputOnBothStreamsIsRelayedInOrder(): void
    {
        file_put_contents($this->dir . '/talk.php', '<?php echo "first\n"; fwrite(STDERR, "second\n"); echo "third\n"; fwrite(STDERR, "fourth\n");');
        $runner = new BuildHookRunner($this->config([['@php', 'talk.php']]), $this->neverResolve());
        $lines = [];
        $runner->setLogger(function (string $level, string $message) use (&$lines): void {
            if ($level === 'hook') {
                $lines[] = $message;
            }
        });

        $runner->runBeforeBuild($this->context);

        self::assertSame(['first', 'second', 'third', 'fourth'], $lines);
    }

    public function testHookEnvironmentNamesItsInterpreter(): void
    {
        file_put_contents($this->dir . '/which.php', '<?php file_put_contents("php.txt", (string) getenv("PHPOLYGON_HOOK_PHP"));');
        $runner = new BuildHookRunner($this->config([['@php', 'which.php']]), $this->neverResolve());

        $runner->runBeforeBuild($this->context);

        self::assertSame(PHP_BINARY, file_get_contents($this->dir . '/php.txt'));
    }

    public function testFailingHookFailsTheBuild(): void
    {
        file_put_contents($this->dir . '/fail.php', '<?php exit(3);');
        $runner = new BuildHookRunner($this->config([['@php', 'fail.php']]), $this->neverResolve());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('exit code 3');
        $runner->runBeforeBuild($this->context);
    }

    public function testSkipEnvironmentVariableSkipsHooks(): void
    {
        file_put_contents($this->dir . '/fail.php', '<?php exit(3);');
        $runner = new BuildHookRunner($this->config([['@php', 'fail.php']]), $this->neverResolve());
        putenv('PHPOLYGON_SKIP_HOOKS=1');

        $runner->runBeforeBuild($this->context);
        $this->addToAssertionCount(1);
    }

    public function testHostPhpRunsHooksWhoseRequirementsItMeets(): void
    {
        $runner = new BuildHookRunner($this->config([]), $this->neverResolve());
        self::assertSame(PHP_BINARY, $runner->interpreter(['run' => ['@php'], 'requires' => ['json_encode'], 'runtimeVariant' => null], $this->context));
    }

    public function testMissingRequirementsSelectTheGameRuntime(): void
    {
        $calls = [];
        $sfx = $this->dir . '/micro.sfx';
        file_put_contents($sfx, 'MICROSFX');
        $runner = new BuildHookRunner($this->config([]), function (string $platform, string $arch, string $variant, string $php) use (&$calls, $sfx): string {
            $calls[] = [$platform, $arch, $variant, $php];
            return $sfx;
        }, $this->dir . '/runners');

        $interpreter = $runner->interpreter(['run' => ['@php'], 'requires' => ['no_such_function_for_hooks'], 'runtimeVariant' => 'steam'], ['variant' => 'base'] + $this->context);

        self::assertSame([[BuildHookRunner::hostPlatform(), \PHPolygon\Build\StaticPhpResolver::detectArch(), 'steam', '8.5']], $calls);
        self::assertFileExists($interpreter);
        self::assertSame('MICROSFX' . MicroIni::block(BuildHookRunner::RUNNER_INI) . BuildHookRunner::RUNNER_BOOTSTRAP, file_get_contents($interpreter));
        self::assertSame($interpreter, $runner->runner($sfx), 'the runner is built once per runtime file');

        putenv('PHPOLYGON_HOOK_PHP=/opt/php/bin/php');
        self::assertSame('/opt/php/bin/php', $runner->interpreter(['run' => ['@php'], 'requires' => ['no_such_function_for_hooks'], 'runtimeVariant' => null], $this->context));
    }

    public function testRunnerCarriesTheLibrariesTheRuntimeLoads(): void
    {
        mkdir($this->dir . '/cache');
        $sfx = $this->dir . '/cache/micro.sfx';
        $lib = $this->dir . '/cache/runtime-lib.dll';
        file_put_contents($sfx, 'MICROSFX');
        file_put_contents($lib, 'LIB');
        $libCalls = [];
        $runner = new BuildHookRunner(
            $this->config([]),
            static fn (string $platform, string $arch, string $variant, string $php): string => $sfx,
            $this->dir . '/runners',
            static function (string $platform, string $arch, string $variant, string $php) use (&$libCalls, $lib): array {
                $libCalls[] = [$platform, $variant, $php];
                return [$lib];
            },
        );

        $interpreter = $runner->interpreter(['run' => ['@php'], 'requires' => ['no_such_function_for_hooks'], 'runtimeVariant' => 'steam'], $this->context);

        self::assertSame([[BuildHookRunner::hostPlatform(), 'steam', '8.5']], $libCalls);
        self::assertSame('LIB', file_get_contents(dirname($interpreter) . '/runtime-lib.dll'));

        // A runner that exists already still gets a library it lacks.
        unlink(dirname($interpreter) . '/runtime-lib.dll');
        self::assertSame($interpreter, $runner->runner($sfx, [$lib]));
        self::assertFileExists(dirname($interpreter) . '/runtime-lib.dll');
    }

    public function testRunnerLibrariesAreFoundOutsideWindowsToo(): void
    {
        $variable = match (PHP_OS_FAMILY) {
            'Windows' => null,
            'Darwin' => 'DYLD_LIBRARY_PATH',
            default => 'LD_LIBRARY_PATH',
        };
        $environment = ['PATH' => '/usr/bin'];

        if ($variable === null) {
            self::assertSame($environment, BuildHookRunner::withLibraryDirectory($environment, 'C:/runners/abc'));
            return;
        }
        self::assertSame(['PATH' => '/usr/bin', $variable => '/tmp/runners/abc'], BuildHookRunner::withLibraryDirectory($environment, '/tmp/runners/abc'));
        self::assertSame(
            '/tmp/runners/abc' . PATH_SEPARATOR . '/opt/lib',
            BuildHookRunner::withLibraryDirectory([$variable => '/opt/lib'], '/tmp/runners/abc')[$variable],
            'a library path set already stays behind the runner directory',
        );
    }

    /**
     * A runtime is some 70 MB and a build container's PHP has 128 MB of memory: the
     * runner is copied as a stream, never held in memory. Checked in a PHP with less
     * memory than the runtime file.
     */
    public function testRunnerIsBuiltWithoutLoadingTheRuntimeIntoMemory(): void
    {
        $this->config([]);
        $size = 40 * 1024 * 1024;
        $sfx = $this->dir . '/micro.sfx';
        $handle = fopen($sfx, 'wb');
        self::assertIsResource($handle);
        ftruncate($handle, $size);
        fclose($handle);

        $script = $this->dir . '/build-runner.php';
        file_put_contents($script, sprintf(
            '<?php require %s; echo (new PHPolygon\Build\BuildHookRunner(PHPolygon\Build\BuildConfig::load(%s), static fn (): string => "", %s))->runner(%s);',
            var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true),
            var_export($this->dir, true),
            var_export($this->dir . '/runners', true),
            var_export($sfx, true),
        ));

        $process = proc_open([PHP_BINARY, '-d', 'memory_limit=24M', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $interpreter = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $interpreter . $err);

        $bootstrap = MicroIni::block(BuildHookRunner::RUNNER_INI) . BuildHookRunner::RUNNER_BOOTSTRAP;
        self::assertSame($size + strlen($bootstrap), filesize($interpreter));
        $runner = fopen($interpreter, 'rb');
        self::assertIsResource($runner);
        fseek($runner, -strlen($bootstrap), SEEK_END);
        self::assertSame($bootstrap, stream_get_contents($runner));
        fclose($runner);
    }

    /**
     * The bootstrap appended to a micro.sfx is plain PHP: run it with this PHP
     * to check how it passes `-d` options, the script and its arguments on.
     */
    public function testRunnerBootstrapPassesIniOptionsScriptAndArguments(): void
    {
        file_put_contents($this->dir . '/bootstrap.php', BuildHookRunner::RUNNER_BOOTSTRAP);
        file_put_contents($this->dir . '/script.php', '<?php echo json_encode([basename($argv[0]), array_slice($argv, 1), $argc, ini_get("precision")]);');

        $process = proc_open([PHP_BINARY, $this->dir . '/bootstrap.php', '-d', 'precision=9', $this->dir . '/script.php', 'a', 'b c'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $err);
        self::assertSame(['script.php', ['a', 'b c'], 3, '9'], json_decode($out, true));
    }

    /**
     * `-d` reaches the runner as ini_set(), which cannot reserve the JIT buffer
     * or turn on OPcache: a runner without startup settings ran every hook and
     * worker without JIT. The runner embeds them, and build.json `php` wins.
     */
    public function testRunnerEmbedsOpcacheWithJitAndTheGameSettings(): void
    {
        $sfx = $this->dir . '/micro.sfx';
        file_put_contents($sfx, 'MICROSFX');

        $plain = new BuildHookRunner($this->config([]), $this->neverResolve(), $this->dir . '/runners');
        self::assertSame(BuildHookRunner::RUNNER_INI, $plain->runnerIni());
        self::assertSame('1', $plain->runnerIni()['opcache.enable_cli']);
        self::assertNotSame('0', $plain->runnerIni()['opcache.jit_buffer_size']);
        self::assertNotSame('disable', $plain->runnerIni()['opcache.jit']);
        $plainRunner = $plain->runner($sfx);

        $game = new BuildHookRunner($this->config([], ['php' => ['ini' => ['opcache.jit' => 'function', 'memory_limit' => '1G']]]), $this->neverResolve(), $this->dir . '/runners');
        self::assertSame(['opcache.enable_cli' => '1', 'opcache.jit' => 'function', 'opcache.jit_buffer_size' => '128M', 'memory_limit' => '1G'], $game->runnerIni());

        $interpreter = $game->runner($sfx);
        self::assertSame('MICROSFX' . MicroIni::block($game->runnerIni()) . BuildHookRunner::RUNNER_BOOTSTRAP, file_get_contents($interpreter));
        self::assertNotSame($interpreter, $plainRunner, 'other settings, other runner');
    }

    /** A `-d` the runner cannot apply says which value stays, instead of being dropped silently. */
    public function testRunnerBootstrapNamesStartupOnlyOptionsItCannotApply(): void
    {
        file_put_contents($this->dir . '/bootstrap.php', BuildHookRunner::RUNNER_BOOTSTRAP);
        file_put_contents($this->dir . '/script.php', '<?php echo ini_get("memory_limit");');

        $process = proc_open([PHP_BINARY, '-d', 'max_input_time=-1', $this->dir . '/bootstrap.php', '-d', 'max_input_time=12345', '-d', 'memory_limit=77M', '-d', 'no.such.setting=1', $this->dir . '/script.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $err);
        self::assertSame('77M', $out);
        self::assertSame("runner: max_input_time is fixed at startup, keeping max_input_time=-1\n", $err);
    }

    /**
     * @param array<int, mixed> $hooks
     * @param array<string, mixed> $extra
     */
    private function config(array $hooks, array $extra = []): BuildConfig
    {
        file_put_contents($this->dir . '/build.json', json_encode(['name' => 'HookTest', 'hooks' => ['beforeBuild' => $hooks]] + $extra));
        return BuildConfig::load($this->dir);
    }

    private function neverResolve(): \Closure
    {
        return static function (string $platform, string $arch, string $variant, string $php): string {
            throw new \LogicException('the game runtime must not be resolved here');
        };
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
