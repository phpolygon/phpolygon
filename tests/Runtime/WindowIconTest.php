<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Runtime;

use PHPolygon\Icon\Png;
use PHPolygon\Icon\WindowIconSet;
use PHPolygon\Runtime\NullWindow;
use PHPolygon\Runtime\Window;
use PHPolygon\Runtime\WindowIcon;
use PHPolygon\Tests\Icon\TestIcons;
use PHPUnit\Framework\TestCase;

class WindowIconTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/phpolygon-window-icon-' . getmypid();
        @mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
    }

    public function testDefaultUsesTheSetABuildWrote(): void
    {
        $set = WindowIconSet::fromImage(TestIcons::pattern(64));
        file_put_contents($this->dir . '/' . WindowIconSet::FILE, $set->encode());

        $resolved = WindowIcon::resolve(null, $this->dir);
        $this->assertNotNull($resolved);
        $this->assertSame(array_map(static fn ($i) => $i->pixels, $set->images), array_map(static fn ($i) => $i->pixels, $resolved->images));
    }

    public function testDefaultWithoutABuiltSetOrInsideAMacBundleIsNone(): void
    {
        $this->assertNull(WindowIcon::resolve(null, null), 'unbuilt game');
        $this->assertNull(WindowIcon::resolve(null, $this->dir), 'built without icon');

        file_put_contents($this->dir . '/' . WindowIconSet::FILE, WindowIconSet::fromImage(TestIcons::pattern(32))->encode());
        $this->assertNull(WindowIcon::resolve(null, $this->dir, inMacBundle: true), 'the bundle icon is the Dock icon');
    }

    public function testEmptyStringSwitchesItOff(): void
    {
        file_put_contents($this->dir . '/' . WindowIconSet::FILE, WindowIconSet::fromImage(TestIcons::pattern(32))->encode());
        $this->assertNull(WindowIcon::resolve('', $this->dir));
    }

    public function testPngPathIsShrunkAtStartUp(): void
    {
        file_put_contents($this->dir . '/dev.png', Png::encode(TestIcons::pattern(128)));
        $set = WindowIcon::resolve($this->dir . '/dev.png', null);
        $this->assertNotNull($set);
        $this->assertSame([16, 24, 32, 48, 64, 128], array_map(static fn ($i) => $i->width, $set->images));
    }

    public function testMissingFileThrowsButApplyOnlyLogs(): void
    {
        try {
            WindowIcon::resolve($this->dir . '/missing.png', null);
            $this->fail('missing icon accepted');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not found', $e->getMessage());
        }
        $this->assertFalse(WindowIcon::apply(new NullWindow(10, 10, 't'), $this->dir . '/missing.png'));
    }

    public function testWindowsWithoutIconSupportReturnFalse(): void
    {
        file_put_contents($this->dir . '/dev.png', Png::encode(TestIcons::pattern(32)));
        $this->assertFalse(WindowIcon::apply(new NullWindow(10, 10, 't'), $this->dir . '/dev.png'));

        $window = new class (10, 10, 't') extends Window {
            public ?WindowIconSet $received = null;

            public function setIcon(WindowIconSet $icon): bool
            {
                $this->received = $icon;
                return true;
            }
        };
        $this->assertTrue(WindowIcon::apply($window, $this->dir . '/dev.png'));
        $this->assertNotNull($window->received);
    }

    /**
     * php-vio before 2.41 has no vio_set_window_icon: the window keeps its
     * default icon and nothing warns. Run in a PHP without any extension (-n)
     * so the function is missing for sure.
     */
    public function testVioMainWindowWithoutVioSetWindowIconKeepsTheDefault(): void
    {
        $autoload = var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true);
        $script = $this->dir . '/no-vio.php';
        file_put_contents($script, <<<PHP
<?php
require {$autoload};
error_reporting(E_ALL);
set_error_handler(static function (int \$no, string \$msg): bool { echo "warning: \$msg\\n"; return true; });
\$w = new PHPolygon\\Runtime\\VioMainWindow(10, 10, 't');
(new ReflectionProperty(\$w, 'initialized'))->setValue(\$w, true);
\$set = PHPolygon\\Icon\\WindowIconSet::fromImage(new PHPolygon\\Icon\\RgbaImage(16, 16, str_repeat("\\x10\\x20\\x30\\xff", 256)));
echo function_exists('vio_set_window_icon') ? 'has vio' : 'no vio', ' ', var_export(\$w->setIcon(\$set), true), "\\n";
(new ReflectionProperty(\$w, 'initialized'))->setValue(\$w, false); // no context to destroy
PHP);
        exec(escapeshellarg(PHP_BINARY) . ' -n ' . escapeshellarg($script) . ' 2>&1', $output, $code);
        $this->assertSame(0, $code, implode("\n", $output));
        $this->assertSame(['no vio false'], $output);
    }
}
