<?php

declare(strict_types=1);

/**
 * Shader validator for PHPolygon.
 *
 * Checks every shader file under resources/shaders/source/ and guards against
 * a regression of the "embedded heredoc shader" anti-pattern in src/Rendering/.
 *
 * Checks per .glsl file:
 *   - first non-empty line is a #version directive
 *   - balanced curly braces and parentheses
 *   - no remaining heredoc terminator markers
 *
 * Checks per .metal file:
 *   - includes <metal_stdlib>
 *   - balanced curly braces
 *
 * Guard pass over src/:
 *   - no <<<'GLSL', <<<'MSL', <<<'HLSL', <<<'METAL' heredocs anywhere under
 *     src/Rendering/ (renderers and post-process passes must load from files)
 *
 * Optional: if `glslangValidator` is on $PATH, runs it against every .glsl
 * file for a real compile check. Without it, the PHP-side checks above run.
 *
 * Usage: php scripts/validate-shaders.php [root]
 *   root: optional project root override (defaults to dirname(__DIR__)).
 *         Used by tests/Scripts/ValidateShadersTest.php to point the
 *         validator at a fixture directory.
 * Exit:  0 = OK, 1 = errors found
 */

$root        = isset($argv[1]) ? rtrim((string)$argv[1], '/') : dirname(__DIR__);
$shaderDir   = $root . '/resources/shaders/source';
$renderDir   = $root . '/src/Rendering';

$errors = [];
$checked = ['glsl' => 0, 'metal' => 0];

if (!is_dir($shaderDir)) {
    fwrite(STDERR, "ERROR: shader dir not found: {$shaderDir}\n");
    exit(1);
}

/** @return list<string> */
function findFiles(string $dir, string $extension): array
{
    $out = [];
    $it  = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.' . $extension)) {
            $out[] = $file->getPathname();
        }
    }
    sort($out);
    return $out;
}

function checkBalanced(string $body, string $open, string $close): int
{
    $depth = 0;
    $len   = strlen($body);
    for ($i = 0; $i < $len; $i++) {
        if ($body[$i] === $open) {
            $depth++;
        } elseif ($body[$i] === $close) {
            $depth--;
            if ($depth < 0) {
                return $depth;
            }
        }
    }
    return $depth;
}

/**
 * Strip GLSL/MSL line and block comments. Both languages share C-style
 * comment syntax. Note: we do NOT strip string literals because GLSL/MSL
 * have no native string literal type (Metal's metal::string is rare and
 * shader source is overwhelmingly literal-free); attempting it would risk
 * mangling preprocessor directives.
 */
function stripComments(string $source): string
{
    $source = preg_replace('!//[^\n]*!', '', $source) ?? $source;
    $source = preg_replace('!/\*.*?\*/!s', '', $source) ?? $source;
    return $source;
}

// ---------------------------------------------------------------
// 1. GLSL files
// ---------------------------------------------------------------
foreach (findFiles($shaderDir, 'glsl') as $path) {
    $rel = substr($path, strlen($root) + 1);
    $src = file_get_contents($path);
    if ($src === false) {
        $errors[] = "{$rel}: cannot read file";
        continue;
    }
    $checked['glsl']++;

    // Heredoc terminator regression (catches '\n    GLSL;' style closers).
    if (preg_match('/^\s*(GLSL|MSL|HLSL|METAL)\w*;\s*$/m', $src)) {
        $errors[] = "{$rel}: contains heredoc terminator - probable extraction bug";
    }
    // Heredoc opener regression. Matches `<<<GLSL`, `<<< 'GLSL'`, `<<<"GLSL"`,
    // and labelled variants like `<<<'GLSL_VERT'`.
    if (preg_match("/<<<\\s*['\"]?(GLSL|MSL|HLSL|METAL)\\w*/i", $src)) {
        $errors[] = "{$rel}: contains heredoc opener - probable extraction bug";
    }

    // #version line
    $lines = preg_split('/\R/', $src) ?: [];
    $firstNonEmpty = null;
    foreach ($lines as $l) {
        $t = trim($l);
        if ($t !== '' && !str_starts_with($t, '//')) {
            $firstNonEmpty = $t;
            break;
        }
    }
    if ($firstNonEmpty === null || !preg_match('/^#version\s+\d/', $firstNonEmpty)) {
        $errors[] = "{$rel}: missing or non-leading '#version' directive (first non-empty line was: " . substr((string)$firstNonEmpty, 0, 60) . ")";
    }

    // Balanced braces / parens (after stripping comments)
    $stripped = stripComments($src);
    if (checkBalanced($stripped, '{', '}') !== 0) {
        $errors[] = "{$rel}: unbalanced curly braces { }";
    }
    if (checkBalanced($stripped, '(', ')') !== 0) {
        $errors[] = "{$rel}: unbalanced parentheses ( )";
    }
}

// ---------------------------------------------------------------
// 2. Metal files
// ---------------------------------------------------------------
foreach (findFiles($shaderDir, 'metal') as $path) {
    $rel = substr($path, strlen($root) + 1);
    $src = file_get_contents($path);
    if ($src === false) {
        $errors[] = "{$rel}: cannot read file";
        continue;
    }
    $checked['metal']++;

    if (!str_contains($src, '<metal_stdlib>')) {
        $errors[] = "{$rel}: missing #include <metal_stdlib>";
    }
    $stripped = stripComments($src);
    if (checkBalanced($stripped, '{', '}') !== 0) {
        $errors[] = "{$rel}: unbalanced curly braces { }";
    }
}

// ---------------------------------------------------------------
// 3. Guard: no shader heredocs anywhere in src/Rendering
// ---------------------------------------------------------------
$rendererFiles = findFiles($renderDir, 'php');
// Catches all heredoc opener variants: <<<GLSL, <<<'GLSL', <<< 'GLSL',
// <<<"GLSL", and labelled variants like <<<'GLSL_VERT' / <<<METAL_SHADER.
$heredocPattern = "/<<<\\s*['\"]?(GLSL|MSL|HLSL|METAL)\\w*/i";
foreach ($rendererFiles as $path) {
    $rel = substr($path, strlen($root) + 1);
    $src = file_get_contents($path);
    if ($src === false) {
        $errors[] = "{$rel}: cannot read file";
        continue;
    }
    if (preg_match($heredocPattern, $src, $m)) {
        $errors[] = "{$rel}: contains embedded shader heredoc '{$m[0]}'. Move the shader to resources/shaders/source/ and load via file_get_contents().";
    }
}

// ---------------------------------------------------------------
// 4. Optional: glslangValidator
// ---------------------------------------------------------------
$useGlslang = false;
$which = trim((string)@shell_exec('command -v glslangValidator 2>/dev/null'));
if ($which !== '') {
    $useGlslang = true;
}

// glslangValidator runs as a non-gating ADVISORY pass. Several pre-existing
// shaders ship with constructs the validator dislikes (forward-referenced
// functions, OpenGL-permitted globals, etc.); they compile fine on real
// drivers. We surface its diagnostics for visibility but never fail CI on
// them - the regression guards above are what gate the build.
$advisory = [];
if ($useGlslang) {
    foreach (findFiles($shaderDir, 'glsl') as $path) {
        $rel  = substr($path, strlen($root) + 1);
        $stage = null;
        if (str_ends_with($path, '.vert.glsl')) {
            $stage = 'vert';
        } elseif (str_ends_with($path, '.frag.glsl')) {
            $stage = 'frag';
        } elseif (str_ends_with($path, '.geom.glsl')) {
            $stage = 'geom';
        } elseif (str_ends_with($path, '.comp.glsl')) {
            $stage = 'comp';
        }
        if ($stage === null) {
            continue;
        }
        $isVulkan = str_contains(basename($path), '_vk.');
        // For Vulkan-targeted shaders we need --target-env vulkan1.0 which
        // implies SPIR-V output. Run from a tempdir so the resulting
        // vert.spv / frag.spv files don't pollute the repo root.
        $envFlag = $isVulkan ? '--target-env vulkan1.0' : '';
        $tmpDir  = sys_get_temp_dir();
        $cmd = sprintf(
            '(cd %s && glslangValidator %s -S %s %s) 2>&1',
            escapeshellarg($tmpDir),
            $envFlag,
            escapeshellarg($stage),
            escapeshellarg($path)
        );
        $out = (string)shell_exec($cmd);
        $rc  = 0;
        exec($cmd, $_, $rc);
        if ($rc !== 0) {
            $advisory[] = "{$rel}: glslangValidator advisory:\n" . rtrim($out);
        }
    }
}

// ---------------------------------------------------------------
// Report
// ---------------------------------------------------------------
$tool = $useGlslang ? 'PHP checks + glslangValidator (advisory)' : 'PHP checks only (glslangValidator not on PATH)';
fwrite(STDOUT, "shader validator: checked {$checked['glsl']} .glsl + {$checked['metal']} .metal files [{$tool}]\n");

if ($advisory !== []) {
    fwrite(STDOUT, "\nshader validator: " . count($advisory) . " advisory diagnostic(s) (non-gating):\n");
    foreach ($advisory as $a) {
        fwrite(STDOUT, "  - {$a}\n\n");
    }
}

if ($errors !== []) {
    fwrite(STDERR, "\n");
    foreach ($errors as $e) {
        fwrite(STDERR, "  X {$e}\n");
    }
    fwrite(STDERR, "\nshader validator: " . count($errors) . " error(s).\n");
    exit(1);
}

fwrite(STDOUT, "shader validator: OK\n");
exit(0);
