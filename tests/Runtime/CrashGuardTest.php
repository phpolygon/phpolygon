<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use PHPolygon\Runtime\CrashGuard;
use PHPolygon\Runtime\CrashInfo;

final class CrashGuardTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/phpolygon_crashguard_' . uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        CrashGuard::reset();
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function testInstallIsIdempotent(): void
    {
        $this->assertNull(CrashGuard::current());
        $guard = CrashGuard::install();

        $this->assertSame($guard, CrashGuard::install());
        $this->assertSame($guard, CrashGuard::current());
    }

    public function testListenersHearTheFirstCrashOnly(): void
    {
        $heard = [];
        $guard = CrashGuard::install()
            ->onCrash(function (CrashInfo $c) use (&$heard): void { $heard[] = 'a:' . $c->message; })
            ->onCrash(function (CrashInfo $c) use (&$heard): void { $heard[] = 'b:' . $c->message; });

        $guard->handleThrowable(new \RuntimeException('first'));
        $guard->handleThrowable(new \RuntimeException('second'));

        $this->assertSame(['a:first', 'b:first'], $heard);
    }

    public function testAFailingListenerDoesNotStopTheNextOne(): void
    {
        $heard = false;
        $guard = CrashGuard::install()
            ->onCrash(function (): void { throw new \LogicException('listener broke'); })
            ->onCrash(function () use (&$heard): void { $heard = true; });

        $guard->handleThrowable(new \RuntimeException('boom'));

        $this->assertTrue($heard);
    }

    public function testADisarmedGuardStaysQuiet(): void
    {
        $heard = false;
        $guard = CrashGuard::install()->onCrash(function () use (&$heard): void { $heard = true; });

        $guard->disarm();
        $guard->handleThrowable(new \RuntimeException('after a deliberate exit'));

        $this->assertFalse($heard);
    }

    public function testTheReserveIsHeldUntilItIsNeeded(): void
    {
        $guard = CrashGuard::install();
        $this->assertSame(CrashGuard::RESERVE_BYTES, $guard->reservedBytes());

        $guard->handleThrowable(new \RuntimeException('boom'));
        $this->assertSame(0, $guard->reservedBytes());
    }

    public function testCrashInfoCarriesThePreviousThrowables(): void
    {
        $inner = new \InvalidArgumentException('inner cause');
        $info = CrashInfo::fromThrowable(new \RuntimeException('outer', 0, $inner));

        $this->assertSame(CrashInfo::KIND_EXCEPTION, $info->kind);
        $this->assertSame(\RuntimeException::class, $info->type);
        $this->assertSame('outer', $info->message);
        $this->assertSame(__FILE__, $info->file);
        $this->assertStringContainsString('Caused by InvalidArgumentException: inner cause', $info->trace);
    }

    public function testFatalErrorsAreNamed(): void
    {
        $info = CrashInfo::fromError(['type' => E_ERROR, 'message' => 'Allowed memory size exhausted', 'file' => '/x/a.php', 'line' => 3]);

        $this->assertSame(CrashInfo::KIND_FATAL, $info->kind);
        $this->assertSame('E_ERROR', $info->type);
        $this->assertSame('', $info->trace);
        $this->assertTrue(CrashGuard::isFatal(E_ERROR));
        $this->assertFalse(CrashGuard::isFatal(E_WARNING));
    }

    /** The real thing: a process that dies of an uncaught exception. */
    public function testAnUncaughtExceptionReachesTheListener(): void
    {
        $out = $this->runCrashingScript('throw new \RuntimeException("died in the loop");');

        $this->assertSame("exception|RuntimeException|died in the loop", $out);
        $this->assertStringContainsString('FATAL: Uncaught RuntimeException: died in the loop', $this->gameLog());
    }

    /** A fatal error only shows at shutdown, with the memory limit in the way. */
    public function testAnOutOfMemoryDeathReachesTheListener(): void
    {
        $out = $this->runCrashingScript(
            'ini_set("memory_limit", "32M"); $a = []; while (true) { $a[] = str_repeat("x", 1024 * 1024); }',
        );

        $this->assertStringStartsWith('fatal|E_ERROR|Allowed memory size', $out);
    }

    private function runCrashingScript(string $crash): string
    {
        $autoload = var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true);
        $root = var_export($this->dir, true);
        $result = var_export($this->dir . '/listener.txt', true);
        $script = $this->dir . '/crash.php';
        file_put_contents($script, <<<PHP
            <?php
            define('PHPOLYGON_PATH_ROOT', {$root});
            require {$autoload};
            \\PHPolygon\\Runtime\\CrashGuard::install()->onCrash(function (\\PHPolygon\\Runtime\\CrashInfo \$c): void {
                file_put_contents({$result}, \$c->kind . '|' . \$c->type . '|' . \$c->message);
            });
            {$crash}
            PHP);

        exec(escapeshellarg(PHP_BINARY) . ' -n -d display_errors=0 ' . escapeshellarg($script) . ' 2>&1', $output, $code);

        $this->assertNotSame(0, $code, 'the script was meant to crash');
        $this->assertFileExists($this->dir . '/listener.txt', implode("\n", $output));

        return (string) file_get_contents($this->dir . '/listener.txt');
    }

    private function gameLog(): string
    {
        return (string) @file_get_contents($this->dir . '/game.log');
    }
}
