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

    public function testGenerateStubHandlesMacOSAppBundle(): void
    {
        $config = BuildConfig::load($this->projectDir);
        $builder = new PharBuilder($config);

        $stub = $builder->generateStub();

        $this->assertStringContainsString('.app/Contents/MacOS', $stub);
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
