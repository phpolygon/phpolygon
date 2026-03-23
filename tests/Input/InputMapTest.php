<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Input;

use PHPUnit\Framework\TestCase;
use PHPolygon\Input\InputBinding;
use PHPolygon\Input\InputMap;
use PHPolygon\Runtime\Input;

class InputMapTest extends TestCase
{
    private Input $rawInput;
    private InputMap $inputMap;

    protected function setUp(): void
    {
        $this->rawInput = new Input();
        $this->inputMap = new InputMap();
    }

    public function testActionPressed(): void
    {
        // GLFW_KEY_SPACE = 32
        $this->inputMap->addAction('jump', InputBinding::key(32));

        // Frame 1: Key down
        $this->rawInput->handleKeyEvent(32, 1); // GLFW_PRESS
        $this->inputMap->poll($this->rawInput);

        $jump = $this->inputMap->getAction('jump');
        $this->assertTrue($jump->isPressed());
        $this->assertTrue($jump->isHeld());
        $this->assertFalse($jump->isReleased());
        $this->assertEqualsWithDelta(1.0, $jump->getValue(), 0.001);
    }

    public function testActionHeld(): void
    {
        $this->inputMap->addAction('jump', InputBinding::key(32));

        // Frame 1: Key down
        $this->rawInput->handleKeyEvent(32, 1);
        $this->inputMap->poll($this->rawInput);

        // Frame 2: Key still down
        $this->inputMap->poll($this->rawInput);

        $jump = $this->inputMap->getAction('jump');
        $this->assertFalse($jump->isPressed()); // Not first frame
        $this->assertTrue($jump->isHeld());
        $this->assertFalse($jump->isReleased());
    }

    public function testActionReleased(): void
    {
        $this->inputMap->addAction('jump', InputBinding::key(32));

        // Frame 1: Key down
        $this->rawInput->handleKeyEvent(32, 1);
        $this->inputMap->poll($this->rawInput);

        // Frame 2: Key up
        $this->rawInput->handleKeyEvent(32, 0);
        $this->inputMap->poll($this->rawInput);

        $jump = $this->inputMap->getAction('jump');
        $this->assertFalse($jump->isPressed());
        $this->assertFalse($jump->isHeld());
        $this->assertTrue($jump->isReleased());
    }

    public function testMultipleBindings(): void
    {
        // Jump can be triggered by Space (32) or W (87)
        $this->inputMap->addAction('jump', InputBinding::key(32), InputBinding::key(87));

        $this->rawInput->handleKeyEvent(87, 1); // W pressed
        $this->inputMap->poll($this->rawInput);

        $this->assertTrue($this->inputMap->getAction('jump')->isHeld());
    }

    public function testMouseButtonBinding(): void
    {
        $this->inputMap->addAction('shoot', InputBinding::mouseButton(0)); // Left click

        $this->rawInput->handleMouseButtonEvent(0, 1);
        $this->inputMap->poll($this->rawInput);

        $this->assertTrue($this->inputMap->getAction('shoot')->isPressed());
    }

    public function testAxis(): void
    {
        // Horizontal axis: D = positive, A = negative
        $this->inputMap->addAxis('horizontal',
            positive: [InputBinding::key(68)], // D
            negative: [InputBinding::key(65)], // A
        );

        // Press D
        $this->rawInput->handleKeyEvent(68, 1);
        $this->inputMap->poll($this->rawInput);
        $this->assertEqualsWithDelta(1.0, $this->inputMap->getAxis('horizontal'), 0.001);

        // Release D, press A
        $this->rawInput->handleKeyEvent(68, 0);
        $this->rawInput->handleKeyEvent(65, 1);
        $this->inputMap->poll($this->rawInput);
        $this->assertEqualsWithDelta(-1.0, $this->inputMap->getAxis('horizontal'), 0.001);

        // Release A
        $this->rawInput->handleKeyEvent(65, 0);
        $this->inputMap->poll($this->rawInput);
        $this->assertEqualsWithDelta(0.0, $this->inputMap->getAxis('horizontal'), 0.001);
    }

    public function testHasAction(): void
    {
        $this->inputMap->addAction('jump', InputBinding::key(32));
        $this->assertTrue($this->inputMap->hasAction('jump'));
        $this->assertFalse($this->inputMap->hasAction('fly'));
    }

    public function testGetUnknownActionThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->inputMap->getAction('nonexistent');
    }
}
