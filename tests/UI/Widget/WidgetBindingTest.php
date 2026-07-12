<?php

declare(strict_types=1);

namespace PHPolygon\Tests\UI\Widget;

use PHPolygon\UI\Widget\Button;
use PHPolygon\UI\Widget\DataWidgetContext;
use PHPolygon\UI\Widget\Label;
use PHPolygon\UI\Widget\Panel;
use PHPolygon\UI\Widget\Repeater;
use PHPolygon\UI\Widget\Slider;
use PHPolygon\UI\Widget\WidgetBinder;
use PHPolygon\UI\Widget\WidgetSerializer;
use PHPUnit\Framework\TestCase;

class WidgetBindingTest extends TestCase
{
    public function testValueBindingReadsFromContext(): void
    {
        $label = new Label('placeholder');
        $label->bindings['text'] = 'title';

        (new WidgetBinder)->bind($label, new DataWidgetContext((object) ['title' => 'Clients']));

        $this->assertSame('Clients', $label->text);
    }

    public function testMissingBindingLeavesNonNullableStringEmpty(): void
    {
        $label = new Label('placeholder');
        $label->bindings['text'] = 'does.not.exist';

        (new WidgetBinder)->bind($label, new DataWidgetContext((object) []));

        // Coerced to '' rather than violating the non-nullable string property.
        $this->assertSame('', $label->text);
    }

    public function testTwoWayBindingWritesBackOnChange(): void
    {
        $vm = new class
        {
            public float $volume = 0.5;
        };
        $slider = new Slider('Vol', 0.0, 0.0, 1.0);
        $slider->bindings['value'] = 'volume';

        $ctx = new DataWidgetContext($vm);
        (new WidgetBinder)->bind($slider, $ctx);
        $this->assertSame(0.5, $slider->value, 'read binding applied');

        // The tree mutates the widget then emits 'change' (see WidgetTree).
        $slider->value = 0.8;
        $slider->emit('change', 0.8);

        $this->assertSame(0.8, $vm->volume, 'write-back applied');
    }

    public function testActionBindingDispatchesThroughContext(): void
    {
        $clicked = false;
        $ctx = new DataWidgetContext(null, ['confirm' => function () use (&$clicked): void {
            $clicked = true;
        }]);

        $button = new Button('OK');
        $button->eventBindings['click'] = 'confirm';
        (new WidgetBinder)->bind($button, $ctx);

        $button->emit('click');

        $this->assertTrue($clicked);
    }

    public function testRebindDoesNotStackListeners(): void
    {
        $count = 0;
        $ctx = new DataWidgetContext(null, ['confirm' => function () use (&$count): void {
            $count++;
        }]);

        $button = new Button('OK');
        $button->eventBindings['click'] = 'confirm';
        $binder = new WidgetBinder;
        $binder->bind($button, $ctx);
        $binder->bind($button, $ctx); // re-bind (e.g. next frame)

        $button->emit('click');

        $this->assertSame(1, $count, 'click fires once, not once per bind()');
    }

    public function testRepeaterExpandsPerItemWithItemScopedBindings(): void
    {
        $repeater = new Repeater;
        $repeater->each = 'clients';
        $repeater->template = ['_widget' => Label::class, 'text' => ['$bind' => 'name']];

        $vm = (object) ['clients' => [(object) ['name' => 'Acme'], (object) ['name' => 'Globex']]];
        (new WidgetBinder)->bind($repeater, new DataWidgetContext($vm));

        $rows = $repeater->getChildren();
        $this->assertCount(2, $rows);
        $this->assertInstanceOf(Label::class, $rows[0]);
        $this->assertSame('Acme', $rows[0]->text);
        $this->assertSame('Globex', $rows[1]->text);
    }

    public function testRepeaterRowActionReachesOuterContextWithItem(): void
    {
        $item = (object) ['id' => 'acme'];
        $received = null;
        $ctx = new DataWidgetContext(
            (object) ['rows' => [$item]],
            ['select' => function (mixed $it) use (&$received): void {
                $received = $it;
            }],
        );

        $repeater = new Repeater;
        $repeater->each = 'rows';
        $repeater->template = ['_widget' => Button::class, 'label' => 'go', '$on' => ['click' => 'select']];
        (new WidgetBinder)->bind($repeater, $ctx);

        $repeater->getChildren()[0]->emit('click');

        $this->assertSame($item, $received, 'row action reaches the outer VM with its item');
    }

    public function testRepeaterReexpandsOnRebind(): void
    {
        $repeater = new Repeater;
        $repeater->each = 'clients';
        $repeater->template = ['_widget' => Label::class, 'text' => ['$bind' => 'name']];

        $vm = (object) ['clients' => [(object) ['name' => 'Acme']]];
        $binder = new WidgetBinder;
        $binder->bind($repeater, new DataWidgetContext($vm));
        $this->assertCount(1, $repeater->getChildren());

        $vm->clients[] = (object) ['name' => 'Globex'];
        $binder->bind($repeater, new DataWidgetContext($vm));
        $this->assertCount(2, $repeater->getChildren(), 'rows track the collection on rebind');
    }

    public function testBindingsSurviveSerializationRoundTrip(): void
    {
        $panel = new Panel;

        $label = new Label('x');
        $label->bindings['text'] = 'title';

        $button = new Button('OK');
        $button->eventBindings['click'] = 'confirm';

        $repeater = new Repeater;
        $repeater->each = 'clients';
        $repeater->template = ['_widget' => Label::class, 'text' => ['$bind' => 'name']];

        $panel->addChild($label);
        $panel->addChild($button);
        $panel->addChild($repeater);

        $serializer = new WidgetSerializer;
        $restored = $serializer->fromArray($serializer->toArray($panel));

        $kids = $restored->getChildren();
        $this->assertSame('title', $kids[0]->bindings['text']);
        $this->assertSame('confirm', $kids[1]->eventBindings['click']);
        $this->assertInstanceOf(Repeater::class, $kids[2]);
        $this->assertSame('clients', $kids[2]->each);
        $this->assertSame(['_widget' => Label::class, 'text' => ['$bind' => 'name']], $kids[2]->template);
    }

    public function testBoundPropertyEmittedAsBindNotLiteral(): void
    {
        $label = new Label('literal-should-not-appear');
        $label->bindings['text'] = 'title';

        $array = (new WidgetSerializer)->toArray($label);

        $this->assertSame(['$bind' => 'title'], $array['text']);
    }
}
