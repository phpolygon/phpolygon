<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Build;

use PHPolygon\Build\BuildConfig;
use PHPolygon\Build\PharBuilder;
use PHPUnit\Framework\TestCase;

class PharBuilderTest extends TestCase
{
    private string $tempDir;
    private string $projectDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/phpolygon-phar-test-' . getmypid();
        $this->projectDir = $this->tempDir . '/project';
        @mkdir($this->projectDir . '/src', 0755, true);
        @mkdir($this->projectDir . '/vendor/some-lib/src', 0755, true);
        @mkdir($this->projectDir . '/resources/shaders', 0755, true);
        @mkdir($this->projectDir . '/assets/sprites', 0755, true);

        file_put_contents($this->projectDir . '/composer.json', json_encode(['name' => 'test/game']));
        file_put_contents($this->projectDir . '/game.php', '<?php echo "game";');
        file_put_contents($this->projectDir . '/src/Game.php', '<?php class Game {}');
        file_put_contents($this->projectDir . '/vendor/some-lib/src/Lib.php', '<?php class Lib {}');
        file_put_contents($this->projectDir . '/vendor/autoload.php', '<?php // autoload');
        file_put_contents($this->projectDir . '/resources/shaders/basic.glsl', 'void main() {}');
        file_put_contents($this->projectDir . '/assets/sprites/hero.png', 'fake-image');
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    public function testStageCreatesCorrectStructure(): void
    {
        $config = BuildConfig::load($this->projectDir);
        $builder = new PharBuilder($config);

        $stagingDir = $this->tempDir . '/staging';
        $builder->stage($stagingDir);

        $this->assertDirectoryExists($stagingDir . '/src');
        $this->assertDirectoryExists($stagingDir . '/vendor');
        $this->assertDirectoryExists($stagingDir . '/resources/shaders');
        $this->assertDirectoryExists($stagingDir . '/assets/sprites');
        $this->assertFileExists($stagingDir . '/game.php');
        $this->assertFileExists($stagingDir . '/src/Game.php');
        $this->assertFileExists($stagingDir . '/vendor/autoload.php');
        $this->assertFileExists($stagingDir . '/resources/shaders/basic.glsl');
        $this->assertFileExists($stagingDir . '/assets/sprites/hero.png');
    }

    public function testStageExcludesTestDirectories(): void
    {
        // Create test dirs in vendor
        @mkdir($this->projectDir . '/vendor/some-lib/tests', 0755, true);
        file_put_contents($this->projectDir . '/vendor/some-lib/tests/SomeTest.php', '<?php');

        $config = BuildConfig::load($this->projectDir);
        $builder = new PharBuilder($config);

        $stagingDir = $this->tempDir . '/staging';
        $builder->stage($stagingDir);

        $this->assertFileDoesNotExist($stagingDir . '/vendor/some-lib/tests/SomeTest.php');
    }

    public function testBuildJsonExcludeKeepsDefaultExcludes(): void
    {
        file_put_contents($this->projectDir . '/build.json', json_encode([
            'phar' => ['exclude' => ['**/fixtures']],
        ]));
        @mkdir($this->projectDir . '/vendor/some-lib/examples', 0755, true);
        @mkdir($this->projectDir . '/vendor/some-lib/fixtures', 0755, true);
        file_put_contents($this->projectDir . '/vendor/some-lib/examples/demo.gif', 'gif');
        file_put_contents($this->projectDir . '/vendor/some-lib/fixtures/data.json', '{}');
        file_put_contents($this->projectDir . '/vendor/some-lib/debug.log', 'log');

        $stagingDir = $this->tempDir . '/staging';
        (new PharBuilder(BuildConfig::load($this->projectDir)))->stage($stagingDir);

        $this->assertDirectoryDoesNotExist($stagingDir . '/vendor/some-lib/examples');
        $this->assertDirectoryDoesNotExist($stagingDir . '/vendor/some-lib/fixtures');
        $this->assertFileDoesNotExist($stagingDir . '/vendor/some-lib/debug.log');
        $this->assertFileExists($stagingDir . '/vendor/some-lib/src/Lib.php');
    }

    public function testStageSkipsComposerLeftoversAndEngineDevFiles(): void
    {
        $engine = $this->projectDir . '/vendor/phpolygon/phpolygon';
        @mkdir($this->projectDir . '/vendor/composer', 0755, true);
        @mkdir($engine . '/src/Geometry/Generated', 0755, true);
        @mkdir($engine . '/benchmarks/micro', 0755, true);
        @mkdir($engine . '/.github/workflows', 0755, true);
        @mkdir($engine . '/resources/shaders', 0755, true);
        file_put_contents($this->projectDir . '/vendor/composer/tmp-0123abcd.zip', 'zip');
        file_put_contents($this->projectDir . '/vendor/composer/installed.php', '<?php');
        file_put_contents($engine . '/game.log', 'log');
        file_put_contents($engine . '/.github/workflows/ci.yml', 'on: push');
        file_put_contents($engine . '/.gitignore', '/vendor/');
        file_put_contents($engine . '/CLAUDE.md', '#');
        file_put_contents($engine . '/LICENSE', 'MIT');
        file_put_contents($engine . '/composer.json', '{}');
        file_put_contents($engine . '/composer.lock', '{}');
        file_put_contents($engine . '/benchmarks/micro/Bench.php', '<?php');
        file_put_contents($engine . '/src/Engine.php', '<?php');
        file_put_contents($engine . '/src/Geometry/Generated/CarMesh.php', '<?php');
        file_put_contents($engine . '/src/Geometry/Generated/CarMesh.php.json', '{}');
        file_put_contents($engine . '/resources/shaders/mesh3d.frag.glsl', 'void main(){}');
        $lib = $this->projectDir . '/vendor/acme/lib';
        @mkdir($lib . '/src', 0755, true);
        file_put_contents($lib . '/composer.lock', '{}');
        file_put_contents($lib . '/README.md', '#');
        file_put_contents($lib . '/phpunit.xml.dist', '<phpunit/>');
        file_put_contents($lib . '/LICENSE.md', 'MIT');
        file_put_contents($lib . '/src/NOTES.md', 'kept: only package roots are cleaned');

        $stagingDir = $this->tempDir . '/staging';
        (new PharBuilder(BuildConfig::load($this->projectDir)))->stage($stagingDir);

        $staged = $stagingDir . '/vendor/phpolygon/phpolygon';
        $this->assertFileDoesNotExist($stagingDir . '/vendor/composer/tmp-0123abcd.zip');
        $this->assertFileDoesNotExist($staged . '/game.log');
        $this->assertFileDoesNotExist($staged . '/CLAUDE.md');
        $this->assertFileDoesNotExist($staged . '/composer.lock');
        $this->assertDirectoryDoesNotExist($staged . '/benchmarks');
        $this->assertDirectoryDoesNotExist($staged . '/.github');
        $this->assertFileDoesNotExist($staged . '/.gitignore');
        $this->assertFileDoesNotExist($staged . '/src/Geometry/Generated/CarMesh.php.json');

        $stagedLib = $stagingDir . '/vendor/acme/lib';
        $this->assertFileDoesNotExist($stagedLib . '/composer.lock');
        $this->assertFileDoesNotExist($stagedLib . '/README.md');
        $this->assertFileDoesNotExist($stagedLib . '/phpunit.xml.dist');
        $this->assertFileExists($stagedLib . '/LICENSE.md');
        $this->assertFileExists($stagedLib . '/src/NOTES.md');

        $this->assertFileExists($stagingDir . '/vendor/composer/installed.php');
        $this->assertFileExists($staged . '/LICENSE');
        $this->assertFileExists($staged . '/composer.json');
        $this->assertFileExists($staged . '/src/Engine.php');
        $this->assertFileExists($staged . '/src/Geometry/Generated/CarMesh.php');
        $this->assertFileExists($staged . '/resources/shaders/mesh3d.frag.glsl');
    }

    public function testStageRespectsExternalResources(): void
    {
        // Mark shaders as external
        file_put_contents($this->projectDir . '/build.json', json_encode([
            'resources' => ['external' => ['resources/shaders']],
        ]));

        $config = BuildConfig::load($this->projectDir);
        $builder = new PharBuilder($config);

        $stagingDir = $this->tempDir . '/staging';
        $builder->stage($stagingDir);

        // External resources should NOT be staged
        $this->assertDirectoryDoesNotExist($stagingDir . '/resources/shaders');
    }

    public function testStageIncludesNestedShaderSubdirectories(): void
    {
        // Mirror the resources/shaders/source/vio/ structure introduced by
        // the Vio shader-extraction refactor. PharBuilder copies resources/
        // entries via copyDirectory(), so any subtree depth must round-trip.
        @mkdir($this->projectDir . '/resources/shaders/source/vio', 0o755, true);
        file_put_contents(
            $this->projectDir . '/resources/shaders/source/vio/mesh3d.vert.glsl',
            "#version 410 core\nvoid main(){}\n",
        );
        file_put_contents(
            $this->projectDir . '/resources/shaders/source/vio/mesh3d.frag.glsl',
            "#version 410 core\nvoid main(){}\n",
        );

        $config = BuildConfig::load($this->projectDir);
        $builder = new PharBuilder($config);

        $stagingDir = $this->tempDir . '/staging';
        $builder->stage($stagingDir);

        $this->assertDirectoryExists($stagingDir . '/resources/shaders/source/vio');
        $this->assertFileExists($stagingDir . '/resources/shaders/source/vio/mesh3d.vert.glsl');
        $this->assertFileExists($stagingDir . '/resources/shaders/source/vio/mesh3d.frag.glsl');
    }

    public function testStageIncludesCustomEntry(): void
    {
        file_put_contents($this->projectDir . '/main.php', '<?php echo "main";');
        file_put_contents($this->projectDir . '/build.json', json_encode([
            'entry' => 'main.php',
        ]));

        $config = BuildConfig::load($this->projectDir);
        $builder = new PharBuilder($config);

        $stagingDir = $this->tempDir . '/staging';
        $builder->stage($stagingDir);

        $this->assertFileExists($stagingDir . '/main.php');
    }

    public function testGenerateStubContainsPHPolygonConstants(): void
    {
        $config = BuildConfig::load($this->projectDir);
        $builder = new PharBuilder($config);

        $stub = $builder->generateStub();

        $this->assertStringContainsString('PHPOLYGON_PATH_ROOT', $stub);
        $this->assertStringContainsString('PHPOLYGON_PATH_ASSETS', $stub);
        $this->assertStringContainsString('PHPOLYGON_PATH_RESOURCES', $stub);
        $this->assertStringContainsString('PHPOLYGON_PATH_SAVES', $stub);
        $this->assertStringContainsString('PHPOLYGON_PATH_MODS', $stub);
        $this->assertStringContainsString('__HALT_COMPILER', $stub);
        $this->assertStringContainsString('vendor/autoload.php', $stub);
    }

    /**
     * mkdir() on an existing directory warns, and the stub extracts every asset on
     * every start: it must only create directories that are missing.
     */
    public function testStubCreatesOnlyMissingDirectories(): void
    {
        $stub = (new PharBuilder(BuildConfig::load($this->projectDir)))->generateStub();

        $this->assertSame(1, substr_count($stub, '@mkdir('), 'one guarded mkdir() call, in $__ensureDir');
        $this->assertStringContainsString("if (!is_dir(\$dir)) {", $stub);
    }

    /**
     * The stub's error handler logs every warning of the shipped game. Errors
     * silenced with @ (probing mkdir, optional files) must stay out of the log:
     * a start produced over a thousand such lines.
     */
    public function testStubErrorHandlerSkipsSilencedErrors(): void
    {
        $stub = (new PharBuilder(BuildConfig::load($this->projectDir)))->generateStub();
        $start = strpos($stub, 'set_error_handler(');
        $this->assertIsInt($start);
        $end = strpos($stub, "\n});", $start);
        $this->assertIsInt($end);

        $logged = [];
        $__engineLog = static function (string $message) use (&$logged): void {
            $logged[] = $message;
        };
        $displayErrors = ini_set('display_errors', '0');
        $logErrors = ini_set('log_errors', '0');
        $reporting = error_reporting(E_ALL);
        eval(substr($stub, $start, $end + 4 - $start));
        try {
            @trigger_error('silenced', E_USER_WARNING);
            trigger_error('loud', E_USER_WARNING);
        } finally {
            restore_error_handler();
            error_reporting($reporting);
            ini_set('log_errors', (string) $logErrors);
            ini_set('display_errors', (string) $displayErrors);
        }

        $this->assertCount(1, $logged);
        $this->assertStringStartsWith('WARNING: loud in ', $logged[0]);
    }

    public function testGenerateStubIncludesAdditionalRequires(): void
    {
        file_put_contents($this->projectDir . '/build.json', json_encode([
            'phar' => ['additionalRequires' => ['bootstrap.php']],
        ]));

        $config = BuildConfig::load($this->projectDir);
        $builder = new PharBuilder($config);

        $stub = $builder->generateStub();

        $this->assertStringContainsString("require_once \$pharBase . '/bootstrap.php'", $stub);
    }

    public function testGenerateStubIncludesRunCode(): void
    {
        file_put_contents($this->projectDir . '/build.json', json_encode([
            'run' => '\App\Game::start();',
        ]));

        $config = BuildConfig::load($this->projectDir);
        $builder = new PharBuilder($config);

        $stub = $builder->generateStub();

        $this->assertStringContainsString('\App\Game::start();', $stub);
    }

    public function testBuildCompressesEveryEntryWithGzipByDefault(): void
    {
        if (!\Phar::canCompress(\Phar::GZ)) {
            $this->markTestSkipped('zlib missing: gzip PHAR entries cannot be written or read');
        }
        $pharPath = $this->buildPhar();

        $entries = $this->pharEntries($pharPath);
        $this->assertNotEmpty($entries);
        foreach ($entries as $path => $info) {
            $this->assertTrue($info->isCompressed(\Phar::GZ), "entry $path is gzip-compressed");
        }
        // Compressed entries still read back unchanged through phar://.
        $base = 'phar://' . str_replace('\\', '/', $pharPath);
        $this->assertSame('fake-image', file_get_contents($base . '/assets/sprites/hero.png'));
        $this->assertSame('<?php class Game {}', file_get_contents($base . '/src/Game.php'));
    }

    public function testBuildJsonCompressionNoneStoresEntriesPlain(): void
    {
        file_put_contents($this->projectDir . '/build.json', json_encode([
            'phar' => ['compression' => 'none'],
        ]));
        $pharPath = $this->buildPhar();

        foreach ($this->pharEntries($pharPath) as $path => $info) {
            $this->assertFalse($info->isCompressed(), "entry $path is stored plain");
        }
    }

    /**
     * Without zlib a compressed archive fails on its first include with an opaque
     * phar error; the stub has to refuse up front and say why in game.log.
     */
    public function testStubRefusesARuntimeWithoutZlibOnlyWhenCompressed(): void
    {
        $gzipStub = (new PharBuilder(BuildConfig::load($this->projectDir)))->generateStub();
        $guard = strpos($gzipStub, "if (!extension_loaded('zlib'))");
        $this->assertIsInt($guard);
        $this->assertLessThan(strpos($gzipStub, 'vendor/autoload.php'), $guard, 'checked before the first read from the archive');
        $this->assertLessThan(strpos($gzipStub, 'BundleExtractor::sync('), $guard, 'checked before the asset extraction');
        $this->assertStringNotContainsString('@@ZLIB_GUARD@@', $gzipStub);

        file_put_contents($this->projectDir . '/build.json', json_encode([
            'phar' => ['compression' => 'none'],
        ]));
        $plainStub = (new PharBuilder(BuildConfig::load($this->projectDir)))->generateStub();
        $this->assertStringNotContainsString("extension_loaded('zlib')", $plainStub);
        $this->assertStringNotContainsString('@@ZLIB_GUARD@@', $plainStub);
    }

    /**
     * Builds the fixture project's PHAR in a child process: creating a PHAR needs
     * phar.readonly=0, which cannot be switched off at runtime.
     */
    private function buildPhar(): string
    {
        $stagingDir = $this->tempDir . '/staging';
        // A fresh name per build: PHP caches an opened PHAR's manifest (and its
        // alias, the basename) for the life of the process.
        $pharPath = $this->tempDir . '/game-' . uniqid() . '.phar';
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $code = sprintf(
            'require %s; $b = new PHPolygon\Build\PharBuilder(PHPolygon\Build\BuildConfig::load(%s)); $b->stage(%s); $b->build(%s, %s);',
            var_export($autoload, true),
            var_export($this->projectDir, true),
            var_export($stagingDir, true),
            var_export($stagingDir, true),
            var_export($pharPath, true),
        );
        $cmd = escapeshellarg(PHP_BINARY) . ' -d phar.readonly=0 -r ' . escapeshellarg($code) . ' 2>&1';
        exec($cmd, $output, $exit);
        $this->assertSame(0, $exit, "PHAR build failed:\n" . implode("\n", $output));
        $this->assertFileExists($pharPath);

        return $pharPath;
    }

    /**
     * @return array<string, \PharFileInfo>
     */
    private function pharEntries(string $pharPath): array
    {
        $phar = new \Phar($pharPath);
        $prefix = 'phar://' . str_replace('\\', '/', $pharPath) . '/';
        $entries = [];
        foreach (new \RecursiveIteratorIterator($phar) as $info) {
            /** @var \PharFileInfo $info */
            $entries[substr(str_replace('\\', '/', $info->getPathname()), strlen($prefix))] = $info;
        }
        unset($phar);

        return $entries;
    }

    public function testGenerateStubHandlesMacOSAppBundle(): void
    {
        $config = BuildConfig::load($this->projectDir);
        $builder = new PharBuilder($config);

        $stub = $builder->generateStub();

        $this->assertStringContainsString('.app/Contents/(MacOS|Resources)', $stub);
    }

    /**
     * External paths ship loose next to the executable; under assets/ they used
     * to land in the PHAR as well (only resources/ was filtered).
     */
    public function testStageLeavesExternalPathsUnderAnyRootOutOfThePhar(): void
    {
        @mkdir($this->projectDir . '/assets/audio/music', 0755, true);
        file_put_contents($this->projectDir . '/assets/audio/music/theme.flac', 'flac');
        file_put_contents($this->projectDir . '/assets/manual.pdf', 'pdf');
        file_put_contents($this->projectDir . '/build.json', json_encode([
            'resources' => ['external' => ['assets/audio', 'assets/manual.pdf', 'resources/shaders']],
        ]));

        $stagingDir = $this->tempDir . '/staging';
        (new PharBuilder(BuildConfig::load($this->projectDir)))->stage($stagingDir);

        $this->assertDirectoryDoesNotExist($stagingDir . '/assets/audio');
        $this->assertFileDoesNotExist($stagingDir . '/assets/manual.pdf');
        $this->assertDirectoryDoesNotExist($stagingDir . '/resources/shaders');
        $this->assertFileExists($stagingDir . '/assets/sprites/hero.png');
    }

    public function testStageAppliesResourcesExcludeUnderEveryRoot(): void
    {
        @mkdir($this->projectDir . '/assets/audio/voice-src/de', 0755, true);
        file_put_contents($this->projectDir . '/assets/audio/voice-src/de/line1.wav', 'wav');
        file_put_contents($this->projectDir . '/assets/sprites/hero.psd', 'psd');
        file_put_contents($this->projectDir . '/resources/shaders/notes.txt', 'notes');
        file_put_contents($this->projectDir . '/build.json', json_encode([
            'resources' => ['exclude' => ['assets/audio/voice-src', '**/*.psd', 'resources/shaders/*.txt']],
        ]));

        $stagingDir = $this->tempDir . '/staging';
        (new PharBuilder(BuildConfig::load($this->projectDir)))->stage($stagingDir);

        $this->assertDirectoryDoesNotExist($stagingDir . '/assets/audio/voice-src');
        $this->assertFileDoesNotExist($stagingDir . '/assets/sprites/hero.psd');
        $this->assertFileDoesNotExist($stagingDir . '/resources/shaders/notes.txt');
        $this->assertFileExists($stagingDir . '/assets/sprites/hero.png');
        $this->assertFileExists($stagingDir . '/resources/shaders/basic.glsl');
    }

    /**
     * Files a build step adds after staging (the compiled meshes of
     * assets.meshes = binary) are held to resources.exclude as well.
     */
    public function testPruneExcludedDropsFilesCreatedAfterStaging(): void
    {
        file_put_contents($this->projectDir . '/build.json', json_encode([
            'resources' => ['exclude' => ['assets/meshes/debug', 'assets/**/*.preview.mesh.bin']],
        ]));
        $stagingDir = $this->tempDir . '/staging';
        $builder = new PharBuilder(BuildConfig::load($this->projectDir));
        $builder->stage($stagingDir);
        @mkdir($stagingDir . '/assets/meshes/debug', 0755, true);
        file_put_contents($stagingDir . '/assets/meshes/crate.mesh.bin', 'bin');
        file_put_contents($stagingDir . '/assets/meshes/crate.preview.mesh.bin', 'bin');
        file_put_contents($stagingDir . '/assets/meshes/debug/axis.mesh.bin', 'bin');

        $this->assertSame(2, $builder->pruneExcluded($stagingDir));

        $this->assertFileExists($stagingDir . '/assets/meshes/crate.mesh.bin');
        $this->assertFileDoesNotExist($stagingDir . '/assets/meshes/crate.preview.mesh.bin');
        $this->assertFileDoesNotExist($stagingDir . '/assets/meshes/debug/axis.mesh.bin');
        $this->assertFileExists($stagingDir . '/assets/sprites/hero.png');
    }

    public function testBareExternalNameStillMeansTheResourcesFolder(): void
    {
        file_put_contents($this->projectDir . '/build.json', json_encode([
            'resources' => ['external' => ['shaders']],
        ]));

        $stagingDir = $this->tempDir . '/staging';
        (new PharBuilder(BuildConfig::load($this->projectDir)))->stage($stagingDir);

        $this->assertDirectoryDoesNotExist($stagingDir . '/resources/shaders');
        $this->assertFileExists($stagingDir . '/assets/sprites/hero.png');
    }

    public function testBuildIdChangesOnlyWithTheExtractedFiles(): void
    {
        $stagingDir = $this->tempDir . '/staging';
        $builder = new PharBuilder(BuildConfig::load($this->projectDir));
        $builder->stage($stagingDir);
        $first = PharBuilder::computeBuildId($stagingDir);

        file_put_contents($stagingDir . '/src/Game.php', '<?php class Game { /* code only */ }');
        $this->assertSame($first, PharBuilder::computeBuildId($stagingDir), 'a code change extracts nothing');

        file_put_contents($stagingDir . '/assets/sprites/hero.png', 'new-image');
        $this->assertNotSame($first, PharBuilder::computeBuildId($stagingDir));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $first);
    }

    public function testBuiltPharCarriesItsBuildIdInStubAndArchive(): void
    {
        $pharPath = $this->buildPhar();

        $buildId = PharBuilder::readBuildId($pharPath);
        $this->assertNotNull($buildId);
        $stub = (string) file_get_contents($pharPath, false, null, 0, 20000);
        $this->assertStringContainsString("\$__buildId = '{$buildId}';", $stub);
    }

    /**
     * The built archive itself, run with this PHP: next to a writable install it
     * extracts in place once, and a second start copies nothing.
     */
    public function testRunningTheArchiveExtractsOncePerBuild(): void
    {
        $install = $this->tempDir . '/install';
        $phar = $this->runnablePhar($install . '/game.phar');

        $first = $this->runPhar($phar);
        // realpath: on macOS the temp dir /var/folders is a symlink into /private/var
        $this->assertSame(str_replace('\\', '/', (string) realpath($install . '/assets')), str_replace('\\', '/', (string) realpath($first['assets'])));
        $this->assertSame('fake-image', file_get_contents($install . '/assets/sprites/hero.png'));
        $this->assertStringContainsString('Extracted ', (string) file_get_contents($install . '/game.log'));

        file_put_contents($install . '/assets/sprites/hero.png', 'kept');
        $this->runPhar($phar);
        $this->assertSame('kept', file_get_contents($install . '/assets/sprites/hero.png'), 'same build: no copy');
        $this->assertStringNotContainsString('Extracted ', (string) file_get_contents($install . '/game.log'));
        $this->assertStringContainsString('Extracted ', (string) file_get_contents($install . '/game.log.1'), 'the previous start is kept');
    }

    /**
     * Inside a macOS .app nothing is written to the bundle: the log goes to
     * ~/Library/Logs/<name>, saves to Application Support, and files the build
     * did not pre-extract to the user's cache.
     */
    public function testRunningTheArchiveInsideAnAppBundleNeverWritesToIt(): void
    {
        $resources = $this->tempDir . '/Test.app/Contents/Resources';
        $phar = $this->runnablePhar($resources . '/game.phar');
        $home = $this->tempDir . '/home';
        mkdir($home, 0755, true);
        $before = $this->listTree($this->tempDir . '/Test.app');

        $paths = $this->runPhar($phar, ['HOME' => $home, 'LOCALAPPDATA' => $home . '/AppData/Local', 'XDG_CACHE_HOME' => '']);

        $this->assertSame($before, $this->listTree($this->tempDir . '/Test.app'), 'the bundle is unchanged');
        $this->assertFileExists($home . '/Library/Logs/Fixture/game.log');
        $this->assertStringStartsWith(str_replace('\\', '/', $home), $paths['assets']);
        $this->assertSame('fake-image', file_get_contents($paths['assets'] . '/sprites/hero.png'));
        $this->assertSame(str_replace('\\', '/', $home) . '/Library/Application Support/com.test.fixture/saves', str_replace('\\', '/', $paths['saves']));
    }

    public function testMacLauncherLogsFirstAndRunsTheSharedArchive(): void
    {
        file_put_contents($this->projectDir . '/build.json', json_encode(['name' => 'My Game']));
        $launcher = (new PharBuilder(BuildConfig::load($this->projectDir)))->generateMacLauncher('my-game.phar');

        $this->assertStringContainsString("dirname(\$__bin, 2) . '/Resources/' . 'my-game.phar'", $launcher);
        $this->assertStringContainsString("'/Library/Logs/' . 'My Game'", $launcher);
        $this->assertLessThan(strpos($launcher, 'require $__phar'), strpos($launcher, "boot.log"), 'logged before the archive loads');

        $file = $this->tempDir . '/launcher.php';
        file_put_contents($file, $launcher);
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $output, $exit);
        $this->assertSame(0, $exit, implode("\n", $output));
    }

    /**
     * A PHAR of the fixture project that can run: its autoloader is the engine's,
     * and the run code prints the paths the stub chose.
     */
    private function runnablePhar(string $target): string
    {
        $engineAutoload = var_export(str_replace('\\', '/', dirname(__DIR__, 2) . '/vendor/autoload.php'), true);
        file_put_contents($this->projectDir . '/vendor/autoload.php', "<?php require {$engineAutoload};");
        file_put_contents($this->projectDir . '/build.json', json_encode([
            'name' => 'Fixture',
            'identifier' => 'com.test.fixture',
            'run' => 'echo "\nPATHS:" . json_encode(["assets" => str_replace("\\\\", "/", PHPOLYGON_PATH_ASSETS), "saves" => PHPOLYGON_PATH_SAVES, "logs" => PHPOLYGON_PATH_LOGS]);',
        ]));
        $phar = $this->buildPhar();
        @mkdir(dirname($target), 0755, true);
        rename($phar, $target);

        return $target;
    }

    /**
     * @param array<string, string> $env
     * @return array{assets: string, saves: string, logs: string}
     */
    private function runPhar(string $phar, array $env = []): array
    {
        $process = proc_open(
            [PHP_BINARY, $phar],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname($phar),
            $env === [] ? null : [...getenv(), ...$env],
        );
        $this->assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        proc_close($process);

        $this->assertMatchesRegularExpression('/PATHS:(\{.*\})/', $out, $out);
        preg_match('/PATHS:(\{.*\})/', $out, $m);
        /** @var array{assets: string, saves: string, logs: string} $paths */
        $paths = json_decode($m[1], true);

        return $paths;
    }

    /** @return list<string> */
    private function listTree(string $dir): array
    {
        $entries = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $item) {
            /** @var \SplFileInfo $item */
            $entries[] = substr($item->getPathname(), strlen($dir)) . ':' . ($item->isDir() ? 'd' : $item->getSize() . '@' . $item->getMTime());
        }
        sort($entries);

        return $entries;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
