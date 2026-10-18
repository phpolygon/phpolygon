<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Build;

use PHPolygon\Build\FileTree;
use PHPolygon\Build\PythonRuntimeTrimmer;
use PHPUnit\Framework\TestCase;

class PythonRuntimeTrimmerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/phpolygon-pytrim-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        FileTree::remove($this->dir);
    }

    public function testWindowsLayoutDefaultKeepsTheRuntimeAndDropsTooling(): void
    {
        $root = $this->windowsInstall();

        $result = PythonRuntimeTrimmer::trim($root);

        foreach ([
            'python.exe', 'python314.dll', 'Lib/os.py', 'Lib/threading.py', 'Lib/json/__init__.py',
            'Lib/__pycache__/os.cpython-314.pyc', 'Lib/unittest/__init__.py', 'Lib/sqlite3/__init__.py',
            'DLLs/_ssl.pyd', 'DLLs/libcrypto-3-x64.dll', 'DLLs/_sqlite3.pyd', 'Lib/pydoc_data/topics.py',
        ] as $kept) {
            $this->assertFileExists($root . '/' . $kept, "{$kept} must survive the default trim");
        }
        foreach ([
            'Lib/site-packages/pip', 'Lib/ensurepip', 'Lib/venv', 'Lib/tkinter', 'Lib/idlelib', 'tcl',
            'include', 'libs', 'Scripts',
        ] as $gone) {
            $this->assertDirectoryDoesNotExist($root . '/' . $gone);
        }
        foreach (['DLLs/_tkinter.pyd', 'DLLs/tcl90.dll', 'DLLs/_testcapi.pyd', 'pythonw.exe', 'Lib/turtle.py', 'Lib/__pycache__/os.cpython-314.opt-1.pyc'] as $gone) {
            $this->assertFileDoesNotExist($root . '/' . $gone);
        }
        $this->assertContains('Lib/site-packages/pip', $result['removed']);
        $this->assertGreaterThan(0, $result['bytes']);
    }

    public function testOptInGroupsRemoveNetworkSqliteAndDocs(): void
    {
        $root = $this->windowsInstall();

        PythonRuntimeTrimmer::trim($root, ['default', 'network', 'sqlite', 'docs']);

        foreach (['DLLs/_ssl.pyd', 'DLLs/_hashlib.pyd', 'DLLs/libcrypto-3-x64.dll', 'DLLs/libssl-3-x64.dll', 'DLLs/_sqlite3.pyd', 'DLLs/sqlite3.dll'] as $gone) {
            $this->assertFileDoesNotExist($root . '/' . $gone);
        }
        $this->assertDirectoryDoesNotExist($root . '/Lib/sqlite3');
        $this->assertDirectoryDoesNotExist($root . '/Lib/pydoc_data');
        $this->assertFileExists($root . '/Lib/threading.py');
    }

    public function testUnixLayoutUsesTheVersionedStdlib(): void
    {
        $root = $this->dir . '/unix';
        $this->files($root, [
            'bin/python3.14', 'bin/pip3', 'bin/idle3.14', 'bin/python3.14-config',
            'lib/libpython3.14.so.1.0', 'lib/libtcl9.0.so', 'lib/tcl9.0/init.tcl', 'lib/thread3.0.6/x.tcl',
            'lib/pkgconfig/python3.pc',
            'lib/python3.14/os.py', 'lib/python3.14/threading.py', 'lib/python3.14/tkinter/__init__.py',
            'lib/python3.14/site-packages/pip/__init__.py', 'lib/python3.14/config-3.14-x86_64-linux-gnu/Makefile',
            'lib/python3.14/lib-dynload/_tkinter.cpython-314-x86_64-linux-gnu.so',
            'lib/python3.14/lib-dynload/_json.cpython-314-x86_64-linux-gnu.so',
            'include/python3.14/Python.h', 'share/man/man1/python3.1',
            // ncurses' terminal database: names that differ only in case.
            'share/terminfo/E/Eterm', 'share/terminfo/e/eterm',
        ]);

        $this->assertSame(['lib/python3.14', 'lib/python3.14/lib-dynload'], PythonRuntimeTrimmer::layout($root));
        PythonRuntimeTrimmer::trim($root);

        foreach (['bin/python3.14', 'lib/libpython3.14.so.1.0', 'lib/python3.14/os.py', 'lib/python3.14/threading.py', 'lib/python3.14/lib-dynload/_json.cpython-314-x86_64-linux-gnu.so'] as $kept) {
            $this->assertFileExists($root . '/' . $kept);
        }
        foreach (['bin/pip3', 'bin/idle3.14', 'bin/python3.14-config', 'lib/libtcl9.0.so', 'lib/python3.14/lib-dynload/_tkinter.cpython-314-x86_64-linux-gnu.so'] as $gone) {
            $this->assertFileDoesNotExist($root . '/' . $gone);
        }
        foreach (['lib/tcl9.0', 'lib/thread3.0.6', 'lib/pkgconfig', 'lib/python3.14/tkinter', 'lib/python3.14/site-packages/pip', 'lib/python3.14/config-3.14-x86_64-linux-gnu', 'include', 'share/man', 'share/terminfo'] as $gone) {
            $this->assertDirectoryDoesNotExist($root . '/' . $gone);
        }
    }

    public function testKeepWinsAndReachesIntoRemovedDirectories(): void
    {
        $root = $this->windowsInstall();

        PythonRuntimeTrimmer::trim($root, ['default'], remove: ['{lib}/json'], keep: ['{lib}/idlelib/keep.py', 'DLLs/_testcapi.pyd']);

        $this->assertFileExists($root . '/Lib/idlelib/keep.py');
        $this->assertFileDoesNotExist($root . '/Lib/idlelib/idle.py');
        $this->assertFileExists($root . '/DLLs/_testcapi.pyd');
        $this->assertDirectoryDoesNotExist($root . '/Lib/json');
    }

    public function testDryRunReportsWithoutDeleting(): void
    {
        $root = $this->windowsInstall();

        $result = PythonRuntimeTrimmer::trim($root, dryRun: true);

        $this->assertContains('Lib/ensurepip', $result['removed']);
        $this->assertDirectoryExists($root . '/Lib/ensurepip');
    }

    public function testUnknownGroupAndMissingStdlibAreRejected(): void
    {
        $root = $this->windowsInstall();
        try {
            PythonRuntimeTrimmer::trim($root, ['gui', 'nope']);
            $this->fail('unknown group accepted');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('nope', $e->getMessage());
        }

        mkdir($this->dir . '/empty');
        $this->expectException(\InvalidArgumentException::class);
        PythonRuntimeTrimmer::trim($this->dir . '/empty');
    }

    private function windowsInstall(): string
    {
        $root = $this->dir . '/win';
        $this->files($root, [
            'python.exe', 'pythonw.exe', 'python314.dll',
            'Lib/os.py', 'Lib/threading.py', 'Lib/turtle.py', 'Lib/json/__init__.py',
            'Lib/__pycache__/os.cpython-314.pyc', 'Lib/__pycache__/os.cpython-314.opt-1.pyc',
            'Lib/unittest/__init__.py', 'Lib/sqlite3/__init__.py', 'Lib/pydoc_data/topics.py',
            'Lib/ensurepip/__init__.py', 'Lib/venv/__init__.py', 'Lib/tkinter/__init__.py',
            'Lib/idlelib/idle.py', 'Lib/idlelib/keep.py', 'Lib/idlelib/idle_test/t.py',
            'Lib/site-packages/pip/__init__.py', 'Lib/site-packages/README.txt',
            'DLLs/_ssl.pyd', 'DLLs/_hashlib.pyd', 'DLLs/libcrypto-3-x64.dll', 'DLLs/libssl-3-x64.dll',
            'DLLs/_sqlite3.pyd', 'DLLs/sqlite3.dll', 'DLLs/_tkinter.pyd', 'DLLs/tcl90.dll', 'DLLs/_testcapi.pyd',
            'DLLs/_json.pyd', 'tcl/tcl9.0/init.tcl', 'include/Python.h', 'libs/python314.lib', 'Scripts/pip.exe',
        ]);
        return $root;
    }

    /** @param list<string> $files */
    private function files(string $root, array $files): void
    {
        foreach ($files as $file) {
            $path = $root . '/' . $file;
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0755, true);
            }
            file_put_contents($path, str_repeat('x', 32));
        }
    }
}
