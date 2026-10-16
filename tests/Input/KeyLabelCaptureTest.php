<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Input;

use PHPUnit\Framework\TestCase;
use PHPolygon\Input\InputBinding;
use PHPolygon\Input\KeyCapture;
use PHPolygon\Input\KeyLabel;
use PHPolygon\Locale\LocaleManager;
use PHPolygon\Runtime\Input;

class KeyLabelCaptureTest extends TestCase
{
    public function testPrintableKeyFollowsTheLayout(): void
    {
        // A layout with Y and Z swapped.
        $label = new KeyLabel(keyName: static fn (int $key): ?string => match ($key) {
            89 => 'z',
            90 => 'y',
            default => null,
        });

        $this->assertSame('Z', $label->label(89));
        $this->assertSame('Y', $label->label(90));
        $this->assertSame('G', $label->label(71)); // no layout info -> US position
    }

    public function testNamedKeysFallBackToEnglish(): void
    {
        $label = new KeyLabel(keyName: static fn (int $key): ?string => null);

        $this->assertSame('Space', $label->label(32));
        $this->assertSame('F5', $label->label(294));
        $this->assertSame('Num 3', $label->label(323));
        $this->assertSame('Left Mouse', $label->label(InputBinding::mouseButton(0)));
        $this->assertSame('Mouse 5', $label->label(InputBinding::mouseButton(4)));
        $this->assertSame('#999', $label->label(999));
    }

    public function testNamedKeysAreTranslatedWhenTheGameProvidesKeys(): void
    {
        $locale = new LocaleManager('de');
        $locale->add('de', ['input.key.space' => 'Leertaste', 'input.key.mouse_left' => 'Linke Maustaste']);
        $label = new KeyLabel($locale, static fn (int $key): ?string => null);

        $this->assertSame('Leertaste', $label->label(32));
        $this->assertSame('Linke Maustaste', $label->label(InputBinding::mouseButton(0)));
        $this->assertSame('Enter', $label->label(257)); // untranslated -> English
    }

    public function testSlugs(): void
    {
        $this->assertSame('left_shift', KeyLabel::slug(340));
        $this->assertSame('f12', KeyLabel::slug(301));
        $this->assertNull(KeyLabel::slug(65));
    }

    public function testCaptureReportsTheFirstNewKey(): void
    {
        $input = new Input();
        $capture = new KeyCapture($input);
        $capture->start();

        $this->assertNull($capture->poll());
        $input->handleKeyEvent(84, 1);

        $binding = $capture->poll();
        $this->assertNotNull($binding);
        $this->assertSame(84, $binding->code);
        $this->assertFalse($capture->isActive());
        $this->assertNull($capture->poll());
    }

    public function testCaptureDoesNotConsumeThePress(): void
    {
        $input = new Input();
        $capture = new KeyCapture($input);
        $capture->start();
        $input->handleKeyEvent(84, 1);

        $this->assertNotNull($capture->poll());
        $this->assertTrue($input->isKeyPressed(84));
    }

    public function testKeyHeldAtStartOnlyCountsAfterARelease(): void
    {
        $input = new Input();
        $input->handleKeyEvent(257, 1); // Enter opened the prompt and is still down
        $capture = new KeyCapture($input);
        $capture->start();

        $this->assertNull($capture->poll());
        $input->handleKeyEvent(257, 0);
        $this->assertNull($capture->poll());
        $input->handleKeyEvent(257, 1);
        $this->assertSame(257, $capture->poll()?->code);
    }

    public function testEscapeIsNotACandidateByDefault(): void
    {
        $input = new Input();
        $capture = new KeyCapture($input);
        $capture->start();
        $input->handleKeyEvent(256, 1);

        $this->assertNull($capture->poll());
        $this->assertTrue($capture->isActive());
        $this->assertNotContains(256, KeyCapture::defaultKeys());
    }

    public function testCandidatesAndMouseButtons(): void
    {
        $input = new Input();
        $capture = new KeyCapture($input);
        $capture->start([65, 66], [0]);

        $input->handleKeyEvent(67, 1); // not a candidate
        $this->assertNull($capture->poll());

        $input->handleMouseButtonEvent(0, 1);
        $binding = $capture->poll();
        $this->assertNotNull($binding);
        $this->assertTrue($binding->equals(InputBinding::mouseButton(0)));
    }

    public function testCancel(): void
    {
        $input = new Input();
        $capture = new KeyCapture($input);
        $capture->start();
        $capture->cancel();
        $input->handleKeyEvent(84, 1);
        $this->assertNull($capture->poll());
    }
}
