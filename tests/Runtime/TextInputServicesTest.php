<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Runtime;

use PHPolygon\Runtime\Input;
use PHPolygon\Runtime\KeyModifiers;
use PHPolygon\Runtime\MemoryClipboard;
use PHPolygon\Runtime\UsKeyboardLayout;
use PHPolygon\Runtime\VioClipboard;
use PHPolygon\Runtime\VioKeyboardLayout;
use PHPUnit\Framework\TestCase;

/**
 * What text editing needs from the input layer besides keys and characters:
 * modifier state, the clipboard and the keyboard layout.
 */
final class TextInputServicesTest extends TestCase
{
    // ── modifiers ───────────────────────────────────────────────────

    public function testModifiersAreReadFromBothKeysOfEachPair(): void
    {
        $input = new Input();
        self::assertFalse(KeyModifiers::fromInput($input)->any());

        $input->handleKeyEvent(344, 1); // right shift
        $input->handleKeyEvent(345, 1); // right control
        $mods = KeyModifiers::fromInput($input, mac: false);
        self::assertTrue($mods->shift);
        self::assertTrue($mods->control);
        self::assertFalse($mods->alt);
        self::assertFalse($mods->super);

        $input->handleKeyEvent(342, 1); // left alt
        $input->handleKeyEvent(347, 1); // right super
        $mods = KeyModifiers::fromInput($input, mac: false);
        self::assertTrue($mods->alt);
        self::assertTrue($mods->super);
    }

    public function testControlIsThePrimaryModifierOutsideMacOsAndCommandOnIt(): void
    {
        $ctrl = new KeyModifiers(control: true, mac: false);
        self::assertTrue($ctrl->primary());
        self::assertTrue($ctrl->shortcut());
        self::assertTrue($ctrl->wordJump());

        $cmd = new KeyModifiers(super: true, mac: true);
        self::assertTrue($cmd->primary());
        self::assertTrue($cmd->shortcut());
        self::assertFalse($cmd->wordJump(), 'Option jumps words on macOS');
        self::assertTrue((new KeyModifiers(alt: true, mac: true))->wordJump());

        self::assertFalse((new KeyModifiers(control: true, mac: true))->primary());
        self::assertFalse((new KeyModifiers(super: true, mac: false))->primary());
    }

    public function testAltGrIsNoShortcut(): void
    {
        // AltGr arrives as Control + Alt on Windows; it types characters.
        $altGr = new KeyModifiers(control: true, alt: true, mac: false);
        self::assertTrue($altGr->primary());
        self::assertFalse($altGr->shortcut());
    }

    // ── clipboard ───────────────────────────────────────────────────

    public function testTheMemoryClipboardRoundTrips(): void
    {
        $clip = new MemoryClipboard();
        self::assertSame('', $clip->getText());
        $clip->setText("a\n漢字");
        self::assertSame("a\n漢字", $clip->getText());
    }

    public function testTheVioClipboardRoundTripsWithOrWithoutTheExtension(): void
    {
        $clip = new VioClipboard();
        $previous = $clip->getText();
        try {
            $clip->setText("copy ✓ 字");
            self::assertSame("copy ✓ 字", $clip->getText());
        } finally {
            $clip->setText($previous);
        }
    }

    // ── keyboard layout ─────────────────────────────────────────────

    public function testTheUsLayoutMapsLettersToTheirKeys(): void
    {
        $layout = new UsKeyboardLayout();
        self::assertSame(90, $layout->keyFor('z'));
        self::assertSame(65, $layout->keyFor('A'));
        self::assertSame(-1, $layout->keyFor('?'));
    }

    public function testTheVioLayoutFollowsTheKeycaps(): void
    {
        // QWERTZ: the key at the US 'Y' position types 'z' and vice versa.
        $qwertz = static fn (int $key): ?string => match ($key) {
            89 => 'z',
            90 => 'y',
            default => $key >= 65 && $key <= 90 ? strtolower(chr($key)) : null,
        };
        $layout = new VioKeyboardLayout($qwertz);

        self::assertSame(89, $layout->keyFor('z'));
        self::assertSame(90, $layout->keyFor('y'));
        self::assertSame(67, $layout->keyFor('c'));
    }

    public function testTheVioLayoutFallsBackToTheKeyPositionWhenACharacterHasNoKey(): void
    {
        // AZERTY-like without any 'm' among the letter keys, or no layout at all.
        $layout = new VioKeyboardLayout(static fn (int $key): ?string => null);
        self::assertSame(86, $layout->keyFor('v'));
    }

    public function testTheVioLayoutIsReReadAfterTheRefreshInterval(): void
    {
        $swap = false;
        $namer = static function (int $key) use (&$swap): ?string {
            if ($key === 89) {
                return $swap ? 'z' : 'y';
            }
            if ($key === 90) {
                return $swap ? 'y' : 'z';
            }
            return null;
        };
        $now = 0.0;
        $layout = new VioKeyboardLayout($namer, static function () use (&$now): float {
            return $now;
        });
        self::assertSame(90, $layout->keyFor('z'));

        $swap = true;
        self::assertSame(90, $layout->keyFor('z'), 'cached');
        $now += VioKeyboardLayout::REFRESH_SECONDS + 0.1;
        self::assertSame(89, $layout->keyFor('z'), 'the user switched the layout');
    }
}
