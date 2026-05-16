<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use PHPolygon\Runtime\Input;

class InputTest extends TestCase
{
    private Input $input;

    protected function setUp(): void
    {
        $this->input = new Input();
    }

    // ── Char events ──────────────────────────────────────────────

    public function testCharEventBuffered(): void
    {
        $this->input->handleCharEvent(65); // 'A'
        $this->input->handleCharEvent(66); // 'B'

        $chars = $this->input->getCharsTyped();
        $this->assertCount(2, $chars);
        $this->assertEquals('A', $chars[0]);
        $this->assertEquals('B', $chars[1]);
    }

    public function testGetTextInputConcatenates(): void
    {
        $this->input->handleCharEvent(72);  // H
        $this->input->handleCharEvent(105); // i

        $this->assertEquals('Hi', $this->input->getTextInput());
    }

    public function testCharBufferClearedOnEndFrame(): void
    {
        $this->input->handleCharEvent(65);
        $this->assertCount(1, $this->input->getCharsTyped());

        $this->input->endFrame();
        $this->assertCount(0, $this->input->getCharsTyped());
    }

    public function testUnicodeCharEvent(): void
    {
        $this->input->handleCharEvent(0x00E4); // ä
        $this->input->handleCharEvent(0x1F600); // emoji

        $chars = $this->input->getCharsTyped();
        $this->assertEquals('ä', $chars[0]);
    }

    // ── Suppression ──────────────────────────────────────────────

    public function testSuppressBlocksKeyEvents(): void
    {
        $this->input->suppress();
        $this->input->handleKeyEvent(65, 1); // GLFW_PRESS

        $this->assertFalse($this->input->isKeyDown(65));
    }

    public function testSuppressBlocksMouseEvents(): void
    {
        $this->input->suppress();
        $this->input->handleMouseButtonEvent(0, 1);

        $this->assertFalse($this->input->isMouseButtonDown(0));
    }

    public function testSuppressDoesNotBlockCharEvents(): void
    {
        $this->input->suppress();
        $this->input->handleCharEvent(65);

        $this->assertCount(1, $this->input->getCharsTyped());
    }

    public function testUnsuppress(): void
    {
        $this->input->suppress();
        $this->assertTrue($this->input->isSuppressed());

        $this->input->unsuppress();
        $this->assertFalse($this->input->isSuppressed());

        $this->input->handleKeyEvent(65, 1);
        $this->assertTrue($this->input->isKeyDown(65));
    }

    // ── Suppression covers release edges (issue #14) ─────────────

    public function testMouseReleasedRespectsSuppression(): void
    {
        // Press is recorded normally
        $this->input->handleMouseButtonEvent(0, 1);
        $this->input->endFrame();
        $this->assertTrue($this->input->isMouseButtonDown(0));

        // Suppress mid-click (e.g. scene transition fired by the press)
        $this->input->suppress();

        // Simulate the release; even if the GLFW handler did record state,
        // the suppressed reader must report no release-edge.
        $this->input->handleMouseButtonEvent(0, 0);
        $this->assertFalse(
            $this->input->isMouseButtonReleased(0),
            'isMouseButtonReleased must respect isSuppressed() (issue #14)'
        );
    }

    public function testKeyReleasedRespectsSuppression(): void
    {
        $this->input->handleKeyEvent(65, 1);
        $this->input->endFrame();
        $this->assertTrue($this->input->isKeyDown(65));

        $this->input->suppress();
        $this->input->handleKeyEvent(65, 0);

        $this->assertFalse(
            $this->input->isKeyReleased(65),
            'isKeyReleased must respect isSuppressed() (issue #14)'
        );
    }

    public function testReleaseResumesAfterUnsuppress(): void
    {
        // Mouse release-edge detection is `!mouseDown && mousePrev` — this test
        // proves that suppression only gates the *reader*, it does not
        // permanently consume the underlying edge state once suppression ends.
        //
        // Note: only the mouse side is exercised here. Input::isKeyReleased in
        // the GLFW fallback has a pre-existing quirk (it requires the key to
        // be entirely absent from keysDown, not just false), which is
        // unrelated to issue #14. The suppression-gate is still covered by
        // testKeyReleasedRespectsSuppression.

        // Frame N-1: mouse held.
        $this->input->handleMouseButtonEvent(0, 1);
        $this->input->endFrame();

        // Frame N: suppress, simulate a release we want to swallow.
        $this->input->suppress();
        $this->input->handleMouseButtonEvent(0, 0);
        $this->assertFalse($this->input->isMouseButtonReleased(0));

        // Unsuppress and drive a clean re-press → release sequence.
        $this->input->unsuppress();
        $this->input->handleMouseButtonEvent(0, 1);
        $this->input->endFrame();
        $this->input->handleMouseButtonEvent(0, 0);

        $this->assertTrue(
            $this->input->isMouseButtonReleased(0),
            'Fresh release must fire normally once suppression has ended'
        );
    }

    // ── Existing key/mouse behavior (regression) ─────────────────

    public function testKeyPressedAndReleased(): void
    {
        $this->input->handleKeyEvent(32, 1); // PRESS
        $this->assertTrue($this->input->isKeyDown(32));
        $this->assertTrue($this->input->isKeyPressed(32));

        $this->input->endFrame();
        $this->assertFalse($this->input->isKeyPressed(32));
        $this->assertTrue($this->input->isKeyDown(32));

        $this->input->handleKeyEvent(32, 0); // RELEASE
        $this->input->endFrame();

        // After endFrame the released flag needs a fresh check
        $this->assertFalse($this->input->isKeyDown(32));
    }

    public function testMousePosition(): void
    {
        $this->input->handleCursorPosEvent(100.0, 200.0);
        $pos = $this->input->getMousePosition();

        $this->assertEquals(100.0, $pos->x);
        $this->assertEquals(200.0, $pos->y);
    }

    public function testScrollAccumulation(): void
    {
        $this->input->handleScrollEvent(1.0, 2.0);
        $this->input->handleScrollEvent(0.5, 1.0);

        $this->assertEquals(1.5, $this->input->getScrollX());
        $this->assertEquals(3.0, $this->input->getScrollY());

        $this->input->endFrame();
        $this->assertEquals(0.0, $this->input->getScrollX());
        $this->assertEquals(0.0, $this->input->getScrollY());
    }
}
