<?php

declare(strict_types=1);

namespace PHPolygon\Tests\UI\Accessibility;

use PHPUnit\Framework\TestCase;
use PHPolygon\Runtime\Input;
use PHPolygon\Tests\UI\Widget\WidgetTestHelper;
use PHPolygon\UI\Accessibility\AccessibilityBridge;
use PHPolygon\UI\UIStyle;
use PHPolygon\UI\Widget\Button;
use PHPolygon\UI\Widget\VBox;
use PHPolygon\UI\Widget\WidgetTree;

class AccessibilityAnnounceTest extends TestCase
{
    private RecordingAccessibilitySink $sink;
    private AccessibilityBridge $bridge;
    private WidgetTree $tree;

    protected function setUp(): void
    {
        $this->sink = new RecordingAccessibilitySink();
        $this->bridge = new AccessibilityBridge($this->sink, 'Game');
        $this->tree = new WidgetTree((new VBox())->addChild(new Button('OK')), new WidgetTestHelper(), new Input(), 800, 600, UIStyle::dark());
        $this->tree->performLayout();
    }

    public function testBothLiveRegionsExistFromTheFirstUpdateAsChildrenOfTheWindow(): void
    {
        $this->bridge->sync($this->tree);

        $this->assertContains(AccessibilityBridge::LIVE_POLITE, $this->sink->tree['root']['children']);
        $this->assertContains(AccessibilityBridge::LIVE_ASSERTIVE, $this->sink->tree['root']['children']);
        $this->assertSame('status', $this->sink->tree[AccessibilityBridge::LIVE_POLITE]['role']);
        $this->assertSame('alert', $this->sink->tree[AccessibilityBridge::LIVE_ASSERTIVE]['role']);
    }

    public function testAnnounceChangesOnlyTheLiveNode(): void
    {
        $this->bridge->sync($this->tree);

        $this->bridge->announce('Game saved');

        $this->assertCount(2, $this->sink->updates);
        $this->assertSame(['nodes' => [['id' => 'announce:polite', 'role' => 'status', 'label' => 'Game saved']]], $this->sink->updates[1]);
    }

    public function testAssertiveMessagesUseTheAlertNode(): void
    {
        $this->bridge->sync($this->tree);

        $this->bridge->announce('Connection lost', false);

        $this->assertSame('Connection lost', $this->sink->tree[AccessibilityBridge::LIVE_ASSERTIVE]['label']);
        $this->assertArrayNotHasKey('label', $this->sink->tree[AccessibilityBridge::LIVE_POLITE]);
    }

    public function testASecondIdenticalMessageIsStillAChange(): void
    {
        $this->bridge->sync($this->tree);

        $this->bridge->announce('Saved');
        $this->bridge->announce('Saved');
        $this->bridge->announce('Saved');

        $labels = array_map(fn (array $u) => $u['nodes'][0]['label'], array_slice($this->sink->updates, 1));
        $this->assertCount(3, $labels);
        $this->assertNotSame($labels[0], $labels[1], 'a repeat differs from the one before');
        $this->assertNotSame($labels[1], $labels[2]);
        $this->assertSame('Saved', rtrim($labels[1], "\u{200B}"));
    }

    public function testAnnouncementBeforeTheFirstSyncGoesOutWithTheTree(): void
    {
        $this->bridge->announce('Welcome');
        $this->assertSame([], $this->sink->updates);

        $this->bridge->sync($this->tree);

        $this->assertSame('Welcome', $this->sink->tree[AccessibilityBridge::LIVE_POLITE]['label']);
    }

    public function testNormalSyncsDoNotResendTheMessage(): void
    {
        $this->bridge->sync($this->tree);
        $this->bridge->announce('Saved');

        $this->bridge->sync($this->tree);

        $this->assertCount(2, $this->sink->updates, 'the live node was sent once, the next frame had nothing new');
    }

    public function testEmptyTextClearsTheRegion(): void
    {
        $this->bridge->sync($this->tree);
        $this->bridge->announce('Saved');

        $this->bridge->announce('');

        $this->assertArrayNotHasKey('label', $this->sink->updates[2]['nodes'][0]);
    }

    public function testAnnounceIsASilentNoOpWhereAccessibilityIsUnavailable(): void
    {
        $sink = new RecordingAccessibilitySink(false);
        $bridge = new AccessibilityBridge($sink);

        $bridge->announce('Anything');
        $bridge->sync($this->tree);
        $bridge->announce('Anything', false);

        $this->assertSame([], $sink->updates);
    }
}
