<?php

declare(strict_types=1);

namespace PHPolygon\Tests\SaveGame;

use PHPUnit\Framework\TestCase;
use PHPolygon\SaveGame\Autosave;
use PHPolygon\SaveGame\SaveManager;
use PHPolygon\SaveGame\SaveSlot;

class AutosaveTest extends TestCase
{
    private SaveManager $saves;
    private string $savePath;

    protected function setUp(): void
    {
        $this->savePath = sys_get_temp_dir() . '/phpolygon_autosave_test_' . uniqid();
        $this->saves = new SaveManager($this->savePath, 5);
    }

    protected function tearDown(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $path = $this->savePath . '/slot_' . $i . '.save.json';
            if (file_exists($path)) unlink($path);
        }
        if (is_dir($this->savePath)) rmdir($this->savePath);
    }

    private function autosave(float $interval = 60.0, int $slot = 0): Autosave
    {
        return new Autosave(
            $this->saves,
            fn() => ['hp' => 100, 'level' => 3],
            $slot,
            $interval,
        );
    }

    public function testTickDoesNotSaveBeforeInterval(): void
    {
        $auto = $this->autosave(10.0);

        $saved = $auto->tick(5.0);

        $this->assertFalse($saved);
        $this->assertEquals(0, $auto->getSaveCount());
        $this->assertNull($auto->getLastSave());
    }

    public function testTickSavesAtInterval(): void
    {
        $auto = $this->autosave(10.0);

        $auto->tick(5.0);
        $saved = $auto->tick(5.0);

        $this->assertTrue($saved);
        $this->assertEquals(1, $auto->getSaveCount());
        $this->assertInstanceOf(SaveSlot::class, $auto->getLastSave());
        $this->assertEquals('Autosave', $auto->getLastSave()->name);
    }

    public function testTickSavesMultipleTimes(): void
    {
        $auto = $this->autosave(5.0);

        $auto->tick(5.0); // save 1
        $auto->tick(5.0); // save 2
        $auto->tick(5.0); // save 3

        $this->assertEquals(3, $auto->getSaveCount());
    }

    public function testTickResetsTimerAfterSave(): void
    {
        $auto = $this->autosave(10.0);

        $auto->tick(10.0); // triggers save, resets elapsed
        $this->assertEqualsWithDelta(0.0, $auto->getElapsed(), 0.001);

        $saved = $auto->tick(3.0);
        $this->assertFalse($saved);
        $this->assertEqualsWithDelta(3.0, $auto->getElapsed(), 0.001);
    }

    public function testDisabledDoesNotSave(): void
    {
        $auto = $this->autosave(5.0);
        $auto->disable();

        $saved = $auto->tick(100.0);

        $this->assertFalse($saved);
        $this->assertEquals(0, $auto->getSaveCount());
    }

    public function testEnableResumesSaving(): void
    {
        $auto = $this->autosave(5.0);
        $auto->disable();
        $auto->tick(100.0);

        $auto->enable();
        $saved = $auto->tick(5.0);

        $this->assertTrue($saved);
    }

    public function testSaveNowForcesImmediateSave(): void
    {
        $auto = $this->autosave(999.0);

        $slot = $auto->saveNow();

        $this->assertInstanceOf(SaveSlot::class, $slot);
        $this->assertEquals(1, $auto->getSaveCount());
        $this->assertEquals(['hp' => 100, 'level' => 3], $slot->data);
    }

    public function testSaveNowWritesToDisk(): void
    {
        $auto = $this->autosave(999.0, slot: 2);
        $auto->saveNow();

        // Verify by loading from a fresh manager
        $fresh = new SaveManager($this->savePath, 5);
        $loaded = $fresh->load(2);

        $this->assertNotNull($loaded);
        $this->assertEquals('Autosave', $loaded->name);
        $this->assertEquals(['hp' => 100, 'level' => 3], $loaded->data);
    }

    public function testMetadataProvider(): void
    {
        $auto = $this->autosave(5.0);
        $auto->setMetadataProvider(fn() => ['scene' => 'forest', 'autosave' => true]);

        $auto->tick(5.0);

        $this->assertEquals('forest', $auto->getLastSave()->metadata['scene']);
    }

    public function testPlayTimeProvider(): void
    {
        $auto = $this->autosave(5.0);
        $auto->setPlayTimeProvider(fn() => 1234.5);

        $auto->tick(5.0);

        $this->assertEqualsWithDelta(1234.5, $auto->getLastSave()->playTime, 0.01);
    }

    public function testDefaultMetadataContainsAutosaveFlag(): void
    {
        $auto = $this->autosave(5.0);
        $auto->tick(5.0);

        $this->assertTrue($auto->getLastSave()->metadata['autosave']);
    }

    public function testSlotIndex(): void
    {
        $auto = $this->autosave(5.0, slot: 3);
        $this->assertEquals(3, $auto->getSlotIndex());

        $auto->setSlotIndex(4);
        $this->assertEquals(4, $auto->getSlotIndex());
    }

    public function testIntervalAccessors(): void
    {
        $auto = $this->autosave(60.0);
        $this->assertEquals(60.0, $auto->getIntervalSeconds());

        $auto->setIntervalSeconds(120.0);
        $this->assertEquals(120.0, $auto->getIntervalSeconds());
    }

    public function testIntervalMinimum(): void
    {
        $auto = $this->autosave(60.0);
        $auto->setIntervalSeconds(0.1);
        $this->assertEquals(1.0, $auto->getIntervalSeconds());
    }

    public function testResetTimer(): void
    {
        $auto = $this->autosave(10.0);
        $auto->tick(8.0);
        $this->assertEqualsWithDelta(8.0, $auto->getElapsed(), 0.001);

        $auto->resetTimer();
        $this->assertEqualsWithDelta(0.0, $auto->getElapsed(), 0.001);
    }

    public function testGetTimeUntilNext(): void
    {
        $auto = $this->autosave(10.0);
        $this->assertEqualsWithDelta(10.0, $auto->getTimeUntilNext(), 0.001);

        $auto->tick(3.0);
        $this->assertEqualsWithDelta(7.0, $auto->getTimeUntilNext(), 0.001);
    }

    public function testDataProviderCalledEachSave(): void
    {
        $counter = 0;
        $auto = new Autosave(
            $this->saves,
            function () use (&$counter) {
                $counter++;
                return ['save_number' => $counter];
            },
            intervalSeconds: 5.0,
        );

        $auto->tick(5.0);
        $auto->tick(5.0);

        $this->assertEquals(2, $counter);
        $this->assertEquals(['save_number' => 2], $auto->getLastSave()->data);
    }
}
