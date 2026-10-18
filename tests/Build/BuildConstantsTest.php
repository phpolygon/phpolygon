<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Build;

use PHPolygon\Build\BuildConfig;
use PHPolygon\Build\BuildConstants;
use PHPUnit\Framework\TestCase;

class BuildConstantsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/phpolygon-constants-test-' . getmypid() . '-' . bin2hex(random_bytes(3));
        mkdir($this->dir, 0755, true);
        file_put_contents($this->dir . '/composer.json', json_encode(['name' => 'test/game']));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    public function testFilesFollowRequireOrderAndSkipMissingOnes(): void
    {
        file_put_contents($this->dir . '/build.json', json_encode(['phar' => ['additionalRequires' => ['version.php', 'missing.php', 'constants.php']]]));
        file_put_contents($this->dir . '/bootstrap.php', '<?php');
        file_put_contents($this->dir . '/version.php', '<?php');
        file_put_contents($this->dir . '/constants.php', '<?php');

        $this->assertSame(
            ['bootstrap.php', 'version.php', 'constants.php'],
            BuildConstants::files(BuildConfig::load($this->dir), $this->dir),
        );
    }

    public function testApplyRewritesTheConstantInAnyAdditionalRequire(): void
    {
        // The layout that once shipped a demo as the full game: no bootstrap.php,
        // the flag lives in an additionalRequires file on one line with others.
        $line = "<?php if (!defined('VERSION')) define('VERSION', '1.2.0'); if (!defined('DEMO')) define('DEMO', false); if (!defined('TRIAL')) define('TRIAL', false);";
        file_put_contents($this->dir . '/version.php', $line);
        file_put_contents($this->dir . '/constants.php', "<?php\nif (!defined('DEMO')) define('DEMO', false);\n");

        $applied = BuildConstants::apply($this->dir, ['DEMO' => true], ['version.php', 'constants.php']);

        $this->assertSame(['DEMO' => ['version.php', 'constants.php']], $applied);
        $version = (string) file_get_contents($this->dir . '/version.php');
        $this->assertStringContainsString("define('DEMO', true);", $version);
        $this->assertStringContainsString("define('VERSION', '1.2.0');", $version, 'other constants untouched');
        $this->assertStringContainsString("define('TRIAL', false);", $version);
    }

    public function testApplyRefusesAConstantNoFileDefines(): void
    {
        file_put_contents($this->dir . '/version.php', "<?php define('VERSION', '1.0.0');");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DEMO');
        BuildConstants::apply($this->dir, ['DEMO' => true], ['version.php']);
    }

    public function testResolveTakesTheFirstDefineInRequireOrder(): void
    {
        $sources = [
            "<?php if (!defined('DEMO')) define('DEMO', true); define('LEVELS', [1, 2]);",
            "<?php if (!defined('DEMO')) define('DEMO', false);\n// define('TRIAL', true);\ndefine('TRIAL', 3 * 2);",
        ];

        [$values, $unresolved] = BuildConstants::resolve($sources, ['DEMO', 'LEVELS', 'TRIAL', 'NOWHERE']);

        $this->assertSame(['DEMO' => true, 'LEVELS' => [1, 2], 'TRIAL' => 6], $values, 'a commented-out define never counts');
        $this->assertSame(['NOWHERE'], $unresolved);
        $this->assertFalse(defined('DEMO'), 'resolving defines nothing');
    }

    public function testAValueThatNeedsTheGameIsUnresolved(): void
    {
        [$values, $unresolved] = BuildConstants::resolve(["<?php define('VERSION', \$build['version'] ?? '0.0.0');"], ['VERSION']);

        $this->assertSame([], $values);
        $this->assertSame(['VERSION'], $unresolved);
    }
}
