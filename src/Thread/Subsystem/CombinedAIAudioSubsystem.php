<?php

declare(strict_types=1);

namespace PHPolygon\Thread\Subsystem;

use PHPolygon\ECS\World;
use PHPolygon\Thread\SubsystemInterface;

/**
 * Combined AI + Audio subsystem for hardware with fewer than 8 cores.
 *
 * Audio always processes first (buffer-critical), then AI gets remaining frame time.
 * Used when ThreadScheduler merges the two subsystems to save a core.
 */
class CombinedAIAudioSubsystem implements SubsystemInterface
{
    private AudioSubsystem $audio;
    private AISubsystem $ai;

    public function __construct()
    {
        $this->audio = new AudioSubsystem();
        $this->ai = new AISubsystem();
    }

    public function getAudioSubsystem(): AudioSubsystem
    {
        return $this->audio;
    }

    public function getAISubsystem(): AISubsystem
    {
        return $this->ai;
    }

    public function prepareInput(World $world, float $dt): array
    {
        return [
            'audio' => $this->audio->prepareInput($world, $dt),
            'ai' => $this->ai->prepareInput($world, $dt),
        ];
    }

    public function applyDeltas(World $world, array $deltas): void
    {
        /** @var array<string, mixed> $audioDeltas */
        $audioDeltas = $deltas['audio'] ?? [];
        /** @var array<string, mixed> $aiDeltas */
        $aiDeltas = $deltas['ai'] ?? [];

        $this->audio->applyDeltas($world, $audioDeltas);
        $this->ai->applyDeltas($world, $aiDeltas);
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
        /** @var array<string, mixed> $audioInput */
        $audioInput = $input['audio'] ?? [];
        /** @var array<string, mixed> $aiInput */
        $aiInput = $input['ai'] ?? [];

        // Audio first — highest priority
        $audioDeltas = AudioSubsystem::compute($audioInput);
        // AI with remaining time
        $aiDeltas = AISubsystem::compute($aiInput);

        return [
            'audio' => $audioDeltas,
            'ai' => $aiDeltas,
        ];
    }
}
