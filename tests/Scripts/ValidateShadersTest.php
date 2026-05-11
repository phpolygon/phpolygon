<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Scripts;

use PHPUnit\Framework\TestCase;

/**
 * Locks the regression-guard semantics of scripts/validate-shaders.php.
 *
 * Each test spins up a self-contained fixture root with a minimal pair of
 * `resources/shaders/source/` and `src/Rendering/` trees, invokes the
 * validator with that root, and asserts on the exit code + stderr output.
 *
 * If the validator's checks change, these tests should change with them.
 */
final class ValidateShadersTest extends TestCase
{
    private const SCRIPT = __DIR__ . '/../../scripts/validate-shaders.php';

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/phpolygon-validate-shaders-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/resources/shaders/source', 0o777, true);
        mkdir($this->root . '/src/Rendering', 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->root);
    }

    public function testValidShadersPass(): void
    {
        $this->writeGoodGlsl('mesh.vert.glsl', "vec3 a;\nvoid main(){\n  a = vec3(1.0);\n}\n");
        [$rc, $stdout, $stderr] = $this->runValidator();
        self::assertSame(0, $rc, "Expected exit 0, got {$rc}. stderr:\n{$stderr}");
        self::assertStringContainsString('shader validator: OK', $stdout);
    }

    public function testHeredocOpenerInRendererFails(): void
    {
        $this->writeGoodGlsl('mesh.vert.glsl', "void main(){}\n");
        file_put_contents(
            $this->root . '/src/Rendering/Evil.php',
            "<?php\n\$x = <<<'GLSL'\nvoid main(){}\nGLSL;\n",
        );
        [$rc, , $stderr] = $this->runValidator();
        self::assertSame(1, $rc);
        self::assertStringContainsString('embedded shader heredoc', $stderr);
    }

    public function testLabelledHeredocVariantCaught(): void
    {
        $this->writeGoodGlsl('mesh.vert.glsl', "void main(){}\n");
        file_put_contents(
            $this->root . '/src/Rendering/Evil.php',
            "<?php\n\$x = <<<'GLSL_VERT'\nvoid main(){}\nGLSL_VERT;\n",
        );
        [$rc, , $stderr] = $this->runValidator();
        self::assertSame(1, $rc, "Labelled heredoc variant must trip the guard");
        self::assertStringContainsString('GLSL_VERT', $stderr);
    }

    public function testSpacedHeredocVariantCaught(): void
    {
        $this->writeGoodGlsl('mesh.vert.glsl', "void main(){}\n");
        file_put_contents(
            $this->root . '/src/Rendering/Evil.php',
            "<?php\n\$x = <<< 'GLSL'\nvoid main(){}\nGLSL;\n",
        );
        [$rc, , $stderr] = $this->runValidator();
        self::assertSame(1, $rc, "<<< 'GLSL' (with space) must trip the guard");
    }

    public function testNonShaderHeredocDoesNotFalseTrigger(): void
    {
        $this->writeGoodGlsl('mesh.vert.glsl', "void main(){}\n");
        file_put_contents(
            $this->root . '/src/Rendering/Benign.php',
            "<?php\n\$sql = <<<'SQL'\nSELECT * FROM t\nSQL;\n",
        );
        [$rc, , $stderr] = $this->runValidator();
        self::assertSame(0, $rc, "SQL heredoc must not be flagged. stderr:\n{$stderr}");
    }

    public function testMissingVersionDirectiveFails(): void
    {
        file_put_contents(
            $this->root . '/resources/shaders/source/bad.vert.glsl',
            "void main(){}\n",
        );
        [$rc, , $stderr] = $this->runValidator();
        self::assertSame(1, $rc);
        self::assertStringContainsString("'#version'", $stderr);
    }

    public function testUnbalancedBracesFail(): void
    {
        $this->writeGoodGlsl('bad.vert.glsl', "void main(){\n  // missing closer\n");
        [$rc, , $stderr] = $this->runValidator();
        self::assertSame(1, $rc);
        self::assertStringContainsString('unbalanced curly braces', $stderr);
    }

    public function testHeredocTerminatorInGlslFails(): void
    {
        // Reproduces an extraction bug where the heredoc body was written
        // verbatim including its terminator line.
        $body = "#version 410 core\nvoid main(){}\n    GLSL;\n";
        file_put_contents($this->root . '/resources/shaders/source/bad.vert.glsl', $body);
        [$rc, , $stderr] = $this->runValidator();
        self::assertSame(1, $rc);
        self::assertStringContainsString('heredoc terminator', $stderr);
    }

    public function testMetalMissingStdlibFails(): void
    {
        $this->writeGoodGlsl('mesh.vert.glsl', "void main(){}\n");
        file_put_contents(
            $this->root . '/resources/shaders/source/bad.metal',
            "kernel void f(){}\n",
        );
        [$rc, , $stderr] = $this->runValidator();
        self::assertSame(1, $rc);
        self::assertStringContainsString('metal_stdlib', $stderr);
    }

    private function writeGoodGlsl(string $name, string $body): void
    {
        file_put_contents(
            $this->root . '/resources/shaders/source/' . $name,
            "#version 410 core\n" . $body,
        );
    }

    /**
     * @return array{0:int,1:string,2:string} [exitCode, stdout, stderr]
     */
    private function runValidator(): array
    {
        $cmd = sprintf(
            '%s %s %s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(self::SCRIPT),
            escapeshellarg($this->root),
        );
        $process = proc_open(
            $cmd,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!is_resource($process)) {
            self::fail('proc_open failed');
        }
        $stdout = (string)stream_get_contents($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $rc = proc_close($process);

        return [$rc, $stdout, $stderr];
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->rrmdir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
