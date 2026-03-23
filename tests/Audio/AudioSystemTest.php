<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Audio;

use PHPUnit\Framework\TestCase;
use PHPolygon\Audio\NullAudioBackend;
use PHPolygon\Component\AudioSource;
use PHPolygon\ECS\World;
use PHPolygon\System\AudioSystem;

class AudioSystemTest extends TestCase
{
    public function testPlayAndStop(): void
    {
        $backend = new NullAudioBackend();
        $system = new AudioSystem($backend);
        $source = new AudioSource(clipId: 'music', volume: 0.8);

        $system->play($source);
        $this->assertTrue($source->playing);
        $this->assertGreaterThan(0, $source->playbackId);

        $system->stop($source);
        $this->assertFalse($source->playing);
        $this->assertSame(0, $source->playbackId);
    }

    public function testPlayOnAwake(): void
    {
        $backend = new NullAudioBackend();
        $system = new AudioSystem($backend);

        $world = new World();
        $entity = $world->createEntity();
        $entity->attach(new AudioSource(clipId: 'bgm', playOnAwake: true));

        $world->addSystem($system);

        $source = $entity->get(AudioSource::class);
        $this->assertTrue($source->playing);
    }

    public function testEmptyClipDoesNotPlay(): void
    {
        $system = new AudioSystem();
        $source = new AudioSource(clipId: '');

        $system->play($source);
        $this->assertFalse($source->playing);
    }

    public function testMasterVolume(): void
    {
        $backend = new NullAudioBackend();
        $this->assertEqualsWithDelta(1.0, $backend->getMasterVolume(), 0.001);

        $backend->setMasterVolume(0.5);
        $this->assertEqualsWithDelta(0.5, $backend->getMasterVolume(), 0.001);
    }
}
