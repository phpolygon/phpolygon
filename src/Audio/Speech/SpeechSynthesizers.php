<?php

declare(strict_types=1);

namespace PHPolygon\Audio\Speech;

/**
 * The synthesizers of build.json `speech.synthesizer` / `--synth=`: name → class
 * and parameter table.
 */
final class SpeechSynthesizers
{
    public const string CHATTERBOX = 'chatterbox';
    public const string VOXCPM = 'voxcpm';

    /** @var list<string> */
    public const array NAMES = [self::CHATTERBOX, self::VOXCPM];

    /**
     * @return class-string<PythonSynthesizer>
     * @throws \InvalidArgumentException
     */
    public static function class(string $name): string
    {
        return match ($name) {
            self::CHATTERBOX => ChatterboxSynthesizer::class,
            self::VOXCPM => VoxCpmSynthesizer::class,
            default => throw new \InvalidArgumentException("unknown speech synthesizer \"{$name}\" (" . implode(', ', self::NAMES) . ')'),
        };
    }

    /**
     * @return class-string<SynthesizerParams>
     * @throws \InvalidArgumentException
     */
    public static function paramsClass(string $name): string
    {
        return match ($name) {
            self::CHATTERBOX => SpeechParams::class,
            self::VOXCPM => VoxCpmParams::class,
            default => throw new \InvalidArgumentException("unknown speech synthesizer \"{$name}\" (" . implode(', ', self::NAMES) . ')'),
        };
    }

    /**
     * The synthesizer of build.json `speech`, with the environment overrides of
     * {@see PythonSynthesizer::fromOptions()}.
     *
     * @param (callable(): ?int)|null $freeMemory
     */
    public static function fromConfig(SpeechConfig $config, string $projectRoot, ?callable $freeMemory = null): PythonSynthesizer
    {
        return self::class($config->synthesizer)::fromConfig($config, $projectRoot, $freeMemory);
    }
}
