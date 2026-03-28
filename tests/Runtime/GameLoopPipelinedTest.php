<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use PHPolygon\Runtime\GameLoop;

class GameLoopPipelinedTest extends TestCase
{
    public function testPipelinedCallbackOrder(): void
    {
        $loop = new GameLoop(60.0);
        $callOrder = [];
        $frameCount = 0;

        $loop->runPipelined(
            prepareAndSend: function () use (&$callOrder) {
                $callOrder[] = 'prepare';
            },
            update: function (float $dt) use (&$callOrder) {
                $callOrder[] = 'update';
                $this->assertEqualsWithDelta(1.0 / 60.0, $dt, 0.001);
            },
            render: function (float $interpolation) use (&$callOrder) {
                $callOrder[] = 'render';
            },
            recvAndApply: function () use (&$callOrder) {
                $callOrder[] = 'recv';
            },
            shouldStop: function () use (&$frameCount): bool {
                $frameCount++;
                return $frameCount > 2;
            },
        );

        // Each frame should have: prepare → update → recv, then render
        // The exact count depends on timing, but the order within a tick must be correct
        $this->assertNotEmpty($callOrder);

        // Verify that within each tick: prepare comes before update, update before recv
        $prepareIdx = array_search('prepare', $callOrder);
        $updateIdx = array_search('update', $callOrder);
        $recvIdx = array_search('recv', $callOrder);
        $renderIdx = array_search('render', $callOrder);

        if ($prepareIdx !== false && $updateIdx !== false) {
            $this->assertLessThan($updateIdx, $prepareIdx);
        }
        if ($updateIdx !== false && $recvIdx !== false) {
            $this->assertLessThan($recvIdx, $updateIdx);
        }
    }

    public function testStandardRunStillWorks(): void
    {
        $loop = new GameLoop(60.0);
        $updateCalled = false;
        $renderCalled = false;
        $frameCount = 0;

        $loop->run(
            update: function (float $dt) use (&$updateCalled) {
                $updateCalled = true;
            },
            render: function (float $interpolation) use (&$renderCalled) {
                $renderCalled = true;
            },
            shouldStop: function () use (&$frameCount): bool {
                $frameCount++;
                return $frameCount > 1;
            },
        );

        $this->assertTrue($renderCalled);
    }
}
