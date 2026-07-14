<?php

declare(strict_types=1);

namespace PHPolygon\Tests\UI\Widget;

use PHPolygon\UI\Widget\UiLayoutTranspiler;
use PHPUnit\Framework\TestCase;

final class UiLayoutTranspilerTest extends TestCase
{
    public function test_layout_name_and_class_name_derivation(): void
    {
        self::assertSame('finance_overview', UiLayoutTranspiler::layoutName('/x/resources/ui/finance_overview.ui.json'));
        self::assertSame('FinanceOverviewLayout', UiLayoutTranspiler::className('finance_overview'));
        self::assertSame('MailPanelLayout', UiLayoutTranspiler::className('mail-panel'));
    }

    public function test_transpile_file_generates_a_build_factory(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/foo_bar.ui.json', json_encode([
            '_format' => 1,
            'name' => 'foo_bar',
            'root' => ['_widget' => 'PHPolygon\\UI\\Widget\\VBox'],
        ]));

        $php = (new UiLayoutTranspiler)->transpileFile($dir . '/foo_bar.ui.json', 'Acme\\Ui');

        self::assertIsString($php);
        self::assertStringContainsString('namespace Acme\\Ui;', $php);
        self::assertStringContainsString('final class FooBarLayout', $php);
        self::assertStringContainsString('public static function build(): Widget', $php);
    }

    public function test_transpile_file_returns_null_for_non_layout(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/bad.ui.json', '{"not":"a widget"}');

        self::assertNull((new UiLayoutTranspiler)->transpileFile($dir . '/bad.ui.json', 'X'));
        self::assertNull((new UiLayoutTranspiler)->transpileFile($dir . '/missing.ui.json', 'X'));
    }

    public function test_transpile_dir_writes_one_class_per_layout(): void
    {
        $ui = $this->tempDir();
        $out = $this->tempDir();
        foreach (['alpha', 'beta_two'] as $name) {
            file_put_contents("{$ui}/{$name}.ui.json", json_encode([
                'root' => ['_widget' => 'PHPolygon\\UI\\Widget\\Label'],
            ]));
        }

        $written = (new UiLayoutTranspiler)->transpileDir($ui, $out, 'Acme\\Ui');

        sort($written);
        self::assertSame(['alpha', 'beta_two'], $written);
        self::assertFileExists("{$out}/AlphaLayout.php");
        self::assertFileExists("{$out}/BetaTwoLayout.php");
        self::assertStringContainsString('final class BetaTwoLayout', (string) file_get_contents("{$out}/BetaTwoLayout.php"));
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/phpolygon-uit-' . bin2hex(random_bytes(6));
        mkdir($dir, 0775, true);

        return $dir;
    }
}
