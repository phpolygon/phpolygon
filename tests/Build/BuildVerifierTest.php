<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Build;

use PHPolygon\Build\BuildConfig;
use PHPolygon\Build\BuildVerifier;
use PHPolygon\Build\FileTree;
use PHPolygon\Build\PharBuilder;
use PHPUnit\Framework\TestCase;

class BuildVerifierTest extends TestCase
{
    private string $tempDir;
    private string $projectDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/phpolygon-verify-test-' . getmypid() . '-' . bin2hex(random_bytes(3));
        $this->projectDir = $this->tempDir . '/project';
        mkdir($this->projectDir . '/src', 0755, true);
        mkdir($this->projectDir . '/vendor', 0755, true);
        mkdir($this->projectDir . '/resources/keys', 0755, true);
        file_put_contents($this->projectDir . '/composer.json', json_encode(['name' => 'test/game']));
        file_put_contents($this->projectDir . '/game.php', '<?php');
        file_put_contents($this->projectDir . '/vendor/autoload.php', '<?php');
        file_put_contents($this->projectDir . '/resources/keys/token', 'secret');
        file_put_contents(
            $this->projectDir . '/version.php',
            "<?php if (!defined('GAME_VERSION')) define('GAME_VERSION', '2.3.4'); if (!defined('DEMO_MODE')) define('DEMO_MODE', false); if (!defined('PLAYTEST_MODE')) define('PLAYTEST_MODE', false);",
        );
        file_put_contents($this->projectDir . '/build.json', json_encode([
            'name' => 'Probe',
            'version' => '2.3.4',
            'phar' => ['additionalRequires' => ['version.php'], 'compression' => 'none'],
            'buildTypes' => [
                'demo' => ['constants' => ['DEMO_MODE' => true]],
                'playtest' => ['constants' => ['DEMO_MODE' => false, 'PLAYTEST_MODE' => true]],
            ],
            'verify' => ['versionConstant' => 'GAME_VERSION', 'files' => ['*' => ['resources/keys/token']]],
        ]));
    }

    protected function tearDown(): void
    {
        FileTree::remove($this->tempDir);
    }

    public function testExpectationsFollowTheTypeAndKeepTheRestAtTheSourceValue(): void
    {
        $verifier = new BuildVerifier(BuildConfig::load($this->projectDir));

        $this->assertSame(['DEMO_MODE' => true, 'PLAYTEST_MODE' => false, 'GAME_VERSION' => '2.3.4'], $verifier->expectedConstants('demo'));
        $this->assertSame(['DEMO_MODE' => false, 'PLAYTEST_MODE' => false, 'GAME_VERSION' => '2.3.4'], $verifier->expectedConstants('full'));
        $this->assertSame('playtest', $verifier->typeFromPath('build/linux-x86_64-steam-playtest'));
        $this->assertSame('full', $verifier->typeFromPath('build/linux-x86_64-steam'));
    }

    public function testADemoPharBuiltWithTheConstantsPasses(): void
    {
        $phar = $this->buildPhar('demo');

        $result = (new BuildVerifier(BuildConfig::load($this->projectDir)))->verifyPhar($phar, 'demo');

        $this->assertTrue($result->ok(), implode("\n", $result->problems));
        $this->assertContains('DEMO_MODE = true', $result->passed);
        $this->assertContains('resources/keys/token present', $result->passed);
    }

    public function testAFullPharShippedAsDemoFails(): void
    {
        // What happened when the override only looked at bootstrap.php: the
        // "demo" PHAR still said DEMO_MODE = false.
        $phar = $this->buildPhar('full');

        $result = (new BuildVerifier(BuildConfig::load($this->projectDir)))->verifyPhar($phar, 'demo');

        $this->assertFalse($result->ok());
        $this->assertSame(['DEMO_MODE = false, but build type demo expects true'], $result->problems);
    }

    public function testADemoPharShippedAsFullFails(): void
    {
        $phar = $this->buildPhar('demo');

        $result = (new BuildVerifier(BuildConfig::load($this->projectDir)))->verifyPhar($phar, 'full');

        $this->assertSame(['DEMO_MODE = true, but build type full expects false'], $result->problems);
    }

    public function testVersionAndMissingFilesAreReported(): void
    {
        $phar = $this->buildPhar('full', withToken: false);
        $json = json_decode((string) file_get_contents($this->projectDir . '/build.json'), true);
        $json['version'] = '2.4.0';
        file_put_contents($this->projectDir . '/build.json', json_encode($json));

        $result = (new BuildVerifier(BuildConfig::load($this->projectDir)))->verifyPhar($phar, 'full');

        $this->assertSame([
            "GAME_VERSION = '2.3.4', but build type full expects '2.4.0'",
            'resources/keys/token: missing or empty in the build',
        ], $result->problems);
    }

    public function testAFileAmongTheExternalResourcesCounts(): void
    {
        $phar = $this->buildPhar('full', withToken: false);
        $external = $this->tempDir . '/package';
        mkdir($external . '/resources/keys', 0755, true);
        file_put_contents($external . '/resources/keys/token', 'secret');

        $result = (new BuildVerifier(BuildConfig::load($this->projectDir)))->verifyPhar($phar, 'full', $external);

        $this->assertTrue($result->ok(), implode("\n", $result->problems));
    }

    public function testThePharIsFoundInsideACombinedBinary(): void
    {
        $phar = $this->buildPhar('playtest');
        // A runtime that itself contains the halt-compiler token and a stray
        // marker, then the php.ini block, then the PHAR - as GameBuilder combines it.
        $package = $this->tempDir . '/build/linux-x86_64-steam-playtest/Probe';
        mkdir($package, 0755, true);
        $runtime = random_bytes(4096) . '__HALT_COMPILER(); ?>' . PharBuilder::STUB_MARKER . random_bytes(9000);
        file_put_contents($package . '/Probe', $runtime . "\xfd\xf6\x69\xe6" . pack('N', 3) . "a=1" . file_get_contents($phar));

        $verifier = new BuildVerifier(BuildConfig::load($this->projectDir));
        $dir = dirname($package);
        $result = $verifier->verifyPackage($dir, $verifier->typeFromPath($dir));

        $this->assertTrue($result->ok(), implode("\n", $result->problems));
        $this->assertContains('PLAYTEST_MODE = true', $result->passed);
    }

    /**
     * Stage, apply the type's constants the way GameBuilder does and build the
     * PHAR - in a child process, since creating a PHAR needs phar.readonly=0.
     */
    private function buildPhar(string $type, bool $withToken = true): string
    {
        if (!$withToken) {
            unlink($this->projectDir . '/resources/keys/token');
        }
        $staging = $this->tempDir . '/staging-' . uniqid();
        $pharPath = $this->tempDir . '/probe-' . uniqid() . '.phar';
        $code = sprintf(
            'require %s; $c = PHPolygon\Build\BuildConfig::load(%s); $b = new PHPolygon\Build\PharBuilder($c); $b->stage(%s);'
            . ' $k = $c->buildTypes[%s][\'constants\'] ?? []; if ($k) { PHPolygon\Build\BuildConstants::apply(%s, $k, PHPolygon\Build\BuildConstants::files($c, %s)); }'
            . ' $b->build(%s, %s);',
            var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true),
            var_export($this->projectDir, true),
            var_export($staging, true),
            var_export($type, true),
            var_export($staging, true),
            var_export($staging, true),
            var_export($staging, true),
            var_export($pharPath, true),
        );
        exec(escapeshellarg(PHP_BINARY) . ' -d phar.readonly=0 -r ' . escapeshellarg($code) . ' 2>&1', $output, $exit);
        $this->assertSame(0, $exit, "PHAR build failed:\n" . implode("\n", $output));

        return $pharPath;
    }
}
