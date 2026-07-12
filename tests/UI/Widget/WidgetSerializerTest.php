<?php

declare(strict_types=1);

namespace PHPolygon\Tests\UI\Widget;

use PHPolygon\UI\Widget\Button;
use PHPolygon\UI\Widget\EdgeInsets;
use PHPolygon\UI\Widget\Label;
use PHPolygon\UI\Widget\Panel;
use PHPolygon\UI\Widget\Sizing;
use PHPolygon\UI\Widget\VBox;
use PHPolygon\Rendering\Color;
use PHPolygon\UI\UIStyle;
use PHPolygon\UI\Widget\WidgetSerializer;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class WidgetSerializerTest extends TestCase
{
    private function sampleTree(): Panel
    {
        $panel = new Panel('Settings');
        $panel->padding = EdgeInsets::all(12.0);

        $vbox = new VBox(spacing: 10.0);
        $vbox->sizing = Sizing::fill();

        $label = new Label('Hello');
        $label->fontSize = 20.0;

        $button = new Button('OK');
        $button->enabled = false;

        $vbox->addChild($label);
        $vbox->addChild($button);
        $panel->addChild($vbox);

        return $panel;
    }

    public function test_serializes_widget_marker_and_props(): void
    {
        $data = (new WidgetSerializer)->toArray($this->sampleTree());

        $this->assertSame(Panel::class, $data['_widget']);
        $this->assertSame('Settings', $data['title']);
        $this->assertArrayHasKey('children', $data);
        $this->assertCount(1, $data['children']);
        $this->assertSame(VBox::class, $data['children'][0]['_widget']);
    }

    public function test_omits_children_key_for_leaf_widgets(): void
    {
        $data = (new WidgetSerializer)->toArray(new Label('x'));
        $this->assertArrayNotHasKey('children', $data);
    }

    public function test_drops_transient_runtime_state(): void
    {
        $data = (new WidgetSerializer)->toArray(new Button('Go'));

        $this->assertArrayNotHasKey('hovered', $data);
        $this->assertArrayNotHasKey('pressed', $data);
        $this->assertArrayNotHasKey('styleOverride', $data);
        $this->assertSame('Go', $data['label']);
    }

    public function test_round_trip_preserves_structure_and_values(): void
    {
        $serializer = new WidgetSerializer;
        $restored = $serializer->fromArray($serializer->toArray($this->sampleTree()));

        $this->assertInstanceOf(Panel::class, $restored);
        $this->assertSame('Settings', $restored->title);
        $this->assertSame(12.0, $restored->padding->left);

        $children = $restored->getChildren();
        $this->assertCount(1, $children);

        $vbox = $children[0];
        $this->assertInstanceOf(VBox::class, $vbox);
        $this->assertSame(10.0, $vbox->spacing);
        $this->assertTrue($vbox->sizing->fillWidth);

        [$label, $button] = $vbox->getChildren();
        $this->assertInstanceOf(Label::class, $label);
        $this->assertSame('Hello', $label->text);
        $this->assertSame(20.0, $label->fontSize);

        $this->assertInstanceOf(Button::class, $button);
        $this->assertSame('OK', $button->label);
        $this->assertFalse($button->enabled);
    }

    public function test_round_trip_is_stable_across_two_passes(): void
    {
        $serializer = new WidgetSerializer;
        $once = $serializer->toArray($this->sampleTree());
        $twice = $serializer->toArray($serializer->fromArray($once));

        $this->assertSame($once, $twice);
    }

    public function test_compact_omits_default_properties(): void
    {
        $serializer = new WidgetSerializer;

        // A pristine widget has nothing but its type once defaults are dropped.
        $compact = $serializer->toArray(new VBox);
        $this->assertSame(['_widget'], array_keys($compact));
        $this->assertSame(VBox::class, $compact['_widget']);

        // The non-compact form spells out every property (for schema use).
        $full = $serializer->toArray(new VBox, compact: false);
        $this->assertArrayHasKey('spacing', $full);
        $this->assertArrayHasKey('sizing', $full);
        $this->assertArrayHasKey('padding', $full);
    }

    public function test_compact_keeps_changed_properties(): void
    {
        $label = new Label('Hi');
        $label->fontSize = 20.0;

        $data = (new WidgetSerializer)->toArray($label);
        $this->assertSame('Hi', $data['text']);
        $this->assertSame(20.0, $data['fontSize']);
        $this->assertArrayNotHasKey('visible', $data); // still default
    }

    public function test_style_override_round_trips(): void
    {
        $serializer = new WidgetSerializer;
        $label = new Label('Styled');
        $label->styleOverride = new UIStyle(textColor: new Color(1.0, 0.0, 0.0, 1.0), fontSize: 22.0);

        $restored = $serializer->fromArray($serializer->toArray($label));

        $this->assertInstanceOf(UIStyle::class, $restored->styleOverride);
        $this->assertSame(22.0, $restored->styleOverride->fontSize);
        $this->assertSame(1.0, $restored->styleOverride->textColor->r);
        $this->assertSame(0.0, $restored->styleOverride->textColor->g);
    }

    public function test_invalid_widget_class_throws(): void
    {
        $this->expectException(RuntimeException::class);
        (new WidgetSerializer)->fromArray(['_widget' => 'Not\\A\\Widget']);
    }

    public function test_missing_widget_marker_throws(): void
    {
        $this->expectException(RuntimeException::class);
        (new WidgetSerializer)->fromArray(['title' => 'orphan']);
    }
}
