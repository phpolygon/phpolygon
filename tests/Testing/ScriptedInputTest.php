<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Testing;

use PHPUnit\Framework\TestCase;
use PHPolygon\Runtime\InputInterface;
use PHPolygon\Testing\RecordingAccessibilitySink;
use PHPolygon\Testing\ScriptedInput;
use PHPolygon\Tests\UI\Widget\WidgetTestHelper;
use PHPolygon\UI\Accessibility\AccessibilityBridge;
use PHPolygon\UI\UIStyle;
use PHPolygon\UI\Widget\Button;
use PHPolygon\UI\Widget\VBox;
use PHPolygon\UI\Widget\WidgetTree;

class ScriptedInputTest extends TestCase
{
    private const KEY_TAB = 258;
    private const KEY_ENTER = 257;

    public function testNothingHappensUntilScripted(): void
    {
        $input = new ScriptedInput();

        $this->assertInstanceOf(InputInterface::class, $input);
        $this->assertFalse($input->isKeyDown(self::KEY_TAB));
        $this->assertFalse($input->isKeyPressed(self::KEY_TAB));
        $this->assertFalse($input->isKeyTyped(self::KEY_TAB));
        $this->assertFalse($input->isKeyReleased(self::KEY_TAB));
        $this->assertSame([], $input->getCharsTyped());
    }

    public function testPressIsDownPressedAndTypedUntilEndFrame(): void
    {
        $input = (new ScriptedInput())->press(self::KEY_ENTER);

        $this->assertTrue($input->isKeyDown(self::KEY_ENTER));
        $this->assertTrue($input->isKeyPressed(self::KEY_ENTER));
        $this->assertTrue($input->isKeyTyped(self::KEY_ENTER));

        $input->endFrame();

        $this->assertTrue($input->isKeyDown(self::KEY_ENTER), 'still held');
        $this->assertFalse($input->isKeyPressed(self::KEY_ENTER));
        $this->assertFalse($input->isKeyTyped(self::KEY_ENTER));
    }

    public function testRepeatIsTypedWithoutAPress(): void
    {
        $input = (new ScriptedInput())->repeat(self::KEY_ENTER);

        $this->assertTrue($input->isKeyTyped(self::KEY_ENTER));
        $this->assertFalse($input->isKeyPressed(self::KEY_ENTER));
    }

    public function testReleaseEndsTheHoldAndReportsTheEdge(): void
    {
        $input = (new ScriptedInput())->hold(self::KEY_TAB)->release(self::KEY_TAB);

        $this->assertFalse($input->isKeyDown(self::KEY_TAB));
        $this->assertTrue($input->isKeyReleased(self::KEY_TAB));
    }

    public function testTypedCharactersAreUtf8AwareAndTextInputConsumes(): void
    {
        $input = (new ScriptedInput())->type('aé');

        $this->assertSame(['a', 'é'], $input->getCharsTyped());
        $this->assertSame('aé', $input->getTextInput());
        $this->assertSame('', $input->getTextInput());
    }

    public function testMouseAndScroll(): void
    {
        $input = (new ScriptedInput())->setMouse(10.0, 20.0)->clickMouse()->setScroll(0.0, -1.0);

        $this->assertSame(10.0, $input->getMouseX());
        $this->assertSame(20.0, $input->getMousePosition()->y);
        $this->assertTrue($input->isMouseButtonPressed(0));
        $this->assertSame(-1.0, $input->getScrollY());

        $input->endFrame();

        $this->assertTrue($input->isMouseButtonDown(0));
        $this->assertFalse($input->isMouseButtonPressed(0));
        $this->assertSame(0.0, $input->getScrollY());
    }

    public function testNotFinalSoATestCanOverrideAQuirk(): void
    {
        $this->assertFalse((new \ReflectionClass(ScriptedInput::class))->isFinal());
        $this->assertFalse((new \ReflectionClass(RecordingAccessibilitySink::class))->isFinal());
    }

    public function testDrivesTabNavigationAndTheBridgeReportsTheFocus(): void
    {
        $input = new ScriptedInput();
        $first = new Button('One');
        $second = new Button('Two');
        $tree = new WidgetTree((new VBox())->addChild($first)->addChild($second), new WidgetTestHelper(), $input, 800, 600, UIStyle::dark());
        $tree->setKeyboardNavigation(true);
        $tree->performLayout();
        $sink = new RecordingAccessibilitySink();
        $bridge = new AccessibilityBridge($sink);

        $input->press(self::KEY_TAB);
        $tree->processInput();
        $bridge->sync($tree);
        $input->endFrame();

        $this->assertSame($first, $tree->getFocusedWidget());
        $this->assertSame('path:0', $sink->focus);

        $input->press(self::KEY_TAB);
        $tree->processInput();
        $bridge->sync($tree);

        $this->assertSame($second, $tree->getFocusedWidget());
        $this->assertSame('path:1', $sink->focus);
    }

    public function testRecordingSinkCanRefuseAnUpdate(): void
    {
        $sink = new RecordingAccessibilitySink();
        $sink->refuse = true;

        $this->assertFalse($sink->update(['nodes' => [['id' => 'root', 'role' => 'window']]]));
        $this->assertSame([], $sink->updates);

        $sink->refuse = false;
        $this->assertTrue($sink->update(['nodes' => [['id' => 'root', 'role' => 'window']]]));
    }
}
