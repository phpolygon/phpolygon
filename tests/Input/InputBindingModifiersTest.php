<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Input;

use PHPUnit\Framework\TestCase;
use PHPolygon\Input\InputBinding;
use PHPolygon\Input\InputMap;
use PHPolygon\Input\KeyCapture;
use PHPolygon\Input\KeyLabel;
use PHPolygon\Runtime\Input;

/** Bindings with modifiers (Shift+Tab): matching, rebinding, saving, capturing, labelling. */
class InputBindingModifiersTest extends TestCase
{
    private const TAB = 258;
    private const SHIFT = 340;
    private const CTRL = 341;

    private function map(): InputMap
    {
        return (new InputMap())
            ->addAction('next', InputBinding::key(self::TAB))
            ->addAction('previous', InputBinding::key(self::TAB, InputBinding::MOD_SHIFT));
    }

    public function testModifierBindingNeedsTheModifierHeld(): void
    {
        $input = new Input();
        $map = $this->map();

        $input->handleKeyEvent(self::TAB, 1);
        $map->poll($input);
        $this->assertTrue($map->getAction('next')->isHeld());
        $this->assertFalse($map->getAction('previous')->isHeld());

        $input->handleKeyEvent(self::SHIFT, 1);
        $map->poll($input);
        $this->assertTrue($map->getAction('previous')->isHeld());
    }

    public function testBindingWithoutModifiersIgnoresThemAsBefore(): void
    {
        $input = new Input();
        $map = $this->map();
        $input->handleKeyEvent(self::TAB, 1);
        $input->handleKeyEvent(self::CTRL, 1);

        $map->poll($input);

        $this->assertTrue($map->getAction('next')->isHeld());
    }

    public function testSameKeyWithDifferentModifiersIsNotAConflict(): void
    {
        $map = $this->map();
        $map->addAction('other', InputBinding::key(70));

        $this->assertNull($map->findActionFor(InputBinding::key(self::TAB, InputBinding::MOD_CONTROL)));
        $this->assertSame('previous', $map->findActionFor(InputBinding::key(self::TAB, InputBinding::MOD_SHIFT)));
        $this->assertTrue($map->rebind('other', InputBinding::key(self::TAB, InputBinding::MOD_CONTROL))->isSuccess());
    }

    public function testModifiersSurviveExportAndImport(): void
    {
        $map = $this->map();
        $map->rebind('previous', InputBinding::key(71, InputBinding::MOD_SHIFT | InputBinding::MOD_CONTROL));

        $saved = json_decode(json_encode($map->exportOverrides(), JSON_THROW_ON_ERROR), true);
        $fresh = $this->map();
        $dropped = $fresh->importOverrides($saved);

        $this->assertSame([], $dropped);
        $this->assertTrue($fresh->getBinding('previous')->equals(InputBinding::key(71, 3)));
    }

    public function testOldSavesAndPlainBindingsKeepTheirArrayShape(): void
    {
        $this->assertSame(['type' => 'key', 'code' => 65], InputBinding::key(65)->toArray());
        $this->assertSame(0, InputBinding::fromArray(['type' => 'key', 'code' => 65])->modifiers);
        $this->assertSame(1, InputBinding::fromArray(['type' => 'key', 'code' => 65, 'modifiers' => 1])->modifiers);
        $this->assertSame(0, InputBinding::fromArray(65)->modifiers);
    }

    public function testCaptureReportsHeldModifiersOnRequest(): void
    {
        $input = new Input();
        $capture = new KeyCapture($input);
        $capture->start(withModifiers: true);

        $input->handleKeyEvent(self::SHIFT, 1);
        $this->assertNull($capture->poll(), 'a modifier alone is not a binding');
        $input->handleKeyEvent(self::TAB, 1);
        $binding = $capture->poll();

        $this->assertNotNull($binding);
        $this->assertTrue($binding->equals(InputBinding::key(self::TAB, InputBinding::MOD_SHIFT)));
    }

    public function testCaptureWithoutTheOptionIsUnchanged(): void
    {
        $input = new Input();
        $capture = new KeyCapture($input);
        $capture->start();

        $input->handleKeyEvent(self::SHIFT, 1);

        $binding = $capture->poll();
        $this->assertNotNull($binding);
        $this->assertSame(self::SHIFT, $binding->code);
        $this->assertSame(0, $binding->modifiers);
    }

    public function testLabelPutsModifiersFirst(): void
    {
        $label = new KeyLabel(keyName: static fn (int $key): ?string => null);

        $this->assertSame('Shift+Tab', $label->label(InputBinding::key(self::TAB, InputBinding::MOD_SHIFT)));
        $this->assertSame('Ctrl+Shift+Tab', $label->label(InputBinding::key(self::TAB, InputBinding::MOD_SHIFT | InputBinding::MOD_CONTROL)));
        $this->assertSame('Tab', $label->label(self::TAB));
    }
}
