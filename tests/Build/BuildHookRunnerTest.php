<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Build;

use PHPolygon\Build\BuildConfig;
use PHPolygon\Build\BuildHookRunner;
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
        self::assertSame('MICROSFX' . BuildHookRunner::RUNNER_BOOTSTRAP, file_get_contents($interpreter));
        self::assertSame($interpreter, $runner->runner($sfx), 'the runner is built once per runtime file');

        putenv('PHPOLYGON_HOOK_PHP=/opt/php/bin/php');
        self::assertSame('/opt/php/bin/php', $runner->interpreter(['run' => ['@php'], 'requires' => ['no_such_function_for_hooks'], 'runtimeVariant' => null], $this->context));
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

    /** @param array<int, mixed> $hooks */
    private function config(array $hooks): BuildConfig
    {
        file_put_contents($this->dir . '/build.json', json_encode(['name' => 'HookTest', 'hooks' => ['beforeBuild' => $hooks]]));
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
