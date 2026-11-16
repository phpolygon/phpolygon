<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Build;

use PHPolygon\Build\FileTree;
use PHPUnit\Framework\TestCase;

/**
 * A build type derived from another type's PHAR must stay readable in EVERY entry.
 *
 * `PharBuilder::deriveBuildType()` copies the source PHAR, reads the bootstrap files
 * named in build.json (`phar.additionalRequires`) to patch the constants, and rewrites
 * the ones that change. It used to read those files through `phar://` on the COPY it was
 * about to rewrite: the entries it had only read (not changed) were left with stale
 * offsets, and the derived PHAR died at start-up with "zlib: data error ... actual
 * filesize mismatch on file ..." - on the first file it required. The BuildVerifier did
 * not notice: it checks the constants and the token, not that every entry inflates.
 */
class DeriveBuildTypeIntegrityTest extends TestCase
{
    private string $tempDir;
    private string $projectDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/phpolygon-derive-integrity-' . getmypid() . '-' . bin2hex(random_bytes(3));
        $this->projectDir = $this->tempDir . '/project';
        mkdir($this->projectDir . '/src/Globals', 0755, true);
        mkdir($this->projectDir . '/src/Scene', 0755, true);
        mkdir($this->projectDir . '/vendor', 0755, true);
        file_put_contents($this->projectDir . '/composer.json', json_encode(['name' => 'test/game']));
        file_put_contents($this->projectDir . '/game.php', '<?php');
        file_put_contents($this->projectDir . '/vendor/autoload.php', '<?php');

        // The files a real game lists in phar.additionalRequires: two define the type constants and
        // change on derive, the others are only read to look for them and must come out untouched.
        file_put_contents($this->projectDir . '/bootstrap.php', "<?php\nif (!defined('DEMO_MODE')) define('DEMO_MODE', false);\n" . str_repeat("// padding line\n", 80));
        file_put_contents($this->projectDir . '/bootstrap_version.php', "<?php\nif (!defined('GAME_VERSION')) define('GAME_VERSION', '2.3.4');\n" . str_repeat("// version padding\n", 60));
        file_put_contents($this->projectDir . '/bootstrap_constants.php', "<?php\nif (!defined('DEMO_END_YEAR')) define('DEMO_END_YEAR', 0);\n" . str_repeat("// constants padding\n", 70));
        file_put_contents($this->projectDir . '/src/Globals/helpers.php', "<?php\n" . str_repeat("function helper_line() { return 'helpers'; }\n", 400));
        file_put_contents($this->projectDir . '/src/Scene/main_menu.php', "<?php\n" . str_repeat("// main menu line with some text to compress\n", 900));
        file_put_contents($this->projectDir . '/src/Scene/company_setup.php', "<?php\n" . str_repeat("// company setup line with some text to compress\n", 700));
        for ($i = 0; $i < 25; $i++) {
            file_put_contents($this->projectDir . "/src/Unrelated$i.php", "<?php\n// unrelated file $i\n" . str_repeat("echo $i;\n", 150 + $i));
        }

        file_put_contents($this->projectDir . '/build.json', json_encode([
            'name' => 'Probe',
            'version' => '2.3.4',
            'phar' => [
                'compression' => 'gzip',
                'additionalRequires' => [
                    'bootstrap_version.php', 'bootstrap.php', 'bootstrap_constants.php',
                    'src/Globals/helpers.php', 'src/Scene/main_menu.php', 'src/Scene/company_setup.php',
                ],
            ],
            'buildTypes' => ['demo' => ['constants' => ['DEMO_MODE' => true, 'DEMO_END_YEAR' => 1982]]],
        ]));
    }

    protected function tearDown(): void
    {
        FileTree::remove($this->tempDir);
    }

    public function testEveryEntryOfADerivedGzipPharStaysReadable(): void
    {
        $full = $this->buildFullPhar();
        $demo = $this->tempDir . '/derived-demo.phar';

        $this->childPhp(sprintf(
            'require %s; $c = PHPolygon\Build\BuildConfig::load(%s);'
            . ' (new PHPolygon\Build\PharBuilder($c))->deriveBuildType(%s, %s, (new PHPolygon\Build\BuildVerifier($c))->typeConstants(\'demo\'));',
            var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true),
            var_export($this->projectDir, true),
            var_export($full, true),
            var_export($demo, true),
        ), 'derive');

        $report = $this->readAll($demo);

        $this->assertGreaterThan(30, $report['entries']);
        $this->assertSame([], $report['unreadable'], 'entries that do not inflate to their manifest size: ' . implode(', ', $report['unreadable']) . ' | last error: ' . ($report['err'] ?? 'none'));
    }

    public function testOnlyTheConstantFilesChangeAndTheRestIsByteForByteTheSource(): void
    {
        $full = $this->buildFullPhar();
        $demo = $this->tempDir . '/derived-demo.phar';
        $this->childPhp(sprintf(
            'require %s; $c = PHPolygon\Build\BuildConfig::load(%s);'
            . ' (new PHPolygon\Build\PharBuilder($c))->deriveBuildType(%s, %s, (new PHPolygon\Build\BuildVerifier($c))->typeConstants(\'demo\'));',
            var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true),
            var_export($this->projectDir, true),
            var_export($full, true),
            var_export($demo, true),
        ), 'derive');

        $before = $this->readAll($full)['hashes'];
        $after = $this->readAll($demo)['hashes'];

        $changed = array_keys(array_filter($after, static fn (string $h, string $f): bool => ($before[$f] ?? null) !== $h, ARRAY_FILTER_USE_BOTH));
        sort($changed);
        $this->assertSame(['bootstrap.php', 'bootstrap_constants.php'], $changed);
        $this->assertStringContainsString("define('DEMO_MODE', true)", $this->entry($demo, 'bootstrap.php'));
        $this->assertStringContainsString("define('DEMO_END_YEAR', 1982)", $this->entry($demo, 'bootstrap_constants.php'));
    }

    private function buildFullPhar(): string
    {
        $staging = $this->tempDir . '/staging-' . uniqid();
        $phar = $this->tempDir . '/full-' . uniqid() . '.phar';
        $this->childPhp(sprintf(
            'require %s; $c = PHPolygon\Build\BuildConfig::load(%s); $b = new PHPolygon\Build\PharBuilder($c); $b->stage(%s); $b->build(%s, %s);',
            var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true),
            var_export($this->projectDir, true),
            var_export($staging, true),
            var_export($staging, true),
            var_export($phar, true),
        ), 'build');

        return $phar;
    }

    /** Read every entry of a PHAR in a fresh process (an entry read in this one could be cached). */
    private function readAll(string $phar): array
    {
        $code = sprintf(
            '$p = new Phar(%1$s); $prefix = \'phar://\' . str_replace(chr(92), \'/\', realpath(%1$s)) . \'/\'; $r = [\'entries\' => 0, \'unreadable\' => [], \'hashes\' => []];'
            . ' foreach (new RecursiveIteratorIterator($p, RecursiveIteratorIterator::LEAVES_ONLY) as $f) {'
            . '   $rel = substr((string) $f, strlen($prefix)); $r[\'entries\']++; set_error_handler(function ($n, $s) use (&$r) { $r[\'err\'] = $s; return true; });'
            . '   $d = file_get_contents((string) $f); restore_error_handler();'
            . '   if ($d === false) { $r[\'unreadable\'][] = $rel; } else { $r[\'hashes\'][$rel] = md5($d); } }'
            . ' echo json_encode($r);',
            var_export($phar, true),
        );
        exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1', $output, $exit);
        $this->assertSame(0, $exit, "reading the PHAR failed:\n" . implode("\n", $output));
        $decoded = json_decode(implode("\n", $output), true);
        $this->assertIsArray($decoded, 'reader output: ' . implode("\n", $output));

        return $decoded;
    }

    private function entry(string $phar, string $path): string
    {
        return (string) file_get_contents('phar://' . str_replace('\\', '/', $phar) . '/' . $path);
    }

    private function childPhp(string $code, string $what): void
    {
        exec(escapeshellarg(PHP_BINARY) . ' -d phar.readonly=0 -r ' . escapeshellarg($code) . ' 2>&1', $output, $exit);
        $this->assertSame(0, $exit, "PHAR $what failed:\n" . implode("\n", $output));
    }
}
