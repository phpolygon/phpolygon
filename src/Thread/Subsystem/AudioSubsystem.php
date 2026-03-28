<?php

declare(strict_types=1);

namespace PHPolygon\Thread\Subsystem;

use PHPolygon\Component\AudioSource;
use PHPolygon\ECS\World;
use PHPolygon\Thread\SubsystemInterface;

/**
 * Audio subsystem for threaded execution.
 *
 * Main thread collects play/stop commands via AudioCommandBuffer.
 * Worker thread processes commands against the AudioBackendInterface and
 * reports back playback status (which clips finished playing).
 *
 * The AudioBackend is instantiated INSIDE the worker thread — native audio
 * resources cannot be serialized across channels.
 */
class AudioSubsystem implements SubsystemInterface
{
    private AudioCommandBuffer $commandBuffer;

    /** @var string Backend class to instantiate in the worker thread */
    private string $backendClass;

    public function __construct()
    {
        $this->commandBuffer = new AudioCommandBuffer();
        $this->backendClass = \PHPolygon\Audio\NullAudioBackend::class;
    }

    public function setBackendClass(string $class): void
    {
        $this->backendClass = $class;
    }

    public function getCommandBuffer(): AudioCommandBuffer
    {
        return $this->commandBuffer;
    }

    public function prepareInput(World $world, float $dt): array
    {
        // Collect any pending play-on-awake sources
        foreach ($world->query(AudioSource::class) as $entity) {
            $source = $world->getComponent($entity->id, AudioSource::class);
            if ($source->playOnAwake && !$source->playing && $source->clipId !== '') {
                $this->commandBuffer->play($source->clipId, $source->volume, $source->loop);
                $source->playing = true;
            }
        }

        return [
            'commands' => $this->commandBuffer->flush(),
            'backendClass' => $this->backendClass,
        ];
    }

    public function applyDeltas(World $world, array $deltas): void
    {
        /** @var list<int> $finishedPlaybacks */
        $finishedPlaybacks = $deltas['finished'] ?? [];

        foreach ($world->query(AudioSource::class) as $entity) {
            $source = $world->getComponent($entity->id, AudioSource::class);
            if ($source->playing && $source->playbackId > 0) {
                if (in_array($source->playbackId, $finishedPlaybacks, true)) {
                    $source->playing = false;
                    $source->playbackId = 0;
                }
            }
        }
    }

    public static function threadEntry(string $channelPrefix): void
    {
        $in = \parallel\Channel::open("{$channelPrefix}_in");
        $out = \parallel\Channel::open("{$channelPrefix}_out");

        while (true) {
            $input = $in->recv();
            if (!is_array($input)) {
                break;
            }
            /** @var array<string, mixed> $input */
            $out->send(self::compute($input));
        }
    }

    public static function compute(array $input): array
    {
        /** @var list<array<string, string|float|int|bool>> $commands */
        $commands = $input['commands'] ?? [];

        // In a real audio thread, we'd instantiate the backend and process commands.
        // For now, return empty finished list (no real audio hardware in test context).
        $finished = [];
        $playbackIds = [];

        foreach ($commands as $cmd) {
            $type = (string) ($cmd['type'] ?? '');
            switch ($type) {
                case 'play':
                    // Would call backend->play() — return a fake playbackId
                    $playbackIds[] = random_int(1000, 9999);
                    break;
                case 'stop':
                    $finished[] = (int) ($cmd['playbackId'] ?? 0);
                    break;
                case 'stopAll':
                    // Would call backend->stopAll()
                    break;
            }
        }

        return [
            'finished' => $finished,
            'playbackIds' => $playbackIds,
        ];
    }
}
