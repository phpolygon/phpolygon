<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering\Text;

use PHPUnit\Framework\TestCase;

/**
 * Without php-vio a plate stays plain: building the baker must not touch
 * vio_text_bitmap(). The check runs in a PHP without any extension (-n), because
 * a loaded extension cannot be taken away from this process.
 */
final class SurfaceTextWithoutVioTest extends TestCase
{
    public function testBakerWorksAndFallsBackToAPlainPlate(): void
    {
        $src = addslashes(dirname(__DIR__, 3) . '/src/');
        $script = <<<PHP
            <?php
            declare(strict_types=1);
            spl_autoload_register(static function (string \$class): void {
                if (str_starts_with(\$class, 'PHPolygon\\\\')) {
                    require '{$src}' . strtr(substr(\$class, 10), '\\\\', '/') . '.php';
                }
            });
            echo function_exists('vio_text_bitmap') ? 'vio loaded' : 'no vio', "\\n";
            \$locale = new PHPolygon\\Locale\\LocaleManager('en', 'en');
            \$baker = new PHPolygon\\Rendering\\Text\\SurfaceTextBaker(\$locale, new PHPolygon\\Testing\\NullTextureManager());
            \$surface = new PHPolygon\\Component\\SurfaceText(text: 'Sign');
            \$material = \$baker->materialFor(\$surface, 'en');
            echo \$material === PHPolygon\\Rendering\\Text\\SurfaceTextBaker::plainMaterial(\$surface) ? 'plain' : 'other', "\\n";
            PHP;

        $file = (string) tempnam(sys_get_temp_dir(), 'surface_text');
        file_put_contents($file, $script);
        try {
            $output = [];
            exec(escapeshellarg(PHP_BINARY) . ' -n ' . escapeshellarg($file) . ' 2>&1', $output, $code);
        } finally {
            unlink($file);
        }

        self::assertSame(['no vio', 'plain'], $output, implode("\n", $output));
        self::assertSame(0, $code);
    }
}
