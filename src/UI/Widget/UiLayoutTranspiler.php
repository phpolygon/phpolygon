<?php

declare(strict_types=1);

namespace PHPolygon\UI\Widget;

/**
 * Batch-transpiles a directory of editor-authored `*.ui.json` layouts into
 * zero-parse PHP builder classes via {@see WidgetCodeGenerator}.
 *
 * This is the reusable core behind both the dev-time transpile tool and the
 * build pipeline's UI-transpile step: the consuming game keeps JSON as the
 * canonical editor artifact, and the runtime loads compiled PHP instead of
 * deserializing JSON. Naming matches a `<layout>` -> `<Studly>Layout` class in
 * the configured namespace, e.g. `finance_overview.ui.json` ->
 * `FinanceOverviewLayout`.
 */
final class UiLayoutTranspiler
{
    /**
     * Transpile every `<uiDir>/*.ui.json` into `<outDir>/<Studly>Layout.php` in
     * $namespace, creating $outDir if needed. Returns the layout names written
     * (invalid/non-widget files are skipped).
     *
     * @return list<string>
     */
    public function transpileDir(string $uiDir, string $outDir, string $namespace): array
    {
        $files = glob(rtrim($uiDir, '/\\') . '/*.ui.json') ?: [];
        if ($files !== [] && ! is_dir($outDir)) {
            mkdir($outDir, 0775, true);
        }

        $written = [];
        foreach ($files as $path) {
            $php = $this->transpileFile($path, $namespace);
            if ($php === null) {
                continue;
            }
            $name = self::layoutName($path);
            file_put_contents(rtrim($outDir, '/\\') . '/' . self::className($name) . '.php', $php);
            $written[] = $name;
        }

        return $written;
    }

    /**
     * Generate the PHP builder source for one `*.ui.json`, or null when the file
     * is unreadable / not a widget layout.
     */
    public function transpileFile(string $path, string $namespace): ?string
    {
        $raw = @file_get_contents($path);
        $data = $raw !== false ? json_decode($raw, true) : null;
        if (! is_array($data)) {
            return null;
        }

        $node = isset($data['root']) && is_array($data['root']) ? $data['root'] : $data;
        if (! isset($node['_widget'])) {
            return null;
        }

        /** @var array<string, mixed> $node */
        return (new WidgetCodeGenerator)->generate($node, self::className(self::layoutName($path)), $namespace);
    }

    /** `.../finance_overview.ui.json` -> `finance_overview`. */
    public static function layoutName(string $path): string
    {
        return (string) preg_replace('/\.ui\.json$/', '', basename($path));
    }

    /** `finance_overview` -> `FinanceOverviewLayout`. */
    public static function className(string $layoutName): string
    {
        return str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $layoutName))) . 'Layout';
    }
}
