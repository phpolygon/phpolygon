<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Audio;

use PHPUnit\Framework\TestCase;
use PHPolygon\Audio\AudioManager;
use PHPolygon\Audio\GLFWAudioBackend;
use PHPolygon\Audio\NullAudioBackend;

class GLFWAudioBackendTest extends TestCase
{
    public function testNullBackendPlayReturnsIncrementingIds(): void
    {
        $backend = new NullAudioBackend();
        $backend->load('test', '/dev/null');

        $id1 = $backend->play('test');
        $id2 = $backend->play('test');

        $this->assertSame(1, $id1);
        $this->assertSame(2, $id2);
    }

    public function testNullBackendIsPlayingAlwaysFalse(): void
    {
        $backend = new NullAudioBackend();
        $this->assertFalse($backend->isPlaying(1));
    }

    public function testNullBackendMasterVolume(): void
    {
        $backend = new NullAudioBackend();
        $backend->setMasterVolume(0.5);
        $this->assertEqualsWithDelta(0.5, $backend->getMasterVolume(), 0.001);
    }

    public function testAudioManagerWithNullBackend(): void
    {
        $manager = new AudioManager(new NullAudioBackend());
        $manager->loadClip('sfx', '/dev/null');

        $id = $manager->playSfx('sfx', 0.8);
        $this->assertGreaterThan(0, $id);

        $manager->stop($id);
        $manager->dispose();
    }

    public function testAudioManagerChannelVolume(): void
    {
        $manager = new AudioManager();
        $manager->setMasterVolume(0.5);
        $this->assertEqualsWithDelta(0.5, $manager->getMasterVolume(), 0.001);
    }

    public function testGLFWAudioBackendClassExists(): void
    {
        $this->assertTrue(class_exists(GLFWAudioBackend::class));
    }

    /**
     * @requires extension glfw
     */
    public function testGLFWAudioBackendCanInstantiate(): void
    {
        if (!class_exists(\GL\Audio\Engine::class)) {
            $this->markTestSkipped('GL\Audio\Engine not available');
        }

        $backend = new GLFWAudioBackend();
        $this->assertEqualsWithDelta(1.0, $backend->getMasterVolume(), 0.001);

        $backend->setMasterVolume(0.7);
        $this->assertEqualsWithDelta(0.7, $backend->getMasterVolume(), 0.001);

        $backend->dispose();
    }
}
