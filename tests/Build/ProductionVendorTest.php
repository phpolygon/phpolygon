<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Build;

use PHPolygon\Build\BuildConfig;
use PHPolygon\Build\FileTree;
use PHPolygon\Build\GameBuilder;
use PHPolygon\Build\ProductionVendor;
use PHPUnit\Framework\TestCase;

/**
 * The production install of a build runs in a working copy and leaves the
 * project's development install alone.
 *
 * The fixture is a project with a runtime and a dev dependency, both from path
 * repositories next to it (no network), a PSR-4 root and a classmap file - the
 * shapes whose autoload paths must keep resolving once vendor/ sits in a PHAR.
 */
class ProductionVendorTest extends TestCase
{
    private static string $root = '';
    private static string $project = '';
    private static string $composer = '';

    public static function setUpBeforeClass(): void
    {
        self::$composer = self::findComposer() ?? '';
        if (self::$composer === '') {
            return;
        }
        self::$root = sys_get_temp_dir() . '/phpolygon-prodvendor-test-' . getmypid() . '-' . bin2hex(random_bytes(3));
        self::$project = self::$root . '/game';

        self::write('libs/runtime/composer.json', self::json([
            'name' => 'fixture/runtime',
            'version' => '1.0.0',
            'autoload' => ['psr-4' => ['Fixture\\Runtime\\' => 'src/']],
        ]));
        self::write('libs/runtime/src/Lib.php', '<?php namespace Fixture\Runtime; class Lib { public const ID = "runtime"; }');
        self::write('libs/devtool/composer.json', self::json([
            'name' => 'fixture/devtool',
            'version' => '1.0.0',
            'autoload' => ['psr-4' => ['Fixture\\Devtool\\' => 'src/']],
        ]));
        self::write('libs/devtool/src/Tool.php', '<?php namespace Fixture\Devtool; class Tool {}');
        self::write('game/composer.json', self::json([
            'name' => 'fixture/game',
            'repositories' => [
                ['type' => 'path', 'url' => '../libs/runtime', 'options' => ['symlink' => false]],
                ['type' => 'path', 'url' => '../libs/devtool', 'options' => ['symlink' => false]],
            ],
            'require' => ['fixture/runtime' => '*'],
            'require-dev' => ['fixture/devtool' => '*'],
            'autoload' => [
                'psr-4' => ['Game\\' => 'src/'],
                // Two classes in one file: only a classmap finds the second.
                'classmap' => ['src/Interp/Ast.php'],
            ],
        ]));
        self::write('game/src/Main.php', '<?php namespace Game; class Main { public const ID = "main"; }');
        self::write('game/src/Interp/Ast.php', '<?php namespace Game\Interp; class AstNode {} class AstLeaf extends AstNode {}');
        self::write('game/game.php', '<?php echo "game";');

        // The development install the build must leave alone.
        $output = [];
        exec(self::$composer . ' install --no-interaction --no-progress --working-dir=' . escapeshellarg(self::$project) . ' 2>&1', $output, $exit);
        if ($exit !== 0) {
            throw new \RuntimeException("Fixture install failed:\n" . implode("\n", $output));
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$root !== '') {
            FileTree::remove(self::$root);
        }
    }

    protected function setUp(): void
    {
        if (self::$composer === '') {
            $this->markTestSkipped('Composer is not available');
        }
    }

    public function testInstallLeavesTheProjectInstallUntouched(): void
    {
        $before = self::projectFingerprint();
        $this->assertFileExists(self::$project . '/vendor/fixture/devtool/src/Tool.php', 'fixture has its dev install');

        $vendor = (new ProductionVendor(self::$project, self::$composer))->install(self::$root . '/work-a/composer');

        $this->assertSame($before, self::projectFingerprint(), 'vendor/, composer.json and composer.lock are byte-identical');
        $this->assertFileExists($vendor . '/autoload.php');
        $this->assertFileExists($vendor . '/fixture/runtime/src/Lib.php');
        $this->assertDirectoryDoesNotExist($vendor . '/fixture/devtool', 'no require-dev in the production install');
    }

    public function testAutoloadPathsAreRelativeToTheArchiveRoot(): void
    {
        $vendor = (new ProductionVendor(self::$project, self::$composer))->install(self::$root . '/work-b/composer');

        $classmap = (string) file_get_contents($vendor . '/composer/autoload_classmap.php');
        $this->assertStringContainsString("'Game\\\\Interp\\\\AstLeaf' => \$baseDir . '/src/Interp/Ast.php'", $classmap);
        $this->assertStringContainsString("'Fixture\\\\Runtime\\\\Lib' => \$vendorDir . '/fixture/runtime/src/Lib.php'", $classmap);
        $psr4 = (string) file_get_contents($vendor . '/composer/autoload_psr4.php');
        $this->assertStringContainsString("'Game\\\\' => array(\$baseDir . '/src')", $psr4);
        $this->assertStringNotContainsString(str_replace('\\', '/', self::$root), str_replace('\\', '/', $classmap . $psr4), 'no absolute paths');
    }

    public function testPharLoadsProjectAndRuntimeClassesFromTheProductionVendor(): void
    {
        $before = self::projectFingerprint();
        $pharPath = self::$root . '/game-' . uniqid() . '.phar';
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';

        // GameBuilder phases 1-3 in a child process: a PHAR needs phar.readonly=0.
        $code = sprintf(
            'require %s; $c = PHPolygon\Build\BuildConfig::load(%s);'
            . ' $v = (new PHPolygon\Build\ProductionVendor(%s, %s))->install(%s);'
            . ' $b = new PHPolygon\Build\PharBuilder($c); $b->stage(%s, $v); $b->build(%s, %s);',
            var_export($autoload, true),
            var_export(self::$project, true),
            var_export(self::$project, true),
            var_export(self::$composer, true),
            var_export(self::$root . '/work-c/composer', true),
            var_export(self::$root . '/work-c/staging', true),
            var_export(self::$root . '/work-c/staging', true),
            var_export($pharPath, true),
        );
        exec(escapeshellarg(PHP_BINARY) . ' -d phar.readonly=0 -r ' . escapeshellarg($code) . ' 2>&1', $output, $exit);
        $this->assertSame(0, $exit, "PHAR build failed:\n" . implode("\n", $output));
        $this->assertSame($before, self::projectFingerprint());

        // Load the archive's autoloader in a clean process, away from the project.
        // No double quotes: escapeshellarg() on Windows replaces them.
        $probe = sprintf(
            'require %s;'
            . ' echo implode(%s, [Game\Main::ID, Fixture\Runtime\Lib::ID, class_exists(Game\Interp\AstLeaf::class) ? %s : %s,'
            . ' class_exists(%s) ? %s : %s, (new ReflectionClass(Game\Main::class))->getFileName()]);',
            var_export('phar://' . str_replace('\\', '/', $pharPath) . '/vendor/autoload.php', true),
            var_export(',', true),
            var_export('leaf', true),
            var_export('-', true),
            var_export('Fixture\\Devtool\\Tool', true),
            var_export('dev', true),
            var_export('nodev', true),
        );
        $result = [];
        exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($probe) . ' 2>&1', $result, $exit);
        $this->assertSame(0, $exit, implode("\n", $result));
        $parts = explode(',', implode("\n", $result));
        $this->assertSame(['main', 'runtime', 'leaf', 'nodev'], array_slice($parts, 0, 4));
        $this->assertStringStartsWith('phar://', $parts[4], 'project classes load from the archive, not the project');
    }

    public function testFailingComposerFailsWithItsOutput(): void
    {
        $before = self::projectFingerprint();
        $failing = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg("echo 'lock file broken'; exit(3);") . ' --';

        try {
            (new ProductionVendor(self::$project, $failing))->install(self::$root . '/work-d/composer');
            $this->fail('expected a RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('exit code 3', $e->getMessage());
            $this->assertStringContainsString('lock file broken', $e->getMessage());
        }
        $this->assertSame($before, self::projectFingerprint());
    }

    public function testBuildFailsBeforeAnyLaterPhaseWhenComposerFails(): void
    {
        $before = self::projectFingerprint();
        $failing = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg("echo 'no network'; exit(2);") . ' --';
        $builder = new GameBuilder(BuildConfig::load(self::$project), $failing);

        try {
            $builder->build('linux', self::$root . '/out', self::$root . '/missing-micro.sfx');
            $this->fail('expected a RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('production dependencies failed', $e->getMessage());
            $this->assertStringContainsString('no network', $e->getMessage());
        }
        $this->assertSame($before, self::projectFingerprint());
    }

    public function testMissingComposerJsonIsReported(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no composer.json');
        (new ProductionVendor(self::$root . '/libs', self::$composer))->install(self::$root . '/work-e/composer');
    }

    /**
     * Content hash of every file of the development install, plus composer.json
     * and composer.lock.
     */
    private static function projectFingerprint(): string
    {
        $hashes = [
            'composer.json' => (string) hash_file('sha256', self::$project . '/composer.json'),
            'composer.lock' => (string) hash_file('sha256', self::$project . '/composer.lock'),
        ];
        foreach (FileTree::walk(self::$project . '/vendor') as $relative => $item) {
            $hashes['vendor/' . $relative] = $item->isDir() ? 'dir' : (string) hash_file('sha256', $item->getPathname());
        }
        ksort($hashes);

        return hash('sha256', (string) json_encode($hashes));
    }

    private static function findComposer(): ?string
    {
        $env = getenv('PHPOLYGON_COMPOSER');
        $candidate = is_string($env) && $env !== '' ? $env : 'composer';
        exec($candidate . ' --version 2>&1', $output, $exit);

        return $exit === 0 ? $candidate : null;
    }

    private static function write(string $path, string $content): void
    {
        $file = self::$root . '/' . $path;
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0755, true);
        }
        file_put_contents($file, $content);
    }

    /** @param array<string, mixed> $data */
    private static function json(array $data): string
    {
        return (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
